// Parliament Chess: "How a neural net plays", recorded from the running game. Headless Chrome, driven
// over the DevTools protocol, watches the champion net play Medium Populist side by side with the
// net's mind, reads the panel aloud in captions, hovers an input, a unit, the value and the move played,
// and records every frame the browser paints (Page.startScreencast); ffmpeg turns them into an MP4.
// Needs Node 22+, Chrome and ffmpeg.
//
//   PC_DATA_DIR=$(mktemp -d) php -S 127.0.0.1:8094 -t public public/index.php &
//   node film/brain_demo.mjs film/out/net_mind.mp4
import { spawn, spawnSync } from 'node:child_process';
import { writeFileSync, mkdirSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';

const BASE = process.env.PC_BASE || 'http://127.0.0.1:8094';
const OUT = process.argv[2] || 'film/out/net_mind.mp4';
const WORK = join(dirname(OUT), 'net-mind-frames');
const W = 1920, H = 1080;
rmSync(WORK, { recursive: true, force: true });
mkdirSync(WORK, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// --- Chrome and the protocol -------------------------------------------------------------------
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', [
  '--headless=new', '--hide-scrollbars', '--remote-debugging-port=9335', '--force-device-scale-factor=1',
  `--user-data-dir=${WORK}/../chrome-net-mind`, `--window-size=${W},${H}`, 'about:blank'], { stdio: 'ignore' });
