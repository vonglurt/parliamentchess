/* Parliament Chess client — MIT License.
 * One page, five views: lobby, game (play or watch), replays, the Orrery, the rules.
 * The board is a <canvas> so a game can be recorded straight to WebM. */
'use strict';

const $ = (s, r = document) => r.querySelector(s);
function h(tag, attrs = {}, ...kids) {
  const e = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (k === 'class') e.className = v;
    else if (k.startsWith('on') && typeof v === 'function') e.addEventListener(k.slice(2), v);
    else if (k === 'html') e.innerHTML = v;
    else if (v !== false && v != null) e.setAttribute(k, v === true ? '' : v);
  }
  for (const k of kids.flat(9)) if (k != null && k !== false) e.append(k.nodeType ? k : String(k));
  return e;
}
const api = {
  async get(route, q = {}) {
    const r = await fetch('/api/' + route + '?' + new URLSearchParams(q));
    const j = await r.json();
    if (!r.ok) throw new Error(j.error || r.statusText);
    return j;
  },
  async post(route, body = {}) {
    const r = await fetch('/api/' + route, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const j = await r.json();
    if (!r.ok) throw new Error(j.error || r.statusText);
    return j;
  },
};
function toast(msg, ms = 2600) {
  const t = h('div', { class: 'toast' }, msg);
  document.body.append(t);
  setTimeout(() => t.remove(), ms);
}

// Your name: a pumpkin or another large squash until you choose one; kept in this browser.
const PUMPKINS = ['Atlantic Giant', 'Big Max', 'Prizewinner', 'Big Moose', 'Mammoth Gold', 'Connecticut Field', 'Howden',
  "Jack-o'-Lantern", "Rouge Vif d'Etampes", 'Jarrahdale', 'Musquee de Provence', 'Hundredweight'];
const store = {
  get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
  set(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* private window: the name lasts this page */ } },
};
function myName() {
  let n = store.get('pc-name');
  if (!n) { n = PUMPKINS[Math.floor(Math.random() * PUMPKINS.length)]; store.set('pc-name', n); }
  return n;
}
function setMyName(n) { n = (n || '').trim().slice(0, 40); if (n) store.set('pc-name', n); return myName(); }
const LEVEL = (spec) => spec ? spec.replace('+net2', ' + net').replace('/', ' \u00b7 ') : '';
const localTime = (iso) => { const d = new Date(iso); return isNaN(d) ? '' : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }); };
const localDate = (iso) => { const d = new Date(iso); return isNaN(d) ? '' : d.toLocaleDateString([], { year: 'numeric', month: 'short', day: 'numeric' }); };
function download(name, text, type) {
  const a = h('a', { href: URL.createObjectURL(new Blob([text], { type })), download: name });
  document.body.append(a); a.click(); a.remove();
}
const csvCell = (v) => { const s = v == null ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; };

const Q = new URLSearchParams(location.search);
const FILES = 'abcdefgh';
const sqName = (s) => FILES[s & 7] + ((s >> 3) + 1);
const VS = '︎';
const GLYPH = { 1: '♟', 2: '♞', 3: '♝', 4: '♜', 5: '♛', 6: '♚' };
const LETTER = ['', 'P', 'N', 'B', 'R', 'Q', 'K'];
const NAME = ['', 'Pawn', 'Knight', 'Bishop', 'Rook', 'Queen', 'King'];
const OUTCOME = { coronation: 'Coronation', contested: 'Contested Coronation', swap: 'Royal Swap', defeat: 'Defeat', failed: 'Failed', void: 'Void' };
const OUTCOME_LINE = {
  coronation: 'The Candidate is crowned; the old King becomes a Pretender pawn.',
  contested: 'The College crowns the Candidate against the Commons; the opponent gains a Writ.',
  swap: 'The Commons back the Candidate, the College keeps the King: they trade squares.',
  defeat: 'Both chambers back the Incumbent. The crown stays.',
  failed: 'The Candidate could not stand.',
  void: 'The result would have broken the rules of check, so it is void.',
};
const COL = {
  light: '#efe3c8', dark: '#8f9f78', gold: '#d4a62a', gold2: '#f0cf6a', inc: '#3d6fa8', lords: '#9b2a3a',
  commons: '#2f7a57', bench: '#0f2c24', bench2: '#163a30', parch: '#f3ead6', ink: '#1e1a14',
};
const PIECE_FONT = '"Apple Symbols","Segoe UI Symbol","Noto Sans Symbols 2","DejaVu Sans",serif';
const SERIF = '"Iowan Old Style","Palatino Linotype",Palatino,Georgia,serif';
const ease = (t) => (t < 0 ? 0 : t > 1 ? 1 : t * t * (3 - 2 * t));

/** The board before a Count changed it, so the vote can be shown on the board it was held on. */
function preCount(board, ev) {
  const b = board.slice();
  if (!ev || !['coronation', 'contested', 'swap'].includes(ev.outcome)) return b;
  const c = ev.color;
  if (ev.outcome === 'swap') {
    [b[ev.kingSq], b[ev.candSq]] = [b[ev.candSq], b[ev.kingSq]];
  } else {
    b[ev.candSq] = c * ev.candType;
    b[ev.kingSq] = c * 6;
  }
  return b;
}
const sameEvent = (a, b) => a && b && a.color === b.color && a.full === b.full && a.candSq === b.candSq;

// ---------------------------------------------------------------------------
// The board
// ---------------------------------------------------------------------------
class BoardView {
  constructor(canvas) {
    this.c = canvas;
    this.x = canvas.getContext('2d');
    this.S = 120;
    this.CAP = 100;
    canvas.width = 960;
    canvas.height = 1060;
    this.flipped = false;
    this.frame = null;
    this.sel = -1;
    this.targets = [];
    this.writSquares = [];
    this.preview = null;
    this.queue = [];
    this.caption = '';
    this.status = '';
    this.names = ['White', 'Black'];
    this.onClick = null;
    canvas.addEventListener('click', (e) => this.click(e));
    const tick = (t) => { this.draw(t); requestAnimationFrame(tick); };
    requestAnimationFrame(tick);
  }
  xy(sq) {
    const f = sq & 7, r = sq >> 3, S = this.S;
    return this.flipped ? [(7 - f) * S, r * S] : [f * S, (7 - r) * S];
  }
  sqAt(px, py) {
    const f = Math.floor(px / this.S), r = Math.floor(py / this.S);
    if (f < 0 || f > 7 || r < 0 || r > 7) return -1;
    return this.flipped ? r * 8 + (7 - f) : (7 - r) * 8 + f;
  }
  click(e) {
    const rect = this.c.getBoundingClientRect();
    const sq = this.sqAt((e.clientX - rect.left) * (960 / rect.width), (e.clientY - rect.top) * (1060 / rect.height));
    if (sq >= 0 && this.onClick) this.onClick(sq);
  }
  get busy() { return this.queue.length > 0; }

