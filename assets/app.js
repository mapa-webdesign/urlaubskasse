'use strict';

const app = document.getElementById('app');
const nav = document.getElementById('nav');
let me = { csrf: '', user: null };
let trip = null; // aktuell geladene Reise (Payload von trips.get)
let ctx = null; // { mode: 'owner', id } oder { mode: 'guest', share }
let editExpenseId = null;

// ---- Helfer ----------------------------------------------------------------

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const eur = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' });
const money = (cents) => eur.format((cents || 0) / 100);
const fmtDate = (d) => (d ? new Date(d + 'T00:00:00').toLocaleDateString('de-DE') : '');
const centsToInput = (c) => (c / 100).toFixed(2).replace('.', ',');
const $ = (sel, root = app) => root.querySelector(sel);
const $$ = (sel, root = app) => [...root.querySelectorAll(sel)];
const formData = (form) => Object.fromEntries(new FormData(form).entries());
const shareLink = (token) => `${location.origin}/r/${token}`;

const store = {
  get(k) { try { return localStorage.getItem(k); } catch { return null; } },
  set(k, v) { try { localStorage.setItem(k, v); } catch { /* egal */ } },
  del(k) { try { localStorage.removeItem(k); } catch { /* egal */ } },
};

async function api(action, body, params = {}) {
  const qs = new URLSearchParams({ action, ...params });
  const opts = { credentials: 'same-origin', headers: {} };
  if (ctx?.mode === 'guest') opts.headers['X-Share-Token'] = ctx.share;
  if (body !== undefined) {
    opts.method = 'POST';
    opts.headers['Content-Type'] = 'application/json';
    opts.headers['X-CSRF-Token'] = me.csrf;
    opts.body = JSON.stringify(body);
  }
  const res = await fetch('api.php?' + qs, opts);
  let data;
  try { data = await res.json(); } catch { data = { error: 'Unerwartete Serverantwort.' }; }
  if (!res.ok) {
    const err = new Error(data.error || 'Fehler');
    err.status = res.status;
    throw err;
  }
  return data;
}

function toast(msg, isError = false) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast' + (isError ? ' error' : '');
  t.hidden = false;
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => (t.hidden = true), 3500);
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text);
    toast('In die Zwischenablage kopiert.');
  } catch {
    prompt('Zum Kopieren markieren:', text);
  }
}

/** Formular absenden mit Fehlerbehandlung und gesperrtem Button. */
function onSubmit(form, handler) {
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const btn = form.querySelector('button[type=submit]');
    if (btn) btn.disabled = true;
    try {
      await handler(formData(form), form);
    } catch (e) {
      toast(e.message, true);
    } finally {
      if (btn) btn.disabled = false;
    }
  });
}

async function refreshMe() {
  me = await api('me');
  renderNav();
}

function renderNav() {
  if (ctx?.mode === 'guest' && !me.user) {
    nav.innerHTML = '';
    return;
  }
  if (me.user) {
    nav.innerHTML = `<a href="#/">Meine Reisen</a><span class="muted hide-sm">${esc(me.user.name)}</span><button class="link" id="logout">Abmelden</button>`;
    document.getElementById('logout').onclick = async () => { await api('logout', {}); await refreshMe(); location.hash = '#/'; };
  } else {
    nav.innerHTML = '';
  }
}

// ---- Router ----------------------------------------------------------------

