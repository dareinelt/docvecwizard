/* Document Embedding Manager - single-page UI (vanilla JS, no frameworks/CDN).
 *
 * Security notes (audit fixes):
 *  - every dynamic value is HTML-escaped via esc() before it is put into markup
 *  - every path parameter is URL-encoded via enc()
 *  - no inline event handlers / style attributes (strict Content-Security-Policy)
 *  - the API requires a session login; 401 responses show the login screen
 */
(function () {
  'use strict';

  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

  const state = {
    csrf: null,
    user: null,
    currentView: 'dashboard',
    browsePath: '',
    pollTimer: null,
    lastFocus: null,
    navToken: 0,
  };

  /* ---------- utilities ---------- */
  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  // FIX: IDs were concatenated into URLs without encoding.
  const enc = value => encodeURIComponent(String(value == null ? '' : value));

  function fmtBytes(n) {
    n = Number(n) || 0;
    if (n < 1024) return n + ' B';
    const units = ['KB', 'MB', 'GB', 'TB'];
    let i = -1;
    do { n /= 1024; i++; } while (n >= 1024 && i < units.length - 1);
    return n.toFixed(1) + ' ' + units[i];
  }

  function fmtDate(s) {
    if (!s) return '–';
    const d = new Date(String(s).replace(' ', 'T') + (String(s).includes('T') ? '' : 'Z'));
    if (isNaN(d.getTime())) return esc(s);
    return esc(d.toLocaleString('de-DE'));
  }

  function fmtNumber(n) {
    return Number(n || 0).toLocaleString('de-DE');
  }

  const STATUS_LABELS = {
    COMPLETED: 'Abgeschlossen', RUNNING: 'Läuft', CREATED: 'Erstellt', PENDING: 'Wartend',
    PROCESSING: 'In Verarbeitung', DISCOVERED: 'Gefunden', FAILED: 'Fehlgeschlagen',
    CANCELLED: 'Abgebrochen', IMPORTED: 'Importiert', PAUSED: 'Pausiert',
  };

  function statusBadge(status) {
    const s = String(status || '').toUpperCase();
    const map = {
      COMPLETED: 'badge-green', RUNNING: 'badge-blue', CREATED: 'badge-gray',
      PENDING: 'badge-gray', PROCESSING: 'badge-blue', DISCOVERED: 'badge-gray',
      FAILED: 'badge-red', CANCELLED: 'badge-amber', IMPORTED: 'badge-green',
      PAUSED: 'badge-amber',
    };
    const label = STATUS_LABELS[s] || status || '–';
    return '<span class="badge ' + (map[s] || 'badge-gray') + '" title="' + esc(status || '') + '">' + esc(label) + '</span>';
  }

  class ApiError extends Error {
    constructor(message, status) { super(message); this.status = status; }
  }

  const GENERIC_ERRORS = {
    401: 'Bitte melden Sie sich an.',
    403: 'Zugriff verweigert.',
    404: 'Nicht gefunden.',
    413: 'Die Datei ist zu groß.',
    429: 'Zu viele Anfragen. Bitte später erneut versuchen.',
    502: 'Ein abhängiger Dienst ist nicht erreichbar.',
    504: 'Zeitüberschreitung beim Server.',
  };

  async function api(path, opts, retried) {
    opts = opts || {};
    const method = (opts.method || 'GET').toUpperCase();
    const headers = Object.assign({ Accept: 'application/json' }, opts.headers || {});
    if (method !== 'GET' && method !== 'HEAD' && state.csrf) headers['X-CSRF-Token'] = state.csrf;
    let body = opts.body;
    if (body != null && !(body instanceof FormData) && typeof body === 'object') {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(body);
    }
    let res;
    try {
      res = await fetch(path, { method, headers, body, credentials: 'same-origin', cache: 'no-store' });
    } catch (e) {
      throw new ApiError('Server nicht erreichbar. Bitte Verbindung prüfen.', 0);
    }
    const text = await res.text();
    let data = null;
    try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
    if (data && typeof data.csrf_token === 'string') state.csrf = data.csrf_token;

    if (!res.ok) {
      const msg = (data && data.error) || GENERIC_ERRORS[res.status] || ('Fehler (HTTP ' + res.status + ')');
      // FIX: session expired -> show the login screen instead of failing silently.
      if (res.status === 401 && !path.startsWith('/api/auth/')) {
        showLogin('Ihre Sitzung ist abgelaufen. Bitte erneut anmelden.');
      }
      // CSRF token stale (e.g. after a server-side session rotation): refresh once and retry.
      if (res.status === 403 && !retried && method !== 'GET') {
        const fresh = await refreshSession();
        if (fresh) return api(path, opts, true);
      }
      throw new ApiError(msg, res.status);
    }
    return data;
  }

  /** Reload login state + CSRF token. Returns true when still authenticated. */
  async function refreshSession() {
    try {
      const me = await api('/api/auth/me');
      state.user = me.authenticated ? me.user : null;
      return !!me.authenticated;
    } catch (e) { return false; }
  }

  function notify(msg, type) {
    const el = document.createElement('div');
    el.className = 'toast ' + (type || '');
    const text = document.createElement('span');
    text.className = 'toast-msg';
    text.textContent = msg;
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'toast-close';
    close.setAttribute('aria-label', 'Meldung schließen');
    close.textContent = '✕';
    close.addEventListener('click', () => el.remove());
    el.append(text, close);
    // Errors go into the assertive live region and stay longer.
    $(type === 'error' ? '#toast-root-alert' : '#toast-root').appendChild(el);
    setTimeout(() => el.remove(), type === 'error' ? 8000 : 4000);
  }

  /** Disable a button while an async action runs (prevents double submits). */
  async function busy(btn, fn) {
    if (!btn || btn.getAttribute('aria-busy') === 'true') return undefined;
    const label = btn.textContent;
    btn.disabled = true;
    btn.setAttribute('aria-busy', 'true');
    btn.textContent = label + ' …';
    try { return await fn(); }
    finally {
      if (btn.isConnected) {
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
        btn.textContent = label;
      }
    }
  }

  /* ---------- modal (focus management, Escape, focus trap) ---------- */
  const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function openModal(title, html) {
    if ($('#modal-backdrop').classList.contains('hidden')) state.lastFocus = document.activeElement;
    $('#modal-title').textContent = title;
    $('#modal-body').innerHTML = html;
    $('#modal-backdrop').classList.remove('hidden');
    const first = $(FOCUSABLE, $('#modal-body'));
    (first || $('#modal')).focus();
  }

  function closeModal() {
    $('#modal-backdrop').classList.add('hidden');
    $('#modal-body').innerHTML = '';
    if (state.lastFocus && state.lastFocus.isConnected) state.lastFocus.focus();
    state.lastFocus = null;
  }

  function modalKeydown(ev) {
    if ($('#modal-backdrop').classList.contains('hidden')) return;
    if (ev.key === 'Escape') { ev.preventDefault(); closeModal(); return; }
    if (ev.key !== 'Tab') return;
    const items = $$(FOCUSABLE, $('#modal')).filter(x => x.offsetParent !== null);
    if (!items.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
    else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
  }

  function renderInto(el, html) { el.innerHTML = html; }

  /** Make table rows with data-* attributes clickable and keyboard-operable. */
  function rowActivation(container, selector, handler) {
    container.addEventListener('click', ev => {
      if (ev.target.closest('button, a, input, select, textarea')) return;
      const tr = ev.target.closest(selector);
      if (tr && container.contains(tr)) handler(tr);
    });
    container.addEventListener('keydown', ev => {
      if (ev.key !== 'Enter' && ev.key !== ' ') return;
      const tr = ev.target.closest(selector);
      if (tr && ev.target === tr) { ev.preventDefault(); handler(tr); }
    });
  }

  /* ---------- authentication ---------- */
  function showLogin(message) {
    state.user = null;
    stopPolling();
    closeModal();
    $('#app').classList.add('hidden');
    $('#login-screen').classList.remove('hidden');
    $('#login-error').textContent = message || '';
    $('#login-password').value = '';
    $('#login-username').focus();
  }

  function showApp() {
    $('#login-screen').classList.add('hidden');
    $('#app').classList.remove('hidden');
    $('#current-user').textContent = state.user ? 'Angemeldet als ' + state.user.username : '';
    const initial = (location.hash || '#dashboard').slice(1);
    navigate(VIEWS[initial] ? initial : 'dashboard');
    startPolling();
  }

  async function onLogin(ev) {
    ev.preventDefault();
    const btn = $('#login-form button[type=submit]');
    const username = $('#login-username').value.trim();
    const password = $('#login-password').value;
    if (!username || !password) {
      $('#login-error').textContent = 'Bitte Benutzername und Passwort eingeben.';
      return;
    }
    $('#login-error').textContent = '';
    await busy(btn, async () => {
      try {
        if (!state.csrf) await refreshSession();
        const r = await api('/api/auth/login', { method: 'POST', body: { username, password } });
        state.user = r.user;
        $('#login-password').value = '';
        showApp();
      } catch (e) {
        $('#login-error').textContent = e.message;
        $('#login-password').select();
      }
    });
  }

  async function onLogout() {
    try { await api('/api/auth/logout', { method: 'POST' }); } catch (e) { /* show login regardless */ }
    showLogin('Sie wurden abgemeldet.');
  }

  /* ---------- navigation ---------- */
  const TITLES = {
    dashboard: 'Dashboard', documents: 'Dokumente', browse: 'Ordner',
    jobs: 'Aufträge', search: 'Suche', collections: 'Kollektionen',
    statistics: 'Statistiken', export: 'Export / Import', tls: 'TLS-Zertifikat',
    settings: 'Einstellungen', system: 'System',
  };

  const VIEWS = {
    dashboard: renderDashboard, documents: renderDocuments, browse: renderBrowse,
    jobs: renderJobs, search: renderSearch, collections: renderCollections,
    statistics: renderStatistics, export: renderExport, tls: renderTls,
    settings: renderSettings, system: renderSystem,
  };

  function navigate(view, keepFocus) {
    if (!state.user) return;
    state.currentView = view;
    $$('#nav a').forEach(a => {
      const active = a.dataset.view === view;
      a.classList.toggle('active', active);
      if (active) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
    $('#nav').classList.remove('open');
    $('#nav-toggle').setAttribute('aria-expanded', 'false');
    $('#view-title').textContent = TITLES[view] || view;
    document.title = (TITLES[view] || view) + ' – Document Embedding Manager';
    const viewEl = $('#view');
    // Replace the element to drop all listeners of the previous view (event delegation is per view).
    const fresh = viewEl.cloneNode(false);
    viewEl.replaceWith(fresh);
    fresh.innerHTML = '<div class="spinner">Lade…</div>';
    fresh.setAttribute('aria-busy', 'true');
    const token = ++state.navToken;
    const fn = VIEWS[view] || renderDashboard;
    Promise.resolve(fn(fresh))
      .catch(err => {
        if (token !== state.navToken || (err && err.status === 401)) return;
        fresh.innerHTML = '<div class="card"><p class="muted">Fehler: ' + esc(err.message) + '</p>' +
          '<button class="btn mt" type="button" data-retry>Erneut versuchen</button></div>';
        const retry = $('[data-retry]', fresh);
        if (retry) retry.addEventListener('click', () => navigate(view));
      })
      .finally(() => { fresh.setAttribute('aria-busy', 'false'); });
    if (!keepFocus) fresh.focus({ preventScroll: true });
  }

  /* ---------- shared widgets ---------- */
  async function modelOptions(selected) {
    let models = [];
    try { const r = await api('/api/models'); models = r.models || []; } catch (e) { models = []; }
    if (!models.length) {
      return '<option value="">Keine Modelle – bitte unter „System“ synchronisieren</option>';
    }
    return models.map(m =>
      '<option value="' + esc(m.name) + '"' + (m.name === selected || (!selected && m.active) ? ' selected' : '') + '>' +
      esc(m.name) + ' (' + esc(m.dimension) + ' dim' + (m.active ? ', aktiv' : '') + ')</option>'
    ).join('');
  }

  function badgeForHealth(ok) {
    return ok ? '<span class="badge badge-green">OK</span>' : '<span class="badge badge-red">Fehler</span>';
  }

  /* ---------- views ---------- */
  async function renderDashboard(el) {
    const [stats, jobs] = await Promise.all([api('/api/statistics'), api('/api/jobs?limit=5')]);
    const d = stats.documents || {};
    const j = stats.jobs || {};
    const t = stats.totals || {};
    const m = stats.models || {};
    renderInto(el, `
      <div class="grid cards mb">
        <div class="card stat"><div class="value">${fmtNumber(d.total)}</div><div class="label">Dokumente gesamt</div></div>
        <div class="card stat"><div class="value">${fmtNumber(d.processed)}</div><div class="label">Verarbeitet</div></div>
        <div class="card stat"><div class="value">${fmtNumber(d.failed)}</div><div class="label">Fehlgeschlagen</div></div>
        <div class="card stat"><div class="value">${fmtNumber(d.pending)}</div><div class="label">Ausstehend</div></div>
        <div class="card stat"><div class="value">${fmtNumber(j.total)}</div><div class="label">Aufträge (${fmtNumber(j.running)} laufen)</div></div>
        <div class="card stat"><div class="value">${fmtNumber(t.chunks)}</div><div class="label">Chunks</div></div>
        <div class="card stat"><div class="value">${fmtNumber(t.tokens)}</div><div class="label">Tokens (geschätzt)</div></div>
        <div class="card stat"><div class="value">${fmtNumber(m.active)}/${fmtNumber(m.total)}</div><div class="label">Aktive Modelle</div></div>
      </div>
      <section class="card" aria-labelledby="dash-jobs">
        <h2 id="dash-jobs">Letzte Aufträge</h2>
        ${jobsTable(jobs.jobs || [])}
      </section>
    `);
    rowActivation(el, 'tr[data-job]', tr => openJob(tr.dataset.job));
  }

  function jobsTable(jobs) {
    if (!jobs.length) return '<p class="empty">Keine Aufträge vorhanden.</p>';
    return `<div class="table-wrap"><table>
      <thead><tr><th scope="col">Name</th><th scope="col">Quelle</th><th scope="col">Modell</th><th scope="col">Status</th><th scope="col">Dokumente</th><th scope="col">Erstellt</th></tr></thead>
      <tbody>${jobs.map(j => `<tr class="clickable" tabindex="0" data-job="${esc(j.job_id)}" aria-label="Auftrag ${esc(j.name)} öffnen">
        <td>${esc(j.name)}</td>
        <td class="mono">${esc(j.source_directory)}</td>
        <td class="mono">${esc(j.embedding_model)}</td>
        <td>${statusBadge(j.status)}</td>
        <td>${fmtNumber(j.documents_processed)}/${fmtNumber(j.documents_total)}</td>
        <td>${fmtDate(j.created_at)}</td>
      </tr>`).join('')}</tbody>
    </table></div>`;
  }

  async function renderDocuments(el) {
    const r = await api('/api/documents?limit=200');
    renderInto(el, `
      <div class="card">
        <div class="flex mb">
          <label for="doc-filter" class="visually-hidden">Dokumente filtern</label>
          <input id="doc-filter" type="search" class="grow" placeholder="Nach Dateiname/Pfad filtern…" maxlength="200">
          <span id="doc-count" class="muted" role="status" aria-live="polite"></span>
        </div>
        <div class="table-wrap"><table>
          <thead><tr><th scope="col">Datei</th><th scope="col">Pfad</th><th scope="col">Status</th><th scope="col">Chunks</th><th scope="col">Größe</th><th scope="col">Modell</th><th scope="col"><span class="visually-hidden">Aktionen</span></th></tr></thead>
          <tbody id="doc-rows"></tbody>
        </table></div>
      </div>`);
    const setRows = docs => {
      $('#doc-rows', el).innerHTML = docRows(docs);
      $('#doc-count', el).textContent = fmtNumber(docs.length) + ' Treffer';
    };
    setRows(r.documents || []);

    let timer;
    let seq = 0;
    $('#doc-filter', el).addEventListener('input', e => {
      clearTimeout(timer);
      timer = setTimeout(async () => {
        const mine = ++seq;
        try {
          const r2 = await api('/api/documents?limit=200&search=' + enc(e.target.value));
          if (mine === seq) setRows(r2.documents || []); // ignore out-of-order responses
        } catch (err) { notify(err.message, 'error'); }
      }, 250);
    });

    // FIX: event delegation - the previous per-row listeners were lost after the filter re-rendered the rows.
    const tbody = $('#doc-rows', el);
    rowActivation(tbody, 'tr[data-doc]', tr => showDocument(tr.dataset.doc));
    tbody.addEventListener('click', ev => {
      const b = ev.target.closest('button[data-del]');
      if (b) deleteDocument(b.dataset.del, b.dataset.name, b);
    });
  }

  function docRows(docs) {
    if (!docs.length) return '<tr><td colspan="7" class="muted">Keine Dokumente gefunden.</td></tr>';
    return docs.map(d => `<tr class="clickable" tabindex="0" data-doc="${esc(d.document_id)}" aria-label="Dokument ${esc(d.filename)} anzeigen">
      <td>${esc(d.filename)}</td>
      <td class="mono">${esc(d.relative_path)}</td>
      <td>${statusBadge(d.processing_status)}</td>
      <td>${fmtNumber(d.chunk_count)}</td>
      <td>${fmtBytes(d.file_size)}</td>
      <td class="mono">${esc(d.embedding_model || '–')}</td>
      <td><button class="btn btn-danger btn-sm" type="button" data-del="${esc(d.document_id)}" data-name="${esc(d.filename)}" aria-label="${esc(d.filename)} löschen">Löschen</button></td>
    </tr>`).join('');
  }

  async function showDocument(id) {
    openModal('Dokument', '<div class="spinner">Lade…</div>');
    let d;
    let meta = {};
    let vectors = [];
    try {
      const [docRes, metaRes, vecRes] = await Promise.all([
        api('/api/documents/' + enc(id)),
        api('/api/documents/' + enc(id) + '/metadata').catch(() => ({ metadata: {} })),
        api('/api/documents/' + enc(id) + '/vectors').catch(() => ({ vectors: [] })),
      ]);
      d = docRes.document || {};
      meta = metaRes.metadata || {};
      vectors = vecRes.vectors || [];
    } catch (e) {
      $('#modal-body').innerHTML = '<p class="muted">Fehler: ' + esc(e.message) + '</p>';
      return;
    }

    const s = d.source || {};
    const versions = d.versions || [];
    const chunks = d.chunks || [];
    const metaEntries = Object.entries(meta);
    const tabs = [
      ['overview', 'Übersicht'],
      ['versions', 'Versionen (' + versions.length + ')'],
      ['chunks', 'Chunks (' + chunks.length + ')'],
      ['vectors', 'Vektoren (' + vectors.length + ')'],
      ['source', 'Quelle'],
      ['metadata', 'Metadaten (' + metaEntries.length + ')'],
    ];
    const html = `
      <div class="tabs" id="doc-tabs" role="tablist" aria-label="Dokumentdetails">
        ${tabs.map(([key, label], i) => `<button class="tab${i === 0 ? ' active' : ''}" type="button" role="tab"
          id="tab-${key}" data-tab="${key}" aria-controls="panel-${key}" aria-selected="${i === 0}" tabindex="${i === 0 ? 0 : -1}">${esc(label)}</button>`).join('')}
      </div>

      <div class="tab-panel active" id="panel-overview" role="tabpanel" aria-labelledby="tab-overview" data-panel="overview">
        <div class="grid cards mb">
          <div class="card stat"><div class="value">${esc(s.original_filename || d.filename)}</div><div class="label">Originaldatei</div></div>
          <div class="card stat"><div class="value">${fmtBytes(s.file_size)}</div><div class="label">Originalgröße</div></div>
          <div class="card stat"><div class="value">${esc(d.embedding_model || '–')}</div><div class="label">Modell</div></div>
          <div class="card stat"><div class="value">${statusBadge(d.processing_status)}</div><div class="label">Status</div></div>
        </div>
        <div class="flex mb">
          ${s.source_available ? `<a class="btn btn-primary" href="/api/documents/${enc(d.document_id)}/download">Original herunterladen</a>` : '<span class="muted">Original nicht verfügbar</span>'}
        </div>
        <dl class="kv">
          <dt>Dokument-ID</dt><dd class="mono">${esc(d.document_id)}</dd>
          <dt>Versions-ID</dt><dd class="mono">${esc(d.document_version_id)}</dd>
          <dt>Version</dt><dd>${fmtNumber(d.version)}</dd>
          <dt>SHA-256</dt><dd class="mono">${esc(s.sha256 || d.file_hash)}</dd>
          <dt>Pfad</dt><dd class="mono">${esc(d.relative_path)}</dd>
          <dt>Seiten</dt><dd>${fmtNumber(d.page_count)}</dd>
          <dt>Chunks</dt><dd>${fmtNumber(d.chunk_count)}</dd>
          ${d.error_message ? `<dt>Fehler</dt><dd>${esc(d.error_message)}</dd>` : ''}
        </dl>
      </div>

      <div class="tab-panel" id="panel-versions" role="tabpanel" aria-labelledby="tab-versions" data-panel="versions">
        ${versions.length ? `<div class="table-wrap"><table>
          <thead><tr><th scope="col">Version</th><th scope="col">Versions-ID</th><th scope="col">Status</th><th scope="col">Chunks</th><th scope="col">Größe</th><th scope="col">Aktuell</th></tr></thead>
          <tbody>${versions.map(v => `<tr>
            <td>${fmtNumber(v.version)}</td>
            <td class="mono">${esc(v.document_version_id)}</td>
            <td>${statusBadge(v.processing_status)}</td>
            <td>${fmtNumber(v.chunk_count)}</td>
            <td>${fmtBytes(v.file_size)}</td>
            <td>${Number(v.is_current) ? '<span aria-label="aktuelle Version">✓</span>' : ''}</td>
          </tr>`).join('')}</tbody></table></div>` : '<p class="empty">Keine Versionen.</p>'}
      </div>

      <div class="tab-panel" id="panel-chunks" role="tabpanel" aria-labelledby="tab-chunks" data-panel="chunks">
        ${chunks.map(c => `<div class="card mb">
          <div class="muted">Chunk ${fmtNumber(c.chunk_index)} · ${fmtNumber(c.token_count)} Tokens · Textlänge ${fmtNumber(c.text_length)} · <span class="mono">${esc(c.chunk_id)}</span></div>
          <p>${esc(String(c.text || '').slice(0, 600))}${String(c.text || '').length > 600 ? '…' : ''}</p>
        </div>`).join('') || '<p class="empty">Keine Chunks.</p>'}
      </div>

      <div class="tab-panel" id="panel-vectors" role="tabpanel" aria-labelledby="tab-vectors" data-panel="vectors">
        ${vectors.length ? `<div class="table-wrap"><table>
          <thead><tr><th scope="col">Index</th><th scope="col">Vektor-ID</th><th scope="col">Chunk-ID</th><th scope="col">Seite</th></tr></thead>
          <tbody>${vectors.map(v => `<tr>
            <td>${fmtNumber(v.chunk_index)}</td>
            <td class="mono">${esc(v.vector_id)}</td>
            <td class="mono">${esc(v.chunk_id)}</td>
            <td>${fmtNumber(v.page_start)}–${fmtNumber(v.page_end)}</td>
          </tr>`).join('')}</tbody></table></div>` : '<p class="empty">Keine Vektoren.</p>'}
      </div>

      <div class="tab-panel" id="panel-source" role="tabpanel" aria-labelledby="tab-source" data-panel="source">
        <dl class="kv">
          <dt>Originaldatei</dt><dd>${esc(s.original_filename || d.filename)}</dd>
          <dt>MIME-Typ</dt><dd>${esc(s.mime_type || d.mime_type)}</dd>
          <dt>Encoding</dt><dd class="mono">${esc(s.encoding || '–')}</dd>
          <dt>Originalgröße</dt><dd>${fmtBytes(s.file_size)}</dd>
          <dt>SHA-256</dt><dd class="mono">${esc(s.sha256 || d.file_hash)}</dd>
          <dt>Quellpfad (Eingabe)</dt><dd class="mono">${esc(d.source_path)}</dd>
          <dt>Relativer Pfad</dt><dd class="mono">${esc(d.relative_path)}</dd>
          <dt>Verfügbar</dt><dd>${s.source_available ? 'Ja' : 'Nein'}</dd>
        </dl>
      </div>

      <div class="tab-panel" id="panel-metadata" role="tabpanel" aria-labelledby="tab-metadata" data-panel="metadata">
        ${metaEntries.length ? `<pre class="code">${esc(JSON.stringify(meta, null, 2))}</pre>` : '<p class="empty">Keine Metadaten.</p>'}
      </div>`;

    $('#modal-title').textContent = 'Dokument: ' + (d.filename || '');
    $('#modal-body').innerHTML = html;
    const tabButtons = $$('#doc-tabs .tab');
    const select = t => {
      tabButtons.forEach(x => {
        const on = x === t;
        x.classList.toggle('active', on);
        x.setAttribute('aria-selected', String(on));
        x.tabIndex = on ? 0 : -1;
      });
      $$('#modal-body .tab-panel').forEach(p => p.classList.toggle('active', p.dataset.panel === t.dataset.tab));
    };
    tabButtons.forEach((t, i) => {
      t.addEventListener('click', () => select(t));
      // Arrow-key navigation per WAI-ARIA tabs pattern.
      t.addEventListener('keydown', ev => {
        let next = null;
        if (ev.key === 'ArrowRight') next = tabButtons[(i + 1) % tabButtons.length];
        if (ev.key === 'ArrowLeft') next = tabButtons[(i - 1 + tabButtons.length) % tabButtons.length];
        if (next) { ev.preventDefault(); select(next); next.focus(); }
      });
    });
    tabButtons[0].focus();
  }

  async function deleteDocument(id, name, btn) {
    if (!confirm('Dokument „' + (name || id) + '“ wirklich löschen?\nAlle Versionen, Chunks und Vektoren in Milvus werden entfernt.')) return;
    await busy(btn, async () => {
      try { await api('/api/documents/' + enc(id), { method: 'DELETE' }); notify('Dokument gelöscht.', 'success'); navigate('documents', true); }
      catch (e) { notify(e.message, 'error'); }
    });
  }

  async function renderBrowse(el) {
    await browse(el, state.browsePath);
  }

  async function browse(el, path) {
    let r;
    try {
      r = await api('/api/browse?path=' + enc(path));
    } catch (e) {
      // Directory vanished: fall back to the root instead of a dead end.
      if (e.status === 404 && path) { state.browsePath = ''; return browse(el, ''); }
      throw e;
    }
    const entries = r.entries || [];
    const current = r.path || '';
    renderInto(el, `
      <div class="card">
        <nav class="mono muted crumbs mb" aria-label="Pfad">${buildCrumbs(current)}</nav>
        <form id="upload-form" class="flex wrap mb">
          <label for="upload-files" class="visually-hidden">Dateien zum Hochladen auswählen</label>
          <input type="file" id="upload-files" name="files" multiple class="w-auto">
          <button class="btn btn-primary" type="submit">Hochladen</button>
          <span class="muted">Ziel: <span class="mono">/${esc(current)}</span></span>
        </form>
        <button class="btn mb" type="button" id="job-here">Auftrag aus diesem Ordner erstellen</button>
        <div class="table-wrap"><table>
          <thead><tr><th scope="col">Name</th><th scope="col">Typ</th><th scope="col">Größe</th><th scope="col">Geändert</th></tr></thead>
          <tbody id="browse-rows">
          ${entries.map(en => `<tr${en.type === 'dir' ? ` class="clickable" tabindex="0" aria-label="Ordner ${esc(en.name)} öffnen"` : ''} data-path="${esc(en.path)}" data-type="${esc(en.type)}">
            <td><span aria-hidden="true">${en.type === 'dir' ? '📁 ' : '📄 '}</span>${esc(en.name)}</td>
            <td>${en.type === 'dir' ? 'Ordner' : 'Datei'}</td>
            <td>${en.type === 'file' ? fmtBytes(en.size) : ''}</td>
            <td>${fmtDate(en.modified_at)}</td>
          </tr>`).join('') || '<tr><td colspan="4" class="muted">Ordner ist leer.</td></tr>'}
          </tbody>
        </table></div>
      </div>`);

    const go = p => { state.browsePath = p; browse(el, p).catch(e => notify(e.message, 'error')); };
    rowActivation($('#browse-rows', el), 'tr[data-type="dir"]', tr => go(tr.dataset.path));
    $$('.crumb', el).forEach(a => a.addEventListener('click', ev => { ev.preventDefault(); go(a.dataset.path); }));

    $('#upload-form', el).addEventListener('submit', async ev => {
      ev.preventDefault();
      const input = $('#upload-files', el);
      if (!input.files.length) { notify('Bitte mindestens eine Datei auswählen.', 'error'); input.focus(); return; }
      const fd = new FormData();
      fd.append('path', current);
      for (const f of input.files) fd.append('files[]', f);
      await busy($('#upload-form button[type=submit]', el), async () => {
        try {
          const res = await api('/api/upload', { method: 'POST', body: fd });
          const n = (res && res.uploaded && res.uploaded.length) || input.files.length;
          notify(fmtNumber(n) + ' Datei(en) hochgeladen.', 'success');
          go(current);
        } catch (e) { notify(e.message, 'error'); }
      });
    });

    $('#job-here', el).addEventListener('click', () => openJobModal(current));
  }

  function buildCrumbs(path) {
    const parts = (path || '').split('/').filter(Boolean);
    let acc = '';
    const items = ['<a href="#browse" class="crumb" data-path="">/ (Wurzel)</a>'];
    parts.forEach((p, i) => {
      acc = acc ? acc + '/' + p : p;
      const last = i === parts.length - 1;
      items.push('<a href="#browse" class="crumb" data-path="' + esc(acc) + '"' + (last ? ' aria-current="location"' : '') + '>' + esc(p) + '</a>');
    });
    return items.join('<span aria-hidden="true"> / </span>');
  }

  async function openJobModal(sourceDir) {
    const opts = await modelOptions('');
    openModal('Auftrag erstellen', `
      <form id="job-form" novalidate>
        <label for="job-name">Name</label><input id="job-name" maxlength="255" placeholder="z. B. Handbuch-Import">
        <label for="job-src">Quellordner (relativ zum Eingabeordner)</label>
        <input id="job-src" class="mono" value="${esc(sourceDir || '')}" placeholder="/" maxlength="1024">
        <div class="checkbox"><input type="checkbox" id="job-rec" checked> <label for="job-rec">Rekursiv durchsuchen</label></div>
        <label for="job-model">Embedding-Modell</label><select id="job-model" required>${opts}</select>
        <div class="flex mt"><button class="btn btn-primary" type="submit" id="job-create">Erstellen</button></div>
      </form>`);
    $('#job-form').addEventListener('submit', async ev => {
      ev.preventDefault();
      const body = {
        name: $('#job-name').value.trim() || 'Import ' + new Date().toLocaleString('de-DE'),
        source_directory: $('#job-src').value.trim(),
        recursive: $('#job-rec').checked,
        embedding_model: $('#job-model').value,
      };
      if (!body.embedding_model) { notify('Bitte ein Modell wählen.', 'error'); $('#job-model').focus(); return; }
      await busy($('#job-create'), async () => {
        try { await api('/api/jobs', { method: 'POST', body }); notify('Auftrag erstellt.', 'success'); closeModal(); location.hash = '#jobs'; navigate('jobs'); }
        catch (e) { notify(e.message, 'error'); }
      });
    });
  }

  async function renderJobs(el) {
    const r = await api('/api/jobs?limit=200');
    const jobs = r.jobs || [];
    renderInto(el, `
      <div class="card">
        <div class="flex mb"><button class="btn btn-primary" type="button" id="job-new">Neuer Auftrag</button></div>
        ${jobsTable(jobs)}
      </div>`);
    $('#job-new', el).addEventListener('click', () => openJobModal(''));
    rowActivation(el, 'tr[data-job]', tr => openJob(tr.dataset.job));
  }

  async function openJob(id) {
    try {
      const r = await api('/api/jobs/' + enc(id));
      showJob(r.job);
    } catch (e) { notify(e.message, 'error'); }
  }

  function showJob(j) {
    const cancellable = ['RUNNING', 'CREATED', 'PENDING', 'PAUSED'].includes(String(j.status).toUpperCase());
    openModal('Auftrag: ' + (j.name || ''), `
      <div class="grid cards mb">
        <div class="card stat"><div class="value">${esc(j.name)}</div><div class="label">Name</div></div>
        <div class="card stat"><div class="value">${statusBadge(j.status)}</div><div class="label">Status</div></div>
        <div class="card stat"><div class="value">${fmtNumber(j.documents_processed)}/${fmtNumber(j.documents_total)}</div><div class="label">Dokumente</div></div>
      </div>
      ${j.error_message ? `<p class="form-error">${esc(j.error_message)}</p>` : ''}
      <details><summary>Rohdaten</summary><pre class="code">${esc(JSON.stringify(j, null, 2))}</pre></details>
      <div class="flex mt">${cancellable ? '<button class="btn btn-danger" type="button" id="job-cancel">Auftrag abbrechen</button>' : ''}</div>`);
    const cancel = $('#job-cancel');
    if (cancel) cancel.addEventListener('click', async () => {
      if (!confirm('Auftrag „' + j.name + '“ wirklich abbrechen?')) return;
      await busy(cancel, async () => {
        try { await api('/api/jobs/' + enc(j.job_id) + '/cancel', { method: 'POST' }); notify('Auftrag abgebrochen.', 'success'); closeModal(); navigate('jobs', true); }
        catch (e) { notify(e.message, 'error'); }
      });
    });
  }

  async function renderSearch(el) {
    renderInto(el, `
      <section class="card" aria-labelledby="search-title">
        <h2 id="search-title">Semantische Suche</h2>
        <form id="q-form" class="flex wrap" role="search">
          <label for="q" class="visually-hidden">Suchanfrage</label>
          <input id="q" type="search" class="grow" placeholder="Suchanfrage eingeben…" maxlength="2000" required>
          <label for="q-limit" class="visually-hidden">Anzahl Treffer</label>
          <select id="q-limit" class="w-auto">
            <option value="5">5</option><option value="10" selected>10</option>
            <option value="20">20</option><option value="50">50</option>
          </select>
          <button class="btn btn-primary" type="submit" id="q-go">Suchen</button>
        </form>
        <div id="q-results" class="mt" aria-live="polite"></div>
      </section>`);
    $('#q', el).focus();
    $('#q-form', el).addEventListener('submit', ev => { ev.preventDefault(); busy($('#q-go', el), runSearch); });

    async function runSearch() {
      const out = $('#q-results', el);
      const query = $('#q', el).value.trim();
      if (!query) { out.innerHTML = '<p class="muted">Bitte einen Suchbegriff eingeben.</p>'; return; }
      out.innerHTML = '<div class="spinner">Suche…</div>';
      try {
        const r = await api('/api/search', { method: 'POST', body: { query, limit: Number($('#q-limit', el).value) } });
        const results = r.results || [];
        if (!results.length) { out.innerHTML = '<p class="empty">Keine Treffer.</p>'; return; }
        // UX: show filename, pages, text snippet and a download link instead of only the raw document ID.
        out.innerHTML = `<p class="muted">${fmtNumber(results.length)} Treffer</p><ol class="hits">${results.map(h => {
          const pages = h.page_start ? ('Seite ' + fmtNumber(h.page_start) + (h.page_end && h.page_end !== h.page_start ? '–' + fmtNumber(h.page_end) : '')) : '';
          const text = String(h.text || '');
          return `<li class="hit">
            <div class="hit-head">
              <strong>${esc(h.filename || h.document_id || 'Unbekannt')}</strong>
              <span class="muted">${esc(pages)}</span>
              <span class="muted">Chunk ${fmtNumber(h.chunk_index)}</span>
              <span class="badge badge-gray" title="Distanz (kleiner = ähnlicher)">${Number(h.distance).toFixed(4)}</span>
              ${h.document_id ? `<button class="btn btn-sm" type="button" data-open-doc="${esc(h.document_id)}">Details</button>` : ''}
              ${h.download_endpoint ? `<a class="btn btn-sm" href="${esc(h.download_endpoint)}">Original</a>` : ''}
            </div>
            ${text ? `<p class="hit-text">${esc(text.slice(0, 500))}${text.length > 500 ? '…' : ''}</p>` : ''}
          </li>`;
        }).join('')}</ol>`;
      } catch (e) { out.innerHTML = '<p class="form-error">Fehler: ' + esc(e.message) + '</p>'; }
    }
    $('#q-results', el).addEventListener('click', ev => {
      const b = ev.target.closest('button[data-open-doc]');
      if (b) showDocument(b.dataset.openDoc);
    });
  }

  async function renderCollections(el) {
    try {
      const r = await api('/api/collections');
      const cols = r.collections || [];
      renderInto(el, `<section class="card" aria-labelledby="col-title"><h2 id="col-title">Milvus-Kollektionen</h2>
        <div class="table-wrap"><table>
          <thead><tr><th scope="col">Name</th><th scope="col"><span class="visually-hidden">Aktionen</span></th></tr></thead>
          <tbody>${cols.map(c => `<tr><td class="mono">${esc(c)}</td>
            <td><button class="btn btn-sm" type="button" data-col="${esc(c)}">Statistik</button></td></tr>`).join('')
            || '<tr><td colspan="2" class="muted">Keine Kollektionen.</td></tr>'}</tbody>
        </table></div></section>`);
      el.addEventListener('click', async ev => {
        const b = ev.target.closest('button[data-col]');
        if (!b) return;
        openModal('Kollektion: ' + b.dataset.col, '<div class="spinner">Lade…</div>');
        try {
          const s = await api('/api/collections/' + enc(b.dataset.col) + '/stats');
          $('#modal-body').innerHTML = '<pre class="code">' + esc(JSON.stringify(s, null, 2)) + '</pre>';
        } catch (e) { $('#modal-body').innerHTML = '<p class="muted">Fehler: ' + esc(e.message) + '</p>'; }
      });
    } catch (e) {
      if (e.status === 401) throw e;
      renderInto(el, '<div class="card"><p class="muted">Milvus nicht erreichbar: ' + esc(e.message) + '</p></div>');
    }
  }

  async function renderStatistics(el) {
    const [stats, ext] = await Promise.all([
      api('/api/statistics'),
      api('/api/statistics/extensions'),
    ]);
    const d = stats.documents || {};
    const t = stats.totals || {};
    const st = stats.storage || {};
    const rows = (ext.extensions || []).map(x =>
      `<tr><td class="mono">${esc(x.extension)}</td><td>${fmtNumber(x.count)}</td><td>${fmtBytes(x.bytes)}</td></tr>`).join('');
    renderInto(el, `
      <div class="grid cards mb">
        <div class="card stat"><div class="value">${fmtNumber(d.total)}</div><div class="label">Dokumente</div></div>
        <div class="card stat"><div class="value">${fmtNumber(d.versions)}</div><div class="label">Versionen</div></div>
        <div class="card stat"><div class="value">${fmtNumber(d.multi_version)}</div><div class="label">Mit mehreren Versionen</div></div>
        <div class="card stat"><div class="value">${fmtNumber(d.processed)}</div><div class="label">Verarbeitet</div></div>
        <div class="card stat"><div class="value">${fmtNumber(d.failed)}</div><div class="label">Fehler</div></div>
        <div class="card stat"><div class="value">${fmtNumber(t.chunks)}</div><div class="label">Chunks</div></div>
      </div>
      <section class="card mb" aria-labelledby="stat-storage">
        <h2 id="stat-storage">Speicher</h2>
        <div class="grid cards">
          <div class="card stat"><div class="value">${fmtBytes(st.original_bytes)}</div><div class="label">Originaldaten</div></div>
          <div class="card stat"><div class="value">${fmtBytes(st.base64_bytes)}</div><div class="label">Base64 (MySQL)</div></div>
          <div class="card stat"><div class="value">${fmtNumber(st.base64_overhead_percent)} %</div><div class="label">Base64-Overhead</div></div>
          <div class="card stat"><div class="value">${fmtBytes(st.mysql_bytes)}</div><div class="label">MySQL (Tabellen)</div></div>
          <div class="card stat"><div class="value">${st.milvus_bytes == null ? '–' : fmtBytes(st.milvus_bytes)}</div><div class="label">Milvus</div></div>
          <div class="card stat"><div class="value">${fmtBytes(st.total_bytes)}</div><div class="label">Gesamt (ohne Milvus)</div></div>
        </div>
        <p class="muted mt">Vektoren: ${fmtNumber(st.vectors ? st.vectors.total : 0)} gesamt, ${fmtNumber(st.vectors ? st.vectors.linked : 0)} verknüpft.</p>
      </section>
      <section class="card mb" aria-labelledby="stat-integrity">
        <div class="flex">
          <h2 id="stat-integrity" class="m-0 grow">Integrität</h2>
          <button class="btn btn-primary btn-sm" type="button" id="integrity-run">Prüfung ausführen</button>
        </div>
        <div id="integrity-result" class="mt" aria-live="polite"><p class="muted">Noch nicht geprüft.</p></div>
      </section>
      <section class="card" aria-labelledby="stat-ext"><h2 id="stat-ext">Dokumente nach Dateiendung</h2>
        <div class="table-wrap"><table>
          <thead><tr><th scope="col">Endung</th><th scope="col">Anzahl</th><th scope="col">Bytes</th></tr></thead>
          <tbody>${rows || '<tr><td colspan="3" class="muted">Keine Daten.</td></tr>'}</tbody>
        </table></div></section>`);

    const runBtn = $('#integrity-run', el);
    runBtn.addEventListener('click', () => busy(runBtn, async () => {
      const out = $('#integrity-result', el);
      out.innerHTML = '<div class="spinner">Prüfe… (kann bei vielen Dokumenten dauern)</div>';
      try {
        const r = await api('/api/integrity');
        const issues = (r.issues || []).map(i => `<li>${esc(i.type)}: ${esc(i.detail || '')}</li>`).join('');
        out.innerHTML = `
          <div class="grid cards">
            <div class="card stat"><div class="value">${fmtNumber(r.inconsistencies)}</div><div class="label">Inkonsistenzen</div></div>
            <div class="card stat"><div class="value">${fmtNumber(r.documents_without_blob)}</div><div class="label">Ohne Original</div></div>
            <div class="card stat"><div class="value">${fmtNumber(r.hash_errors)}</div><div class="label">Hash-Fehler</div></div>
            <div class="card stat"><div class="value">${fmtNumber(r.vectors_without_document)}</div><div class="label">Verwaiste Vektoren</div></div>
            <div class="card stat"><div class="value">${fmtNumber(r.documents_without_vectors)}</div><div class="label">Dokumente ohne Vektoren</div></div>
          </div>
          ${issues ? '<h3>Details</h3><ul>' + issues + '</ul>' : '<p class="muted">Keine Auffälligkeiten.</p>'}
          <p class="muted">Milvus: ${r.milvus_available ? 'erreichbar' : 'nicht erreichbar'} · Vektoren gesamt: ${fmtNumber(r.vectors)}</p>`;
      } catch (e) { out.innerHTML = '<p class="form-error">Fehler: ' + esc(e.message) + '</p>'; }
    }));
  }

  async function renderExport(el) {
    const r = await api('/api/exports');
    const exports = r.exports || [];
    renderInto(el, `
      <div class="grid split">
        <section class="card" aria-labelledby="exp-title">
          <h2 id="exp-title">Exporte</h2>
          <button class="btn btn-primary mb" type="button" id="export-create">Neuen Export erstellen</button>
          <div class="table-wrap"><table>
            <thead><tr><th scope="col">Datei</th><th scope="col">Größe</th><th scope="col">Erstellt</th><th scope="col"><span class="visually-hidden">Aktionen</span></th></tr></thead>
            <tbody>${exports.map(x => `<tr>
              <td class="mono">${esc(x.filename)}</td>
              <td>${fmtBytes(x.file_size)}</td>
              <td>${fmtDate(x.created_at)}</td>
              <td><a class="btn btn-sm" href="/api/exports/${enc(x.export_id)}/download" aria-label="${esc(x.filename)} herunterladen">Download</a></td>
            </tr>`).join('') || '<tr><td colspan="4" class="muted">Keine Exporte.</td></tr>'}</tbody>
          </table></div>
        </section>
        <section class="card" aria-labelledby="imp-title">
          <h2 id="imp-title">Import</h2>
          <p class="muted">Lade ein Export-Archiv (.tar.gz) hoch, um Dokumente, Originale, Chunks und Vektoren vollständig und ID-erhaltend wiederherzustellen – oder füge ein Legacy-Manifest (JSON) ein.</p>
          <label for="import-archive">Export-Archiv (.tar.gz)</label>
          <input type="file" id="import-archive" accept=".tar.gz,.tgz,application/gzip">
          <label for="import-strategy">Konfliktstrategie</label>
          <select id="import-strategy">
            <option value="skip">Überspringen (empfohlen)</option>
            <option value="overwrite">Überschreiben</option>
          </select>
          <div class="flex mt"><button class="btn btn-primary" type="button" id="import-archive-go">Archiv importieren</button></div>
          <hr class="divider">
          <label for="import-manifest">Manifest (JSON)</label>
          <textarea id="import-manifest" class="mono" placeholder='{"documents":[…] }'></textarea>
          <div class="flex mt"><button class="btn btn-primary" type="button" id="import-go">Manifest importieren</button></div>
        </section>
      </div>`);
    const createBtn = $('#export-create', el);
    createBtn.addEventListener('click', () => busy(createBtn, async () => {
      notify('Export wird erstellt – das kann einige Minuten dauern.', 'info');
      try { await api('/api/exports', { method: 'POST' }); notify('Export erstellt.', 'success'); navigate('export', true); }
      catch (e) { notify(e.message, 'error'); }
    }));
    const archiveBtn = $('#import-archive-go', el);
    archiveBtn.addEventListener('click', async () => {
      const input = $('#import-archive', el);
      if (!input.files.length) { notify('Bitte eine .tar.gz-Datei auswählen.', 'error'); input.focus(); return; }
      const strategy = $('#import-strategy', el).value;
      // UX/safety: destructive strategy requires explicit confirmation.
      if (strategy === 'overwrite' && !confirm('Vorhandene Dokumente mit gleicher ID werden überschrieben (inkl. Vektoren). Fortfahren?')) return;
      const fd = new FormData();
      fd.append('archive', input.files[0]);
      fd.append('strategy', strategy);
      await busy(archiveBtn, async () => {
        try {
          const res = await api('/api/import', { method: 'POST', body: fd });
          notify('Import abgeschlossen: ' + fmtNumber(res.imported) + ' importiert, ' + fmtNumber(res.reused || 0) + ' wiederverwendet, ' + fmtNumber(res.skipped || 0) + ' übersprungen.', 'success');
          input.value = '';
        } catch (e) { notify(e.message, 'error'); }
      });
    });
    const manifestBtn = $('#import-go', el);
    manifestBtn.addEventListener('click', async () => {
      let manifest;
      try { manifest = JSON.parse($('#import-manifest', el).value); }
      catch (e) { notify('Ungültiges JSON: ' + e.message, 'error'); return; }
      await busy(manifestBtn, async () => {
        try {
          const res = await api('/api/import', { method: 'POST', body: { manifest } });
          notify('Import abgeschlossen: ' + fmtNumber(res.imported) + ' importiert, ' + fmtNumber(res.skipped) + ' übersprungen.', 'success');
        } catch (e) { notify(e.message, 'error'); }
      });
    });
  }

  async function renderTls(el) {
    let status;
    try { status = await api('/api/tls'); }
    catch (e) { if (e.status === 401) throw e; status = { served: null, records: [] }; }
    const served = status.served;
    const records = status.records || [];
    renderInto(el, `
      <div class="grid split">
        <section class="card" aria-labelledby="tls-cur">
          <h2 id="tls-cur">Aktuell ausgeliefert</h2>
          ${served ? `<div class="muted">Subjekt: <span class="mono">${esc(JSON.stringify(served.subject))}</span></div>
            <div class="muted mt">Gültig bis: ${fmtDate(served.valid_to)}</div>` : '<p class="empty">Kein Zertifikat aktiv (Self-Signed-Fallback wird verwendet).</p>'}
          <h2 class="mt">Zertifikatsdatensätze</h2>
          <div class="table-wrap"><table>
            <thead><tr><th scope="col">CN</th><th scope="col">Typ</th><th scope="col">Schlüssel</th><th scope="col">Aktiv</th><th scope="col"><span class="visually-hidden">Aktionen</span></th></tr></thead>
            <tbody>${records.map(r => `<tr>
              <td class="mono">${esc(r.common_name)}</td>
              <td>${esc(r.kind)}</td>
              <td>${esc(r.key_type)}</td>
              <td>${r.active ? '<span class="badge badge-green">aktiv</span>' : ''}</td>
              <td>${r.has_cert && !r.active ? `<button class="btn btn-sm" type="button" data-act="${esc(r.id)}" data-cn="${esc(r.common_name)}">Aktivieren</button>` : ''}</td>
            </tr>`).join('') || '<tr><td colspan="5" class="muted">Keine Datensätze.</td></tr>'}</tbody>
          </table></div>
        </section>
        <section class="card" aria-labelledby="tls-gen">
          <h2 id="tls-gen">CSR / Self-Signed erzeugen</h2>
          <label for="tls-cn">Common Name (CN)</label><input id="tls-cn" value="localhost" maxlength="64" required>
          <label for="tls-san">SAN (eine pro Zeile, z. B. DNS:example.com oder IP:192.168.1.10)</label>
          <textarea id="tls-san">DNS:localhost</textarea>
          <label for="tls-key">Schlüsseltyp</label>
          <select id="tls-key"><option value="rsa2048">RSA 2048</option><option value="rsa3072" selected>RSA 3072</option><option value="rsa4096">RSA 4096</option><option value="ec256">EC P-256</option><option value="ec384">EC P-384</option></select>
          <div class="flex mt">
            <button class="btn" type="button" id="tls-csr">CSR erzeugen</button>
            <button class="btn btn-primary" type="button" id="tls-self">Self-Signed erzeugen</button>
          </div>
          <div id="tls-out" class="mt"></div>
          <h2 class="mt">Zertifikat importieren (PEM)</h2>
          <label for="tls-import" class="visually-hidden">Zertifikat im PEM-Format</label>
          <textarea id="tls-import" class="mono" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
          <div class="flex mt"><button class="btn" type="button" id="tls-import-go">Importieren</button></div>
        </section>
      </div>`);
    const san = () => $('#tls-san', el).value.split('\n').map(s => s.trim()).filter(Boolean);
    const doGen = (btn, endpoint) => busy(btn, async () => {
      try {
        const r = await api(endpoint, { method: 'POST', body: { common_name: $('#tls-cn', el).value.trim(), san: san(), key_type: $('#tls-key', el).value } });
        const c = r.certificate || {};
        $('#tls-out', el).innerHTML = '<pre class="code">' + esc(c.csr_pem || JSON.stringify(c, null, 2)) + '</pre>';
        notify(c.csr_pem ? 'CSR erzeugt.' : 'Self-Signed-Zertifikat erzeugt. Zum Ausliefern bitte aktivieren.', 'success');
        if (!c.csr_pem) navigate('tls', true);
      } catch (e) { notify(e.message, 'error'); }
    });
    $('#tls-csr', el).addEventListener('click', ev => doGen(ev.currentTarget, '/api/tls/csr'));
    $('#tls-self', el).addEventListener('click', ev => doGen(ev.currentTarget, '/api/tls/selfsigned'));
    const importBtn = $('#tls-import-go', el);
    importBtn.addEventListener('click', () => busy(importBtn, async () => {
      const pem = $('#tls-import', el).value.trim();
      if (!pem) { notify('Bitte ein PEM-Zertifikat einfügen.', 'error'); return; }
      try { await api('/api/tls/import', { method: 'POST', body: { cert_pem: pem } }); notify('Zertifikat importiert.', 'success'); navigate('tls', true); }
      catch (e) { notify(e.message, 'error'); }
    }));
    el.addEventListener('click', ev => {
      const b = ev.target.closest('button[data-act]');
      if (!b) return;
      // Safety: activation replaces the certificate served by nginx.
      if (!confirm('Zertifikat „' + b.dataset.cn + '“ aktivieren? nginx lädt das Zertifikat automatisch neu; Browser zeigen ggf. eine neue Zertifikatswarnung.')) return;
      busy(b, async () => {
        try { await api('/api/tls/' + enc(b.dataset.act) + '/activate', { method: 'POST' }); notify('Zertifikat aktiviert (nginx lädt automatisch neu).', 'success'); navigate('tls', true); }
        catch (e) { notify(e.message, 'error'); }
      });
    });
  }

  async function renderSettings(el) {
    const r = await api('/api/settings');
    const settings = r.settings || {};
    const keys = Object.keys(settings);
    renderInto(el, `
      <div class="grid split">
        <section class="card" aria-labelledby="set-title"><h2 id="set-title">Einstellungen</h2>
          <form id="settings-form">
          ${keys.length
            ? keys.map((k, i) => `<label for="set-${i}">${esc(k)}</label><input id="set-${i}" name="${esc(k)}" value="${esc(settings[k])}" maxlength="4096">`).join('')
            : '<p class="empty">Keine Einstellungen.</p>'}
          ${keys.length ? '<div class="flex mt"><button class="btn btn-primary" type="submit">Speichern</button></div>' : ''}
          </form>
        </section>
        <section class="card" aria-labelledby="pw-title"><h2 id="pw-title">Passwort ändern</h2>
          <form id="password-form" novalidate>
            <input type="text" name="username" autocomplete="username" value="${esc(state.user ? state.user.username : '')}" class="visually-hidden" tabindex="-1" aria-hidden="true" readonly>
            <label for="pw-current">Aktuelles Passwort</label>
            <input id="pw-current" type="password" autocomplete="current-password" required maxlength="1024">
            <label for="pw-new">Neues Passwort</label>
            <input id="pw-new" type="password" autocomplete="new-password" required minlength="12" maxlength="1024" aria-describedby="pw-hint">
            <p id="pw-hint" class="muted">Mindestens 12 Zeichen, nicht gleich dem Benutzernamen.</p>
            <label for="pw-repeat">Neues Passwort wiederholen</label>
            <input id="pw-repeat" type="password" autocomplete="new-password" required maxlength="1024">
            <p id="pw-error" class="form-error" role="alert"></p>
            <div class="flex mt"><button class="btn btn-primary" type="submit">Passwort ändern</button></div>
          </form>
        </section>
      </div>`);
    const form = $('#settings-form', el);
    form.addEventListener('submit', async ev => {
      ev.preventDefault();
      const values = {};
      $$('input', form).forEach(i => { values[i.name] = i.value; });
      await busy($('button[type=submit]', form), async () => {
        try { await api('/api/settings', { method: 'PUT', body: { settings: values } }); notify('Einstellungen gespeichert.', 'success'); }
        catch (e) { notify(e.message, 'error'); }
      });
    });

    const pwForm = $('#password-form', el);
    pwForm.addEventListener('submit', async ev => {
      ev.preventDefault();
      const err = $('#pw-error', el);
      const current = $('#pw-current', el).value;
      const next = $('#pw-new', el).value;
      err.textContent = '';
      if (!current || !next) { err.textContent = 'Bitte alle Felder ausfüllen.'; return; }
      if (next.length < 12) { err.textContent = 'Das neue Passwort muss mindestens 12 Zeichen lang sein.'; return; }
      if (next !== $('#pw-repeat', el).value) { err.textContent = 'Die neuen Passwörter stimmen nicht überein.'; return; }
      await busy($('button[type=submit]', pwForm), async () => {
        try {
          await api('/api/auth/password', { method: 'POST', body: { current_password: current, new_password: next } });
          pwForm.reset();
          notify('Passwort geändert.', 'success');
        } catch (e) { err.textContent = e.message; }
      });
    });
  }

  async function renderSystem(el) {
    const [info, health, models, metrics] = await Promise.all([
      api('/api/system'), api('/api/health'), api('/api/models'), api('/api/metrics?limit=30'),
    ]);
    const c = health.checks || {};
    const modelsList = models.models || [];
    const app = info.app || {};
    const php = info.php || {};
    renderInto(el, `
      <div class="grid cards mb">
        <div class="card stat"><div class="value">${esc(app.version)}</div><div class="label">App-Version</div></div>
        <div class="card stat"><div class="value">${esc(php.version)}</div><div class="label">PHP</div></div>
        <div class="card stat"><div class="value">${esc(app.timezone)}</div><div class="label">Zeitzone</div></div>
        <div class="card stat"><div class="value">${health.status === 'ok' ? 'OK' : 'Eingeschränkt'}</div><div class="label">Gesamtzustand</div></div>
      </div>
      <div class="grid split mb">
        <section class="card" aria-labelledby="sys-svc">
          <h2 id="sys-svc">Dienste</h2>
          <div class="table-wrap"><table>
            <tbody>
              <tr><th scope="row">Datenbank</th><td>${badgeForHealth(c.database)}</td></tr>
              <tr><th scope="row">Embedding</th><td>${badgeForHealth(c.embedding)}</td></tr>
              <tr><th scope="row">Converter</th><td>${badgeForHealth(c.converter)}</td></tr>
              <tr><th scope="row">Milvus</th><td>${badgeForHealth(c.milvus)}</td></tr>
            </tbody></table></div>
        </section>
        <section class="card" aria-labelledby="sys-paths">
          <h2 id="sys-paths">Speicherpfade</h2>
          <pre class="code">${esc(JSON.stringify(info.storage, null, 2))}</pre>
        </section>
      </div>
      <section class="card mb" aria-labelledby="sys-models">
        <div class="flex"><h2 id="sys-models" class="m-0 grow">Embedding-Modelle</h2>
          <button class="btn" type="button" id="models-sync">Synchronisieren</button></div>
        <div class="table-wrap mt"><table>
          <thead><tr><th scope="col">Name</th><th scope="col">Dimension</th><th scope="col">Max. Tokens</th><th scope="col">Metrik</th><th scope="col">Aktiv</th><th scope="col"><span class="visually-hidden">Aktionen</span></th></tr></thead>
          <tbody>${modelsList.map(m => `<tr>
            <td class="mono">${esc(m.name)}</td>
            <td>${fmtNumber(m.dimension)}</td>
            <td>${fmtNumber(m.max_input_tokens)}</td>
            <td>${esc(m.distance_metric)}</td>
            <td>${m.active ? '<span class="badge badge-green">aktiv</span>' : ''}</td>
            <td>${m.active ? '' : `<button class="btn btn-sm" type="button" data-model="${esc(m.name)}">Aktivieren</button>`}</td>
          </tr>`).join('') || '<tr><td colspan="6" class="muted">Keine Modelle synchronisiert.</td></tr>'}</tbody>
        </table></div>
      </section>
      <section class="card" aria-labelledby="sys-metrics">
        <h2 id="sys-metrics">System-Metriken</h2>
        <div class="table-wrap"><table>
          <thead><tr><th scope="col">Service</th><th scope="col">Metrik</th><th scope="col">Wert</th><th scope="col">Zeitpunkt</th></tr></thead>
          <tbody>${(metrics.metrics || []).map(m => `<tr>
            <td>${esc(m.service)}</td><td>${esc(m.metric)}</td>
            <td>${Number(m.value).toFixed(2)}</td><td>${fmtDate(m.recorded_at)}</td>
          </tr>`).join('') || '<tr><td colspan="4" class="muted">Keine Metriken.</td></tr>'}</tbody>
        </table></div>
      </section>`);
    const syncBtn = $('#models-sync', el);
    syncBtn.addEventListener('click', () => busy(syncBtn, async () => {
      try { const r = await api('/api/models/sync', { method: 'POST' }); notify(fmtNumber(r.synced) + ' Modelle synchronisiert.', 'success'); navigate('system', true); }
      catch (e) { notify(e.message, 'error'); }
    }));
    el.addEventListener('click', ev => {
      const b = ev.target.closest('button[data-model]');
      if (!b) return;
      busy(b, async () => {
        try { await api('/api/models/activate', { method: 'POST', body: { name: b.dataset.model } }); notify('Modell aktiviert.', 'success'); navigate('system', true); }
        catch (e) { notify(e.message, 'error'); }
      });
    });
  }

  /* ---------- health polling ---------- */
  async function pollHealth() {
    if (!state.user || document.hidden) return;
    try {
      const h = await api('/api/health');
      $('#health-dot').className = 'dot ' + (h.status === 'ok' ? 'dot-ok' : 'dot-degraded');
      $('#health-label').textContent = h.status === 'ok' ? 'System bereit' : 'Eingeschränkt';
    } catch (e) {
      if (e.status === 401) return;
      $('#health-dot').className = 'dot dot-error';
      $('#health-label').textContent = 'Nicht erreichbar';
    }
  }

  function startPolling() {
    stopPolling();
    pollHealth();
    state.pollTimer = setInterval(pollHealth, 15000);
  }

  function stopPolling() {
    if (state.pollTimer) clearInterval(state.pollTimer);
    state.pollTimer = null;
  }

  /* ---------- init ---------- */
  async function init() {
    $('#login-form').addEventListener('submit', onLogin);
    $('#logout-btn').addEventListener('click', onLogout);
    $('#refresh-btn').addEventListener('click', () => navigate(state.currentView, true));
    $('#modal-close').addEventListener('click', closeModal);
    $('#modal-backdrop').addEventListener('click', ev => { if (ev.target === $('#modal-backdrop')) closeModal(); });
    document.addEventListener('keydown', modalKeydown);
    $('#nav-toggle').addEventListener('click', () => {
      const open = $('#nav').classList.toggle('open');
      $('#nav-toggle').setAttribute('aria-expanded', String(open));
    });
    window.addEventListener('hashchange', () => {
      const v = (location.hash || '#dashboard').slice(1);
      if (VIEWS[v] && state.user) navigate(v);
    });

    let me = null;
    try { me = await api('/api/auth/me'); } catch (e) { me = null; }
    if (me && me.authenticated) {
      state.user = me.user;
      showApp();
    } else {
      showLogin(me ? '' : 'Server nicht erreichbar. Bitte später erneut versuchen.');
    }
  }

  document.addEventListener('DOMContentLoaded', init);
})();
