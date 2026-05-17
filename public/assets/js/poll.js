// Long-poll naar /api/state. Bij een hogere state_version: page-reload.
// Server stuurt ook nieuwe events terug (sinds event_since). We bewaren
// die in localStorage zodat de toasts de reload overleven — direct na
// page-load worden ze gerenderd en weer leeggehaald.
//
// event_since wordt PER spel in localStorage bijgehouden. Op de allereerste
// load (geen localStorage) gebruiken we data-event-since uit de body, zodat
// een nieuwe speler niet de hele backlog binnenkrijgt. Daarna is localStorage
// leidend — dat zorgt er ook voor dat na een eigen POST→redirect de gap
// alsnog opgehaald wordt en je je eigen acties als toast ziet.
(function () {
  const root = document.body;
  const code = root.dataset.gameCode;
  if (!code) return;

  const TOAST_KEY = 'eop_toasts_' + code;
  const SEEN_KEY  = 'eop_seen_'   + code;
  const TOAST_TTL = 8000;
  const QUEUE_TTL = 60_000;
  const QUEUE_MAX = 30;

  let ver = parseInt(root.dataset.stateVersion || '0', 10);

  function initEventSince() {
    const raw = localStorage.getItem(SEEN_KEY);
    if (raw !== null && /^\d+$/.test(raw)) return parseInt(raw, 10);
    return parseInt(root.dataset.eventSince || '0', 10);
  }
  let eventSince = initEventSince();
  function saveEventSince() {
    try { localStorage.setItem(SEEN_KEY, String(eventSince)); } catch (e) {}
  }
  saveEventSince();   // zet baseline ook bij allereerste load

  let backoff = 1000;

  function showToast(msg) {
    const stack = document.getElementById('toast-stack');
    if (!stack) return;
    const el = document.createElement('div');
    el.className = 'toast';
    el.textContent = msg;
    stack.appendChild(el);
    requestAnimationFrame(() => el.classList.add('show'));
    setTimeout(() => {
      el.classList.remove('show');
      setTimeout(() => el.remove(), 250);
    }, TOAST_TTL);
  }

  function readQueue() {
    try {
      const raw = localStorage.getItem(TOAST_KEY);
      return raw ? JSON.parse(raw) : [];
    } catch (e) { return []; }
  }
  function writeQueue(items) {
    try {
      while (items.length > QUEUE_MAX) items.shift();
      localStorage.setItem(TOAST_KEY, JSON.stringify(items));
    } catch (e) {}
  }
  function clearQueue() {
    try { localStorage.removeItem(TOAST_KEY); } catch (e) {}
  }

  function queueEvents(events) {
    const items = readQueue();
    const now = Date.now();
    events.forEach(ev => items.push({ m: ev.message, t: now }));
    writeQueue(items);
  }

  function drainQueue() {
    const items = readQueue();
    if (!items.length) return;
    const cutoff = Date.now() - QUEUE_TTL;
    items.filter(it => it.t >= cutoff).forEach(it => showToast(it.m));
    clearQueue();
  }

  async function tick() {
    try {
      const url = '/api/state?code=' + encodeURIComponent(code)
                + '&since='        + ver
                + '&event_since='  + eventSince;
      const res = await fetch(url, { credentials: 'same-origin' });
      if (!res.ok) throw new Error('http ' + res.status);
      const data = await res.json();
      backoff = 1000;

      if (Array.isArray(data.events) && data.events.length) {
        const maxId = data.events.reduce((m, e) => Math.max(m, e.id), eventSince);
        eventSince = maxId;
        saveEventSince();
        if (typeof data.version === 'number' && data.version > ver) {
          queueEvents(data.events);
          window.location.reload();
          return;
        }
        // State-version niet bewogen (typisch: jouw eigen actie waar je
        // net van bent gerefreshed) — inline toasten.
        data.events.forEach(e => showToast(e.message));
      } else if (typeof data.version === 'number' && data.version > ver) {
        window.location.reload();
        return;
      }
      setTimeout(tick, 50);
    } catch (e) {
      setTimeout(tick, backoff);
      backoff = Math.min(backoff * 2, 30_000);
    }
  }

  // Direct na load: oude queue afdraaien zodat toasts de reload overleven.
  drainQueue();
  tick();
})();