  /** Show a new frame. With the previous frame, the move slides and a new Count is played out. */
  setFrame(frame, prev = null, animate = true) {
    const now = performance.now();
    const ev = frame.event;
    const newCount = ev && ev.outcome && !(prev && sameEvent(prev.event, ev));
    const base = newCount ? preCount(frame.board, ev) : frame.board;
    this.queue = [];
    const lm = frame.lastMove || [];
    const moved = prev && lm.length && (prev.fen !== frame.fen);
    if (animate && moved && !(lm[2] & 32)) {
      const piece = prev.board[lm[0]];
      if (piece) this.queue.push({ type: 'move', from: lm[0], to: lm[1], piece, base, dur: 260 });
    }
    if (animate && newCount) {
      this.queue.push({ type: 'count', ev, base, dur: ev.outcome === 'failed' ? 1500 : 3000 });
    }
    let t = now;
    for (const a of this.queue) { a.t0 = t; t += a.dur; }
    this.frame = frame;
  }

  draw(now) {
    const x = this.x, S = this.S, f = this.frame;
    while (this.queue.length && now > this.queue[0].t0 + this.queue[0].dur) this.queue.shift();
    const anim = this.queue[0] && now >= this.queue[0].t0 ? this.queue[0] : null;
    x.clearRect(0, 0, 960, 1060);
    // Squares and coordinates
    for (let sq = 0; sq < 64; sq++) {
      const [px, py] = this.xy(sq);
      x.fillStyle = ((sq & 7) + (sq >> 3)) % 2 ? COL.light : COL.dark;
      x.fillRect(px, py, S, S);
    }
    x.font = `600 18px ${SERIF}`;
    for (let i = 0; i < 8; i++) {
      const fileSq = this.flipped ? 7 - i : i;
      x.fillStyle = i % 2 ? COL.light : COL.dark;
      x.fillStyle = (i % 2 === (this.flipped ? 1 : 0)) ? COL.light : COL.dark;
      x.fillText(FILES[fileSq], i * S + S - 16, 8 * S - 8);
      const rank = this.flipped ? i + 1 : 8 - i;
      x.fillStyle = (i % 2 === 0) ? COL.dark : COL.light;
      x.fillText(String(rank), 6, i * S + 22);
    }
    if (!f) return;
    // Last move, check, selection
    const lm = f.lastMove || [];
    if (lm.length && !(lm[2] & 32)) {
      for (const s of [lm[0], lm[1]]) { const [px, py] = this.xy(s); x.fillStyle = 'rgba(212,166,42,0.38)'; x.fillRect(px, py, S, S); }
    }
    const counting = anim && anim.type === 'count';
    const board = anim ? anim.base : f.board;
    if (f.check && !counting) {
      const k = f.ksq[f.side === 1 ? 0 : 1];
      const [px, py] = this.xy(k);
      const g = x.createRadialGradient(px + S / 2, py + S / 2, 8, px + S / 2, py + S / 2, S * 0.7);
      g.addColorStop(0, 'rgba(210,40,40,0.85)'); g.addColorStop(1, 'rgba(210,40,40,0)');
      x.fillStyle = g; x.fillRect(px, py, S, S);
    }
    if (this.sel >= 0) { const [px, py] = this.xy(this.sel); x.fillStyle = 'rgba(47,122,87,0.5)'; x.fillRect(px, py, S, S); }
    for (const s of this.writSquares) {
      const [px, py] = this.xy(s);
      x.setLineDash([10, 8]); x.lineWidth = 4; x.strokeStyle = COL.gold; x.strokeRect(px + 5, py + 5, S - 10, S - 10); x.setLineDash([]);
    }
    // Pending Candidates: a gold ring and the Writ's flag
    if (!counting) {
      f.pending.forEach((s, i) => {
        if (s < 0) return;
        const [px, py] = this.xy(s);
        const pulse = 0.55 + 0.45 * Math.sin(now / 260);
        x.lineWidth = 6; x.strokeStyle = `rgba(212,166,42,${pulse})`;
        x.beginPath(); x.arc(px + S / 2, py + S / 2, S * 0.46, 0, Math.PI * 2); x.stroke();
        x.fillStyle = i === 0 ? '#fbf6ea' : '#1d1a17'; x.strokeStyle = COL.gold; x.lineWidth = 2;
        x.beginPath(); x.moveTo(px + S - 30, py + 8); x.lineTo(px + S - 30, py + 40); x.stroke();
        x.fillStyle = COL.gold; x.beginPath(); x.moveTo(px + S - 30, py + 8); x.lineTo(px + S - 8, py + 15); x.lineTo(px + S - 30, py + 22); x.fill();
      });
    }
    // Pieces
    const hide = anim && anim.type === 'move' ? anim.to : -1;
    const finalAlpha = counting ? ease((now - anim.t0 - anim.dur * 0.62) / (anim.dur * 0.2)) : 1;
    for (let sq = 0; sq < 64; sq++) {
      if (sq === hide) continue;
      const v = board[sq];
      const fv = f.board[sq];
      if (counting && v !== fv) {
        if (v) this.piece(sq, v, 1 - finalAlpha);
        if (fv) this.piece(sq, fv, finalAlpha, f.badges[sq], f.born[sq]);
        continue;
      }
      if (v) this.piece(sq, v, 1, anim ? 0 : f.badges[sq], f.born[sq]);
    }
    if (anim && anim.type === 'move') {
      const k = ease((now - anim.t0) / anim.dur);
      const [ax, ay] = this.xy(anim.from), [bx, by] = this.xy(anim.to);
      this.pieceAt(ax + (bx - ax) * k, ay + (by - ay) * k, board[anim.to] || anim.piece, 1);
    }
    // Targets
    for (const t of this.targets) {
      const [px, py] = this.xy(t);
      x.fillStyle = 'rgba(30,26,20,0.28)';
      x.beginPath();
      if (f.board[t]) { x.lineWidth = 8; x.strokeStyle = 'rgba(30,26,20,0.3)'; x.arc(px + S / 2, py + S / 2, S * 0.45, 0, Math.PI * 2); x.stroke(); }
      else { x.arc(px + S / 2, py + S / 2, S * 0.14, 0, Math.PI * 2); x.fill(); }
    }
    // Writ preview: who would vote for whom if the count were now
    if (this.preview && !anim) this.voters(this.preview.poll.voters, this.preview.sq, f.ksq[f.side === 1 ? 0 : 1], 1);
    if (counting) this.drawCount(anim, now);
    this.drawCaption(now, anim);
  }

