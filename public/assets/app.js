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
const LEVEL = (spec) => spec ? spec.replace(/\+net2(policy)?(?::(\S+))?/, (m, pol, n) => ` + ${n === 'champion' ? 'the champion net' : n ? 'net ' + n : 'net'}`).replace('/', ' \u00b7 ') : '';
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
    this.arrows = [];
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
    // The net's arrows: the move it played and the policy's other favourites
    if (!anim) for (const a of this.arrows) { const k = ease((now - a.t0) / 450); if (k > 0) this.arrow(a, k); }
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

  arrow(a, k) {
    const x = this.x, S = this.S;
    const [fx, fy] = this.xy(a.from), [tx, ty] = this.xy(a.to);
    const ax = fx + S / 2, ay = fy + S / 2, bx = tx + S / 2, by = ty + S / 2;
    x.save(); x.globalAlpha = a.alpha * k; x.strokeStyle = x.fillStyle = `rgb(${a.color})`; x.lineCap = 'round';
    if (a.from === a.to) { // a Writ: a ring around the Candidate
      x.lineWidth = a.width / 2; x.beginPath(); x.arc(ax, ay, S * 0.42, 0, Math.PI * 2); x.stroke(); x.restore(); return;
    }
    const ang = Math.atan2(by - ay, bx - ax), head = a.width * 2.3;
    x.lineWidth = a.width; x.beginPath(); x.moveTo(ax, ay);
    x.lineTo(bx - Math.cos(ang) * head * 0.8, by - Math.sin(ang) * head * 0.8); x.stroke();
    x.beginPath(); x.moveTo(bx, by);
    x.lineTo(bx - Math.cos(ang - 0.45) * head, by - Math.sin(ang - 0.45) * head);
    x.lineTo(bx - Math.cos(ang + 0.45) * head, by - Math.sin(ang + 0.45) * head); x.closePath(); x.fill();
    x.restore();
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
  const ais = [['medium+net2:champion', 'The champion net'], ['medium', 'Medium Whig'], ['medium/populist', 'Medium Populist'], ['medium/monarchist', 'Medium Monarchist'], ['hard', 'Hard Whig'], ['easy/populist', 'Easy Populist']];
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
      h('div', { class: 'card net' }, h('h3', {}, 'Play the neural net'), h('p', { id: 'netline' }, 'The champion of the training runs: a search that sees through a trained net. Its mind is drawn under the board on every move.'),
        h('div', { class: 'row' }, sel('ncol', [['white', 'as White'], ['black', 'as Black']], 'white')),
        h('div', { class: 'row' }, h('button', { class: 'btn gold', onclick: () => go({ mode: 'ai', level: 'medium+net2:champion', color: $('#ncol').value }) }, 'Play it'),
          h('button', { class: 'btn ghost', onclick: () => go({ mode: 'aivai', white: 'medium+net2:champion', black: 'medium/populist' }) }, 'Watch it think'),
          h('button', { class: 'btn ghost', title: 'The champion net on both sides (Populist against Whig), each side’s mind in its own panel', onclick: () => { store.set('pc-brain-view', 'both'); go({ mode: 'aivai', white: 'medium+net2:champion', black: 'medium/whig+net2:champion' }); } }, 'Net against net'))),
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
  api.get('nets').then(({ nets, champion }) => {
    const i = nets.findIndex((n) => n.name === champion), c = nets[i];
    const gen = (n) => n.name.slice(1).replace(/^0+/, '');
    // Each champion was elected against the one before it (the first, against plain Medium).
    const rival = i > 0 ? `generation ${gen(nets[i - 1])}, the champion before it` : 'plain Medium';
    if (c) $('#netline').textContent = `Generation ${gen(c)}, the champion: trained by the Orrery fleet, it scored ${Math.round(c.score * 100)}% against ${rival}. Its mind is drawn under the board on every move.`;
  }).catch(() => {});
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
// The net's mind: what a net player saw and computed for its move (Net2::trace)
// ---------------------------------------------------------------------------
const clamp01 = (t) => (t < 0 ? 0 : t > 1 ? 1 : t);
const BRAIN_BOARDS = [
  'Our pawns', 'Our knights', 'Our bishops', 'Our rooks', 'Our queens', 'Our king',
  'Their pawns', 'Their knights', 'Their bishops', 'Their rooks', 'Their queens', 'Their king',
  'Our ex-Kings', 'Their ex-Kings', 'We attack', 'They attack', 'Ours hanging', 'Theirs hanging',
];
// Sensors v2's 51 scalar inputs, grouped as src/AI/Sensors.php emits them ("our" = the side to move).
const BRAIN_SCALARS = [
  { at: 1152, n: 5, col: 0, row: 0, name: 'Our king zone', cells: ['0 attacks', '1', '2', '3–4', '5+'] },
  { at: 1157, n: 5, col: 0, row: 1, name: 'Their king zone', cells: ['0 attacks', '1', '2', '3–4', '5+'] },
  { at: 1163, n: 5, col: 0, row: 2, name: 'Our mobility', cells: ['0–9', '10–19', '20–29', '30–39', '40+'] },
  { at: 1168, n: 5, col: 0, row: 3, name: 'Their mobility', cells: ['0–9', '10–19', '20–29', '30–39', '40+'] },
  { at: 1193, n: 5, col: 0, row: 4, name: 'Our worst hang', cells: ['nothing', 'a pawn', 'a minor', 'a rook', 'a queen'] },
  { at: 1198, n: 5, col: 0, row: 5, name: 'Their worst hang', cells: ['nothing', 'a pawn', 'a minor', 'a rook', 'a queen'] },
  { at: 1173, n: 4, col: 1, row: 0, name: 'Our Writs', cells: ['0', '1', '2', '3'] },
  { at: 1177, n: 4, col: 1, row: 1, name: 'Their Writs', cells: ['0', '1', '2', '3'] },
  { at: 1181, n: 6, col: 1, row: 2, name: 'Election', cells: ['we may file', 'our count pending', 'their count pending', 'Peace is over', 'our Term is up', 'their Term is up'] },
  { at: 1187, n: 4, col: 1, row: 3, name: 'Best poll', cells: ['a coronation', 'contested', 'a swap', 'no Candidate wins'] },
  { at: 1191, n: 2, col: 1, row: 4, name: 'Pending wins', cells: ['theirs would win', 'ours would win'] },
  { at: 1162, n: 1, col: 1, row: 5, name: 'In check', cells: ['we are in check'] },
];

class BrainView {
  constructor(canvas) {
    this.c = canvas;
    this.W = 1240; this.H = 600;
    const dpr = Math.min(2, window.devicePixelRatio || 1);
    canvas.width = this.W * dpr; canvas.height = this.H * dpr;
    this.x = canvas.getContext('2d');
    this.x.scale(dpr, dpr);
    this.b = null; this.t0 = 0; this.hover = null; this.dirty = true; this.DUR = 3600;
    this.placeholder = 'The net’s mind appears here when a net player moves.';
    canvas.addEventListener('mousemove', (e) => this.pointer(e));
    canvas.addEventListener('mouseleave', () => { this.hover = null; this.dirty = true; });
    const tick = (t) => { if (this.dirty || (this.b && t - this.t0 < this.DUR + 200)) this.draw(t); requestAnimationFrame(tick); };
    requestAnimationFrame(tick);
  }
  /** When the current animation ends (the watch pacing waits for it). */
  get doneAt() { return this.b ? this.t0 + this.DUR : 0; }
  set(b) {
    if (!b) { if (this.b) { this.b = null; this.hover = null; this.dirty = true; } return; }
    if (this.b && this.b.ply === b.ply && this.b.side === b.side) return;
    this.b = b;
    this.t0 = performance.now();
    this.mir = b.side === 1 ? 0 : 56;
    this.active = new Set(b.features);
    this.rows = this.pickRows(b);
    const mx = (a) => a.reduce((m, e) => Math.max(m, Math.abs(e[e.length - 1])), 1e-9);
    this.max = { in: mx(b.edges.in), mid: mx(b.edges.mid), out: Math.max(mx(b.edges.value), ...this.rows.map((r) => mx(r.edges || []))) };
    this.dirty = true;
  }
  /** Seven of the policy's favourites and the move actually played (if it is not among them). */
  pickRows(b) {
    const ranked = b.policy.map((r, i) => ({ ...r, rank: i + 1 }));
    const chosen = ranked.find((r) => r.uci === b.chosen);
    let rows = ranked.slice(0, 8);
    if (chosen && chosen.rank > 8) rows = [...ranked.slice(0, 7), chosen];
    return rows;
  }

  // Geometry ---------------------------------------------------------------
  inPos(i) {
    const CELL = 8, BX = 18, PX = 72, BY = [74, 160, 246];
    let board = -1, rs = 0;
    if (i < 768) { board = Math.floor(i / 64); rs = i % 64; }
    else if (i < 1152) { board = 12 + Math.floor((i - 768) / 64); rs = (i - 768) % 64; }
    if (board >= 0) {
      const row = board < 6 ? 0 : board < 12 ? 1 : 2, col = board % 6;
      const x0 = BX + col * PX + (rs & 7) * CELL, y0 = BY[row] + (7 - (rs >> 3)) * CELL;
      return { x: x0 + CELL / 2, y: y0 + CELL / 2, x0, y0, w: CELL - 1, h: CELL - 1, board, rs };
    }
    const g = BRAIN_SCALARS.find((s) => i >= s.at && i < s.at + s.n);
    const k = i - g.at;
    const x0 = 18 + g.col * 222 + 104 + k * 15, y0 = 336 + g.row * 19;
    return { x: x0 + 6, y: y0 + 6, x0, y0, w: 12, h: 12, g, k };
  }
  h1Pos(j) { return { x: 662, y: 70 + j * 3 + 1.5 }; }
  h2Pos(k) { return { x: 880, y: 78 + k * 11.8 }; }
  valPos() { return { x: 990, y: 100 }; }
  rowPos(r) { return { x: 990, y: 192 + r * 46 }; }
  inLabel(i) {
    const p = this.inPos(i);
    if (p.board !== undefined) return `${BRAIN_BOARDS[p.board]} · ${sqName(p.rs ^ this.mir)}`;
    return `${p.g.name}: ${p.g.cells[p.k]}`;
  }

  pointer(e) {
    if (!this.b) return;
    const r = this.c.getBoundingClientRect();
    const px = (e.clientX - r.left) * (this.W / r.width), py = (e.clientY - r.top) * (this.H / r.height);
    let hv = null;
    if (px < 470) {
      for (const i of this.b.features) { const p = this.inPos(i); if (px >= p.x0 - 1 && px <= p.x0 + p.w + 1 && py >= p.y0 - 1 && py <= p.y0 + p.h + 1) { hv = { in: i }; break; } }
    } else if (px > 640 && px < 720 && py >= 68 && py < 70 + 480) hv = { h1: Math.min(159, Math.max(0, Math.floor((py - 70) / 3))) };
    else if (px > 860 && px < 900) { const k = Math.round((py - 78) / 11.8); if (k >= 0 && k < 40) hv = { h2: k }; }
    else if (px > 975) {
      if (py < 150) hv = { value: true };
      else { const r2 = Math.round((py - 192) / 46); if (r2 >= 0 && r2 < this.rows.length) hv = { row: r2 }; }
    }
    if (JSON.stringify(hv) !== JSON.stringify(this.hover)) { this.hover = hv; this.dirty = true; }
  }
  lit(kind, a, b) {
    const h = this.hover;
    if (!h) return true;
    if (kind === 'in') return h.in === a || h.h1 === b;
    if (kind === 'mid') return h.h1 === a || h.h2 === b;
    if (kind === 'val') return h.h2 === a || h.value;
    if (kind === 'row') return h.h2 === a || h.row === b;
    return false;
  }

  // Drawing ----------------------------------------------------------------
  edge(a, b, w, max, prog, dim) {
    if (prog <= 0) return;
    const x = this.x, s = Math.min(1, Math.abs(w) / max);
    x.strokeStyle = w >= 0 ? `rgba(240,207,106,${(0.08 + 0.62 * s) * dim})` : `rgba(122,170,240,${(0.08 + 0.62 * s) * dim})`;
    x.lineWidth = 0.5 + 2.2 * s;
    x.beginPath(); x.moveTo(a.x, a.y);
    const mx = (a.x + b.x) / 2;
    if (prog >= 1) x.bezierCurveTo(mx, a.y, mx, b.y, b.x, b.y);
    else { // grow from the source: de Casteljau split of the same curve at prog
      const t = prog, l = (p, q) => ({ x: p.x + (q.x - p.x) * t, y: p.y + (q.y - p.y) * t });
      const p1 = { x: mx, y: a.y }, p2 = { x: mx, y: b.y };
      const q0 = l(a, p1), q1 = l(p1, p2), q2 = l(p2, b), r0 = l(q0, q1), r1 = l(q1, q2), s0 = l(r0, r1);
      x.bezierCurveTo(q0.x, q0.y, r0.x, r0.y, s0.x, s0.y);
    }
    x.stroke();
  }
  text(s, px, py, font, color, align = 'left') {
    const x = this.x; x.font = font; x.fillStyle = color; x.textAlign = align; x.textBaseline = 'middle'; x.fillText(s, px, py);
  }
  draw(now) {
    this.dirty = false;
    const x = this.x, W = this.W, H = this.H, b = this.b;
    const SANS = '-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif';
    x.clearRect(0, 0, W, H);
    x.fillStyle = '#0a201a'; x.beginPath(); x.roundRect(0, 0, W, H, 12); x.fill();
    if (!b) {
      this.text(this.placeholder, W / 2, H / 2, `italic 18px ${SERIF}`, COL.parch, 'center');
      return;
    }
    const t = now - this.t0, ph = (a, d) => ease((t - a) / d);
    const P = { inp: ph(0, 600), e1: ph(450, 900), h1: ph(1000, 500), e2: ph(1450, 800), h2: ph(2000, 400), e3: ph(2300, 700), out: ph(2650, 600), pick: ph(3200, 400) };
    const dimOf = (on) => (this.hover ? (on ? 1 : 0.07) : 0.85);
    const pre = this.mir ? 'Black' : 'White';

    // Headings
    const moveNo = Math.ceil(b.ply / 2) + (b.side === 1 ? '.' : '…');
    const chosen = b.policy.find((r) => r.uci === b.chosen);
    const reading = b.kind === 'reading';
    this.text(`${reading ? 'The net reads' : 'The net’s mind'} · ${b.net} · ${b.player || pre} (${pre}) · ${moveNo} ${chosen ? chosen.san : b.chosen}`, 18, 22, `600 17px ${SERIF}`, reading ? COL.parch : COL.gold2);
    const on1 = b.a1.filter((v) => v > 0).length, on2 = b.a2.filter((v) => v > 0).length;
    this.text(`${b.features.length} of 1,203 inputs on · ${on1} of ${b.a1.length} units fire in layer 1 · ${on2} of ${b.a2.length} in layer 2 · ${b.traceMs} ms in PHP`, W - 18, 22, `12px ${SANS}`, 'rgba(243,234,214,0.7)', 'right');
    const head = (s, px, py = 50) => this.text(s, px, py, `600 11px ${SANS}`, 'rgba(243,234,214,0.55)');
    head(`INPUTS · WHAT IT SEES, FROM ${pre.toUpperCase()}’S SIDE OF THE BOARD`, 18); head('LAYER 1 · 160', 640); head('LAYER 2 · 40', 852);
    head('VALUE · WHO IS WINNING', 975); head(`MOVES · POLICY OVER ${b.policy.length} LEGAL`, 975, 160);

    // Edges, back to front: in -> h1, h1 -> h2, h2 -> outputs
    for (const [i, j, w] of b.edges.in) this.edge(this.inPos(i), { x: 656, y: this.h1Pos(j).y }, w, this.max.in, P.e1, dimOf(this.lit('in', i, j)));
    for (const [j, k, w] of b.edges.mid) this.edge({ x: 706, y: this.h1Pos(j).y }, { x: 874, y: this.h2Pos(k).y }, w, this.max.mid, P.e2, dimOf(this.lit('mid', j, k)));
    for (const [k, w] of b.edges.value) this.edge({ x: 886, y: this.h2Pos(k).y }, this.valPos(), w, this.max.out, P.e3, dimOf(this.lit('val', k)));
    this.rows.forEach((r, ri) => { for (const [k, w] of r.edges || []) this.edge({ x: 886, y: this.h2Pos(k).y }, this.rowPos(ri), w, this.max.out, P.e3, dimOf(this.lit('row', k, ri))); });

    // A veil over the inputs: the lines leave the matrix faintly and brighten on their way to layer 1
    x.fillStyle = 'rgba(10,32,26,0.62)'; x.fillRect(10, 58, 452, 392);
    const hv = this.hover;
    if (hv && hv.in !== undefined) {
      for (const [i, j, w] of b.edges.in) if (i === hv.in) this.edge(this.inPos(i), { x: 656, y: this.h1Pos(j).y }, w, this.max.in, 1, 1);
    }
    if (hv && hv.h1 !== undefined) for (const [i, w] of b.edges.unitIn[hv.h1] || []) this.edge(this.inPos(i), { x: 656, y: this.h1Pos(hv.h1).y }, w, this.max.in, 1, 1);
    if (hv && hv.h2 !== undefined) for (const [j, w] of b.edges.unitMid[hv.h2] || []) this.edge({ x: 706, y: this.h1Pos(j).y }, { x: 874, y: this.h2Pos(hv.h2).y }, w, this.max.mid, 1, 1);
    // Input boards and scalars
    x.font = `10px ${SANS}`;
    BRAIN_BOARDS.forEach((name, bi) => {
      const bx = 18 + (bi % 6) * 72, by = [74, 160, 246][bi < 6 ? 0 : bi < 12 ? 1 : 2];
      this.text(name, bx, by - 8, `10px ${SANS}`, 'rgba(243,234,214,0.62)');
      for (let s = 0; s < 64; s++) {
        x.fillStyle = ((s & 7) + (s >> 3)) % 2 ? 'rgba(143,159,120,0.16)' : 'rgba(143,159,120,0.08)';
        x.fillRect(bx + (s & 7) * 8, by + (7 - (s >> 3)) * 8, 7, 7);
      }
    });
    for (const g of BRAIN_SCALARS) {
      this.text(g.name, 18 + g.col * 222, 336 + g.row * 19 + 6, `10px ${SANS}`, 'rgba(243,234,214,0.62)');
      for (let k = 0; k < g.n; k++) { x.fillStyle = 'rgba(143,159,120,0.12)'; x.fillRect(18 + g.col * 222 + 104 + k * 15, 336 + g.row * 19, 12, 12); }
    }
    b.features.forEach((i, n) => {
      const p = this.inPos(i);
      const k = clamp01(P.inp * 1.6 - (n / b.features.length) * 0.6);
      if (k <= 0) return;
      const ours = p.board !== undefined ? (p.board < 6 || p.board === 12 || p.board === 14 || p.board === 16) : p.g.name.startsWith('Our') || p.g.name === 'In check';
      const hot = this.hover && this.hover.in === i;
      x.fillStyle = hot ? '#ffffff' : ours ? `rgba(240,207,106,${k})` : `rgba(243,234,214,${0.85 * k})`;
      x.fillRect(p.x0, p.y0, p.w, p.h);
    });

    // Layer 1: one bar per unit, its length the clipped activation
    for (let j = 0; j < b.a1.length; j++) {
      const p = this.h1Pos(j), a = b.a1[j] * P.h1;
      x.fillStyle = 'rgba(243,234,214,0.08)'; x.fillRect(660, p.y - 1, 44, 2);
      if (a > 0) { x.fillStyle = this.hover && this.hover.h1 === j ? '#fff' : `rgba(240,207,106,${0.35 + 0.65 * a})`; x.fillRect(660, p.y - 1.2, 44 * a, 2.4); }
    }
    // Layer 2: one disc per unit
    for (let k = 0; k < b.a2.length; k++) {
      const p = this.h2Pos(k), a = b.a2[k] * P.h2;
      x.beginPath(); x.arc(p.x, p.y, 4.6, 0, Math.PI * 2);
      x.fillStyle = this.hover && this.hover.h2 === k ? '#fff' : `rgba(240,207,106,${0.12 + 0.88 * a})`; x.fill();
      x.strokeStyle = 'rgba(243,234,214,0.35)'; x.lineWidth = 0.8; x.stroke();
    }

    // Value: the net's own judgement, and what the search used
    const vp = this.valPos(), v = b.value * P.out;
    x.beginPath(); x.arc(vp.x, vp.y, 7, 0, Math.PI * 2); x.fillStyle = v >= 0 ? COL.gold2 : '#7aaaf0'; x.globalAlpha = 0.3 + 0.7 * P.out; x.fill(); x.globalAlpha = 1;
    x.fillStyle = 'rgba(243,234,214,0.12)'; x.fillRect(1006, 74, 210, 10);
    x.fillStyle = v >= 0 ? COL.gold2 : '#7aaaf0'; x.fillRect(1111, 74, 105 * v, 10);
    x.fillStyle = COL.parch; x.fillRect(1110, 70, 2, 18);
    this.text(Math.abs(v) < 0.005 ? '0.00' : `${v > 0 ? '+' : '−'}${Math.abs(v).toFixed(2)}`, 1006, 100, `700 16px ${SANS}`, COL.parch);
    if (b.mode === 'residual') {
      const corr = Math.round(400 * Math.max(-2, Math.min(2, b.raw)));
      this.text('for the side to move', 1058, 100, `italic 11px ${SERIF}`, 'rgba(243,234,214,0.55)');
      this.text(`hand ${sgn(b.hand)} + net ${sgn(corr)} = ${sgn(b.total)} cp`, 1006, 121, `12px ${SANS}`, 'rgba(243,234,214,0.85)');
      this.text('the net corrects the hand-written evaluation', 1006, 139, `italic 10.5px ${SERIF}`, 'rgba(243,234,214,0.5)');
    } else this.text(`= ${sgn(b.total)} cp for the side to move`, 1006, 121, `12px ${SANS}`, 'rgba(243,234,214,0.85)');

    // Moves: the policy's favourites, the search's verdict, the one played
    const maxP = Math.max(...this.rows.map((r) => r.p), 1e-9);
    this.rows.forEach((r, ri) => {
      const p = this.rowPos(ri), played = r.uci === b.chosen, k = P.out;
      if (played && P.pick > 0) {
        x.strokeStyle = `rgba(240,207,106,${P.pick})`; x.lineWidth = 2;
        x.beginPath(); x.roundRect(976, p.y - 19, 252, 38, 7); x.stroke();
        x.fillStyle = `rgba(240,207,106,${0.12 * P.pick})`; x.fill();
      }
      x.beginPath(); x.arc(p.x, p.y, 6, 0, Math.PI * 2);
      x.fillStyle = this.hover && this.hover.row === ri ? '#fff' : played ? COL.gold2 : 'rgba(243,234,214,0.75)'; x.globalAlpha = 0.25 + 0.75 * k; x.fill(); x.globalAlpha = 1;
      this.text(r.san, 1004, p.y - 6, `700 14px ${SANS}`, played ? COL.gold2 : COL.parch);
      this.text(`#${r.rank}`, 1004, p.y + 10, `10px ${SANS}`, 'rgba(243,234,214,0.5)');
      x.fillStyle = 'rgba(243,234,214,0.1)'; x.fillRect(1066, p.y - 11, 100, 8);
      x.fillStyle = played ? COL.gold2 : 'rgba(243,234,214,0.7)'; x.fillRect(1066, p.y - 11, 100 * (r.p / maxP) * k, 8);
      this.text(`${(100 * r.p).toFixed(1)}%`, 1172, p.y - 7, `11px ${SANS}`, 'rgba(243,234,214,0.8)');
      if (r.search !== null && r.search !== undefined) this.text(`search ${sgn(r.search)}`, 1066, p.y + 9, `11px ${SANS}`, 'rgba(243,234,214,0.6)');
      if (played && P.pick > 0) this.text('played', 1224, p.y + 9, `700 11px ${SANS}`, `rgba(240,207,106,${P.pick})`, 'right');
    });

    // The arithmetic, under the inputs
    const fy = 480, f = (s, i) => this.text(s, 18, fy + i * 19, `12px ${'ui-monospace,"SF Mono",Menlo,monospace'}`, 'rgba(243,234,214,0.78)');
    this.text('THE ARITHMETIC', 18, fy - 22, `600 11px ${SANS}`, 'rgba(243,234,214,0.55)');
    f('layer 1   = clip( b1 + Σ rows of the lit inputs )', 0);
    f('layer 2   = clip( b2 + W2 · layer 1 )', 1);
    f('value     = tanh( bv + wv · layer 2 )', 2);
    f('move      = softmax( bias + layer 2 · (from + to) )', 3);
    if (!reading) f('the search: 2 plies, scoring leaves with hand + 400·value', 4);
    else f(b.source === 'person' ? 'a person chose this move: the net only reads the position' : 'this bot has no net: its own search chose; the net reads along', 4);

    // Legend and the hover line
    x.fillStyle = COL.gold2; x.fillRect(470, 566, 18, 3); this.text('excites', 494, 568, `11px ${SANS}`, 'rgba(243,234,214,0.7)');
    x.fillStyle = '#7aaaf0'; x.fillRect(546, 566, 18, 3); this.text('inhibits', 570, 568, `11px ${SANS}`, 'rgba(243,234,214,0.7)');
    this.text('Lines: strongest weight × activation (moves: against the average legal move). Hover anything.', 630, 568, `11px ${SANS}`, 'rgba(243,234,214,0.55)');
    const info = this.hoverText();
    if (info) {
      x.fillStyle = '#0a201a'; x.fillRect(460, 552, W - 470, 34);
      this.text(info, 470, 569, `12px ${SANS}`, COL.gold2);
    }
    if (b.source === 'book') this.text('Played from the opening book; the net’s reading as it stood.', 975, H - 50, `italic 11px ${SERIF}`, 'rgba(243,234,214,0.6)');
  }
  hoverText() {
    const h = this.hover, b = this.b;
    if (!h) return '';
    const top = (list, n) => list.sort((p, q) => Math.abs(q[1]) - Math.abs(p[1])).slice(0, n).map(([s, w]) => `${s} ${sgnF(w)}`).join(', ');
    if (h.in !== undefined) {
      const out = b.edges.in.filter((e) => e[0] === h.in).map((e) => [`unit ${e[1]}`, e[2]]);
      return `${this.inLabel(h.in)} (input ${h.in})${out.length ? ' → ' + top(out, 4) : ''}`;
    }
    if (h.h1 !== undefined) {
      const inn = (b.edges.unitIn[h.h1] || []).map(([i, w]) => [this.inLabel(i), w]);
      return `Layer 1 unit ${h.h1}: sum ${b.pre1[h.h1].toFixed(2)} → ${b.a1[h.h1].toFixed(2)}${inn.length ? ' · from ' + top(inn, 3) : ''}`;
    }
    if (h.h2 !== undefined) {
      const inn = (b.edges.unitMid[h.h2] || []).map(([j, w]) => [`unit ${j}`, w]);
      const val = b.edges.value.find((e) => e[0] === h.h2);
      return `Layer 2 unit ${h.h2}: sum ${b.pre2[h.h2].toFixed(2)} → ${b.a2[h.h2].toFixed(2)}${inn.length ? ' · from ' + top(inn, 3) : ''}${val ? ' · to the value ' + sgnF(val[1]) : ''}`;
    }
    if (h.value) return `Value = tanh(${b.raw.toFixed(3)}) = ${b.value.toFixed(3)} for the side to move${b.mode === 'residual' ? `; the search adds ${sgn(Math.round(400 * Math.max(-2, Math.min(2, b.raw))))} cp to the hand evaluation` : ''}`;
    if (h.row !== undefined) {
      const r = this.rows[h.row];
      return `${r.san}: logit ${r.logit.toFixed(2)} → ${(100 * r.p).toFixed(1)}% (rank ${r.rank} of ${b.policy.length})${r.search != null ? ` · search ${sgn(r.search)} cp` : ''}${r.uci === b.chosen ? ' · played' : ''}`;
    }
    return '';
  }
  /** Board arrows: the move played (gold) and the policy's other favourites (blue, by probability). */
  arrows(at) {
    const b = this.b;
    if (!b) return [];
    const out = [];
    b.policy.slice(0, 3).forEach((r) => { if (r.uci !== b.chosen) out.push({ from: r.from, to: r.to, color: '122,170,240', width: 12, alpha: 0.35 + 0.5 * r.p / b.policy[0].p, t0: at }); });
    const c = b.policy.find((r) => r.uci === b.chosen);
    if (c) out.push({ from: c.from, to: c.to, color: '240,207,106', width: 18, alpha: 0.9, t0: at + 500 });
    return out;
  }
}
const sgn = (n) => (n > 0 ? '+' : n < 0 ? '−' : '') + Math.abs(n);
const sgnF = (w) => (w >= 0 ? '+' : '−') + Math.abs(w).toFixed(2);

// ---------------------------------------------------------------------------
// MIND: the end state of the net's computation as a matrix, in the Orrery montage's dress (orrery
// src/montage.rs): black glass, phosphor; green +, amber −, black 0; every unit a square framed in its
// own colour, and a wire wears its source unit's colour, dotted when it inhibits. The inputs on top,
// the transform 1,203 > 160 > 40 > value and moves below, and the game's decisions on the right.
// ---------------------------------------------------------------------------
const MX = { scr: [7, 8, 7], phos: [51, 242, 77], dim: [29, 138, 44], amber: [255, 191, 77], red: [242, 51, 38], sky: [115, 199, 255], white: [240, 248, 240] };
const rgbS = (c) => `rgb(${c[0]},${c[1]},${c[2]})`;
const mixC = (a, b, k) => [0, 1, 2].map((i) => Math.round(a[i] + (b[i] - a[i]) * k));
/** green +, amber −, black 0 (montage.rs shade). */
const shadeM = (v) => { const a = Math.max(-1, Math.min(1, v || 0)); return mixC(MX.scr, a >= 0 ? MX.phos : MX.amber, Math.pow(Math.abs(a), 0.6)); };
/** A unit's own colour: the golden angle round the wheel, so neighbours never share a hue (montage.rs node_hue). */
const nodeHue = (i) => {
  const h = ((i * 0.381966011250105) % 1) * 6, s = 0.62, v = 1, f = h % 1;
  const p = v * (1 - s), q = v * (1 - s * f), t = v * (1 - s * (1 - f));
  const [r, g, b] = [[v, t, p], [q, v, p], [p, v, t], [p, q, v], [t, p, v], [v, p, q]][Math.floor(h) % 6];
  return [r, g, b].map((x) => Math.round(x * 255));
};
const MONO = 'ui-monospace,"SF Mono",Menlo,Consolas,monospace';
const BOARD_SHORT = ['our P', 'our N', 'our B', 'our R', 'our Q', 'our K', 'their P', 'their N', 'their B', 'their R', 'their Q', 'their K',
  'our ex-K', 'their ex-K', 'we attack', 'they att.', 'ours hang', 'their hang'];

class MindView {
  constructor(canvas) {
    this.c = canvas; this.W = 1240; this.H = 470;
    const dpr = Math.min(2, window.devicePixelRatio || 1);
    canvas.width = this.W * dpr; canvas.height = this.H * dpr;
    this.x = canvas.getContext('2d'); this.x.scale(dpr, dpr);
    this.b = null; this.st = null; this.t0 = 0; this.hover = null; this.dirty = true; this.DUR = 1100;
    this.placeholder = 'MIND appears here when a net player moves.';
    canvas.addEventListener('mousemove', (e) => this.pointer(e));
    canvas.addEventListener('mouseleave', () => { this.hover = null; this.dirty = true; });
    const tick = (t) => { if (this.dirty || (this.b && t - this.t0 < this.DUR + 100)) this.draw(t); requestAnimationFrame(tick); };
    requestAnimationFrame(tick);
  }
  get doneAt() { return this.b ? this.t0 + this.DUR : 0; }
  set(b) {
    if (!b) { if (this.b) { this.b = null; this.hover = null; this.dirty = true; } return; }
    if (this.b && this.b.ply === b.ply && this.b.side === b.side) return;
    this.b = b; this.t0 = performance.now(); this.mir = b.side === 1 ? 0 : 56;
    this.ranked = b.policy.map((r, i) => ({ ...r, rank: i + 1 }));
    const ch = this.ranked.find((r) => r.uci === b.chosen);
    this.rows = this.ranked.slice(0, 8);
    if (ch && ch.rank > 8) this.rows = [...this.ranked.slice(0, 7), ch];
    this.dirty = true;
  }
  setGame(st) { this.st = st; this.dirty = true; }
  arrows(at) { return BrainView.prototype.arrows.call(this, at); }

  // Geometry ---------------------------------------------------------------
  inCell(i) {
    let board = -1, rs = 0;
    if (i < 768) { board = Math.floor(i / 64); rs = i % 64; } else if (i < 1152) { board = 12 + Math.floor((i - 768) / 64); rs = (i - 768) % 64; }
    if (board >= 0) {
      const bx = 14 + (board % 9) * 58, by = 60 + Math.floor(board / 9) * 66;
      const x0 = bx + (rs & 7) * 6, y0 = by + (7 - (rs >> 3)) * 6;
      return { x0, y0, w: 5, h: 5, cx: x0 + 2.5, cy: y0 + 2.5, board, rs };
    }
    const gi = BRAIN_SCALARS.findIndex((g) => i >= g.at && i < g.at + g.n), g = BRAIN_SCALARS[gi], k = i - g.at;
    const x0 = 548 + (gi < 6 ? 0 : 1) * 196 + 100 + k * 12, y0 = 60 + (gi % 6) * 16;
    return { x0, y0, w: 10, h: 10, cx: x0 + 5, cy: y0 + 5, g, k };
  }
  h1Cell(j) { const x0 = 14 + (j % 20) * 12, y0 = 216 + Math.floor(j / 20) * 12; return { x0, y0, cx: x0 + 5, cy: y0 + 5 }; }
  h2Cell(k) { const x0 = 300 + (k % 8) * 14, y0 = 226 + Math.floor(k / 8) * 14; return { x0, y0, cx: x0 + 6, cy: y0 + 6 }; }
  outCell(r) { return r < 0 ? { x0: 470, y0: 214, s: 20, cx: 480, cy: 224 } : { x0: 472, y0: 246 + r * 24, s: 16, cx: 480, cy: 254 + r * 24 }; }
  decX0() { return 800; }
  inLabel(i) {
    const p = this.inCell(i);
    return p.board !== undefined ? `${BRAIN_BOARDS[p.board]} · ${sqName(p.rs ^ this.mir)}` : `${p.g.name}: ${p.g.cells[p.k]}`;
  }

  pointer(e) {
    const r = this.c.getBoundingClientRect();
    const px = (e.clientX - r.left) * (this.W / r.width), py = (e.clientY - r.top) * (this.H / r.height);
    let hv = null;
    const b = this.b;
    if (b && py < 190) {
      for (const i of b.features) { const p = this.inCell(i); if (px >= p.x0 - 1 && px <= p.x0 + p.w + 1 && py >= p.y0 - 1 && py <= p.y0 + p.h + 1) { hv = { in: i }; break; } }
    } else if (b && py >= 214 && py < 312 && px >= 14 && px < 254) {
      const j = Math.floor((py - 216) / 12) * 20 + Math.floor((px - 14) / 12); if (j >= 0 && j < b.a1.length) hv = { h1: j };
    } else if (b && py >= 224 && py < 296 && px >= 298 && px < 414) {
      const k = Math.floor((py - 226) / 14) * 8 + Math.floor((px - 300) / 14); if (k >= 0 && k < b.a2.length) hv = { h2: k };
    } else if (b && px >= 466 && px < 640 && py >= 210) {
      if (py < 238) hv = { value: true };
      else { const r2 = Math.floor((py - 242) / 24); if (r2 >= 0 && r2 < this.rows.length) hv = { row: r2 }; }
    } else if (this.st && px >= this.decX0() && py >= 214 && py < 300) {
      const col = Math.floor((px - this.decX0()) / 6), ev = this.cols ? this.cols[col] : null;
      if (ev) hv = { dec: ev };
    }
    if (JSON.stringify(hv) !== JSON.stringify(this.hover)) { this.hover = hv; this.dirty = true; }
  }

  // Drawing ----------------------------------------------------------------
  wire(a, b, col, k, dots) {
    const x = this.x;
    x.strokeStyle = rgbS(mixC(MX.scr, col, k)); x.lineWidth = 1;
    x.setLineDash(dots ? [2, 2] : []);
    x.beginPath(); x.moveTo(a.cx, a.cy); x.lineTo(b.cx, b.cy); x.stroke();
    x.setLineDash([]);
  }
  t(s, px, py, c = MX.dim, size = 11, align = 'left', weight = '') {
    const x = this.x; x.font = `${weight} ${size}px ${MONO}`; x.fillStyle = rgbS(c); x.textAlign = align; x.textBaseline = 'middle'; x.fillText(s, px, py);
  }
  draw(now) {
    this.dirty = false;
    const x = this.x, W = this.W, H = this.H, b = this.b;
    x.clearRect(0, 0, W, H);
    x.fillStyle = rgbS(MX.dim); x.fillRect(0, 0, W, H);
    x.fillStyle = rgbS(MX.scr); x.fillRect(1, 1, W - 2, H - 2);
    x.fillStyle = 'rgb(11,14,11)'; for (let y = 2; y < H - 1; y += 3) x.fillRect(1, y, W - 2, 1);
    if (!b) { this.t(this.placeholder, W / 2, H / 2, MX.phos, 15, 'center'); this.decisions(); return; }
    const T = (now - this.t0) / this.DUR, ph = (a, d) => clamp01((T - a) / d);
    const P = { inp: ph(0, 0.3), h1: ph(0.22, 0.3), h2: ph(0.45, 0.25), out: ph(0.65, 0.3), w: ph(0.3, 0.6) };
    const side = b.side === 1 ? 'White' : 'Black', moveNo = Math.ceil(b.ply / 2) + (b.side === 1 ? '.' : '…');
    const played = this.ranked.find((r) => r.uci === b.chosen);
    const how = b.kind === 'own' ? 'its own net' : b.source === 'person' ? 'a person, read by the net' : 'a bot without a net, read by the net';
    this.t(`MIND  ply ${b.ply}  ${side}  ${b.player || ''}  ${how}  ${moveNo} ${played ? played.san : b.chosen}${b.source === 'book' ? '  (book)' : ''}`, 14, 18, MX.phos, 13, 'left', '600');
    // The generation, the fourth corner.
    const gen = String(b.net || '').replace(/^g0*/, '');
    this.t('GENERATION', W - 236, 44, MX.dim, 12);
    this.t(gen || '?', W - 236, 66, MX.phos, 22, 'left', '600');
    this.t(`${b.net} · the champion`, W - 236, 90, MX.amber, 12);
    this.t(`value ${sgnF(b.value)}  ≈ ${Math.round(50 * (1 + b.value))}% for ${side}`, W - 236, 112, MX.phos, 12);
    const corr = b.mode === 'residual' ? Math.round(400 * Math.max(-2, Math.min(2, b.raw))) : 0;
    this.t(`hand ${sgn(b.hand)} + net ${sgn(corr)} = ${sgn(b.total)} cp`, W - 236, 130, MX.dim, 12);
    if (played) this.t(`played #${played.rank} of ${b.policy.length} · ${(100 * played.p).toFixed(1)}%`, W - 236, 148, MX.dim, 12);

    const hv = this.hover;
    // The transform: wires first, then the units over them.
    const focus = (kind, a, c) => !hv || (kind === 'in' ? hv.in === a || hv.h1 === c : kind === 'mid' ? hv.h1 === a || hv.h2 === c
      : kind === 'val' ? hv.h2 === a || hv.value : hv.h2 === a || hv.row === c);
    const top = (list) => list.reduce((m, e) => Math.max(m, Math.abs(e[e.length - 1])), 1e-9);
    const wk = (v, mx, on) => (Math.pow(Math.abs(v) / mx, 0.8) * 0.78 + 0.22) * P.w * (on ? 1 : 0.12);
    const ins = b.edges.in.slice(0, 48), mi = top(ins);
    for (let n = ins.length - 1; n >= 0; n--) { const [i, j, w] = ins[n]; this.wire(this.inCell(i), this.h1Cell(j), nodeHue(i), wk(w, mi, focus('in', i, j)) * (hv && hv.in !== undefined ? 1 : 0.45), w < 0); }
    const mids = b.edges.mid.slice(0, 150), mm = top(mids);
    for (let n = mids.length - 1; n >= 0; n--) { const [j, k, w] = mids[n]; this.wire(this.h1Cell(j), this.h2Cell(k), nodeHue(j), wk(w, mm, focus('mid', j, k)), w < 0); }
    const outs = [...b.edges.value.map(([k, w]) => [k, -1, w]), ...this.rows.flatMap((r, ri) => (r.edges || []).map(([k, w]) => [k, ri, w]))], mo = top(outs);
    for (const [k, r, w] of outs) this.wire(this.h2Cell(k), this.outCell(r), nodeHue(160 + k), wk(w, mo, r < 0 ? focus('val', k) : focus('row', k, r)), w < 0);
    // The inputs: eighteen boards from the side to move, and the summaries.
    this.t(`inputs  ${b.features.length} of 1,203 on  (from ${side}'s side)`, 14, 40);
    for (let bi = 0; bi < 18; bi++) {
      const bx = 14 + (bi % 9) * 58, by = 60 + Math.floor(bi / 9) * 66;
      this.t(BOARD_SHORT[bi], bx, by - 7, MX.dim, 9);
      x.fillStyle = 'rgb(14,24,15)';
      for (let q = 0; q < 64; q++) x.fillRect(bx + (q & 7) * 6, by + (7 - (q >> 3)) * 6, 5, 5);
    }
    BRAIN_SCALARS.forEach((g, gi) => {
      const x0 = 548 + (gi < 6 ? 0 : 1) * 196, y0 = 60 + (gi % 6) * 16;
      this.t(g.name.toLowerCase(), x0, y0 + 5, MX.dim, 9);
      x.fillStyle = 'rgb(14,24,15)';
      for (let k = 0; k < g.n; k++) x.fillRect(x0 + 100 + k * 12, y0, 10, 10);
    });
    b.features.forEach((i, n) => {
      const p = this.inCell(i), k = clamp01(P.inp * 1.5 - (n / b.features.length) * 0.5);
      if (k <= 0) return;
      x.fillStyle = hv && hv.in === i ? '#fff' : rgbS(mixC(MX.scr, MX.phos, k));
      x.fillRect(p.x0, p.y0, p.w, p.h);
    });

    this.t(`transform  1203 > ${b.a1.length} > ${b.a2.length} > value + ${b.policy.length} moves`, 14, 196);
    for (let j = 0; j < b.a1.length; j++) {
      const p = this.h1Cell(j), on = j < b.a1.length * P.h1 * 1.2;
      x.fillStyle = rgbS(hv && hv.h1 === j ? MX.white : nodeHue(j)); x.fillRect(p.x0, p.y0, 10, 10);
      x.fillStyle = rgbS(on ? shadeM(b.pre1[j]) : MX.scr); x.fillRect(p.x0 + 1, p.y0 + 1, 8, 8);
    }
    for (let k = 0; k < b.a2.length; k++) {
      const p = this.h2Cell(k), on = k < b.a2.length * P.h2 * 1.2;
      x.fillStyle = rgbS(hv && hv.h2 === k ? MX.white : nodeHue(160 + k)); x.fillRect(p.x0, p.y0, 12, 12);
      x.fillStyle = rgbS(on ? shadeM(b.pre2[k]) : MX.scr); x.fillRect(p.x0 + 1, p.y0 + 1, 10, 10);
    }
    // The outputs: the value, and the policy's favourites with the move played framed.
    const v = this.outCell(-1);
    x.fillStyle = rgbS(shadeM(b.value * P.out)); x.fillRect(v.x0, v.y0, v.s, v.s);
    this.t(`value ${sgnF(b.value * P.out)}`, 500, 224, MX.phos, 12);
    const maxP = Math.max(...this.rows.map((r) => r.p), 1e-9);
    this.rows.forEach((r, ri) => {
      const c = this.outCell(ri), isPlayed = r.uci === b.chosen;
      if (isPlayed && P.out >= 1) { x.fillStyle = rgbS(MX.white); x.fillRect(c.x0 - 3, c.y0 - 3, c.s + 6, c.s + 6); x.fillStyle = rgbS(MX.scr); x.fillRect(c.x0 - 1, c.y0 - 1, c.s + 2, c.s + 2); }
      // The policy proposes, the search disposes: a square is green or amber by the search's score for
      // the move (a person's move has none: then by the net's own probability).
      const sv = r.search != null ? r.search / 150 : r.p / maxP;
      x.fillStyle = rgbS(hv && hv.row === ri ? MX.white : shadeM(sv * P.out)); x.fillRect(c.x0, c.y0, c.s, c.s);
      this.t(`${r.san}`, 500, c.cy, isPlayed ? MX.white : MX.phos, 12, 'left', isPlayed ? '600' : '');
      this.t(`#${r.rank} ${(100 * r.p).toFixed(1)}%${r.search != null ? `  ${sgn(r.search)}` : ''}${isPlayed ? '  played' : ''}`, 560, c.cy, isPlayed ? MX.white : MX.dim, 11);
    });

    this.decisions();
    // The hover line and the legend.
    const info = this.hoverText();
    if (info) this.t(info, 14, H - 36, MX.amber, 12);
    this.t('green +, amber −, black 0 (a move: its search score); a wire wears its source unit’s colour, dotted when it inhibits; the white frame is the move played', 14, H - 14, MX.dim, 11);
  }
  /** The game's decisions, one column a move, newest at the right (the montage's "the last 8 s"). */
  decisions() {
    const x = this.x, st = this.st, b = this.b, x0 = this.decX0();
    const evals = (st && st.evals) || [], log = (st && st.log) || [];
    const n = Math.floor((this.W - 14 - x0) / 6);
    const shown = evals.slice(-n);
    this.cols = shown;
    this.t(`decisions, the last ${shown.length || n} moves`, 724, 196);
    const rows = ['value', 'net rank', "net's say", 'who', 'events'];
    rows.forEach((w, r) => this.t(w, 724, 222 + r * 16));
    shown.forEach((e, k) => {
      const cx = x0 + k * 6, m = log[e[0] - 1] || {};
      const rank = e[4], p = e[5], corr = e[6], who = e[7];
      const cells = [shadeM(e[2]),
        rank ? shadeM(rank === 1 ? 1 : Math.max(0.08, 1 - (rank - 1) / 12)) : MX.scr,
        corr !== undefined ? shadeM(corr / 200) : MX.scr,
        who === 'net' ? MX.phos : who === 'person' ? MX.white : who === 'book' ? MX.amber : who === 'bot' ? MX.sky : MX.scr,
        m.count ? MX.white : m.writ ? MX.amber : /x/.test(m.san || '') ? MX.red : /[+#]/.test(m.san || '') ? MX.sky : null];
      cells.forEach((c, r) => { if (c) { x.fillStyle = rgbS(this.hover && this.hover.dec === e ? MX.white : c); x.fillRect(cx, 216 + r * 16, 5, 12); } });
      if (b && e[0] === b.ply) { x.fillStyle = rgbS(MX.white); x.fillRect(cx, 208, 5, 3); }
    });
    x.fillStyle = rgbS(MX.phos); x.fillRect(x0 + shown.length * 6, 212, 1, 5 * 16);
    const L = 724, key = (c, px, word) => { x.fillStyle = rgbS(c); x.fillRect(px, 358, 8, 8); this.t(word, px + 13, 362, MX.dim, 10); };
    this.t('value: the net’s, White +      net rank: bright = its favourite', L, 312, MX.dim, 10);
    this.t('net’s say: its correction in cp, White +', L, 328, MX.dim, 10);
    this.t('who chose: net, person, bot (no net), book      events: count, Writ, capture, check', L, 344, MX.dim, 10);
    key(MX.phos, L, 'net'); key(MX.white, L + 46, 'person / count'); key(MX.sky, L + 160, 'bot / check'); key(MX.amber, L + 256, 'book / Writ'); key(MX.red, L + 352, 'capture');
  }
  hoverText() {
    const h = this.hover, b = this.b;
    if (!h) return '';
    if (h.dec) {
      const e = h.dec, m = (this.st.log || [])[e[0] - 1] || {};
      return `${Math.ceil(e[0] / 2)}${e[1] === 1 ? '.' : '…'} ${m.san || ''} by ${e[1] === 1 ? 'White' : 'Black'} (${e[7] || (e[3] ? 'net' : 'read')}): value ${sgnF(e[2])} for White${e[4] ? ` · the net's #${e[4]}, ${(100 * e[5]).toFixed(1)}%` : ''}${e[6] !== undefined ? ` · net ${sgn(e[6])} cp for White` : ''}`;
    }
    if (!b) return '';
    if (h.in !== undefined) return `${this.inLabel(h.in)} (input ${h.in}) is on`;
    if (h.h1 !== undefined) {
      const inn = (b.edges.unitIn[h.h1] || []).map(([i, w]) => `${this.inLabel(i)} ${sgnF(w)}`).slice(0, 3).join(', ');
      return `layer 1 unit ${h.h1}: sum ${b.pre1[h.h1].toFixed(2)} → ${b.a1[h.h1].toFixed(2)}${inn ? ' · from ' + inn : ''}`;
    }
    if (h.h2 !== undefined) {
      const val = b.edges.value.find((e) => e[0] === h.h2);
      return `layer 2 unit ${h.h2}: sum ${b.pre2[h.h2].toFixed(2)} → ${b.a2[h.h2].toFixed(2)}${val ? ' · to the value ' + sgnF(val[1]) : ''}`;
    }
    if (h.value) return `value = tanh(${b.raw.toFixed(3)}) = ${b.value.toFixed(3)} for the side to move`;
    if (h.row !== undefined) { const r = this.rows[h.row]; return `${r.san}: logit ${r.logit.toFixed(2)} → ${(100 * r.p).toFixed(1)}%, rank ${r.rank} of ${b.policy.length}${r.uci === b.chosen ? ' · played' : ''}`; }
    return '';
  }
}

/**
 * MIND in the sidebar: the last decision's end state, small enough to sit beside the board (360 px):
 * the inputs, the two layers as framed squares (green +, amber −, black 0), the wires in their source
 * unit's colour, the move outputs as squares with their probabilities, the move played framed in white,
 * and the game's decisions, newest at the right. The same numbers as the big panel's Matrix view.
 */
class MindSide {
  constructor(canvas) {
    this.c = canvas; this.W = 360; this.H = 486;
    const dpr = Math.min(2, window.devicePixelRatio || 1);
    canvas.width = this.W * dpr; canvas.height = this.H * dpr;
    this.x = canvas.getContext('2d'); this.x.scale(dpr, dpr);
    this.b = null; this.st = null; this.t0 = 0; this.dirty = true; this.DUR = 700;
    const tick = (t) => { if (this.dirty || (this.b && t - this.t0 < this.DUR + 100)) this.draw(t); requestAnimationFrame(tick); };
    requestAnimationFrame(tick);
  }
  set(b) {
    if (!b) { if (this.b) { this.b = null; this.dirty = true; } return; }
    if (this.b && this.b.ply === b.ply && this.b.side === b.side) return;
    this.b = b; this.t0 = performance.now();
    const ranked = b.policy.map((r, i) => ({ ...r, rank: i + 1 })), ch = ranked.find((r) => r.uci === b.chosen);
    this.rows = ranked.slice(0, 8);
    if (ch && ch.rank > 8) this.rows = [...ranked.slice(0, 7), ch];
    this.played = ch;
    this.dirty = true;
  }
  setGame(st) { this.st = st; this.dirty = true; }
  inCell(i) {
    let board = -1, rs = 0;
    if (i < 768) { board = Math.floor(i / 64); rs = i % 64; } else if (i < 1152) { board = 12 + Math.floor((i - 768) / 64); rs = (i - 768) % 64; }
    if (board >= 0) {
      const x0 = 10 + (board % 6) * 38 + (rs & 7) * 4, y0 = 52 + Math.floor(board / 6) * 38 + (7 - (rs >> 3)) * 4;
      return { x0, y0, w: 3, cx: x0 + 1.5, cy: y0 + 1.5 };
    }
    const gi = BRAIN_SCALARS.findIndex((g) => i >= g.at && i < g.at + g.n), k = i - BRAIN_SCALARS[gi].at;
    const x0 = 246 + (gi < 6 ? 0 : 1) * 54 + k * 7, y0 = 52 + (gi % 6) * 18;
    return { x0, y0, w: 6, cx: x0 + 3, cy: y0 + 3 };
  }
  h1Cell(j) { const x0 = 10 + (j % 16) * 9, y0 = 190 + Math.floor(j / 16) * 9; return { x0, y0, cx: x0 + 4, cy: y0 + 4 }; }
  h2Cell(k) { const x0 = 178 + (k % 5) * 11, y0 = 190 + Math.floor(k / 5) * 11; return { x0, y0, cx: x0 + 5, cy: y0 + 5 }; }
  outCell(r) { return r < 0 ? { x0: 256, y0: 188, s: 14, cx: 263, cy: 195 } : { x0: 257, y0: 214 + r * 19, s: 12, cx: 263, cy: 220 + r * 19 }; }
  t(s, px, py, c = MX.dim, size = 10, align = 'left', weight = '') {
    const x = this.x; x.font = `${weight} ${size}px ${MONO}`; x.fillStyle = rgbS(c); x.textAlign = align; x.textBaseline = 'middle'; x.fillText(s, px, py);
  }
  wire(a, b, col, k, dots) {
    const x = this.x; x.strokeStyle = rgbS(mixC(MX.scr, col, k)); x.lineWidth = 1; x.setLineDash(dots ? [2, 2] : []);
    x.beginPath(); x.moveTo(a.cx, a.cy); x.lineTo(b.cx, b.cy); x.stroke(); x.setLineDash([]);
  }
  draw(now) {
    this.dirty = false;
    const x = this.x, W = this.W, H = this.H, b = this.b;
    x.clearRect(0, 0, W, H);
    x.fillStyle = rgbS(MX.dim); x.fillRect(0, 0, W, H);
    x.fillStyle = rgbS(MX.scr); x.fillRect(1, 1, W - 2, H - 2);
    x.fillStyle = 'rgb(11,14,11)'; for (let y = 2; y < H - 1; y += 3) x.fillRect(1, y, W - 2, 1);
    if (!b) { this.t('MIND: the last decision appears here', W / 2, 120, MX.phos, 12, 'center'); this.decisions(); return; }
    const T = (now - this.t0) / this.DUR, ph = (a, d) => clamp01((T - a) / d);
    const P = { inp: ph(0, 0.35), h1: ph(0.2, 0.35), h2: ph(0.45, 0.25), out: ph(0.6, 0.35), w: ph(0.3, 0.6) };
    const side = b.side === 1 ? 'White' : 'Black', moveNo = Math.ceil(b.ply / 2) + (b.side === 1 ? '.' : '…');
    const how = b.kind === 'own' ? 'its own net' : b.source === 'person' ? 'read: a person' : 'read: a bot, no net';
    this.t(`MIND  ply ${b.ply}  ${side}`, 10, 14, MX.phos, 12, 'left', '600');
    this.t(`GEN ${String(b.net || '').replace(/^g0*/, '')}`, W - 10, 14, MX.amber, 12, 'right', '600');
    this.t(`${moveNo} ${this.played ? this.played.san : b.chosen}  ${how}${b.source === 'book' ? '  (book)' : ''}`, 10, 31, MX.phos, 10);
    // Wires under everything.
    const top = (list) => list.reduce((m, e) => Math.max(m, Math.abs(e[e.length - 1])), 1e-9);
    const wk = (v, mx) => (Math.pow(Math.abs(v) / mx, 0.8) * 0.75 + 0.2) * P.w;
    const ins = b.edges.in.slice(0, 30), mi = top(ins);
    for (let n = ins.length - 1; n >= 0; n--) { const [i, j, w] = ins[n]; this.wire(this.inCell(i), this.h1Cell(j), nodeHue(i), wk(w, mi) * 0.4, w < 0); }
    const mids = b.edges.mid.slice(0, 100), mm = top(mids);
    for (let n = mids.length - 1; n >= 0; n--) { const [j, k, w] = mids[n]; this.wire(this.h1Cell(j), this.h2Cell(k), nodeHue(j), wk(w, mm), w < 0); }
    const outs = [...b.edges.value.map(([k, w]) => [k, -1, w]), ...this.rows.flatMap((r, ri) => (r.edges || []).slice(0, 4).map(([k, w]) => [k, ri, w]))], mo = top(outs);
    for (const [k, r, w] of outs) this.wire(this.h2Cell(k), this.outCell(r), nodeHue(160 + k), wk(w, mo), w < 0);
    // Inputs.
    this.t(`inputs  ${b.features.length} of 1,203`, 10, 44);
    x.fillStyle = 'rgb(14,24,15)';
    for (let bi = 0; bi < 18; bi++) for (let q = 0; q < 64; q++) x.fillRect(10 + (bi % 6) * 38 + (q & 7) * 4, 52 + Math.floor(bi / 6) * 38 + (7 - (q >> 3)) * 4, 3, 3);
    BRAIN_SCALARS.forEach((g, gi) => { for (let k = 0; k < g.n; k++) x.fillRect(246 + (gi < 6 ? 0 : 1) * 54 + k * 7, 52 + (gi % 6) * 18, 6, 6); });
    b.features.forEach((i, n) => {
      const p = this.inCell(i), k = clamp01(P.inp * 1.5 - (n / b.features.length) * 0.5);
      if (k > 0) { x.fillStyle = rgbS(mixC(MX.scr, MX.phos, k)); x.fillRect(p.x0, p.y0, p.w, p.w); }
    });
    // The transform.
    this.t(`transform  1203 > ${b.a1.length} > ${b.a2.length} > moves`, 10, 177);
    for (let j = 0; j < b.a1.length; j++) {
      const p = this.h1Cell(j), on = j < b.a1.length * P.h1 * 1.2;
      x.fillStyle = rgbS(nodeHue(j)); x.fillRect(p.x0, p.y0, 8, 8);
      x.fillStyle = rgbS(on ? shadeM(b.pre1[j]) : MX.scr); x.fillRect(p.x0 + 1, p.y0 + 1, 6, 6);
    }
    for (let k = 0; k < b.a2.length; k++) {
      const p = this.h2Cell(k), on = k < b.a2.length * P.h2 * 1.2;
      x.fillStyle = rgbS(nodeHue(160 + k)); x.fillRect(p.x0, p.y0, 10, 10);
      x.fillStyle = rgbS(on ? shadeM(b.pre2[k]) : MX.scr); x.fillRect(p.x0 + 1, p.y0 + 1, 8, 8);
    }
    // The outputs: the value, and the moves' activations (the search's score colours a square).
    const v = this.outCell(-1);
    x.fillStyle = rgbS(shadeM(b.value * P.out)); x.fillRect(v.x0, v.y0, v.s, v.s);
    this.t(`value ${sgnF(b.value * P.out)}`, 276, 195, MX.phos, 10);
    const maxP = Math.max(...this.rows.map((r) => r.p), 1e-9);
    this.rows.forEach((r, ri) => {
      const c = this.outCell(ri), isPlayed = r.uci === b.chosen;
      if (isPlayed && P.out >= 1) { x.fillStyle = rgbS(MX.white); x.fillRect(c.x0 - 2, c.y0 - 2, c.s + 4, c.s + 4); x.fillStyle = rgbS(MX.scr); x.fillRect(c.x0 - 1, c.y0 - 1, c.s + 2, c.s + 2); }
      const sv = r.search != null ? r.search / 150 : r.p / maxP;
      x.fillStyle = rgbS(shadeM(sv * P.out)); x.fillRect(c.x0, c.y0, c.s, c.s);
      this.t(r.san, 276, c.cy, isPlayed ? MX.white : MX.phos, 10, 'left', isPlayed ? '600' : '');
      this.t(`${(100 * r.p).toFixed(1)}%`, W - 10, c.cy, isPlayed ? MX.white : MX.dim, 10, 'right');
    });
    this.decisions();
    this.t('green +, amber −, black 0; white frame: the move played', 10, H - 12, MX.dim, 9);
  }
  decisions() {
    const x = this.x, st = this.st, b = this.b;
    const evals = (st && st.evals) || [], log = (st && st.log) || [];
    const x0 = 74, n = Math.floor((this.W - 10 - x0) / 4), shown = evals.slice(-n);
    this.t(`decisions, the last ${shown.length || n} moves`, 10, 378);
    ['value', 'net rank', "net's say", 'who', 'events'].forEach((w, r) => this.t(w, 10, 396 + r * 14, MX.dim, 9));
    shown.forEach((e, k) => {
      const cx = x0 + k * 4, m = log[e[0] - 1] || {}, rank = e[4], who = e[7];
      const cells = [shadeM(e[2]), rank ? shadeM(rank === 1 ? 1 : Math.max(0.08, 1 - (rank - 1) / 12)) : MX.scr,
        e[6] !== undefined ? shadeM(e[6] / 200) : MX.scr,
        who === 'net' ? MX.phos : who === 'person' ? MX.white : who === 'book' ? MX.amber : who === 'bot' ? MX.sky : MX.scr,
        m.count ? MX.white : m.writ ? MX.amber : /x/.test(m.san || '') ? MX.red : /[+#]/.test(m.san || '') ? MX.sky : null];
      cells.forEach((c, r) => { if (c) { x.fillStyle = rgbS(c); x.fillRect(cx, 391 + r * 14, 3, 10); } });
      if (b && e[0] === b.ply) { x.fillStyle = rgbS(MX.white); x.fillRect(cx, 386, 3, 2); }
    });
    x.fillStyle = rgbS(MX.phos); x.fillRect(x0 + shown.length * 4, 389, 1, 5 * 14);
  }
}

/**
 * The net's evaluation after every move of the game, from White's side of the board: above the line
 * White stands better. Filled dots are a net player's own calculation, rings the net reading the other
 * side's position. Hover a dot for its move.
 */
class EvalStrip {
  constructor(canvas) {
    this.c = canvas; this.W = 1240; this.H = 130;
    const dpr = Math.min(2, window.devicePixelRatio || 1);
    canvas.width = this.W * dpr; canvas.height = this.H * dpr;
    this.x = canvas.getContext('2d'); this.x.scale(dpr, dpr);
    this.evals = []; this.log = []; this.hot = -1;
    canvas.addEventListener('mousemove', (e) => {
      const r = this.c.getBoundingClientRect(), px = (e.clientX - r.left) * (this.W / r.width);
      let best = -1, d = 1e9;
      this.evals.forEach((ev, i) => { const dd = Math.abs(this.px(ev[0]) - px); if (dd < d) { d = dd; best = i; } });
      const hot = d < 14 ? best : -1;
      if (hot !== this.hot) { this.hot = hot; this.draw(); }
    });
    canvas.addEventListener('mouseleave', () => { this.hot = -1; this.draw(); });
  }
  set(evals, log) { this.evals = evals || []; this.log = log || []; this.draw(); }
  px(ply) { return 60 + (ply - 1) * ((this.W - 90) / Math.max(39, this.span - 1)); }
  py(v) { return 18 + (1 - v) / 2 * (this.H - 50); }
  draw() {
    const x = this.x, W = this.W, H = this.H, SANS = '-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif';
    this.span = Math.max(40, ...this.evals.map((e) => e[0]));
    x.clearRect(0, 0, W, H);
    x.fillStyle = '#0a201a'; x.beginPath(); x.roundRect(0, 0, W, H, 12); x.fill();
    const label = (s, px, py, c = 'rgba(243,234,214,0.55)', a = 'left', f = `11px ${SANS}`) => { x.font = f; x.fillStyle = c; x.textAlign = a; x.textBaseline = 'middle'; x.fillText(s, px, py); };
    x.fillStyle = 'rgba(243,234,214,0.05)'; x.fillRect(60, this.py(1), W - 90, this.py(0) - this.py(1));
    x.strokeStyle = 'rgba(243,234,214,0.35)'; x.lineWidth = 1; x.beginPath(); x.moveTo(60, this.py(0)); x.lineTo(W - 30, this.py(0)); x.stroke();
    label('White', 52, this.py(0.8), 'rgba(243,234,214,0.6)', 'right'); label('Black', 52, this.py(-0.8), 'rgba(243,234,214,0.6)', 'right');
    label('THE NET’S EVALUATION, MOVE BY MOVE, FROM WHITE’S SIDE', 60, H - 14);
    label('● the net’s own move   ○ the net reading the other side', W - 30, H - 14, 'rgba(243,234,214,0.55)', 'right');
    if (!this.evals.length) { label('Nothing yet: the first move draws the first point.', W / 2, H / 2 - 8, 'rgba(243,234,214,0.6)', 'center', `italic 14px ${SERIF}`); return; }
    x.strokeStyle = 'rgba(240,207,106,0.55)'; x.lineWidth = 1.6; x.beginPath();
    this.evals.forEach((e, i) => { const p = [this.px(e[0]), this.py(e[2])]; i ? x.lineTo(...p) : x.moveTo(...p); }); x.stroke();
    this.evals.forEach((e, i) => {
      const cx = this.px(e[0]), cy = this.py(e[2]), hot = i === this.hot;
      x.beginPath(); x.arc(cx, cy, hot ? 5.5 : 3.6, 0, Math.PI * 2);
      if (e[3]) { x.fillStyle = hot ? '#fff' : COL.gold2; x.fill(); }
      else { x.fillStyle = '#0a201a'; x.fill(); x.strokeStyle = hot ? '#fff' : 'rgba(243,234,214,0.85)'; x.lineWidth = 1.4; x.stroke(); }
    });
    if (this.hot >= 0) {
      const e = this.evals[this.hot], m = this.log[e[0] - 1];
      const s = `${Math.ceil(e[0] / 2)}${e[1] === 1 ? '.' : '…'} ${m ? m.san : ''} · ${e[1] === 1 ? 'White' : 'Black'} · ${e[3] ? 'its own net' : 'read by the net'} · ${sgnF(e[2])} for White`;
      label(s, Math.min(W - 40, Math.max(70, this.px(e[0]))), 10, COL.gold2, this.px(e[0]) > W - 300 ? 'right' : 'left', `600 12px ${SANS}`);
    }
  }
}

/**
 * One side's calculation in numbers, beside the other's: the move it played and how the net ranked
 * it, what the net thought of its position (value, and hand + net = total), and its running tally
 * over the game. Clicking a card pins the panel below to that side.
 */
function sideCard(st, side, pinned, onPick) {
  const b = st.brains && st.brains[side], t = st.tally && st.tally[side];
  const color = side === '1' ? 'White' : 'Black', name = st.players[side] || color;
  const bot = st.bots && st.bots[side];
  const who = bot && bot.includes('+net2') ? 'a net player · its own calculation' : bot ? 'a bot without a net · read by the net' : 'a person · read by the net';
  const rows = [];
  if (b) {
    const ranked = b.policy.map((r) => r.uci), rank = ranked.indexOf(b.chosen) + 1, row = b.policy[rank - 1] || {};
    const top = b.policy[0];
    const moveNo = Math.ceil(b.ply / 2) + (b.side === 1 ? '.' : '…');
    rows.push(h('div', { class: 'sc-move' }, h('b', {}, `${moveNo} ${row.san || b.chosen}`),
      ` · the net’s #${rank} of ${b.policy.length} (${(100 * (row.p || 0)).toFixed(1)}%)`,
      rank > 1 && top ? h('span', { class: 'sc-dim' }, ` · its favourite ${top.san}`) : h('span', { class: 'sc-dim' }, ' · its favourite')));
    const pct = Math.round(50 * (1 + b.value));
    rows.push(h('div', { class: 'sc-val' }, h('span', { class: 'sc-bar' }, h('i', { style: `width:${pct}%` })),
      `value ${sgnF(b.value)} for ${color}: about ${pct}% expected`));
    const corr = b.mode === 'residual' ? Math.round(400 * Math.max(-2, Math.min(2, b.raw))) : null;
    rows.push(h('div', { class: 'sc-dim' }, corr !== null ? `hand ${sgn(b.hand)} + net ${sgn(corr)} = ${sgn(b.total)} cp` : `${sgn(b.total)} cp`,
      b.source === 'book' ? ' · played from the opening book' : b.source === 'person' ? ' · chosen by a person'
        : b.score != null ? ` · ${b.kind === 'own' ? 'its search' : 'its own search (no net)'} ${sgn(b.score)} cp` : ''));
  } else rows.push(h('div', { class: 'sc-dim' }, `${color} has not moved yet.`));
  if (t && t.moves) {
    rows.push(h('div', { class: 'sc-tally' },
      `${t.top1} of ${t.moves} moves were the net’s favourite · ${t.top3} in its top 3 · average rank ${(t.rankSum / t.moves).toFixed(1)} · average ${(100 * t.pSum / t.moves).toFixed(1)}% · net correction ${Math.round(t.corrSum / t.moves)} cp on average`));
  }
  return h('button', { class: 'side-card' + (pinned ? ' on' : '') + (side === '1' ? ' white' : ' black'), onclick: () => onPick(side), title: pinned ? 'Showing this side below: click to follow the game again' : `Show ${color}’s calculation below` },
    h('div', { class: 'sc-head' }, h('span', { class: 'sc-dot' }), h('b', {}, `${color} · ${name}`), h('span', { class: 'sc-who' }, who)), ...rows);
}

/** How to read the panel, and how a net plays this game: under the canvas, folded. */
function brainHelp() {
  const p = (t) => h('p', {}, t);
  return h('details', { class: 'brain-help' }, h('summary', {}, 'How a neural net plays Parliament Chess, and how to read this'),
    h('div', { class: 'cols' },
      h('div', {},
        h('h4', {}, '1 · It sees 1,203 switches'),
        p('The position becomes 1,203 inputs, each 0 or 1, always from the side to move (Black’s view is flipped, so “our” pawns march up). Eighteen 8×8 boards: where each of our and their pieces stands, our and their Pretenders, every square either side attacks, and every piece left hanging. Then 51 summaries: pressure on each King, mobility, the worst piece hanging, Writs in hand, whether a Writ may be filed, how the best Candidate would poll, whether a pending count would win, and check. A typical position lights about 90 of them (33 to 125 over eight measured games).'),
        h('h4', {}, '2 · Two layers think'),
        p('Each lit input adds its row of 160 learned weights into layer 1; a unit whose sum is above zero fires (clipped at 1). Layer 2 mixes the 160 into 40. The lines are the strongest contributions, weight times activation: gold pushes a unit up, blue pushes it down. Hover anything to see its numbers.')),
      h('div', {},
        h('h4', {}, '3 · Two heads answer'),
        p('The value head says who is winning, from −1 to +1. This net is residual: it does not replace the hand-written evaluation, it corrects it, by 400 centipawns per unit. The policy head gives every legal move a score (its from-square plus its to-square, or its Writ) and a softmax turns them into the percentages you see.'),
        h('h4', {}, '4 · A search decides'),
        p('The net does not move on instinct. A two-ply search orders its moves by the policy and scores every leaf with the hand evaluation plus the net’s correction; the move with the best search score is played (the gold row and the gold arrow). Blue arrows are the policy’s other favourites. When the two disagree, the search has seen a tactic the instinct missed.'),
        h('h4', {}, '5 · Playing against it'),
        p('Pick “Play the neural net” in the lobby. It is Medium strength with a trained eye: it looks two moves ahead, so a quiet three-move plan or a well-timed Writ is your best weapon. Watch what lights up before it moves: when “Their worst hang” fires, it has seen your loose piece.'))));
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
  let st = null, writMode = false, preview = null, aiBusy = false, flipped = false, brain = null, brainSec = null;
  // Which calculation the panel shows: 'follow' (the last move's), '1' or '-1' (one side's), 'both'.
  const VIEWS = ['follow', '1', '-1', 'both'], askedView = { white: '1', black: '-1' }[Q.get('view')] || Q.get('view');
  let view = VIEWS.includes(askedView) ? askedView : VIEWS.includes(store.get('pc-brain-view')) ? store.get('pc-brain-view') : 'follow';
  let bc = null, brain2 = null, bc2 = null, sidesEl = null, strip = null, viewBtns = null;
  let sideMind = null, sideCanvas = null; // MIND in the sidebar: the last decision, beside the board
  let aiSince = 0, lastChange = 0; // the watchdog's clocks: a bot's move is asked for again if nothing happens
  const hasNet = (s) => Object.values(s.bots || {}).some((b) => b && b.includes('+net2'));
  // Two styles for the same numbers: 'matrix', the end state as Orrery's MIND screen draws a net
  // (MindView), or 'network', the animated network with its curves (BrainView). Remembered; ?panel=.
  const STYLES = ['matrix', 'network'];
  let style = STYLES.includes(Q.get('panel')) ? Q.get('panel') : STYLES.includes(store.get('pc-brain-style')) ? store.get('pc-brain-style') : 'matrix';
  let mind = null, mind2 = null, mc = null, mc2 = null, sc = null, styleBtns = null, hidden = false;
  /** The views on screen now (one, or two in Both), whichever style. */
  const onScreen = () => (hidden || !brain ? [] : (style === 'matrix' ? [mind, mind2] : [brain, brain2]).slice(0, view === 'both' ? 2 : 1));
  const mindsDoneAt = () => Math.max(0, ...onScreen().map((v) => v.doneAt));
  const mindsHover = () => onScreen().some((v) => v.hover) || (strip && strip.hot >= 0 && style === 'network');
  function showBrain() {
    bc = h('canvas', { class: 'brain' });
    brain = window.pcBrain = new BrainView(bc);
    bc2 = h('canvas', { class: 'brain second' });
    brain2 = new BrainView(bc2);
    mc = h('canvas', { class: 'mind' });
    mind = window.pcMind = new MindView(mc);
    mc2 = h('canvas', { class: 'mind second' });
    mind2 = new MindView(mc2);
    const hide = h('button', { class: 'btn ghost small', style: 'color:var(--parch);border-color:rgba(243,234,214,0.3)', onclick: () => {
      hidden = !hidden; hide.textContent = hidden ? 'Show' : 'Hide'; if (st) showMinds(false);
    } }, 'Hide');
    // Side by side: the board and the mind in one view on a wide screen (remembered; ?theatre=1 forces it).
    const setTheatre = (on) => { document.body.classList.toggle('theatre', on); side.textContent = on ? 'Stacked' : 'Side by side'; store.set('pc-theatre', on ? '1' : ''); };
    const side = h('button', { class: 'btn ghost small', style: 'color:var(--parch);border-color:rgba(243,234,214,0.3)', onclick: () => setTheatre(!document.body.classList.contains('theatre')) }, 'Side by side');
    sc = h('canvas', { class: 'evalstrip' });
    strip = new EvalStrip(sc);
    sidesEl = h('div', { class: 'brain-sides' });
    const views = [['follow', 'Follow the game'], ['1', 'White'], ['-1', 'Black'], ['both', 'Both']];
    viewBtns = h('div', { class: 'seg', role: 'group', 'aria-label': 'Whose calculation' }, views.map(([v, label]) =>
      h('button', { 'data-v': v, onclick: () => setView(v) }, label)));
    styleBtns = h('div', { class: 'seg', role: 'group', 'aria-label': 'How it is drawn' }, [['matrix', 'Matrix'], ['network', 'Network']].map(([v, label]) =>
      h('button', { 'data-v': v, title: v === 'matrix' ? 'The end state of the computation, every unit a square (Orrery’s MIND screen)' : 'The network animated, inputs to outputs, with its curves',
        onclick: () => { style = v; store.set('pc-brain-style', v); if (st) showMinds(false); } }, label)));
    brainSec = h('section', { class: 'brain-sec' },
      h('div', { class: 'brain-head' }, h('h2', {}, 'The net’s mind'),
        h('span', { class: 'hint' }, 'Each side’s calculation: a net player’s own, or the net reading the other side.'), viewBtns, styleBtns, side, hide),
      sidesEl, sc, mc, mc2, bc, bc2, h('div', { class: 'brain-note' }, 'Hovering a panel pauses a game between bots. White and Black: the panel holds that side’s last move. Both: one panel per side. Matrix: the end state of the computation; Network: the same numbers animated as a network.'), brainHelp());
    app.append(brainSec);
    setTheatre(Q.get('theatre') === '1' || store.get('pc-theatre') === '1');
  }

  function setView(v) {
    view = v;
    store.set('pc-brain-view', v);
    if (st) showMinds(false);
  }
  /** Put each side's calculation where the chosen view wants it, and the move's arrows on the board. */
  function showMinds(moved) {
    const of = (s) => (st.brains && st.brains[s]) || null;
    const name = (s) => (s === '1' ? 'White' : 'Black');
    let first = st.brain || null, second = null;
    let ph1 = 'The net’s mind appears here when a net player moves.', ph2 = '';
    if (view === '1' || view === '-1') { first = of(view); ph1 = `${name(view)} has not moved yet.`; }
    else if (view === 'both') { first = of('1'); second = of('-1'); ph1 = 'White has not moved yet.'; ph2 = 'Black has not moved yet.'; }
    brain.placeholder = ph1; brain2.placeholder = ph2;
    mind.placeholder = ph1.replace('The net’s mind appears', 'MIND appears'); mind2.placeholder = ph2;
    const live = onScreen();
    const fresh = (v, b) => b && (!v.b || v.b.ply !== b.ply);
    const f = [fresh(live[0] || brain, first), fresh(live[1] || brain2, second)];
    // Every view gets its numbers (a hidden one just waits); only the views on screen animate where seen.
    brain.set(first); brain2.set(second); mind.set(first); mind2.set(second);
    mind.setGame(st); mind2.setGame(st);
    const both = view === 'both';
    bc.hidden = hidden || style !== 'network'; bc2.hidden = hidden || style !== 'network' || !both;
    mc.hidden = hidden || style !== 'matrix'; mc2.hidden = hidden || style !== 'matrix' || !both;
    sc.hidden = hidden || style !== 'network'; // the matrix draws its own decisions strip
    for (const bt of viewBtns.children) bt.classList.toggle('on', bt.dataset.v === view);
    for (const bt of styleBtns.children) bt.classList.toggle('on', bt.dataset.v === style);
    sidesEl.replaceChildren(sideCard(st, '1', view === '1', pick), sideCard(st, '-1', view === '-1', pick));
    strip.set(st.evals, st.log);
    // The arrows belong to the move just played; they arrive as the panel reaches the outputs.
    const shown = [[live[0] || brain, first, f[0]], [live[1] || brain2, second, f[1]]];
    const latest = shown.find(([, b]) => b && b.ply === st.log.length);
    const lag = style === 'matrix' ? 800 : 2700;
    if (latest && latest[2] && moved) board.arrows = latest[0].arrows(performance.now() + lag);
    else if (!latest) board.arrows = [];
  }
  const pick = (s) => setView(view === s ? 'follow' : s);

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
    lastChange = performance.now();
    writMode = false; preview = null; board.preview = null; board.writSquares = [];
    board.sel = -1; board.targets = [];
    board.names = [st.players['1'], st.players['-1']];
    board.setFrame(st.frame, prev ? prev.frame : null, !!prev);
    const last = st.log[st.log.length - 1];
    board.caption = last ? `${Math.ceil(last.ply / 2)}${last.by === 1 ? '.' : '...'} ${last.san}`
      : (st.frame.peace > 0 ? 'The opening: Coronation Peace until move 10' : `Move ${st.frame.full}: the floor is open for Writs`);
    board.status = st.status !== 'playing' ? `${st.result}  ·  ${st.termination}` : (st.frame.check ? 'Check' : (st.frame.pending.some((s) => s >= 0) ? 'A count is pending' : ''));
    if (!brain && hasNet(st)) showBrain();
    if (brain) showMinds(true);
    render();
    maybeAI();
  }
  function maybeAI() {
    if (!st.aiToMove || aiBusy || st.status !== 'playing') return;
    if (st.mode === 'ai' && !st.you) return; // only the player's own browser drives its opponent
    aiBusy = true;
    aiSince = performance.now();
    setTimeout(async () => {
      // A watcher reading the net's mind holds the game: it resumes when the pointer leaves the panel.
      if (st.mode === 'aivai' && brain && mindsHover()) { aiBusy = false; setTimeout(maybeAI, 400); return; }
      try { await api.post('ai', { id, version: st.version }); } catch (e) { /* another watcher moved first */ }
      aiBusy = false;
      poll();
    }, st.mode === 'aivai' ? Math.max(1100, brain ? mindsDoneAt() - performance.now() + 900 : 0) : 400);
  }
  async function poll() {
    try {
      const r = await api.get('state', { id, token: token || '', since: st ? st.version : -1 });
      if (!r.unchanged) apply(r);
      else if (st && st.aiToMove && st.status === 'playing') {
        // A lost or stuck request must not stall a bot's game: ask again after 5 s of silence (30 s if in flight).
        const now = performance.now();
        if (aiBusy && now - aiSince > 30000) aiBusy = false;
        if (!aiBusy && now - lastChange > 5000 && (!brain || now > mindsDoneAt())) maybeAI();
      }
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

    if (hasNet(st) && !sideCanvas) {
      sideCanvas = h('canvas', { class: 'mindside', title: 'The net’s last decision: inputs, both layers, the moves’ activations (green +, amber −, black 0), and the game’s decisions' });
      sideMind = window.pcMindSide = new MindSide(sideCanvas);
    }
    if (sideMind) { sideMind.set(st.brain); sideMind.setGame(st); }
    const parts = [status, sideCanvas, controls];
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
