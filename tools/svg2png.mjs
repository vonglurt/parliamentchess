// An SVG to a PNG through headless Chrome, so the figures render with the system's own fonts.
//   node tools/svg2png.mjs in.svg out.png [scale]
import { spawn } from 'node:child_process';
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [src, out, scale = '2'] = process.argv.slice(2);
const svg = readFileSync(src, 'utf8');
const W = +svg.match(/width="(\d+)"/)[1], H = +svg.match(/height="(\d+)"/)[1];
const port = 9500 + Math.floor(Math.random() * 400), dir = mkdtempSync(join(tmpdir(), 'svg2png-'));
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--hide-scrollbars',
  `--remote-debugging-port=${port}`, `--user-data-dir=${dir}`, 'about:blank'], { stdio: 'ignore' });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let ws;
for (let i = 0; i < 80 && !ws; i++) {
  try {
    const p = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === 'page');
    if (p) { ws = new WebSocket(p.webSocketDebuggerUrl); await new Promise((r) => ws.addEventListener('open', r, { once: true })); }
  } catch {}
  await sleep(150);
}
let id = 1;
const pend = new Map();
ws.addEventListener('message', (e) => { const m = JSON.parse(e.data); if (m.id && pend.has(m.id)) { pend.get(m.id)(m); pend.delete(m.id); } });
const cdp = (method, params = {}) => new Promise((res) => { const i = id++; pend.set(i, (m) => res(m.result || m)); ws.send(JSON.stringify({ id: i, method, params })); });
await cdp('Page.enable');
await cdp('Emulation.setDeviceMetricsOverride', { width: W, height: H, deviceScaleFactor: +scale, mobile: false });
await cdp('Page.navigate', { url: 'data:text/html;charset=utf-8,' + encodeURIComponent(`<!doctype html><html><body style="margin:0">${svg}</body></html>`) });
await sleep(700);
const shot = await cdp('Page.captureScreenshot', { format: 'png', clip: { x: 0, y: 0, width: W, height: H, scale: 1 } });
writeFileSync(out, Buffer.from(shot.data, 'base64'));
ws.close();
const exited = new Promise((r) => chrome.once('exit', r));
chrome.kill();
await exited;
try { rmSync(dir, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 }); } catch { /* the OS cleans its temp folder */ }