  piece(sq, v, alpha, badges = 0, born = 0) {
    const [px, py] = this.xy(sq);
    this.pieceAt(px, py, v, alpha);
    if (!badges || alpha < 0.5) return;
    const x = this.x, S = this.S;
    x.save(); x.globalAlpha = alpha;
    if (badges & 1) { // crowned: gold seal with the birth rank
      x.fillStyle = COL.gold; x.beginPath(); x.arc(px + S - 22, py + 22, 17, 0, Math.PI * 2); x.fill();
      x.fillStyle = COL.ink; x.font = `700 20px ${SERIF}`; x.textAlign = 'center'; x.textBaseline = 'middle';
      x.fillText(LETTER[born] || '?', px + S - 22, py + 23);
    }
    if (badges & 2) { // Pretender: a small struck-through crown
      x.font = `28px ${PIECE_FONT}`; x.textAlign = 'center'; x.textBaseline = 'middle';
      x.fillStyle = COL.lords; x.fillText('♔' + VS, px + S - 22, py + S - 24);
      x.strokeStyle = COL.lords; x.lineWidth = 3; x.beginPath(); x.moveTo(px + S - 38, py + S - 10); x.lineTo(px + S - 6, py + S - 38); x.stroke();
    }
    if (badges & 4) { x.globalAlpha = 0.5 * alpha; x.fillStyle = '#777'; x.fillRect(px + 10, py + S - 14, S - 20, 5); }
    x.restore();
  }
  pieceAt(px, py, v, alpha) {
    const x = this.x, S = this.S;
    x.save(); x.globalAlpha = alpha;
    x.font = `${S * 0.8}px ${PIECE_FONT}`; x.textAlign = 'center'; x.textBaseline = 'middle';
    const g = GLYPH[Math.abs(v)] + VS, cx = px + S / 2, cy = py + S / 2 + 6;
    if (v > 0) {
      x.lineWidth = 7; x.strokeStyle = '#2a2118'; x.strokeText(g, cx, cy);
      x.fillStyle = '#fdf8ec'; x.fillText(g, cx, cy);
    } else {
      x.lineWidth = 4; x.strokeStyle = 'rgba(243,234,214,0.7)'; x.strokeText(g, cx, cy);
      x.fillStyle = '#1b1814'; x.fillText(g, cx, cy);
    }
    x.restore();
  }
  voters(voters, cand, king, alpha) {
    const x = this.x, S = this.S;
    if (!voters) return;
    x.save(); x.globalAlpha = alpha;
    const [cx, cy] = this.xy(cand), [kx, ky] = this.xy(king);
    for (const [k, v] of Object.entries(voters)) {
      const s = +k, [px, py] = this.xy(s), ox = px + S / 2, oy = py + S / 2;
      if (v === 'X') {
        x.strokeStyle = 'rgba(80,80,80,0.9)'; x.lineWidth = 6;
        x.beginPath(); x.moveTo(px + 22, py + 22); x.lineTo(px + S - 22, py + S - 22); x.moveTo(px + S - 22, py + 22); x.lineTo(px + 22, py + S - 22); x.stroke();
        continue;
      }
      const toC = v === 'C', col = toC ? COL.gold : COL.inc, [tx, ty] = toC ? [cx, cy] : [kx, ky];
      x.strokeStyle = col; x.globalAlpha = alpha * 0.55; x.lineWidth = 4;
      x.beginPath(); x.moveTo(ox, oy); x.lineTo(tx + S / 2, ty + S / 2); x.stroke();
      x.globalAlpha = alpha; x.fillStyle = col; x.strokeStyle = '#fff'; x.lineWidth = 3;
      x.beginPath(); x.arc(px + 20, py + S - 20, 13, 0, Math.PI * 2); x.fill(); x.stroke();
    }
    x.restore();
  }
  drawCount(a, now) {
    const x = this.x, S = this.S, ev = a.ev, t = now - a.t0, d = a.dur;
    const c = ev.candSq, k = ev.kingSq;
    // Districts: king-step rings around the Incumbent (blue) and the Candidate (gold)
    const grow = ease(t / (d * 0.3)) * 7;
    for (const [sq, col] of [[k, COL.inc], [c, COL.gold]]) {
      if (sq < 0) continue;
      const [px, py] = this.xy(sq);
      for (let r = 1; r <= Math.floor(grow); r++) {
        x.strokeStyle = col; x.globalAlpha = 0.35 * (1 - r / 8) * (1 - ease((t - d * 0.6) / (d * 0.2))); x.lineWidth = 3;
        x.strokeRect(px - r * S + 3, py - r * S + 3, (2 * r + 1) * S - 6, (2 * r + 1) * S - 6);
      }
      x.globalAlpha = 1;
    }
    if (ev.voters && t > d * 0.18) this.voters(ev.voters, c, k, ease((t - d * 0.18) / (d * 0.2)) * (1 - ease((t - d * 0.82) / (d * 0.15))));
    // The verdict
    if (t > d * 0.45) {
      const al = ease((t - d * 0.45) / (d * 0.12)) * (1 - ease((t - d * 0.9) / (d * 0.1)));
      x.save(); x.globalAlpha = al;
      x.fillStyle = 'rgba(15,44,36,0.88)'; x.fillRect(80, 360, 800, 240);
      x.strokeStyle = COL.gold; x.lineWidth = 3; x.strokeRect(80, 360, 800, 240);
      x.textAlign = 'center'; x.fillStyle = COL.gold2; x.font = `600 26px ${SERIF}`;
      x.fillText(`${ev.color === 1 ? 'White' : 'Black'} counts the vote for ${c >= 0 ? sqName(c) : '?'}`, 480, 410);
      x.fillStyle = COL.parch; x.font = `700 64px ${SERIF}`;
      x.fillText(OUTCOME[ev.outcome] || ev.outcome, 480, 490);
      x.font = `24px ${SERIF}`;
      const line = ev.commons ? `Commons ${ev.commons[0]}–${ev.commons[1]}   ·   College ${ev.college[0]}–${ev.college[1]}` : (ev.reason || '');
      x.fillText(line, 480, 550);
      x.restore();
    }
  }
  drawCaption(now, anim) {
    const x = this.x, f = this.frame, top = 960;
    x.fillStyle = COL.bench; x.fillRect(0, top, 960, this.CAP);
    x.fillStyle = COL.gold; x.fillRect(0, top, 960, 3);
    x.textBaseline = 'middle'; x.textAlign = 'left';
    x.fillStyle = COL.gold; x.font = `40px ${PIECE_FONT}`; x.fillText('♚' + VS, 22, top + 50);
    x.fillStyle = COL.parch; x.font = `600 22px ${SERIF}`; x.fillText('PARLIAMENT CHESS', 70, top + 36);
    x.font = `italic 18px ${SERIF}`; x.fillStyle = COL.gold2; x.fillText(this.caption || '', 70, top + 66);
    // Writs held, right side
    x.textAlign = 'right'; x.font = `16px ${SERIF}`;
    [[0, 'White'], [1, 'Black']].forEach(([i, name], row) => {
      const y = top + 32 + row * 34;
      x.fillStyle = COL.parch; x.fillText(`${this.names[i] || name}`, 820, y);
      for (let k = 0; k < 3; k++) {
        x.fillStyle = k < (f ? f.writs[i] : 0) ? COL.gold : 'rgba(212,166,42,0.18)';
        x.fillRect(836 + k * 30, y - 11, 20, 22);
      }
    });
    if (this.status) {
      x.textAlign = 'center'; x.font = `600 20px ${SERIF}`; x.fillStyle = COL.gold2;
      x.fillText(this.status, 520, top + 50);
    }
  }
}

// ---------------------------------------------------------------------------
// Recording the canvas to WebM
// ---------------------------------------------------------------------------
function makeRecorder(canvas, name) {
  let mr = null, chunks = [];
  return {
    get on() { return !!mr && mr.state === 'recording'; },
    start() {
      const mime = ['video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm'].find((m) => MediaRecorder.isTypeSupported(m));
      mr = new MediaRecorder(canvas.captureStream(30), { mimeType: mime, videoBitsPerSecond: 6e6 });
      chunks = [];
      mr.ondataavailable = (e) => e.data.size && chunks.push(e.data);
      mr.onstop = () => {
        const a = h('a', { href: URL.createObjectURL(new Blob(chunks, { type: 'video/webm' })), download: `${name}.webm` });
        document.body.append(a); a.click(); a.remove();
        toast('Recording saved as ' + name + '.webm');
      };
      mr.start(500);
    },
    stop() { if (mr && mr.state === 'recording') mr.stop(); },
  };
}