let ws;
for (let i = 0; i < 60 && !ws; i++) {
  try {
    const page = (await (await fetch('http://127.0.0.1:9335/json/list')).json()).find((t) => t.type === 'page');
    if (page) {
      ws = new WebSocket(page.webSocketDebuggerUrl);
      await new Promise((r) => ws.addEventListener('open', r, { once: true }));
      break;
    }
  } catch {}
  await sleep(200);
}
let nextId = 1;
const pending = new Map();
const frames = [];
ws.addEventListener('message', (e) => {
  const m = JSON.parse(e.data);
  if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); return; }
  if (m.method === 'Page.screencastFrame') {
    const n = frames.length;
    writeFileSync(`${WORK}/f${String(n).padStart(6, '0')}.jpg`, Buffer.from(m.params.data, 'base64'));
    frames.push(m.params.metadata.timestamp);
    cdp('Page.screencastFrameAck', { sessionId: m.params.sessionId }).catch(() => {});
  }
});
const cdp = (method, params = {}) => new Promise((res, rej) => {
  const id = nextId++;
  pending.set(id, (m) => (m.error ? rej(new Error(method + ': ' + m.error.message)) : res(m.result)));
  ws.send(JSON.stringify({ id, method, params }));
});
const js = async (expr) => {
  const r = await cdp('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
  if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
  return r.result.value;
};
await cdp('Page.enable');
await cdp('Emulation.setDeviceMetricsOverride', { width: W, height: H, deviceScaleFactor: 1, mobile: false });

// --- Captions: a bar along the bottom of the frame, crossfaded ----------------------------------
const CAPTION_CSS = `#cap{position:fixed;left:50%;bottom:28px;transform:translateX(-50%);max-width:1500px;padding:14px 28px;
  border-radius:12px;background:rgba(8,26,21,.9);box-shadow:0 0 0 1px rgba(240,207,106,.35),0 12px 40px rgba(0,0,0,.5);
  color:#f3ead6;font:500 29px/1.35 "Iowan Old Style",Palatino,Georgia,serif;text-align:center;transition:opacity .45s;z-index:99}
  #cap b{color:#f0cf6a;font-weight:600}`;
const caption = async (html) => js(`(async () => {
  let c = document.getElementById('cap');
  if (!c) { const st = document.createElement('style'); st.textContent = ${JSON.stringify(CAPTION_CSS)}; document.head.append(st);
    c = document.createElement('div'); c.id = 'cap'; c.style.opacity = 0; document.body.append(c); }
  if (c.innerHTML) { c.style.opacity = 0; await new Promise((r) => setTimeout(r, 450)); }
  c.innerHTML = ${JSON.stringify(html)}; c.style.opacity = ${html ? 1 : 0};
})()`);

// A card of its own (title and end): the game's colours, centred type.
const card = (title, sub, foot = '') => `data:text/html;charset=utf-8,${encodeURIComponent(`<!doctype html><html><body style="margin:0;height:100vh;display:flex;
  flex-direction:column;align-items:center;justify-content:center;background:radial-gradient(1400px 700px at 30% -10%,#1f4a3d,#0f2c24 62%);
  color:#f3ead6;font-family:'Iowan Old Style',Palatino,Georgia,serif;text-align:center">
  <div style="color:#d4a62a;font-size:64px">&#9818;&#xFE0E;</div>
  <div style="font-size:84px;font-weight:600;letter-spacing:.01em;margin:6px 0 10px">${title}</div>
  <div style="font-size:34px;font-style:italic;color:#f0cf6a;max-width:1300px;line-height:1.4">${sub}</div>
  <div style="font:400 24px -apple-system,sans-serif;color:rgba(243,234,214,.7);margin-top:42px;max-width:1300px;line-height:1.5">${foot}</div></body></html>`)}`;

// --- The game's progress, read from the API ----------------------------------------------------
let gameId = null;
const plies = async () => (await (await fetch(`${BASE}/api/state?id=${gameId}&since=-1`)).json());
const waitFor = async (test, maxMs) => {
  const t0 = Date.now();
  while (Date.now() - t0 < maxMs) { const s = await plies(); if (test(s)) return s; await sleep(400); }
  return plies();
};
// Point at something in the net's mind: canvas coordinates (1240 x 600) to the page's.
const pointAt = async (cx, cy) => {
  const [x, y] = await js(`(() => { const r = document.querySelector('canvas.brain').getBoundingClientRect();
    return [r.left + ${cx} * r.width / 1240, r.top + ${cy} * r.height / 600]; })()`);
  // Glide there, so the pointer reads as a hand.
  for (let i = 1; i <= 12; i++) {
    await cdp('Input.dispatchMouseEvent', { type: 'mouseMoved', x: last[0] + (x - last[0]) * i / 12, y: last[1] + (y - last[1]) * i / 12 });
    await sleep(25);
  }
  last = [x, y];
};
let last = [W / 2, H - 200];
const cursor = async () => js(`(() => { if (document.getElementById('hand')) return; const d = document.createElement('div'); d.id = 'hand';
  d.style.cssText = 'position:fixed;width:22px;height:22px;margin:-11px 0 0 -11px;border-radius:50%;border:3px solid #fff;box-shadow:0 0 0 2px #0f2c24,0 0 14px rgba(240,207,106,.9);pointer-events:none;z-index:98;left:-50px;top:-50px';
  document.body.append(d); addEventListener('mousemove', (e) => { d.style.left = e.clientX + 'px'; d.style.top = e.clientY + 'px'; }); })()`);

// --- The film ----------------------------------------------------------------------------------
await cdp('Page.startScreencast', { format: 'jpeg', quality: 88, maxWidth: W, maxHeight: H, everyNthFrame: 1 });

await cdp('Page.navigate', { url: card('How a neural net plays', 'Parliament Chess: the champion net against a hand-written bot, and everything it computes before every move',
  'Generation 5, trained by the Orrery fleet · the first net elected') });
await sleep(6500);

// The lobby, then the button
await cdp('Page.navigate', { url: `${BASE}/play.html` });
await sleep(1500);
await js(`localStorage.setItem('pc-theatre', '1'); document.querySelector('.card.net').scrollIntoView({ block: 'center' })`);
await caption('In the lobby: <b>Play the neural net</b>, or <b>watch it think</b>.');
await sleep(4200);
await js(`[...document.querySelectorAll('button')].find((b) => b.textContent === 'Watch it think').click()`);
await sleep(1800);
gameId = new URL(await js('location.href')).searchParams.get('watch');
await cursor();
await caption('White is the <b>champion net</b>. Black is <b>Medium Populist</b>: hand-written rules, no net.');
await sleep(5500);
await caption('Left, the game. Right, <b>everything the net computed</b> for the move it just played.');
await sleep(5500);
await caption('It sees <b>1,203 switches</b> from its own side: every piece, every attacked square, loose pieces, the election.');
await sleep(6500);
await caption('Each lit switch adds its weights into <b>160 units</b>. <b>Gold</b> lines excite; <b style="color:#7aaaf0">blue</b> lines inhibit.');
await sleep(6500);
await caption('<b>Forty</b> more units mix them, and two heads answer: <b>who is winning</b>, and <b>how much it likes each move</b>.');
await sleep(6500);
await caption('Then a <b>two-ply search</b> decides. Gold: the move played. Blue arrows: its other favourites.');
await sleep(7000);

// Out of the book: read the panel with the pointer (hovering holds the game)
await waitFor((s) => s.brain && s.brain.source === 'search' && s.log.length === s.brain.ply, 90000);
await sleep(3800);
await caption('Hover anything to read it. While you read, the game waits.');
const b = await js('(() => { const b = pcBrain.b; return { feats: b.features, a1: b.a1, a2: b.a2, rows: pcBrain.rows.map((r) => r.uci), chosen: b.chosen }; })()');
const pick = (want) => b.feats.find((i) => i >= want[0] && i < want[1]);
const loose = pick([1024, 1152]) ?? pick([896, 1024]) ?? b.feats[0];
const pos = await js(`(() => { const p = pcBrain.inPos(${loose}); return [p.x, p.y]; })()`);
await pointAt(pos[0], pos[1]);
await sleep(600);
await caption('An input: where its signal goes, and how strongly.');
await sleep(4500);
const u1 = b.a1.indexOf(Math.max(...b.a1));
await pointAt(680, 70 + u1 * 3 + 1.5);
await caption('A unit in layer 1: its sum, what it fires, and the inputs that drove it.');
await sleep(4800);
const u2 = b.a2.indexOf(Math.max(...b.a2));
await pointAt(880, 78 + u2 * 11.8);
await caption('Layer 2 mixes the 160 into 40 and passes them on.');
await sleep(4200);
await pointAt(990, 100);
await caption('The value: the net’s correction to the hand-written evaluation, in centipawns.');
await sleep(4800);
const row = Math.max(0, b.rows.indexOf(b.chosen));
await pointAt(1100, 192 + row * 46);
await caption('The move played: the policy’s rank for it, and the search’s score.');
await sleep(5000);
await pointAt(1300, 760);
await cdp('Input.dispatchMouseEvent', { type: 'mouseMoved', x: W / 2, y: H - 40 });
await caption('');
await sleep(800);

// Let it play into the middlegame: Writs are legal from move 10
await caption('From move 10 either side may file a <b>Writ</b>: watch the <b>Election</b> row and the Writs in hand.');
await waitFor((s) => s.log.length >= 30 || s.status !== 'playing', 120000);
await caption('Generation 5 scored <b>57%</b> against plain Medium over 200 games: the first net the fleet elected.');
await waitFor((s) => s.log.length >= 40 || s.status !== 'playing', 90000);
await caption('Generations 6, 7 and 8 played it to <b>47–49%</b> and were not elected. The fleet keeps training.');
await sleep(9000);
await caption('');

await cdp('Page.navigate', { url: card('Play it yourself', 'make up · open localhost:8080/play.html · choose <b>Play the neural net</b>',
  'github.com/vonglurt/parliamentchess · Lab Report XII: how a neural net plays, and how to read its mind') });
await sleep(6000);
const stopped = Date.now() / 1000; // a still page paints no new frames: its last frame lasts until here
await cdp('Page.stopScreencast');
await sleep(300);
ws.close();
chrome.kill();

// --- Frames to a film: each frame lasts until the next one was painted --------------------------
const list = frames.map((t, i) => `file 'f${String(i).padStart(6, '0')}.jpg'\nduration ${Math.max(0.001, (frames[i + 1] ?? stopped) - t).toFixed(4)}`);
// (the concat demuxer needs the last image twice; the copy gets one frame's time, not a second helping)
writeFileSync(`${WORK}/frames.txt`, list.join('\n') + `\nfile 'f${String(frames.length - 1).padStart(6, '0')}.jpg'\nduration 0.0333\n`);
const ff = spawnSync('ffmpeg', ['-y', '-loglevel', 'error', '-f', 'concat', '-safe', '0', '-i', `${WORK}/frames.txt`, '-t', (stopped - frames[0]).toFixed(2),
  '-vf', 'fps=30,format=yuv420p', '-c:v', 'libx264', '-preset', 'slow', '-crf', '22', '-movflags', '+faststart', OUT], { stdio: 'inherit' });
console.log(`${frames.length} frames over ${(stopped - frames[0]).toFixed(1)} s -> ${OUT} (ffmpeg ${ff.status})`);