async function route() {
  const parts = location.hash.replace(/^#\/?/, '').split('/').filter(Boolean);
  window.scrollTo(0, 0);
  try {
    if (parts[0] === 'r' && parts[1]) {
      ctx = { mode: 'guest', share: parts[1] };
      renderNav();
      return await viewTrip(parts[2] || 'overview');
    }
    ctx = null;
    renderNav();
    if (parts[0] === 'register') return viewAuth('register');
    if (parts[0] === 'login') return viewAuth('login');
    if (parts[0] === 'trip' && parts[1]) {
      ctx = { mode: 'owner', id: +parts[1] };
      return await viewTrip(parts[2] || 'overview');
    }
    if (me.user) return await viewDashboard();
    return viewAuth('login');
  } catch (e) {
    const guest = ctx?.mode === 'guest';
    app.innerHTML = `<div class="card narrow"><h1>${e.status === 404 && guest ? 'Link ungültig' : e.status === 401 || e.status === 403 ? 'Kein Zugriff' : 'Fehler'}</h1>
      <p>${esc(e.message)}</p>${guest ? '<p class="muted small">Bitte den Organisator um den aktuellen Link.</p>' : '<a class="btn" href="#/login">Zur Anmeldung</a>'}</div>`;
  }
}

// ---- Anmeldung (Organisator) ------------------------------------------------

function viewAuth(mode) {
  const reg = mode === 'register';
  app.innerHTML = `
    <section class="hero">
      <img src="assets/logo.png?v=7" alt="" width="84" height="84" class="hero-logo">
      <h1>Urlaubskosten fair aufteilen</h1>
      <p>Alle tragen ihre Ausgaben ein, die Kosten werden nach Übernachtungen verteilt – und am Ende steht fest, wer wem wieviel überweist.</p>
    </section>
    <div class="card narrow">
      <div class="tabs">
        <a href="#/login" class="${reg ? '' : 'active'}">Anmelden</a>
        <a href="#/register" class="${reg ? 'active' : ''}">Registrieren</a>
      </div>
      <form id="auth" class="form">
        ${reg ? '<label>Dein Name<input name="name" required maxlength="100" autocomplete="name"></label>' : ''}
        <label>E-Mail<input name="email" type="email" required autocomplete="email"></label>
        <label>Passwort<input name="password" type="password" required minlength="${reg ? 8 : 1}" autocomplete="${reg ? 'new-password' : 'current-password'}"></label>
        <button type="submit" class="btn primary">${reg ? 'Konto anlegen' : 'Anmelden'}</button>
      </form>
      <p class="muted small">${reg
        ? 'Als Organisator legst du Reisen und Teilnehmer an und teilst einen Link mit der Gruppe.'
        : 'Mitreisende brauchen kein Konto – sie nutzen einfach den Link zur Reise.'}</p>
    </div>`;
  onSubmit($('#auth'), async (d) => {
    await api(reg ? 'register' : 'login', d);
    await refreshMe();
    location.hash = '#/';
  });
}

// ---- Dashboard -------------------------------------------------------------

async function viewDashboard() {
  const { trips } = await api('trips.list');
  app.innerHTML = `
    <div class="page-head"><h1>Meine Reisen</h1></div>
    <div class="list">
      ${trips.length ? trips.map((t) => `
        <a class="card row link-card" href="#/trip/${t.id}">
          <div><strong>${esc(t.name)}</strong><div class="muted small">${dateRange(t)}${t.participants} Teilnehmer</div></div>
          <span class="amount">${money(t.total_cents)}</span>
        </a>`).join('') : '<p class="muted">Noch keine Reise angelegt.</p>'}
    </div>
    <div class="card">
      <h2>Neue Reise</h2>
      <form id="newtrip" class="form grid">
        <label class="span2">Name der Reise<input name="name" required maxlength="150" placeholder="z. B. Toskana 2026"></label>
        <label>Anreise<input name="start_date" type="date"></label>
        <label>Abreise<input name="end_date" type="date"></label>
        <label>Deine Übernachtungen<input name="nights" type="number" min="0" max="1000" value="0" inputmode="numeric"></label>
        <div class="span2"><button type="submit" class="btn primary">Reise anlegen</button></div>
      </form>
    </div>`;
  const form = $('#newtrip');
  const syncNights = () => {
    const { start_date: s, end_date: e } = formData(form);
    if (s && e) {
      const n = Math.round((new Date(e) - new Date(s)) / 864e5);
      if (n >= 0) form.nights.value = n;
    }
  };
  form.start_date.onchange = syncNights;
  form.end_date.onchange = syncNights;
  onSubmit(form, async (d) => {
    const r = await api('trips.create', d);
    location.hash = `#/trip/${r.id}/people`;
  });
}

function dateRange(t) {
  if (!t.start_date && !t.end_date) return '';
  return `${fmtDate(t.start_date)} – ${fmtDate(t.end_date)} · `;
}

// ---- Reise -----------------------------------------------------------------

const isOwner = () => trip?.role === 'owner';
const tripBase = () => (ctx.mode === 'guest' ? `#/r/${ctx.share}` : `#/trip/${ctx.id}`);
const meKey = () => `uk_me_${ctx.share}`;

/** Gewählte Person: Organisator = eigener Teilnehmer, Gast = im Browser gemerkte Auswahl. */
function meId() {
  if (ctx.mode === 'owner') return trip.participants.find((p) => p.is_owner)?.id ?? null;
  const id = +store.get(meKey());
  return trip.participants.some((p) => p.id === id) ? id : null;
}

async function viewTrip(tab) {
  trip = ctx.mode === 'guest' ? await api('trips.get') : await api('trips.get', undefined, { id: ctx.id });
  renderTrip(tab);
}

function setTrip(payload, tab) {
  trip = payload;
  renderTrip(tab);
}

function nameOf(pid) {
  return trip.participants.find((p) => p.id === pid)?.name ?? '?';
}

function renderPicker() {
  const t = trip.trip;
  app.innerHTML = `
    <div class="card narrow">
      <p class="eyebrow">Urlaubskasse</p>
      <h1>${esc(t.name)}</h1>
      ${t.start_date || t.end_date ? `<p class="muted small">${fmtDate(t.start_date)} – ${fmtDate(t.end_date)}</p>` : ''}
      <p>Wer bist du?</p>
      <div class="picker">${trip.participants.map((p) => `<button class="btn" data-pick="${p.id}">${esc(p.name)}</button>`).join('')}</div>
    </div>`;
  $$('[data-pick]').forEach((b) => (b.onclick = () => { store.set(meKey(), b.dataset.pick); renderTrip('overview'); }));
}

function renderTrip(tab) {
  if (ctx.mode === 'guest' && !meId()) return renderPicker();
  const tabs = [['overview', 'Übersicht'], ['expenses', 'Ausgaben'], ['people', 'Teilnehmer']];
  const t = trip.trip;
  app.innerHTML = `
    <div class="trip-view">
      <div class="page-head">
        <div>
          ${isOwner() && ctx.mode === 'owner' ? '<a href="#/" class="muted small">← Meine Reisen</a>' : ''}
          <h1>${esc(t.name)}</h1>
          ${t.start_date || t.end_date ? `<p class="muted small">${fmtDate(t.start_date)} – ${fmtDate(t.end_date)}</p>` : ''}
        </div>
        ${ctx.mode === 'guest' ? `<div class="whoami small">Du bist <strong>${esc(nameOf(meId()))}</strong> · <button class="link" id="switch">wechseln</button></div>` : ''}
      </div>
      <nav class="tabs">${tabs.map(([k, l]) => `<a href="${tripBase()}/${k}" class="${k === tab ? 'active' : ''}">${l}</a>`).join('')}</nav>
      <div id="tab"></div>
    </div>`;
  const sw = $('#switch');
  if (sw) sw.onclick = () => { store.del(meKey()); renderPicker(); };
  ({ overview: renderOverview, expenses: renderExpenses, people: renderPeople }[tab] || renderOverview)();
}

function renderOverview() {
  const s = trip.settlement;
  const myId = meId();
  const mine = s.people.find((p) => p.id === myId);
  let status = '';
  if (mine && s.computable && mine.balance_cents !== null) {
    if (mine.balance_cents < 0) status = `<div class="callout warn">Du musst noch <strong>${money(-mine.balance_cents)}</strong> zahlen.</div>`;
    else if (mine.balance_cents > 0) status = `<div class="callout ok">Du bekommst noch <strong>${money(mine.balance_cents)}</strong> zurück.</div>`;
    else status = `<div class="callout ok">Bei dir ist alles ausgeglichen.</div>`;
  }
  const bal = (p) => {
    if (p.balance_cents === null) return '–';
    if (p.balance_cents < 0) return `<span class="neg">zahlt ${money(-p.balance_cents)}</span>`;
    if (p.balance_cents > 0) return `<span class="pos">bekommt ${money(p.balance_cents)}</span>`;
    return '<span class="muted">ausgeglichen</span>';
  };
  $('#tab').innerHTML = `
    ${status}
    <div class="kpis">
      <div class="kpi"><span>Gesamtkosten</span><strong>${money(s.total_cents)}</strong></div>
      <div class="kpi"><span>Übernachtungen</span><strong>${s.total_nights}</strong></div>
      <div class="kpi"><span>Pro Person &amp; Nacht</span><strong>${s.cost_per_night_cents === null ? '–' : money(Math.round(s.cost_per_night_cents))}</strong></div>
      <div class="kpi"><span>Teilnehmer</span><strong>${s.people.length}</strong></div>
    </div>
    ${!s.computable ? '<div class="callout warn">Es sind noch keine Übernachtungen eingetragen – ohne Übernachtungen können die Kosten nicht verteilt werden.</div>' : ''}
    <div class="card">
      <h2>Aufstellung pro Person</h2>
      <div class="table-wrap"><table>
        <thead><tr><th>Name</th><th class="num">Nächte</th><th class="num">Ausgaben</th><th class="num">Anteil</th><th class="num">Saldo</th></tr></thead>
        <tbody>${s.people.map((p) => `
          <tr class="${p.id === myId ? 'me' : ''}">
            <td>${esc(p.name)}</td><td class="num">${p.nights}</td><td class="num">${money(p.paid_cents)}</td>
            <td class="num">${p.share_cents === null ? '–' : money(p.share_cents)}</td><td class="num">${bal(p)}</td>
          </tr>`).join('')}</tbody>
        <tfoot><tr><td>Summe</td><td class="num">${s.total_nights}</td><td class="num">${money(s.total_cents)}</td><td class="num">${s.computable ? money(s.total_cents) : '–'}</td><td></td></tr></tfoot>
      </table></div>
      <p class="muted small">Anteil = Gesamtkosten ÷ Übernachtungen aller × eigene Übernachtungen. Saldo = eigene Ausgaben − Anteil.</p>
    </div>
    <div class="card">
      <h2>Wer überweist wem?</h2>
      ${s.transfers.length ? `<ul class="transfers">${s.transfers.map((tr) => `
        <li class="${tr.from === myId || tr.to === myId ? 'me' : ''}">
          <span>${esc(nameOf(tr.from))}</span><span class="arrow">→</span><span>${esc(nameOf(tr.to))}</span><strong>${money(tr.amount_cents)}</strong>
        </li>`).join('')}</ul>`
      : `<p class="muted">${s.total_cents ? 'Alles ausgeglichen – keine Überweisungen nötig.' : 'Noch keine Ausgaben erfasst.'}</p>`}
    </div>`;
}

function renderExpenses() {
  const editing = trip.expenses.find((e) => e.id === editExpenseId) || null;
  const myId = meId();
  const payerId = editing ? editing.participant_id : myId;
  const today = new Date().toISOString().slice(0, 10);
  $('#tab').innerHTML = `
    <div class="card">
      <h2>${editing ? 'Ausgabe bearbeiten' : 'Neue Ausgabe'}</h2>
      <form id="expform" class="form grid">
        <label class="span2">Wofür?<input name="description" required maxlength="200" placeholder="z. B. Supermarkt, Ferienhaus, Tanken" value="${esc(editing?.description)}"></label>
        <label>Betrag (€)<input name="amount" required inputmode="decimal" placeholder="0,00" value="${editing ? centsToInput(editing.amount_cents) : ''}"></label>
        <label>Datum<input name="expense_date" type="date" value="${esc(editing ? editing.expense_date || '' : today)}"></label>
        <label class="span2">Bezahlt von<select name="participant_id">${trip.participants.map((p) => `<option value="${p.id}" ${p.id === payerId ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></label>
        <div class="span2 actions">
          <button type="submit" class="btn primary">${editing ? 'Speichern' : 'Hinzufügen'}</button>
          ${editing ? '<button type="button" class="btn" id="cancel">Abbrechen</button>' : ''}
        </div>
      </form>
    </div>
    <div class="card">
      <h2>Alle Ausgaben <span class="muted">(${trip.expenses.length})</span></h2>
      ${trip.expenses.length ? `<ul class="expenses">${trip.expenses.map((e) => `
        <li class="${e.participant_id === myId ? 'me' : ''}">
          <div class="grow"><strong>${esc(e.description)}</strong>
            <div class="muted small">${esc(nameOf(e.participant_id))}${e.expense_date ? ' · ' + fmtDate(e.expense_date) : ''}</div></div>
          <span class="amount">${money(e.amount_cents)}</span>
          <span class="row-actions"><button class="icon" data-edit="${e.id}" title="Bearbeiten" aria-label="Bearbeiten">✎</button><button class="icon danger" data-del="${e.id}" title="Löschen" aria-label="Löschen">✕</button></span>
        </li>`).join('')}</ul>` : '<p class="muted">Noch keine Ausgaben.</p>'}
    </div>`;

  onSubmit($('#expform'), async (d) => {
    d.participant_id = +d.participant_id;
    const payload = editing
      ? await api('expenses.update', { ...d, id: editing.id })
      : await api('expenses.create', { ...d, trip_id: trip.trip.id });
    editExpenseId = null;
    toast(editing ? 'Ausgabe gespeichert.' : 'Ausgabe hinzugefügt.');
    setTrip(payload, 'expenses');
  });
  const cancel = $('#cancel');
  if (cancel) cancel.onclick = () => { editExpenseId = null; renderExpenses(); };
  $$('[data-edit]').forEach((b) => (b.onclick = () => { editExpenseId = +b.dataset.edit; renderExpenses(); window.scrollTo(0, 0); }));
  $$('[data-del]').forEach((b) => (b.onclick = async () => {
    if (!confirm('Diese Ausgabe löschen?')) return;
    try {
      setTrip(await api('expenses.delete', { id: +b.dataset.del }), 'expenses');
      toast('Ausgabe gelöscht.');
    } catch (e) { toast(e.message, true); }
  }));
}

function renderPeople() {
  const owner = isOwner();
  const myId = meId();
  const t = trip.trip;
  const link = t.share_token ? shareLink(t.share_token) : '';
  const shareText = `Trag deine Ausgaben für „${t.name}“ in der Urlaubskasse ein:\n${link}`;
  $('#tab').innerHTML = `
    ${owner ? `
      <div class="card invite">
        <h2>Link für die Gruppe</h2>
        <p class="muted small">Alle mit diesem Link können ihren Namen wählen, Ausgaben eintragen und Übernachtungen ändern – ohne Anmeldung.</p>
        <p><code>${esc(link)}</code></p>
        <div class="actions">
          <button class="btn primary" id="copylink">Link kopieren</button>
          <a class="btn" target="_blank" rel="noopener" href="https://wa.me/?text=${encodeURIComponent(shareText)}">Per WhatsApp senden</a>
          <button class="btn danger" id="resetlink">Neuen Link erzeugen</button>
        </div>
      </div>` : ''}
    <div class="card">
      <h2>Teilnehmer &amp; Übernachtungen</h2>
      <ul class="people">${trip.participants.map((p) => `
        <li class="${p.id === myId ? 'me' : ''}">
          <form class="person" data-id="${p.id}">
            ${owner ? `<input name="name" value="${esc(p.name)}" required maxlength="100" aria-label="Name">` : `<span class="grow">${esc(p.name)}</span>`}
            <label class="nights"><input name="nights" type="number" min="0" max="1000" value="${p.nights}" inputmode="numeric" aria-label="Übernachtungen"> Nächte</label>
            <button type="submit" class="btn small">Speichern</button>
          </form>
          ${owner ? `<div class="row-actions">${p.is_owner ? '<span class="badge">Organisator</span>' : `<button class="btn small danger" data-remove="${p.id}">Entfernen</button>`}</div>` : ''}
        </li>`).join('')}</ul>
    </div>
    ${owner ? `
      <div class="card">
        <h2>Teilnehmer hinzufügen</h2>
        <form id="addperson" class="form grid">
          <label>Name<input name="name" required maxlength="100"></label>
          <label>Übernachtungen<input name="nights" type="number" min="0" max="1000" value="${trip.participants[0]?.nights ?? 0}" inputmode="numeric"></label>
          <div class="span2"><button type="submit" class="btn primary">Hinzufügen</button></div>
        </form>
      </div>
      <details class="card">
        <summary><h2>Reise bearbeiten</h2></summary>
        <form id="edittrip" class="form grid">
          <label class="span2">Name<input name="name" required maxlength="150" value="${esc(t.name)}"></label>
          <label>Anreise<input name="start_date" type="date" value="${esc(t.start_date || '')}"></label>
          <label>Abreise<input name="end_date" type="date" value="${esc(t.end_date || '')}"></label>
          <div class="span2 actions"><button type="submit" class="btn primary">Speichern</button><button type="button" class="btn danger" id="deltrip">Reise löschen</button></div>
        </form>
      </details>` : ''}`;

  $$('form.person').forEach((f) => onSubmit(f, async (d) => {
    setTrip(await api('participants.update', { ...d, id: +f.dataset.id, trip_id: t.id }), 'people');
    toast('Gespeichert.');
  }));

  if (!owner) return;
  $('#copylink').onclick = () => copy(link);
  $('#resetlink').onclick = async () => {
    if (!confirm('Neuen Link erzeugen? Der bisherige Link funktioniert dann nicht mehr.')) return;
    try {
      setTrip(await api('trips.resetShare', { id: t.id }), 'people');
      toast('Neuer Link erzeugt.');
    } catch (e) { toast(e.message, true); }
  };
  $$('[data-remove]').forEach((b) => (b.onclick = async () => {
    const p = trip.participants.find((x) => x.id === +b.dataset.remove);
    if (!confirm(`${p.name} entfernen? Alle Ausgaben dieser Person werden ebenfalls gelöscht.`)) return;
    try {
      setTrip(await api('participants.delete', { id: p.id, trip_id: t.id }), 'people');
    } catch (e) { toast(e.message, true); }
  }));
  onSubmit($('#addperson'), async (d) => {
    setTrip(await api('participants.create', { ...d, trip_id: t.id }), 'people');
    toast('Teilnehmer hinzugefügt.');
    $('#addperson input[name=name]')?.focus();
  });
  onSubmit($('#edittrip'), async (d) => {
    setTrip(await api('trips.update', { ...d, id: t.id }), 'people');
    toast('Reise gespeichert.');
  });
  $('#deltrip').onclick = async () => {
    if (!confirm(`„${t.name}“ mit allen Teilnehmern und Ausgaben endgültig löschen?`)) return;
    try {
      await api('trips.delete', { id: t.id });
      trip = null;
      location.hash = '#/';
    } catch (e) { toast(e.message, true); }
  };
}

// ---- Start -----------------------------------------------------------------

window.addEventListener('hashchange', () => {
  editExpenseId = null;
  route();
});

(async () => {
  try {
    await refreshMe();
  } catch (e) {
    app.innerHTML = `<div class="card narrow"><h1>Fehler</h1><p>${esc(e.message)}</p></div>`;
    return;
  }
  route();
})();
