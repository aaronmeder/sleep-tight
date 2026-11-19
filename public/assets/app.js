(() => {
'use strict';
const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const icon = name => name ? `<svg class="ic" aria-hidden="true"><use href="#i-${name}"/></svg>` : '';

async function api(action, body) {
  const res = await fetch('api.php?a=' + action, body
    ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
    : {});
  const json = await res.json().catch(() => ({ error: 'Bad response' }));
  if (res.status === 401 && !['login', 'setup'].includes(action)) location.reload();
  if (!res.ok) throw new Error(json.error || 'Something went wrong');
  return json;
}

// --- small delights ---------------------------------------------------------
/** Restarts a one-shot CSS animation class on an element. */
function play(el, cls) {
  el.classList.remove(cls);
  void el.offsetWidth;
  el.classList.add(cls);
}
const tick = (ms = 8) => { try { navigator.vibrate && navigator.vibrate(ms); } catch (e) {} };

// --- auth -------------------------------------------------------------------
const auth = $('#auth');
if (auth) {
  auth.addEventListener('submit', async e => {
    e.preventDefault();
    try {
      await api(auth.dataset.mode, { password: $('#pw').value });
      location.reload();
    } catch (err) {
      $('#authErr').textContent = err.message;
      play(auth, 'shake');
    }
  });
  return;
}

// --- dates ------------------------------------------------------------------
const iso = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const parse = s => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
const addDays = (s, n) => { const d = parse(s); d.setDate(d.getDate() + n); return iso(d); };
const today = () => iso(new Date());
const fmt = (s, opts) => parse(s).toLocaleDateString('en-GB', opts);
const fmtHours = h => `${Math.floor(h)}h ${String(Math.round((h % 1) * 60)).padStart(2, '0')}`;

// --- state ------------------------------------------------------------------
const GROUPS = Object.keys(TAGS);
let entries = {};          // date -> entry
let f = null;              // form state for the current date
let dirty = false;
let view = 'log';
let range = 14;
let picked = null;         // date selected in the chart

const sorted = () => Object.values(entries).sort((a, b) => a.date < b.date ? -1 : 1);

function blank(date) {
  // New night: sensible defaults, clothing carried over from the most recent earlier night.
  const prev = sorted().filter(e => e.date < date).pop();
  return { date, hours: 7.5, breaks: 0, quality: 70, clothing: prev ? [...prev.clothing] : [], day: [], evening: [], specifics: [], concerns: [], note: '' };
}

function open(date) {
  if (dirty && !confirm('Discard unsaved changes?')) return;
  if (date > today()) date = today();
  const dir = f && date !== f.date ? (date > f.date ? 'from-right' : 'from-left') : null;
  f = entries[date] ? structuredClone(entries[date]) : blank(date);
  for (const g of GROUPS) f[g] = (f[g] || []).filter(slug => slug in TAGS[g]);
  dirty = false;
  renderLog();
  if (dir) play($('#view-log'), dir);
}

// --- log view ---------------------------------------------------------------
const saveBtn = $('#save');
const WORDS = [[30, 'rough'], [50, 'poor'], [65, 'okay'], [80, 'good'], [92, 'great'], [101, 'dreamy']];

// "Main concerns" is only asked after a rough night. Mirrors concerns_apply() in lib/app.php.
const concernsApply = () => f.hours < 5 || f.breaks > 1;

function setFill(input) {
  input.style.setProperty('--p', ((input.value - input.min) / (input.max - input.min)) * 100 + '%');
}

/** Updates the three numbers in place; `changed` names the one to animate. */
function renderValues(changed) {
  $('#hours').value = Math.min(f.hours, 14);
  $('#quality').value = f.quality;
  const set = (id, text, key) => {
    const el = $(id);
    if (el.textContent === String(text)) return;
    el.textContent = text;
    if (changed === key) play(el, 'bump');
  };
  set('#hoursOut', fmtHours(f.hours), 'hours');
  set('#qualityOut', f.quality + '%', 'quality');
  set('#breaksOut', f.breaks, 'breaks');
  $('#qualityWord').textContent = '· ' + WORDS.find(([max]) => f.quality < max)[1];
  $$('input[type=range]').forEach(setFill);
  const box = $('#concernsBox'), show = concernsApply();
  if (box.hidden === show) {
    box.hidden = !show;
    if (show && changed) play(box, 'reveal');
  }
}

function renderLog() {
  const t = today();
  $('#dateTitle').textContent = f.date === t ? 'Last night' : f.date === addDays(t, -1) ? 'Night before last' : fmt(f.date, { weekday: 'long' });
  $('#dateSub').textContent = `${fmt(addDays(f.date, -1), { weekday: 'short', day: 'numeric' })} → ${fmt(f.date, { weekday: 'short', day: 'numeric', month: 'short' })}`;
  $('#datePick').value = f.date;
  $('#datePick').max = t;
  $('#next').disabled = f.date >= t;
  renderValues();
  for (const g of GROUPS) {
    $(`.chips[data-group=${g}]`).innerHTML = Object.entries(TAGS[g]).map(([slug, label]) =>
      `<button type="button" class="chip${f[g].includes(slug) ? ' on' : ''}" data-tag="${slug}" aria-pressed="${f[g].includes(slug)}">${icon((ICONS[g] || {})[slug])}${esc(label)}</button>`).join('');
  }
  $('#note').value = f.note;
  $('#del').hidden = !entries[f.date];
  renderSave();
}

function renderSave(flash) {
  const exists = !!entries[f.date];
  $('#saveLabel').textContent = flash || (exists ? (dirty ? 'Update' : 'Saved') : 'Save');
  saveBtn.disabled = exists && !dirty;
}

function touch() { dirty = true; renderSave(); }

const LIMITS = { hours: [0, 24], quality: [1, 100], breaks: [0, 30] };
document.addEventListener('click', e => {
  const step = e.target.closest('[data-step]');
  if (step) {
    const k = step.dataset.step, [lo, hi] = LIMITS[k];
    const next = Math.max(lo, Math.min(hi, f[k] + Number(step.dataset.d)));
    if (next === f[k]) return play(step, 'nope');
    f[k] = next;
    tick();
    renderValues(k);
    return touch();
  }
  const chip = e.target.closest('.chip');
  if (chip) {
    const list = f[chip.parentElement.dataset.group], slug = chip.dataset.tag;
    const on = !list.includes(slug);
    on ? list.push(slug) : list.splice(list.indexOf(slug), 1);
    chip.classList.toggle('on', on);
    chip.setAttribute('aria-pressed', on);
    if (on) play(chip, 'pop');
    tick();
    return touch();
  }
});

for (const k of ['hours', 'quality']) {
  $('#' + k).addEventListener('input', e => { f[k] = Number(e.target.value); renderValues('slide'); touch(); });
}
$('#note').addEventListener('input', e => { f.note = e.target.value; touch(); });
$('#prev').addEventListener('click', () => open(addDays(f.date, -1)));
$('#next').addEventListener('click', () => open(addDays(f.date, 1)));
$('#datePick').addEventListener('change', e => e.target.value && open(e.target.value));

saveBtn.addEventListener('click', async () => {
  saveBtn.disabled = true;
  try {
    if (!concernsApply()) f.concerns = [];
    await api('save', f);
    entries[f.date] = structuredClone(f);
    dirty = false;
    $('#del').hidden = false;
    renderSave('Sleep tight ✓');
    play(saveBtn, 'done');
    tick(20);
  } catch (err) {
    renderSave();
    play(saveBtn, 'shake');
    alert(err.message);
  }
});

$('#del').addEventListener('click', async () => {
  if (!confirm('Delete this night?')) return;
  try {
    await api('delete', { date: f.date });
    delete entries[f.date];
    dirty = false;
    open(f.date);
  } catch (err) { alert(err.message); }
});

// --- tabs -------------------------------------------------------------------
$('.tabs').addEventListener('click', e => {
  const v = (e.target.closest('[data-view]') || {}).dataset;
  if (v && v.view !== view) { tick(); show(v.view); }
});

function show(v) {
  view = v;
  $('#view-log').hidden = v !== 'log';
  $('#view-stats').hidden = v !== 'stats';
  saveBtn.hidden = v !== 'log';
  $$('.tabs [data-view]').forEach(b => { b.classList.toggle('on', b.dataset.view === v); if (b.dataset.view === v) play(b.firstElementChild, 'pill-in'); });
  if (v === 'stats') renderStats();
  play($('#view-' + v), 'fade-in');
  scrollTo(0, 0);
}

// --- stats ------------------------------------------------------------------
const avg = (list, key) => list.length ? list.reduce((s, e) => s + e[key], 0) / list.length : null;
const within = n => { const from = addDays(today(), -n); return sorted().filter(e => e.date > from); };

function renderStats() {
  renderTiles();
  renderDays();
  renderChart(true);
  renderImpact();
}

function renderTiles() {
  const w = within(7), m = within(30);
  const tile = (label, value, sub) => `<div class="tile"><span>${label}</span><b>${value}</b><small>${sub}</small></div>`;
  const h = (l) => avg(l, 'hours') == null ? '–' : fmtHours(avg(l, 'hours'));
  const q = (l) => avg(l, 'quality') == null ? '–' : Math.round(avg(l, 'quality')) + '%';
  $('#tiles').innerHTML =
    tile('Sleep · 7d', h(w), `30d ${h(m)}`) +
    tile('Quality · 7d', q(w), `30d ${q(m)}`) +
    tile('Nights', Object.keys(entries).length, `${w.length}/7 this week`);
}

// Last 7 days as squares (filled when logged); the calendar button expands a full month below.
let calOpen = false;
let calMonth = null;       // first day of the month shown, YYYY-MM-01

function dayButton(d, weekday) {
  const t = today(), on = !!entries[d];
  return `<button class="day${on ? ' on' : ''}${d === t ? ' today' : ''}" data-date="${d}"${d > t ? ' disabled' : ''} aria-label="${fmt(d, { weekday: 'long', day: 'numeric', month: 'long' })}, ${on ? 'logged' : 'not logged'}">
    ${weekday ? `<small>${fmt(d, { weekday: 'narrow' })}</small>` : ''}<i>${parse(d).getDate()}</i></button>`;
}

function renderDays() {
  const t = today();
  $('#days').innerHTML = Array.from({ length: 7 }, (_, i) => dayButton(addDays(t, i - 6), true)).join('') +
    `<button class="day-more${calOpen ? ' on' : ''}" id="calToggle" aria-expanded="${calOpen}" aria-controls="cal" aria-label="${calOpen ? 'Hide' : 'Show'} calendar">${icon('calendar-blank')}</button>`;
  renderCal();
}

function renderCal() {
  const box = $('#cal');
  box.hidden = !calOpen;
  if (!calOpen) return;
  const thisMonth = today().slice(0, 7) + '-01';
  const first = parse(calMonth), y = first.getFullYear(), m = first.getMonth();
  const count = new Date(y, m + 1, 0).getDate(), lead = (first.getDay() + 6) % 7; // weeks start on Monday
  const dates = Array.from({ length: count }, (_, i) => iso(new Date(y, m, i + 1)));
  box.innerHTML = `<div class="cal-head">
      <button class="icon" id="calPrev" aria-label="Previous month">‹</button>
      <span><b>${first.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' })}</b> <small>${dates.filter(d => entries[d]).length} logged</small></span>
      <button class="icon" id="calNext" aria-label="Next month"${calMonth >= thisMonth ? ' disabled' : ''}>›</button>
    </div>
    <div class="cal-grid">${['M', 'T', 'W', 'T', 'F', 'S', 'S'].map(d => `<small>${d}</small>`).join('')}${'<span></span>'.repeat(lead)}${dates.map(d => dayButton(d, false)).join('')}</div>`;
}

$('#daysBox').addEventListener('click', e => {
  const day = e.target.closest('.day');
  if (day) {
    tick();
    open(day.dataset.date);
    if (f.date === day.dataset.date) show('log');
    return;
  }
  if (e.target.closest('#calToggle')) {
    calOpen = !calOpen;
    calMonth = today().slice(0, 7) + '-01';
    tick();
    renderDays();
    if (calOpen) play($('#cal'), 'reveal');
    return;
  }
  const nav = e.target.closest('#calPrev, #calNext');
  if (nav) {
    const d = parse(calMonth), step = nav.id === 'calPrev' ? -1 : 1;
    calMonth = iso(new Date(d.getFullYear(), d.getMonth() + step, 1));
    tick();
    renderCal();
    play($('.cal-grid'), step < 0 ? 'from-left' : 'from-right');
  }
});

$('#range').addEventListener('click', e => {
  if (!e.target.dataset.n) return;
  range = Number(e.target.dataset.n);
  picked = null;
  tick();
  renderChart(true);
});

/** @param animate grow the marks in (first paint / range change), not on every tap */
function renderChart(animate) {
  $$('#range button').forEach(b => b.classList.toggle('on', Number(b.dataset.n) === range));
  const box = $('#chart');
  const days = Array.from({ length: range }, (_, i) => addDays(today(), i - range + 1));
  const data = days.map(d => entries[d] || null);
  if (!data.some(Boolean)) {
    box.innerHTML = '<p class="muted small">No nights logged in this period yet.</p>';
    $('#readout').innerHTML = '';
    return;
  }
  if (!picked || !days.includes(picked) || !entries[picked]) picked = [...data].reverse().find(Boolean).date;

  // Two stacked panels sharing one x axis (hours bars, quality line) rather than a dual axis.
  const W = box.clientWidth || 320, padL = 30, padR = 4;
  const H1 = 104, GAP = 26, H2 = 70, top = 8, base1 = top + H1, top2 = base1 + GAP, base2 = top2 + H2, H = base2 + 20;
  const col = (W - padL - padR) / range;
  const x = i => padL + i * col;
  const maxH = Math.max(10, Math.ceil(Math.max(...data.map(e => e ? e.hours : 0))));
  const yH = h => base1 - (h / maxH) * H1;
  const yQ = q => base2 - (q / 100) * H2;
  const bw = Math.max(2, Math.min(18, col - 2));

  let s = `<svg class="${animate ? 'animate' : ''}" viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" role="img" aria-label="Hours slept and sleep quality for the last ${range} nights">`;
  s += `<defs>
    <linearGradient id="barGrad" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#cf93b0"/><stop offset="1" stop-color="#9c93e0"/></linearGradient>
    <linearGradient id="lineGrad" gradientUnits="userSpaceOnUse" x1="${padL}" x2="${W - padR}" y1="0" y2="0"><stop offset="0" stop-color="#9c93e0"/><stop offset="1" stop-color="#cf93b0"/></linearGradient>
  </defs>`;
  const grid = (y, label) => `<line class="grid" x1="${padL}" x2="${W - padR}" y1="${y}" y2="${y}"/><text class="axis" x="${padL - 6}" y="${y + 3.5}" text-anchor="end">${label}</text>`;
  s += grid(yH(8), '8h') + grid(yH(4), '4h') + grid(base1, '0');
  s += grid(yQ(100), '100') + grid(yQ(50), '50') + grid(base2, '0');
  s += `<text class="axis cap" x="${padL}" y="${top2 - 9}">Quality %</text>`;

  const pi = days.indexOf(picked);
  s += `<rect class="band" x="${x(pi)}" y="${top}" width="${col}" height="${base2 - top}" rx="3"/>`;

  // hours: bars, rounded at the data end, square on the baseline
  data.forEach((e, i) => {
    if (!e || !e.hours) return;
    const bx = x(i) + (col - bw) / 2, y = yH(e.hours), r = Math.min(4, bw / 2, base1 - y);
    s += `<path class="bar${i === pi ? ' on' : ''}" style="animation-delay:${Math.round(i / range * 280)}ms" d="M${bx},${base1}V${y + r}q0,${-r} ${r},${-r}h${bw - 2 * r}q${r},0 ${r},${r}V${base1}z"/>`;
  });

  // quality: line, broken where nights are missing
  let d = '', pen = false;
  data.forEach((e, i) => {
    if (!e) { pen = false; return; }
    d += `${pen ? 'L' : 'M'}${x(i) + col / 2},${yQ(e.quality)}`;
    pen = true;
  });
  s += `<g class="q"><path class="line" d="${d}"/>`;
  data.forEach((e, i) => {
    const lonely = e && !data[i - 1] && !data[i + 1];
    if (e && (range <= 30 || lonely || i === pi)) {
      s += `<circle class="dot${i === pi ? ' on' : ''}" cx="${x(i) + col / 2}" cy="${yQ(e.quality)}" r="${i === pi ? 4.5 : 3}"/>`;
    }
  });
  s += '</g>';

  // x labels: first, middle, last
  [0, Math.floor(range / 2), range - 1].forEach((i, n) => {
    s += `<text class="axis" x="${x(i) + col / 2}" y="${H - 4}" text-anchor="${['start', 'middle', 'end'][n]}">${fmt(days[i], { day: 'numeric', month: 'short' })}</text>`;
  });
  // full-height hit targets, wider than the marks
  days.forEach((dte, i) => { s += `<rect class="hit" data-date="${dte}" x="${x(i)}" y="0" width="${col}" height="${H}"/>`; });
  box.innerHTML = s + '</svg>';

  const e = entries[picked];
  $('#readout').innerHTML = `<span><b>${fmt(picked, { weekday: 'short', day: 'numeric', month: 'short' })}</b> · ${fmtHours(e.hours)} · ${e.quality}% · ${e.breaks} break${e.breaks === 1 ? '' : 's'}</span>
    <button class="link" id="editPicked">Edit</button>`;
}

$('#chart').addEventListener('click', e => {
  const d = e.target.dataset.date;
  if (d && entries[d] && d !== picked) { picked = d; tick(); renderChart(false); play($('#readout'), 'fade-in'); }
});
$('#readout').addEventListener('click', e => {
  if (e.target.id === 'editPicked') { open(picked); if (f.date === picked) show('log'); }
});
addEventListener('resize', () => view === 'stats' && renderChart(false));

function renderImpact() {
  const all = sorted(), MIN = 3, rows = [];
  for (const g of GROUPS) for (const [slug, label] of Object.entries(TAGS[g])) {
    const yes = all.filter(e => e[g].includes(slug)), no = all.filter(e => !e[g].includes(slug));
    if (yes.length < MIN || no.length < MIN) continue;
    rows.push({ label, group: g, slug, n: yes.length, delta: avg(yes, 'quality') - avg(no, 'quality') });
  }
  if (!rows.length) {
    $('#impact').innerHTML = `<p class="muted small">Shows up once a tag has at least ${MIN} nights with it and ${MIN} without.</p>`;
    return;
  }
  rows.sort((a, b) => b.delta - a.delta);
  const max = Math.max(10, ...rows.map(r => Math.abs(r.delta)));
  const names = { clothing: '', day: 'day', evening: 'evening', specifics: '', concerns: 'concern' };
  $('#impact').innerHTML = rows.map((r, i) => {
    const w = Math.abs(r.delta) / max * 50, pos = r.delta >= 0, v = Math.round(r.delta);
    return `<div class="imp">
      <span class="imp-l">${icon((ICONS[r.group] || {})[r.slug])}<span>${esc(r.label)}${names[r.group] ? ` <small>${names[r.group]}</small>` : ''}</span></span>
      <span class="imp-bar"><i class="${pos ? 'pos' : 'neg'}" style="width:${w}%;${pos ? 'left:50%' : 'right:50%'};animation-delay:${i * 25}ms"></i></span>
      <span class="imp-v">${v > 0 ? '+' : v < 0 ? '−' : ''}${Math.abs(v)} <small>${r.n}×</small></span>
    </div>`;
  }).join('');
}

// --- menu & settings --------------------------------------------------------
const menu = $('#menu'), settings = $('#settings');
$('#openMenu').addEventListener('click', () => { tick(); menu.showModal(); });
$('#openSettings').addEventListener('click', async () => {
  menu.close();
  settings.showModal();
  try { $('#token').textContent = (await api('token')).token; } catch (err) { $('#token').textContent = err.message; }
});
$('#copyToken').addEventListener('click', async e => {
  try { await navigator.clipboard.writeText($('#token').textContent); e.target.textContent = 'Copied ✓'; tick(20); }
  catch (err) { e.target.textContent = 'Select the token and copy it'; }
  setTimeout(() => { e.target.textContent = 'Copy token'; }, 2000);
});
for (const d of [menu, settings]) {
  // close on backdrop tap, on [data-close], and after picking a link
  d.addEventListener('click', e => { if (e.target === d || e.target.closest('[data-close], a')) d.close(); });
}
$('#logout').addEventListener('click', async () => { await api('logout', {}); location.reload(); });
addEventListener('beforeunload', e => { if (dirty) e.preventDefault(); });

api('list').then(r => {
  for (const e of r.entries) entries[e.date] = e;
  open(today());
  play($('#view-log'), 'fade-in');
}).catch(err => alert(err.message));
})();
