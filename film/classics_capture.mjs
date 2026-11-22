// Parliament Chess: the live chapter of "The Net Reads the Classics". Headless Chrome, driven over the
// DevTools protocol, opens the lobby, starts The classics → The Opera Game, and records every frame the
// browser paints (Page.startScreencast) while the champion net reads Morphy's moves in the real client.
// Captions are written from the live numbers the server reports (the net's rank of each historic move,
// and what it would play), so they always say what the film shows. Hovering the panel holds the game.
// Writes the film and a timing file (when each ply arrived) for film/classics_cut.py.
// Needs Node 22+, Chrome and ffmpeg.
//
//   PC_DATA_DIR=$(mktemp -d) sh bin/php -S 127.0.0.1:8094 -t public public/index.php &
//   node film/classics_capture.mjs film/out/classics_live.mp4
import { spawn, spawnSync } from 'node:child_process';
import { writeFileSync, mkdirSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';

const BASE = process.env.PC_BASE || 'http://127.0.0.1:8094';
const OUT = process.argv[2] || 'film/out/classics_live.mp4';
const WORK = join(dirname(OUT), 'classics-live-frames');
const W = 1920, H = 1080, PORT = 9337;
rmSync(WORK, { recursive: true, force: true });
mkdirSync(WORK, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// --- Chrome and the protocol -------------------------------------------------------------------
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', [
  '--headless=new', '--hide-scrollbars', `--remote-debugging-port=${PORT}`, '--force-device-scale-factor=1',
  `--user-data-dir=${WORK}/../chrome-classics`, `--window-size=${W},${H}`, 'about:blank'], { stdio: 'ignore' });