// ---------------------------------------------------------------------------
// Panels shared by play and replay
// ---------------------------------------------------------------------------
function tallyBox(ev, title) {
  if (!ev) return h('div', { class: 'box' }, h('h4', {}, title), h('div', { class: 'forecast' }, 'No count yet. A Writ may be filed from move 10.'));
  const box = h('div', { class: 'box' }, h('h4', {}, title));
  if (ev.commons) {
    const bar = (a, b) => {
      const tot = Math.max(1, a + b);
      return h('div', { class: 'bar' }, h('span', { class: 'c', style: `width:${(a / tot) * 100}%` }), h('span', { class: 'i', style: `width:${(b / tot) * 100}%` }));
    };
    box.append(h('div', { class: 'tally' },
      h('span', { class: 'lab' }, ''), h('span', { class: 'lab' }, 'Candidate ' + (ev.candSq >= 0 ? sqName(ev.candSq) : '')), h('span', { class: 'lab', style: 'text-align:right' }, 'Incumbent ' + sqName(ev.kingSq)),
      h('span', { class: 'lab' }, 'Commons'), h('div', { style: 'grid-column:2/4' }, bar(ev.commons[0], ev.commons[1])),
      h('span', {}, ''), h('span', { class: 'num' }, ev.commons[0]), h('span', { class: 'num', style: 'text-align:right' }, ev.commons[1]),
      h('span', { class: 'lab' }, 'College'), h('div', { style: 'grid-column:2/4' }, bar(ev.college[0], ev.college[1])),
      h('span', {}, ''), h('span', { class: 'num' }, ev.college[0]), h('span', { class: 'num', style: 'text-align:right' }, ev.college[1]),
    ));
  }
  box.append(h('span', { class: 'outcome ' + ev.outcome }, OUTCOME[ev.outcome] || ev.outcome));
  box.append(h('div', { class: 'forecast' }, ev.reason ? `${OUTCOME_LINE[ev.outcome]} (${ev.reason})` : OUTCOME_LINE[ev.outcome]));
  return box;
}
function legend() {
  return h('div', { class: 'legend' },
    h('span', {}, h('i', { style: `background:${COL.gold}` }), 'votes Candidate'),
    h('span', {}, h('i', { style: `background:${COL.inc}` }), 'votes Incumbent'),
    h('span', {}, h('i', { style: 'background:#666' }), 'intimidated (abstains)'));
}
function moveList(log, current, onPick) {
  const box = h('div', { class: 'box' }, h('h4', {}, 'Moves'));
  const list = h('div', { class: 'moves' });
  let row = null;
  for (const e of log) {
    if (e.by === 1 || !row) {
      row = h('div', { class: 'mv' }, h('span', { class: 'n' }, Math.ceil(e.ply / 2) + '.'));
      if (e.by === -1) row.append(h('span', {}, '…'));
      list.append(row);
    }
    row.append(h('span', { class: 'san' + (e.writ ? ' writ' : '') + (e.ply === current ? ' cur' : ''), onclick: () => onPick && onPick(e.ply), title: e.writ ? 'A Writ: the election is counted after the reply' : '' }, e.san));
    if (e.count) { list.append(h('div', { class: 'mv' }, h('span', {}), h('span', { class: 'cnt' }, e.count.text))); row = null; }
  }
  box.append(list);
  requestAnimationFrame(() => { const cur = $('.san.cur', list); if (cur) cur.scrollIntoView({ block: 'nearest' }); else list.scrollTop = list.scrollHeight; });
  return box;
}
function playerRows(frame, players, side, you, bots) {
  const box = h('div', { class: 'box' }, h('h4', {}, 'The Houses'));
  [[1, 0, 'w'], [-1, 1, 'b']].forEach(([c, i, cls]) => {
    const bot = bots && bots[String(c)];
    const term = frame.termReady[i], pend = frame.pending[i];
    const pips = h('div', { class: 'writs', title: 'Writs held (max 3)' }, [0, 1, 2].map((k) => h('span', { class: 'writ-pip' + (k < frame.writs[i] ? ' on' : '') })));
    box.append(h('div', { class: 'player' + (side === c ? ' tomove' : '') },
      h('span', { class: 'chip ' + cls }),
      h('span', { class: 'who' }, (players ? players[String(c)] : (c === 1 ? 'White' : 'Black')), you === c ? h('small', {}, '(you)') : null,
        bot ? h('small', { class: 'bot' }, 'bot \u00b7 ' + LEVEL(bot)) : null),
      pips));
    const bits = [];
    if (pend >= 0) bits.push(`Writ pending for ${sqName(pend)}: counted at the start of their next turn`);
    else if (frame.peace > 0) bits.push(`Coronation Peace: no Writs for ${frame.peace} more move${frame.peace > 1 ? 's' : ''}`);
    else if (term > 0) bits.push(`Term: next Writ in ${term} move${term > 1 ? 's' : ''}`);
    if (bits.length) box.append(h('div', { class: 'meta-line' }, bits.join(' · ')));
  });
  return box;
}
/** The game's record: every move, who made it and when (local time), with downloads. */
function recordBox(st) {
  const box = h('div', { class: 'box record' }, h('h4', {}, st.status === 'playing' ? 'Game record so far' : 'Game record'));
  box.append(h('div', { class: 'forecast' }, `Started ${localDate(st.started)} ${localTime(st.started)} \u00b7 ${st.players['1']} (White) vs ${st.players['-1']} (Black)${st.status !== 'playing' ? ' \u00b7 ' + st.result + ', ' + st.termination : ''}`));
  const rows = st.log.map((e) => h('tr', { class: e.writ ? 'writ' : '' },
    h('td', { class: 'num' }, Math.ceil(e.ply / 2) + (e.by === 1 ? '.' : '\u2026')),
    h('td', {}, e.player || (e.by === 1 ? 'White' : 'Black')),
    h('td', { class: 'mono' }, e.san),
    h('td', { class: 'num', title: e.at }, localTime(e.at))));
  box.append(h('div', { class: 'record-scroll' }, h('table', {},
    h('tr', {}, h('th', {}, '#'), h('th', {}, 'Player'), h('th', {}, 'Move'), h('th', {}, 'Time')), rows)));
  const fetchRecord = () => api.get('record', { id: st.id });
  box.append(h('div', { class: 'controls', style: 'margin-top:8px' },
    h('button', { class: 'btn ghost small', onclick: async () => {
      const r = await fetchRecord();
      const head = ['ply', 'move', 'side', 'player', 'bot', 'notation', 'uci', 'datetime_utc', 'count'];
      const lines = [head.join(',')].concat(r.moves.map((m) => [m.ply, m.move, m.side, m.player, m.bot || '', m.san, m.uci, m.at, m.count || ''].map(csvCell).join(',')));
      download(`parliament-chess-${st.id}.csv`, lines.join('\n') + '\n', 'text/csv');
    } }, 'CSV'),
    h('button', { class: 'btn ghost small', onclick: async () => download(`parliament-chess-${st.id}.json`, JSON.stringify(await fetchRecord(), null, 2), 'application/json') }, 'JSON'),
    h('button', { class: 'btn ghost small', onclick: async () => { const { pcn } = await api.get('pcn', { id: st.id }); download(`parliament-chess-${st.id}.pcn`, pcn, 'text/plain'); } }, 'PCN')));
  requestAnimationFrame(() => { const sc = box.querySelector('.record-scroll'); if (sc) sc.scrollTop = sc.scrollHeight; });
  return box;
}

