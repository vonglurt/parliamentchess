// Parliament Chess: screenshots for the player's guide (film/guide.py). It drives headless Chrome over
// the DevTools protocol through a real game on a server of its own and saves a screenshot, and where
// each panel was (rects.json), at every step of a player's first game. Needs Node 22+ and Chrome.
//
//   PC_DATA_DIR=$(mktemp -d) php -S 127.0.0.1:8091 -t public public/index.php &
//   node film/guide_shots.mjs film/guide-shots
import { spawn } from 'node:child_process';
import { writeFileSync, mkdirSync } from 'node:fs';

const BASE = 'http://127.0.0.1:8091';
const OUT = process.argv[2] || 'film/guide-shots';
mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', [
  '--headless=new', '--disable-gpu', '--hide-scrollbars', '--remote-debugging-port=9333',
  `--user-data-dir=${OUT}/../chrome-cdp`, '--window-size=1600,1000', 'about:blank'], { stdio: 'ignore' });
let ws;
for (let i = 0; i < 50; i++) {
  try {
    const list = await (await fetch('http://127.0.0.1:9333/json/list')).json();
    const page = list.find((t) => t.type === 'page');
    if (page) { ws = new WebSocket(page.webSocketDebuggerUrl); break; }
  } catch {}
  await sleep(200);
}
await new Promise((r) => ws.addEventListener('open', r, { once: true }));
let nextId = 1;
const pending = new Map();
ws.addEventListener('message', (e) => {
  const m = JSON.parse(e.data);
  if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
});
const cdp = (method, params = {}) => new Promise((res, rej) => {
  const id = nextId++;
  pending.set(id, (m) => (m.error ? rej(new Error(method + ': ' + m.error.message)) : res(m.result)));
  ws.send(JSON.stringify({ id, method, params }));
});
const js = async (expr) => (await cdp('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true })).result.value;
const go = async (url, wait = 2500) => { await cdp('Page.navigate', { url }); await sleep(wait); };
const rects = {};
const shot = async (name) => {
  const { data } = await cdp('Page.captureScreenshot', { format: 'png' });
  writeFileSync(`${OUT}/${name}.png`, Buffer.from(data, 'base64'));
  // Where each panel is on screen, in CSS pixels (the PNG is 1.5x), for the film's highlights.
  rects[name] = await js(`(() => {
    const R = (el) => { if (!el) return null; const r = el.getBoundingClientRect(); return [r.left, r.top, r.width, r.height]; };
    const box = (t) => [...document.querySelectorAll('.box')].find((b) => (b.querySelector('h4')?.textContent || '').toLowerCase().startsWith(t));
    const btn = (t) => [...document.querySelectorAll('button')].find((b) => b.textContent.trim().startsWith(t));
    return { canvas: R(document.querySelector('canvas')), namebar: R(document.querySelector('.namebar')),
      cards: [...document.querySelectorAll('.card')].map(R), links: R(document.querySelector('.links')),
      houses: R(box('the houses')), count: R(box('the last count')), record: R(document.querySelector('.record')),
      writBox: R(box('file a writ')), writBtn: R(btn('File a Writ')), fileBtn: R(btn('File the Writ')),
      rename: R(box('your name')), ledger: R(box('the ledger')), hero: R(document.querySelector('header')) };
  })()`);
  console.log('shot', name);
};
const clickText = (text) => js(`(() => { const b = [...document.querySelectorAll('button')].find((x) => x.textContent.trim().startsWith(${JSON.stringify(text)})); if (!b) return false; b.click(); return true; })()`);
const clickSquare = (sq) => js(`(() => { const c = document.querySelector('canvas'); const r = c.getBoundingClientRect();
  const f = ${sq} & 7, rk = ${sq} >> 3; const px = f * 120 + 60, py = (7 - rk) * 120 + 60;
  c.dispatchEvent(new MouseEvent('click', { bubbles: true, clientX: r.left + px * r.width / 960, clientY: r.top + py * r.height / 1060 })); return true; })()`);
const api = async (route, body) => {
  const r = await fetch(`${BASE}/api/${route}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  const j = await r.json();
  if (!r.ok) throw new Error(route + ' ' + JSON.stringify(body) + ' -> ' + JSON.stringify(j));
  return j;
};
const setName = (n) => js(`localStorage.setItem('pc-name', ${JSON.stringify(n)}); true`);

await cdp('Page.enable');
await cdp('Runtime.enable');
await cdp('Emulation.setDeviceMetricsOverride', { width: 1600, height: 1000, deviceScaleFactor: 1.5, mobile: false });

// 1. The landing page and the lobby.
await go(`${BASE}/`);
await shot('01-landing');
await go(`${BASE}/play.html`, 800);
await setName('Atlantic Giant');
await go(`${BASE}/play.html`);
await shot('02-lobby');

// 2. Create a two-browser game.
await clickText('Create game');
await sleep(2500);
await shot('03-created');
const q = new URLSearchParams(await js('location.search'));
const id = q.get('game'), white = q.get('token');
const links = await js(`[...document.querySelectorAll('.links input')].map((i) => i.value)`);
const opp = new URL(links.find((u) => u.includes('token=')));
const black = opp.searchParams.get('token');
await api('name', { id, token: black, name: 'Jarrahdale' });
await js(`localStorage.setItem('pc-named-${id}', '1'); true`);

// 3. An Italian opening to move 11.
const opening = ['e4', 'e5', 'Nf3', 'Nc6', 'Bc4', 'Bc5', 'c3', 'Nf6', 'd3', 'd6', 'O-O', 'O-O', 'Re1', 'a6', 'Bb3', 'Ba7', 'h3', 'h6', 'Nbd2', 'Re8'];
for (let i = 0; i < opening.length; i++) {
  await api('move', { id, token: i % 2 ? black : white, move: opening[i] });
  await sleep(120);
}
await go(`${BASE}/play.html?game=${id}&token=${white}`);
await shot('04-floor-open');

// 4. File a Writ for the c1 Bishop: the gold squares, the preview, the filing.
await clickText('File a Writ');
await sleep(1200);
await shot('05-writ-mode');
await clickSquare(2);
await sleep(1500);
await shot('06-preview');
await clickText('File the Writ');
await sleep(2500);
await shot('07-filed');

// 5. Black's campaign move, seen from Black's browser.
await setName('Jarrahdale');
await go(`${BASE}/play.html?game=${id}&token=${black}`);
await shot('08-campaign');
await api('move', { id, token: black, move: 'Be6' });

// 6. The count, seen from White's browser.
await setName('Atlantic Giant');
await go(`${BASE}/play.html?game=${id}&token=${white}`, 4000);
await shot('09-crowned');

// 7. A few more moves, a resignation, and the game record.
for (const [t, m] of [[white, 'Bxe6'], [black, 'Rxe6'], [white, 'Nf1'], [black, 'd5']]) {
  await api('move', { id, token: t, move: m });
  await sleep(300);
}
await api('resign', { id, token: black });
await go(`${BASE}/play.html?game=${id}&token=${white}`, 3500);
await shot('10-over');
await js(`(() => { const r = document.querySelector('.record'); if (r) r.scrollIntoView({ block: 'center' }); return !!r; })()`);
await sleep(800);
await shot('11-record');

// 8. Playing the machine, and watching two machines.
await go(`${BASE}/play.html`, 1500);
await clickText('Play');
await sleep(2500);
const q2 = new URLSearchParams(await js('location.search'));
await api('move', { id: q2.get('game'), token: q2.get('token'), move: 'd4' });
await go(`${BASE}/play.html?game=${q2.get('game')}&token=${q2.get('token')}`, 6000);
await shot('12-bot');
await go(`${BASE}/play.html`, 1500);
await clickText('Watch');
await sleep(9000);
await shot('13-watch');
await go(`${BASE}/play.html?replays=1`, 2500);
await shot('14-replays');

writeFileSync(`${OUT}/rects.json`, JSON.stringify(rects, null, 1));
ws.close();
chrome.kill();
console.log(JSON.stringify({ id, white, black }));