let ws;
for (let i = 0; i < 60 && !ws; i++) {
  try {
    const page = (await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json()).find((t) => t.type === 'page');
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
    writeFileSync(`${WORK}/f${String(frames.length).padStart(6, '0')}.jpg`, Buffer.from(m.params.data, 'base64'));
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

// --- Overlays: captions, a lower third, a badge, the pointer. On <html>, so zooming <body> leaves them be.
const CSS = `#cap{position:fixed;left:50%;bottom:30px;transform:translateX(-50%);max-width:1560px;padding:14px 28px;border-radius:12px;
  background:rgba(8,26,21,.92);box-shadow:0 0 0 1px rgba(240,207,106,.4),0 12px 40px rgba(0,0,0,.55);color:#f3ead6;
  font:500 29px/1.35 "Iowan Old Style",Palatino,Georgia,serif;text-align:center;transition:opacity .4s;z-index:99}
  #cap b{color:#f0cf6a;font-weight:600} #cap i{color:#33f24d;font-style:normal;font-family:Menlo,monospace;font-size:25px}
  #l3{position:fixed;left:28px;top:84px;padding:10px 18px 10px 14px;background:#070807;border-left:6px solid #33f24d;color:#33f24d;
  font:600 22px Menlo,monospace;letter-spacing:.06em;box-shadow:0 10px 30px rgba(0,0,0,.6);transition:transform .6s,opacity .6s;z-index:97}
  #l3 span{color:#f3ead6;font:italic 20px Palatino,serif;letter-spacing:0;margin-left:12px}
  #ff{position:fixed;right:34px;top:84px;padding:8px 16px;background:#ffbf4d;color:#070807;font:700 30px Menlo,monospace;border-radius:6px;z-index:97;transition:opacity .3s}
  #hand{position:fixed;width:24px;height:24px;margin:-12px 0 0 -12px;border-radius:50%;border:3px solid #fff;
  box-shadow:0 0 0 2px #0f2c24,0 0 16px rgba(240,207,106,.95);pointer-events:none;z-index:98;left:-50px;top:-50px}`;
const overlays = () => js(`(() => { if (document.getElementById('ovl-css')) return; const st = document.createElement('style'); st.id = 'ovl-css';
  st.textContent = ${JSON.stringify(CSS)}; document.documentElement.append(st);
  const hand = document.createElement('div'); hand.id = 'hand'; document.documentElement.append(hand);
  addEventListener('mousemove', (e) => { hand.style.left = e.clientX + 'px'; hand.style.top = e.clientY + 'px'; }); })()`);
const caption = async (html) => js(`(async () => {
  let c = document.getElementById('cap');
  if (!c) { c = document.createElement('div'); c.id = 'cap'; c.style.opacity = 0; document.documentElement.append(c); }
  if (c.innerHTML) { c.style.opacity = 0; await new Promise((r) => setTimeout(r, 400)); }
  c.innerHTML = ${JSON.stringify(html)}; c.style.opacity = ${html ? 1 : 0};
})()`);
const lowerThird = (html) => js(`(() => { let l = document.getElementById('l3'); if (!l) { l = document.createElement('div'); l.id = 'l3';
  l.style.transform = 'translateX(-120%)'; document.documentElement.append(l); } l.innerHTML = ${JSON.stringify(html)};
  requestAnimationFrame(() => { l.style.transform = ${html ? "'none'" : "'translateX(-120%)'"}; }); })()`);
const badge = (text) => js(`(() => { let b = document.getElementById('ff'); if (!b) { b = document.createElement('div'); b.id = 'ff'; document.documentElement.append(b); }
  b.textContent = ${JSON.stringify(text)}; b.style.opacity = ${text ? 1 : 0}; })()`);

let last = [W / 2, H - 220];
const glide = async (x, y, steps = 14) => {
  for (let i = 1; i <= steps; i++) {
    await cdp('Input.dispatchMouseEvent', { type: 'mouseMoved', x: last[0] + (x - last[0]) * i / steps, y: last[1] + (y - last[1]) * i / steps });
    await sleep(22);
  }
  last = [x, y];
};
// A point in the MIND matrix (canvas.mind, 1240 x 470) in the page's coordinates, zoom included.
const mindAt = (cx, cy) => js(`(() => { const r = document.querySelector('canvas.mind').getBoundingClientRect();
  return [r.left + ${cx} * r.width / 1240, r.top + ${cy} * r.height / 470]; })()`);
const playedRow = () => js(`(() => { const m = pcMind; return Math.max(0, m.rows.findIndex((r) => r.uci === m.b.chosen)); })()`);
const pointPlayed = async () => { const r = await playedRow(); const [x, y] = await mindAt(560, 254 + r * 24); await glide(x, y); };
const zoom = (on) => js(`(() => { const b = document.body, r = document.querySelector('canvas.mind').getBoundingClientRect();
  b.style.transition = 'transform 1.1s cubic-bezier(.2,.7,.2,1)';
  if (${on}) { b.style.transformOrigin = (r.left + r.width * 0.42) + 'px ' + (r.top + r.height * 0.5 + scrollY) + 'px'; b.style.transform = 'scale(1.55)'; }
  else b.style.transform = 'none'; })()`);

// --- The game's progress -----------------------------------------------------------------------
let gameId = null;
const state = async () => (await (await fetch(`${BASE}/api/state?id=${gameId}&since=-1`)).json());
const times = {};
let t0 = 0;
const shown = async (ply, maxMs = 60000) => {
  // Wait until the page itself shows ply's calculation (its MIND panel has it), and note the time.
  const start = Date.now();
  while (Date.now() - start < maxMs) {
    const ok = await js(`!!(window.pcMind && pcMind.b && pcMind.b.ply === ${ply})`).catch(() => false);
    if (ok) { times[ply] = Date.now() / 1000 - t0; return; }
    await sleep(120);
  }
  throw new Error(`ply ${ply} never showed`);
};
const brainOf = async () => js(`(() => { const b = pcMind.b, rows = pcMind.ranked, me = rows.find((r) => r.uci === b.chosen);
  return { san: me ? me.san : b.chosen, rank: me ? me.rank : 0, n: rows.length, p: me ? me.p : 0, would: b.would, agree: b.wouldUci === b.chosen,
    search: me ? me.search : null, value: b.value }; })()`);
const pct = (p) => (100 * p).toFixed(1) + '%';

// --- The film ----------------------------------------------------------------------------------
await cdp('Page.navigate', { url: `${BASE}/play.html` });
await sleep(1200);
await js(`localStorage.setItem('pc-theatre', '1'); localStorage.setItem('pc-brain-style', 'matrix'); localStorage.setItem('pc-brain-view', 'follow')`);
await cdp('Page.navigate', { url: `${BASE}/play.html` });
await sleep(1500);
await cdp('Page.startScreencast', { format: 'jpeg', quality: 90, maxWidth: W, maxHeight: H, everyNthFrame: 1 });
t0 = Date.now() / 1000;
await overlays();
await js(`(() => { const s = document.getElementById('classic'); s.closest('.card').scrollIntoView({ block: 'center' }); })()`);
await sleep(600);
await lowerThird('LIVE<span>the real client, recorded as it plays</span>');
await caption('In the lobby, a new card: <b>The classics</b>. Famous short games, read by the champion net.');
await sleep(3200);
const [sx, sy] = await js(`(() => { const r = document.getElementById('classic').getBoundingClientRect(); return [r.left + 60, r.top + r.height / 2]; })()`);
await glide(sx, sy);
await js(`(() => { const s = document.getElementById('classic'); s.value = 'opera'; s.dispatchEvent(new Event('change')); })()`);
await caption('Pick <b>The Opera Game, 1858</b>: Paul Morphy against the Duke of Brunswick and Count Isouard.');
await sleep(2600);
const [bx, by] = await js(`(() => { const b = [...document.querySelectorAll('button')].find((x) => x.textContent === 'Watch the net read it'); const r = b.getBoundingClientRect(); return [r.left + r.width / 2, r.top + r.height / 2]; })()`);
await glide(bx, by);
await sleep(300);
await js(`[...document.querySelectorAll('button')].find((b) => b.textContent === 'Watch the net read it').click()`);
await sleep(1600);
gameId = new URL(await js('location.href')).searchParams.get('watch');
await overlays();
await glide(W / 2, H - 60);
await lowerThird('LIVE · THE OPERA GAME<span>Paris, 1858 · every move is history’s</span>');
await shown(1);
await caption('Every move is history’s. The net reads each one through its own weights: inputs, 160 and 40 units, every move’s score.');
await shown(2);
await sleep(1400);
await caption('Beside the board, <b>MIND</b> holds the last decision; above, each side’s card says what the net <b>would</b> have played.');
await shown(3);
await shown(4);
// Fast-forward through the opening and the development (the cut speeds this stretch up).
times.ffFrom = Date.now() / 1000 - t0;
await caption('');
await badge('▶▶  4×');
for (let p = 5; p <= 18; p++) await shown(p, 60000);
await badge('');
times.ffTo = Date.now() / 1000 - t0;
await shown(19);
let b = await brainOf();
await caption(`10.${b.san}! Morphy gives a Knight for two pawns, to open lines at the King. The net ranks it #${b.rank}, ${pct(b.p)}: ${b.agree ? '<b>and would play it too</b>' : `it would play <i>${b.would}</i>`}.`);
await shown(21);
b = await brainOf();
await caption(`11.${b.san}+: check, and after 11\u2026Nbd7 the Knight is pinned. The net ${b.agree ? '<b>would play it too</b>' : `would play <i>${b.would}</i>`}.`);
await shown(23);
b = await brainOf();
await caption(`12.${b.san}: the King to safety and a Rook to the open d-file. ${b.agree ? 'The net <b>agrees</b>.' : `The net would play <i>${b.would}</i>.`}`);
await shown(25);
// 13.Rxd7!: hold the game on it while the pointer reads the move.
await pointPlayed();
b = await brainOf();
times.hold1 = Date.now() / 1000 - t0;
await caption(`13.${b.san}! gives up a Rook for a Knight. The policy ranks it #${b.rank} of ${b.n}, ${pct(b.p)}; ${b.agree ? 'the net would play it too.' : `the net would play <i>${b.would}</i>.`}`);
await sleep(5200);
await glide(W / 2, H - 40);
await shown(27);
b = await brainOf();
await caption(`14.${b.san}: the last Rook joins the pin. ${b.agree ? 'The net <b>agrees</b>.' : `The net would play <i>${b.would}</i>.`}`);
await shown(29);
b = await brainOf();
await caption(`15.${b.san}+: ${b.agree ? 'the net <b>agrees</b>' : `the net would play <i>${b.would}</i>`}. One move to go before the sacrifice.`);
await shown(31);
// 16.Qb8+!!: zoom into the panel and read the move played.
await caption('');
await pointPlayed();
times.hold2 = Date.now() / 1000 - t0;
await zoom(true);
await sleep(1200);
await pointPlayed();
b = await brainOf();
await caption(`16.Qb8+!! The policy ranks it <b>#${b.rank} of ${b.n}</b>, ${pct(b.p)}. The search scores it <i>+${Number(b.search).toLocaleString('en-US')}</i>: mate. ${b.agree ? '<b>The net would play it too.</b>' : `It would play <i>${b.would}</i>.`}`);
await sleep(7600);
await caption('Its square turns green: in MIND, a move’s square takes the colour of the search’s score, not the policy’s hunch.');
await sleep(5200);
await zoom(false);
await sleep(900);
await glide(W / 2, H - 40);
await caption('');
await shown(32);
await shown(33);
b = await brainOf();
times.mate = Date.now() / 1000 - t0;
await caption(`17.${b.san}#. Morphy’s mate, with the last piece he had left. ${b.agree ? '<b>The net finds it too.</b>' : ''}`);
await sleep(5200);
await lowerThird('');
await caption('');
await sleep(900);
const stopped = Date.now() / 1000;
await cdp('Page.stopScreencast');
await sleep(300);
ws.close();
chrome.kill();

// --- Frames to a film: each frame lasts until the next one was painted --------------------------
const list = frames.map((t, i) => `file 'f${String(i).padStart(6, '0')}.jpg'\nduration ${Math.max(0.001, (frames[i + 1] ?? stopped) - t).toFixed(4)}`);
writeFileSync(`${WORK}/frames.txt`, list.join('\n') + `\nfile 'f${String(frames.length - 1).padStart(6, '0')}.jpg'\nduration 0.0333\n`);
const lead = frames[0] - t0; // the first frame came this long after t0
const ff = spawnSync('ffmpeg', ['-y', '-loglevel', 'error', '-f', 'concat', '-safe', '0', '-i', `${WORK}/frames.txt`, '-t', (stopped - frames[0]).toFixed(2),
  '-vf', 'fps=30,format=yuv420p', '-c:v', 'libx264', '-preset', 'slow', '-crf', '18', '-movflags', '+faststart', OUT], { stdio: 'inherit' });
const shift = (v) => (typeof v === 'number' ? Math.max(0, v - lead) : v);
writeFileSync(OUT.replace(/\.mp4$/, '.json'), JSON.stringify(Object.fromEntries(Object.entries(times).map(([k, v]) => [k, shift(v)])), null, 1));
console.log(`${frames.length} frames over ${(stopped - frames[0]).toFixed(1)} s -> ${OUT} (ffmpeg ${ff.status})`);