function ledgerBox(careers) {
  if (!careers || !careers.length) return null;
  const box = h('div', { class: 'box ledger' }, h('h4', {}, 'The Ledger: careers'));
  for (const c of careers.slice(-10)) {
    box.append(h('div', {}, `${c.color === 1 ? 'White' : 'Black'} ${NAME[LETTER.indexOf(c.born)] || c.born} (born ${c.born}): ${c.what}${c.to ? ' to ' + c.to : ''} on ${c.sq} · ply ${c.ply}`));
  }
  return box;
}

// ---------------------------------------------------------------------------
// Lobby
// ---------------------------------------------------------------------------
async function lobby(app) {
  const opts = { elections: true, queenEligible: false };
  const sel = (id, items, val) => h('select', { id }, items.map(([v, t]) => h('option', { value: v, selected: v === val }, t)));
  const levels = [['easy', 'Easy (1 ply)'], ['medium', 'Medium (2 ply)'], ['hard', 'Hard (3 ply)']];
  const styles = [['whig', 'Whig'], ['populist', 'Populist'], ['monarchist', 'Monarchist']];
  const ais = [['medium', 'Medium Whig'], ['medium/populist', 'Medium Populist'], ['medium/monarchist', 'Medium Monarchist'], ['hard', 'Hard Whig'], ['easy/populist', 'Easy Populist']];
  const go = async (body) => {
    try {
      const r = await api.post('new', { ...body, ...opts, name: myName() });
      location.href = r.token ? `/play.html?game=${r.id}&token=${r.token}` : `/play.html?watch=${r.id}`;
    } catch (e) { toast(e.message); }
  };
  app.replaceChildren(
    h('h1', {}, 'Every crown is elected.'),
    h('p', { class: 'lede' }, 'Chess, move for move, with one new office: the King. File a Writ, and your own pieces vote by proximity — pawns in the Commons, Knights, Bishops and Rooks in the College. A dethroned King becomes a pawn.'),
    h('div', { class: 'namebar' },
      h('label', { for: 'myname' }, 'Your name'),
      h('input', { id: 'myname', value: myName(), maxlength: 40, onchange: (e) => { e.target.value = setMyName(e.target.value); toast('You are ' + myName()); } }),
      h('span', { class: 'hint' }, 'Bots are nuts, legumes and squash; people start as pumpkins.')),
    h('div', { class: 'cards' },
      h('div', { class: 'card' }, h('h3', {}, 'Two browsers'), h('p', {}, 'Create a game, then send the second link to your opponent. Anyone with the watch link can follow live.'),
        h('div', { class: 'row' }, h('button', { class: 'btn gold', onclick: () => go({ mode: 'pvp' }) }, 'Create game'))),
      h('div', { class: 'card' }, h('h3', {}, 'Play the machine'), h('p', {}, 'Book-Return Search: a shallow search that steers back to known positions.'),
        h('div', { class: 'row' }, sel('lv', levels, 'medium'), sel('st', styles, 'whig'), sel('col', [['white', 'as White'], ['black', 'as Black']], 'white')),
        h('div', { class: 'row' }, h('button', { class: 'btn gold', onclick: () => go({ mode: 'ai', level: $('#lv').value + '/' + $('#st').value, color: $('#col').value }) }, 'Play'))),
      h('div', { class: 'card' }, h('h3', {}, 'Watch the machines'), h('p', {}, 'Two styles of politics, one board. Record it to WebM from the game page.'),
        h('div', { class: 'row' }, sel('aw', ais, 'medium/populist'), h('span', {}, 'vs'), sel('ab', ais, 'medium/monarchist')),
        h('div', { class: 'row' }, h('button', { class: 'btn gold', onclick: () => go({ mode: 'aivai', white: $('#aw').value, black: $('#ab').value }) }, 'Watch'))),
      h('div', { class: 'card' }, h('h3', {}, 'House rules'), h('p', {}, 'Switches for every game you create here.'),
        h('label', {}, h('input', { type: 'checkbox', checked: true, onchange: (e) => (opts.elections = e.target.checked) }), ' Elections (off = plain FIDE chess)'),
        h('label', {}, h('input', { type: 'checkbox', onchange: (e) => (opts.queenEligible = e.target.checked) }), ' Regency: Queens may stand'),
        h('div', { class: 'row' }, h('a', { href: '/?rules=1' }, 'Read the rules →'))),
    ),
    h('div', { class: 'section' }, h('h2', {}, 'In session'), h('div', { class: 'list', id: 'live' }, h('div', { class: 'empty' }, 'Loading…'))),
  );
  try {
    const { games } = await api.get('games');
    const live = $('#live');
    if (!games.length) { live.replaceChildren(h('div', { class: 'empty' }, 'No games yet. Create one above.')); return; }
    live.replaceChildren(h('table', {}, h('tr', {}, h('th', {}, 'Game'), h('th', {}, 'White'), h('th', {}, 'Black'), h('th', {}, 'Status'), h('th', {}, 'Plies'), h('th', {}, '')),
      games.map((g) => h('tr', {}, h('td', {}, g.id), h('td', {}, g.players['1']), h('td', {}, g.players['-1']),
        h('td', {}, g.status === 'playing' ? 'in play' : g.result), h('td', { class: 'num' }, g.plies),
        h('td', {}, h('a', { href: `/play.html?watch=${g.id}` }, 'Watch'))))));
  } catch (e) { $('#live').replaceChildren(h('div', { class: 'empty' }, e.message)); }
}

// ---------------------------------------------------------------------------
// A live game: play or watch
// ---------------------------------------------------------------------------
async function gameView(app, id, token) {
  const canvas = h('canvas', { class: 'board' });
  const wrap = h('div', { class: 'board-wrap' }, canvas);
  const panel = h('div', { class: 'panel' });
  app.replaceChildren(h('div', { class: 'game' }, wrap, panel));
  const board = new BoardView(canvas);
  const rec = makeRecorder(canvas, 'parliament-chess-' + id);
  let st = null, writMode = false, preview = null, aiBusy = false, flipped = false;

  const legalFrom = (sq) => (st.actions ? st.actions.moves.filter((m) => m.from === sq) : []);
  board.onClick = (sq) => {
    if (!st || !st.actions || board.busy) return;
    if (writMode) {
      const w = st.actions.writs.find((x) => x.sq === sq);
      preview = w || null; board.preview = preview; render(); return;
    }
    if (board.sel >= 0 && board.targets.includes(sq)) {
      const ms = st.actions.moves.filter((m) => m.from === board.sel && m.to === sq);
      board.sel = -1; board.targets = [];
      if (ms.length > 1) return choosePromo(ms);
      return send(ms[0].uci);
    }
    const mine = legalFrom(sq);
    board.sel = mine.length ? sq : -1;
    board.targets = mine.map((m) => m.to);
  };
  function choosePromo(ms) {
    const ov = h('div', { class: 'promo' }, h('div', { class: 'pick' }, ms.map((m) => h('button', { title: NAME[m.promo], onclick: () => { ov.remove(); send(m.uci); } }, GLYPH[m.promo] + VS))));
    ov.addEventListener('click', (e) => { if (e.target === ov) ov.remove(); });
    wrap.append(ov);
  }
  async function send(uci) {
    try { apply(await api.post('move', { id, token, move: uci })); } catch (e) { toast(e.message); }
  }
  function apply(next) {
    const prev = st;
    st = next;
    writMode = false; preview = null; board.preview = null; board.writSquares = [];
    board.sel = -1; board.targets = [];
    board.names = [st.players['1'], st.players['-1']];
    board.setFrame(st.frame, prev ? prev.frame : null, !!prev);
    const last = st.log[st.log.length - 1];
    board.caption = last ? `${Math.ceil(last.ply / 2)}${last.by === 1 ? '.' : '...'} ${last.san}`
      : (st.frame.peace > 0 ? 'The opening: Coronation Peace until move 10' : `Move ${st.frame.full}: the floor is open for Writs`);
    board.status = st.status !== 'playing' ? `${st.result}  ·  ${st.termination}` : (st.frame.check ? 'Check' : (st.frame.pending.some((s) => s >= 0) ? 'A count is pending' : ''));
    render();
    maybeAI();
  }
  function maybeAI() {
    if (!st.aiToMove || aiBusy || st.status !== 'playing') return;
    if (st.mode === 'ai' && !st.you) return; // only the player's own browser drives its opponent
    aiBusy = true;
    setTimeout(async () => {
      try { await api.post('ai', { id, version: st.version }); } catch (e) { /* another watcher moved first */ }
      aiBusy = false;
      poll();
    }, st.mode === 'aivai' ? 1100 : 400);
  }
  async function poll() {
    try {
      const r = await api.get('state', { id, token: token || '', since: st ? st.version : -1 });
      if (!r.unchanged) apply(r);
    } catch (e) { /* keep polling */ }
  }
  function render() {
    const f = st.frame, mover = f.side, yourTurn = st.you === mover && st.status === 'playing' && st.actions;
    let status = '';
    if (st.status !== 'playing') status = h('div', { class: 'status win' }, `${st.result} — ${st.termination}`);
    else if (f.check) status = h('div', { class: 'status alert' }, `${mover === 1 ? 'White' : 'Black'} is in check`);
    else status = h('div', { class: 'status' }, yourTurn ? 'Your move' : `${mover === 1 ? 'White' : 'Black'} to move${st.you ? '' : ' · watching'}`);
    const controls = h('div', { class: 'controls' });
    if (yourTurn && st.actions.canWrit) {
      controls.append(h('button', { class: 'btn ' + (writMode ? 'red' : 'gold'), onclick: () => {
        writMode = !writMode; preview = null; board.preview = null; board.sel = -1; board.targets = [];
        board.writSquares = writMode ? st.actions.writs.map((w) => w.sq) : []; render();
      } }, writMode ? 'Cancel Writ' : 'File a Writ'));
    }
    controls.append(h('button', { class: 'btn ghost', onclick: () => { flipped = !flipped; board.flipped = flipped; } }, 'Flip'));
    controls.append(h('button', { class: 'btn ghost', onclick: () => { rec.on ? rec.stop() : rec.start(); render(); } }, rec.on ? h('span', {}, h('span', { class: 'rec-dot' }), 'Stop & save') : 'Record'));
    controls.append(h('button', { class: 'btn ghost', onclick: async () => {
      const { pcn } = await api.get('pcn', { id });
      const a = h('a', { href: URL.createObjectURL(new Blob([pcn], { type: 'text/plain' })), download: `parliament-chess-${id}.pcn` }); a.click();
    } }, 'PCN'));
    if (st.you && st.status === 'playing') {
      // Two clicks to resign: the first arms the button for three seconds.
      const rb = h('button', { class: 'btn ghost', onclick: async () => {
        if (rb.dataset.armed) { apply(await api.post('resign', { id, token })); return; }
        rb.dataset.armed = '1'; rb.textContent = 'Confirm resign'; rb.classList.add('red');
        setTimeout(() => { delete rb.dataset.armed; rb.textContent = 'Resign'; rb.classList.remove('red'); }, 3000);
      } }, 'Resign');
      controls.append(rb);
    }

    const parts = [status, controls];
    if (writMode) {
      const box = h('div', { class: 'box' }, h('h4', {}, 'File a Writ'));
      if (!preview) box.append(h('div', { class: 'forecast' }, 'Pick a Candidate on the board (gold squares): not your King, not a Queen, never crowned, not under attack. Filing costs your turn; the count comes after your opponent’s reply.'));
      else {
        const p = preview.poll;
        box.append(h('div', {}, `Poll if counted now for ${sqName(preview.sq)}:`));
        box.append(tallyBox({ ...p, candSq: preview.sq, kingSq: f.ksq[mover === 1 ? 0 : 1], color: mover }, 'Forecast'));
        box.append(legend());
        box.append(h('div', { class: 'controls', style: 'margin-top:10px' }, h('button', { class: 'btn gold', onclick: () => send(preview.uci) }, `File the Writ for ${sqName(preview.sq)}`)));
      }
      parts.push(box);
    }
    parts.push(playerRows(f, st.players, mover, st.you, st.bots));
    if (st.you) {
      const inp = h('input', { value: st.players[String(st.you)], maxlength: 40 });
      parts.push(h('div', { class: 'box namebox' }, h('h4', {}, 'Your name in this game'),
        h('div', { class: 'row' }, inp, h('button', { class: 'btn small', onclick: async () => {
          try { const v = setMyName(inp.value); apply(await api.post('name', { id, token, name: v })); toast('You are ' + v); } catch (e) { toast(e.message); }
        } }, 'Rename'))));
    }
    const lastCount = [...st.log].reverse().find((e) => e.count);
    parts.push(tallyBox(lastCount ? lastCount.count : null, 'The last count'));
    parts.push(recordBox(st));
    const led = ledgerBox(st.careers);
    if (led) parts.push(led);
    if (st.links) {
      const lb = h('div', { class: 'box links' }, h('h4', {}, 'Share'));
      for (const [k, v] of Object.entries(st.links)) {
        const url = location.origin + v;
        lb.append(h('label', {}, k === 'opponent' ? 'Your opponent’s link (they play the other colour)' : 'Watch link (spectators)'),
          h('input', { readonly: true, value: url, onclick: (e) => { e.target.select(); navigator.clipboard && navigator.clipboard.writeText(url); toast('Copied'); } }));
      }
      parts.push(lb);
    }
    panel.replaceChildren(...parts.filter(Boolean));
  }
  try { apply(await api.get('state', { id, token: token || '', since: -1 })); } catch (e) { app.replaceChildren(h('p', {}, e.message)); return; }
  if (st.you && !store.get('pc-named-' + id) && st.players[String(st.you)] !== myName()) {
    try { apply(await api.post('name', { id, token, name: myName() })); } catch (e) { /* the name stays as given */ }
  }
  if (st.you) store.set('pc-named-' + id, '1');
  if (st.you === -1) { flipped = true; board.flipped = true; }
  setInterval(poll, 900);
}

// ---------------------------------------------------------------------------
// Replays
// ---------------------------------------------------------------------------
async function replaysList(app) {
  app.replaceChildren(h('h1', {}, 'Replays'), h('p', { class: 'lede' }, 'Every game the project has recorded: the demo reel, the Orrery census, and live games. Click to replay; record any replay to WebM.'), h('div', { class: 'list', id: 'rl' }, h('div', { class: 'empty' }, 'Loading…')));
  const { recordings } = await api.get('recordings');
  const order = { demos: 0, live: 1 };
  recordings.sort((a, b) => (order[a.queue] ?? 2) - (order[b.queue] ?? 2) || b.counts - a.counts || a.path.localeCompare(b.path));
  const shown = recordings.filter((r) => r.queue !== 'census' || r.counts > 0).slice(0, 160);
  $('#rl').replaceChildren(h('table', {}, h('tr', {}, ['Collection', 'Run', 'Result', 'Plies', 'Counts', 'Crown moves', ''].map((t) => h('th', {}, t))),
    shown.map((r) => h('tr', {}, h('td', {}, r.queue), h('td', {}, r.run), h('td', {}, r.result), h('td', { class: 'num' }, r.plies), h('td', { class: 'num' }, r.counts), h('td', { class: 'num' }, r.crownMoves),
      h('td', {}, h('a', { href: '/play.html?replay=' + encodeURIComponent(r.path) }, 'Replay'))))));
  if (recordings.length > shown.length) $('#rl').append(h('div', { class: 'empty' }, `${recordings.length - shown.length} more census games without a count are not listed.`));
}

async function replayView(app, path) {
  const data = await api.get('replay', { path });
  const frames = data.frames, log = data.log, meta = data.meta;
  const canvas = h('canvas', { class: 'board' });
  const panel = h('div', { class: 'panel' });
  const slider = h('input', { type: 'range', min: 0, max: frames.length - 1, value: 0 });
  const plyLab = h('span', { class: 'ply' });
  const playBtn = h('button', { class: 'btn gold small' }, 'Play');
  const speed = h('select', {}, [['0.5', '0.5×'], ['1', '1×'], ['2', '2×'], ['4', '4×']].map(([v, t]) => h('option', { value: v, selected: v === '1' }, t)));
  app.replaceChildren(h('div', { class: 'game' }, h('div', {}, canvas, h('div', { class: 'timeline' },
    h('button', { class: 'btn ghost small', style: 'color:var(--parch)', onclick: () => go(i - 1, false) }, '◀'), playBtn,
    h('button', { class: 'btn ghost small', style: 'color:var(--parch)', onclick: () => go(i + 1, true) }, '▶'), slider, speed, plyLab)), panel));
  const board = new BoardView(canvas);
  board.names = [meta.white || 'White', meta.black || 'Black'];
  const rec = makeRecorder(canvas, 'parliament-chess-replay-' + (meta.id || 'game'));
  let i = 0, timer = null;
  function go(n, animate) {
    n = Math.max(0, Math.min(frames.length - 1, n));
    const prev = frames[i];
    board.setFrame(frames[n], animate && n === i + 1 ? prev : null, animate && n === i + 1);
    i = n; slider.value = n;
    const fr = frames[n];
    board.caption = fr.ply ? `${Math.ceil(fr.ply / 2)}${fr.by === 1 ? '.' : '...'} ${fr.san}` : (meta.title || 'Replay');
    board.status = n === frames.length - 1 ? `${meta.result} · ${meta.termination || ''}` : (fr.check ? 'Check' : '');
    plyLab.textContent = `ply ${fr.ply}/${frames.length - 1}`;
    renderPanel();
  }
  function renderPanel() {
    const fr = frames[i];
    const lastCount = [...log.slice(0, i)].reverse().find((e) => e.count);
    panel.replaceChildren(
      h('div', { class: 'box' }, h('h4', {}, 'Recording'), h('div', { style: 'font:600 17px var(--serif)' }, meta.title || meta.id),
        h('div', { class: 'forecast' }, `${meta.white} vs ${meta.black} · ${meta.result} · ${meta.termination || ''}`)),
      h('div', { class: 'controls' }, h('button', { class: 'btn ghost', style: 'color:var(--parch);border-color:rgba(255,255,255,.3)', onclick: () => { board.flipped = !board.flipped; } }, 'Flip'),
        h('button', { class: 'btn ghost', style: 'color:var(--parch);border-color:rgba(255,255,255,.3)', onclick: () => {
          if (rec.on) { rec.stop(); stop(); } else { rec.start(); if (i >= frames.length - 1) go(0, false); play(); }
          renderPanel();
        } }, rec.on ? h('span', {}, h('span', { class: 'rec-dot' }), 'Stop & save') : 'Record video'),
        h('button', { class: 'btn ghost', style: 'color:var(--parch);border-color:rgba(255,255,255,.3)', onclick: () => {
          const a = h('a', { href: URL.createObjectURL(new Blob([meta.pcn || ''], { type: 'text/plain' })), download: (meta.id || 'game') + '.pcn' }); a.click();
        } }, 'PCN')),
      playerRows(fr, { 1: meta.white, '-1': meta.black }, fr.side, 0, null),
      tallyBox(lastCount ? lastCount.count : null, 'The last count'),
      moveList(log, fr.ply, (ply) => { stop(); go(ply, false); }),
    );
  }
  function play() {
    stop();
    playBtn.textContent = 'Pause';
    const step = () => {
      if (i >= frames.length - 1) { stop(); if (rec.on) setTimeout(() => { rec.stop(); renderPanel(); }, 1500); return; }
      go(i + 1, true);
      const hasCount = frames[i].event && !(frames[i - 1] && sameEvent(frames[i - 1].event, frames[i].event));
      timer = setTimeout(step, (hasCount ? 3400 : 900) / parseFloat(speed.value));
    };
    timer = setTimeout(step, 300);
  }
  function stop() { clearTimeout(timer); timer = null; playBtn.textContent = 'Play'; }
  playBtn.onclick = () => (timer ? stop() : play());
  slider.oninput = () => { stop(); go(+slider.value, false); };
  document.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowRight') { e.preventDefault(); stop(); go(i + 1, true); }
    if (e.key === 'ArrowLeft') { e.preventDefault(); stop(); go(i - 1, false); }
    if (e.key === ' ') { e.preventDefault(); timer ? stop() : play(); }
  });
  go(0, false);
  if (Q.get('autoplay')) play();
}

// ---------------------------------------------------------------------------
// The Orrery: the queue as a clockwork of orbits
// ---------------------------------------------------------------------------
async function orreryView(app) {
  const canvas = h('canvas', { class: 'orrery', width: 1200, height: 560 });
  const tables = h('div');
  app.replaceChildren(h('h1', {}, 'The Orrery'), h('p', { class: 'lede' }, 'Every self-play job is a planet: grey waits in the queue, gold is running on a lane, green is done, red failed. Each ring is one run of the plan; each sun is one queue directory, the same files the Orrery Queue keeper reads.'), canvas, tables);
  const x = canvas.getContext('2d');
  let data = { queues: [] };
  const STATE = { queue: '#6d7a73', running: '#d4a62a', done: '#3fa56f', failed: '#c0394b' };
  async function refresh() {
    try { data = await api.get('orrery'); } catch (e) { return; }
    tables.replaceChildren(...data.queues.map((q) => h('div', { class: 'section' },
      h('h2', {}, `${q.name}: ${q.counts.done} done, ${q.counts.running} running, ${q.counts.queue} queued, ${q.counts.failed} failed`),
      h('p', { style: 'opacity:.8;margin-top:-6px' }, q.about),
      h('div', { class: 'list' }, Object.keys(q.report).length ? h('table', {},
        h('tr', {}, ['Run', 'Games', 'White score', 'Decisive', 'Avg plies', 'Writs/game', 'Counts/game', 'Crown moves/game', "Trailing side's score"].map((t) => h('th', {}, t))),
        Object.entries(q.report).map(([run, c]) => h('tr', {}, h('td', {}, run), h('td', { class: 'num' }, c.games), h('td', { class: 'num' }, c.whiteScore.toFixed(3)),
          h('td', { class: 'num' }, c.decisive.toFixed(3)), h('td', { class: 'num' }, c.avgPlies.toFixed(1)), h('td', { class: 'num' }, c.writsPerGame.toFixed(2)),
          h('td', { class: 'num' }, c.countsPerGame.toFixed(2)), h('td', { class: 'num' }, c.crownMovesPerGame.toFixed(2)),
          h('td', { class: 'num' }, c.trailingScore == null ? '–' : `${c.trailingScore.toFixed(3)} (n=${c.trailingGames})`)))) : h('div', { class: 'empty' }, 'No report yet: the keeper writes one when the queue drains.')))));
  }
  function draw(t) {
    x.clearRect(0, 0, 1200, 560);
    // stars
    for (let i = 0; i < 90; i++) { x.fillStyle = `rgba(243,234,214,${0.15 + ((i * 37) % 10) / 40})`; x.fillRect((i * 397) % 1200, (i * 211) % 560, 2, 2); }
    const qs = data.queues, n = Math.max(1, qs.length);
    qs.forEach((q, qi) => {
      const cx = (1200 / n) * (qi + 0.5), cy = 280, runs = q.runs.length ? q.runs : ['jobs'];
      const maxR = Math.min(1200 / n / 2 - 20, 250);
      x.fillStyle = '#d4a62a'; x.shadowColor = '#f0cf6a'; x.shadowBlur = 30;
      x.beginPath(); x.arc(cx, cy, 26, 0, Math.PI * 2); x.fill(); x.shadowBlur = 0;
      x.fillStyle = '#0f2c24'; x.font = `600 13px ${SERIF}`; x.textAlign = 'center'; x.textBaseline = 'middle'; x.fillText(q.name, cx, cy);
      runs.forEach((run, ri) => {
        const r = 50 + ((maxR - 50) * (ri + 1)) / runs.length;
        x.strokeStyle = 'rgba(240,207,106,0.18)'; x.lineWidth = 1; x.beginPath(); x.arc(cx, cy, r, 0, Math.PI * 2); x.stroke();
        const jobs = q.jobs.filter((j) => j.run === run);
        jobs.forEach((j, k) => {
          const spin = j.state === 'running' ? t / 1400 : j.state === 'queue' ? t / 9000 : 0;
          const a = (k / Math.max(1, jobs.length)) * Math.PI * 2 + spin + ri * 0.7;
          const px = cx + r * Math.cos(a), py = cy + r * Math.sin(a);
          x.fillStyle = STATE[j.state] || '#999';
          if (j.state === 'running') { x.shadowColor = '#f0cf6a'; x.shadowBlur = 16; }
          x.beginPath(); x.arc(px, py, j.state === 'running' ? 7 : 4.5, 0, Math.PI * 2); x.fill(); x.shadowBlur = 0;
        });
        x.fillStyle = 'rgba(243,234,214,0.55)'; x.font = `11px ${SERIF}`; x.textAlign = 'left';
        if (runs.length <= 6) x.fillText(run, cx + 6, cy - r - 6);
      });
    });
    requestAnimationFrame(draw);
  }
  await refresh();
  requestAnimationFrame(draw);
  setInterval(refresh, 2500);
}

// ---------------------------------------------------------------------------
// Rules: RULES.md, rendered by a very small Markdown reader
// ---------------------------------------------------------------------------
function md(src) {
  const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;');
  const inline = (s) => esc(s).replace(/`([^`]+)`/g, '<code>$1</code>').replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>').replace(/\*([^*]+)\*/g, '<i>$1</i>').replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2">$1</a>');
  const lines = src.split('\n'), out = [];
  for (let i = 0; i < lines.length; i++) {
    const l = lines[i];
    let m;
    if ((m = l.match(/^(#{1,4})\s+(.*)/))) out.push(`<h${m[1].length}>${inline(m[2])}</h${m[1].length}>`);
    else if (l.startsWith('|')) {
      const rows = [];
      while (i < lines.length && lines[i].startsWith('|')) rows.push(lines[i++]);
      i--;
      const cells = (r) => r.replace(/^\||\|$/g, '').split('|').map((c) => c.trim());
      out.push('<table><tr>' + cells(rows[0]).map((c) => `<th>${inline(c)}</th>`).join('') + '</tr>' + rows.slice(2).map((r) => '<tr>' + cells(r).map((c) => `<td>${inline(c)}</td>`).join('') + '</tr>').join('') + '</table>');
    } else if (/^\s*([-*]|\d+\.)\s+/.test(l)) {
      const ordered = /^\s*\d+\./.test(l), items = [];
      while (i < lines.length && /^\s*([-*]|\d+\.)\s+/.test(lines[i])) items.push(lines[i++].replace(/^\s*([-*]|\d+\.)\s+/, ''));
      i--;
      out.push(`<${ordered ? 'ol' : 'ul'}>` + items.map((t) => `<li>${inline(t)}</li>`).join('') + `</${ordered ? 'ol' : 'ul'}>`);
    } else if (l.startsWith('```')) {
      const code = [];
      while (++i < lines.length && !lines[i].startsWith('```')) code.push(lines[i]);
      out.push(`<pre><code>${esc(code.join('\n'))}</code></pre>`);
    } else if (l.trim()) out.push(`<p>${inline(l)}</p>`);
  }
  return out.join('\n');
}
async function rulesView(app) {
  const src = await (await fetch('/rules.md')).text();
  app.replaceChildren(h('div', { class: 'doc', html: md(src) }));
}

// ---------------------------------------------------------------------------
(async function main() {
  const app = $('#app');
  try {
    if (Q.get('game')) await gameView(app, Q.get('game'), Q.get('token') || '');
    else if (Q.get('watch')) await gameView(app, Q.get('watch'), '');
    else if (Q.get('replay')) await replayView(app, Q.get('replay'));
    else if (Q.get('replays')) await replaysList(app);
    else if (Q.get('orrery')) await orreryView(app);
    else if (Q.get('rules')) await rulesView(app);
    else await lobby(app);
  } catch (e) {
    app.replaceChildren(h('p', {}, 'Something went wrong: ' + e.message));
    console.error(e);
  }
})();
