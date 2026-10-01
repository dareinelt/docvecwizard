/* Document Embedding Manager - single-page UI (vanilla JS, no frameworks/CDN). */
(function () {
  'use strict';

  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

  const state = {
    csrf: null,
    health: null,
    currentView: 'dashboard',
    browsePath: '',
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
    return d.toLocaleString('de-DE');
  }

  function fmtNumber(n) {
    return Number(n || 0).toLocaleString('de-DE');
  }

  function statusBadge(status) {
    const s = String(status || '').toUpperCase();
    const map = {
      COMPLETED: 'badge-green', RUNNING: 'badge-blue', CREATED: 'badge-gray',
      PENDING: 'badge-gray', PROCESSING: 'badge-blue', DISCOVERED: 'badge-gray',
      FAILED: 'badge-red', CANCELLED: 'badge-amber', IMPORTED: 'badge-green',
      PAUSED: 'badge-amber',
    };
    return '<span class="badge ' + (map[s] || 'badge-gray') + '">' + esc(status || '–') + '</span>';
  }

  async function api(path, opts) {
    opts = opts || {};
    const method = (opts.method || 'GET').toUpperCase();
    const headers = Object.assign({ Accept: 'application/json' }, opts.headers || {});
    if (method !== 'GET' && state.csrf) headers['X-CSRF-Token'] = state.csrf;
    let body = opts.body;
    if (body != null && !(body instanceof FormData) && typeof body === 'object') {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(body);
    }
    const res = await fetch(path, Object.assign({}, opts, { method, headers, body }));
    const text = await res.text();
    let data = null;
    try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
    if (!res.ok) {
      const msg = (data && data.error) || ('HTTP ' + res.status);
      throw new Error(msg);
    }
    return data;
  }

  function notify(msg, type) {
    const el = document.createElement('div');
    el.className = 'toast ' + (type || '');
    el.textContent = msg;
    $('#toast-root').appendChild(el);
    setTimeout(() => el.remove(), 4000);
  }

  function openModal(title, html) {
    $('#modal-title').textContent = title;
    $('#modal-body').innerHTML = html;
    $('#modal-backdrop').classList.remove('hidden');
  }
  function closeModal() { $('#modal-backdrop').classList.add('hidden'); }

  function renderInto(el, html) { el.innerHTML = html; }

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

  function navigate(view) {
    state.currentView = view;
    $$('#nav a').forEach(a => a.classList.toggle('active', a.dataset.view === view));
    $('#view-title').textContent = TITLES[view] || view;
    const viewEl = $('#view');
    viewEl.innerHTML = '<div class="spinner">Lade…</div>';
    const fn = VIEWS[view] || renderDashboard;
    Promise.resolve(fn(viewEl)).catch(err => {
      viewEl.innerHTML = '<div class="card"><p class="muted">Fehler: ' + esc(err.message) + '</p></div>';
    });
  }

  /* ---------- shared widgets ---------- */
  async function modelOptions(selected) {
    let models = [];
    try { const r = await api('/api/models'); models = r.models || []; } catch (e) { models = []; }
    if (!models.length) {
      return '<option value="">Keine Modelle – bitte unter „System“ synchronisieren</option>';
    }
    return models.map(m =>
      '<option value="' + esc(m.name) + '"' + (m.name === selected ? ' selected' : '') + '>' +
      esc(m.name) + ' (' + esc(m.dimension) + ' dim' + (m.active ? ', aktiv' : '') + ')</option>'
    ).join('');
  }

  function badgeForHealth(ok) {
    return ok ? '<span class="badge badge-green">OK</span>' : '<span class="badge badge-red">Fehler</span>';
  }

  /* ---------- views ---------- */
  async function renderDashboard(el) {
    const stats = await api('/api/statistics');
    const jobs = await api('/api/jobs?limit=5');
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
      <div class="card">
        <h2>Letzte Aufträge</h2>
        ${jobsTable(jobs.jobs || [])}
      </div>
    `);
  }

  function jobsTable(jobs) {
    if (!jobs.length) return '<p class="empty">Keine Aufträge vorhanden.</p>';
    return `<div class="table-wrap"><table>
      <thead><tr><th>Name</th><th>Quelle</th><th>Modell</th><th>Status</th><th>Dokumente</th><th>Erstellt</th></tr></thead>
      <tbody>${jobs.map(j => `<tr class="clickable" data-job="${esc(j.job_id)}">
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
    const docs = r.documents || [];
    renderInto(el, `
      <div class="card">
        <div class="flex mb">
          <input id="doc-filter" class="grow" placeholder="Nach Dateiname/Pfad filtern…">
        </div>
        <div class="table-wrap"><table>
          <thead><tr><th>Datei</th><th>Pfad</th><th>Status</th><th>Chunks</th><th>Größe</th><th>Modell</th><th></th></tr></thead>
          <tbody id="doc-rows">${docRows(docs)}</tbody>
        </table></div>
      </div>`);
    let timer;
    $('#doc-filter').addEventListener('input', e => {
      clearTimeout(timer);
      timer = setTimeout(async () => {
        const q = encodeURIComponent(e.target.value);
        const r2 = await api('/api/documents?limit=200&search=' + q);
        $('#doc-rows').innerHTML = docRows(r2.documents || []);
      }, 250);
    });
    $$('#doc-rows tr[data-doc]').forEach(tr => {
      tr.addEventListener('click', ev => {
        if (ev.target.closest('button')) return;
        showDocument(tr.dataset.doc);
      });
    });
    $$('#doc-rows button[data-del]').forEach(b => b.addEventListener('click', ev => {
      ev.stopPropagation();
      deleteDocument(b.dataset.del);
    }));
  }

  function docRows(docs) {
    if (!docs.length) return '<tr><td colspan="7" class="muted">Keine Dokumente gefunden.</td></tr>';
    return docs.map(d => `<tr class="clickable" data-doc="${esc(d.document_id)}">
      <td>${esc(d.filename)}</td>
      <td class="mono">${esc(d.relative_path)}</td>
      <td>${statusBadge(d.processing_status)}</td>
      <td>${fmtNumber(d.chunk_count)}</td>
      <td>${fmtBytes(d.file_size)}</td>
      <td class="mono">${esc(d.embedding_model || '–')}</td>
      <td><button class="btn btn-danger btn-sm" data-del="${esc(d.document_id)}">Löschen</button></td>
    </tr>`).join('');
  }

  async function showDocument(id) {
    openModal('Dokument', '<div class="spinner">Lade…</div>');
    let d;
    let meta = {};
    let vectors = [];
    try {
      const [docRes, metaRes, vecRes] = await Promise.all([
        api('/api/documents/' + id),
        api('/api/documents/' + id + '/metadata').catch(() => ({ metadata: {} })),
        api('/api/documents/' + id + '/vectors').catch(() => ({ vectors: [] })),
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
    const html = `
      <div class="tabs" id="doc-tabs">
        <button class="tab active" data-tab="overview">Übersicht</button>
        <button class="tab" data-tab="versions">Versionen (${versions.length})</button>
        <button class="tab" data-tab="chunks">Chunks (${chunks.length})</button>
        <button class="tab" data-tab="vectors">Vektoren (${vectors.length})</button>
        <button class="tab" data-tab="source">Quelle</button>
        <button class="tab" data-tab="metadata">Metadaten (${metaEntries.length})</button>
      </div>

      <div class="tab-panel active" data-panel="overview">
        <div class="grid cards mb">
          <div class="card stat"><div class="value">${esc(s.original_filename || d.filename)}</div><div class="label">Originaldatei</div></div>
          <div class="card stat"><div class="value">${fmtBytes(s.file_size)}</div><div class="label">Originalgröße</div></div>
          <div class="card stat"><div class="value">${esc(d.embedding_model || '–')}</div><div class="label">Modell</div></div>
          <div class="card stat"><div class="value">${statusBadge(d.processing_status)}</div><div class="label">Status</div></div>
        </div>
        <div class="flex mb">
          ${s.source_available ? `<a class="btn btn-primary" href="/api/documents/${esc(d.document_id)}/download">Original herunterladen</a>` : '<span class="muted">Original nicht verfügbar</span>'}
        </div>
        <dl class="kv">
          <dt>Dokument-ID</dt><dd class="mono">${esc(d.document_id)}</dd>
          <dt>Versions-ID</dt><dd class="mono">${esc(d.document_version_id)}</dd>
          <dt>Version</dt><dd>${fmtNumber(d.version)}</dd>
          <dt>SHA-256</dt><dd class="mono">${esc(s.sha256 || d.file_hash)}</dd>
          <dt>Pfad</dt><dd class="mono">${esc(d.relative_path)}</dd>
          <dt>Seiten</dt><dd>${fmtNumber(d.page_count)}</dd>
          <dt>Chunks</dt><dd>${fmtNumber(d.chunk_count)}</dd>
        </dl>
      </div>

      <div class="tab-panel" data-panel="versions">
        ${versions.length ? `<div class="table-wrap"><table>
          <thead><tr><th>Version</th><th>Versions-ID</th><th>Status</th><th>Chunks</th><th>Größe</th><th>Aktuell</th></tr></thead>
          <tbody>${versions.map(v => `<tr>
            <td>${fmtNumber(v.version)}</td>
            <td class="mono">${esc(v.document_version_id)}</td>
            <td>${statusBadge(v.processing_status)}</td>
            <td>${fmtNumber(v.chunk_count)}</td>
            <td>${fmtBytes(v.file_size)}</td>
            <td>${Number(v.is_current) ? '✓' : ''}</td>
          </tr>`).join('')}</tbody></table></div>` : '<p class="empty">Keine Versionen.</p>'}
      </div>

      <div class="tab-panel" data-panel="chunks">
        ${chunks.map(c => `<div class="card mb">
          <div class="muted">Chunk ${fmtNumber(c.chunk_index)} · ${fmtNumber(c.token_count)} Tokens · Textlänge ${fmtNumber(c.text_length)} · <span class="mono">${esc(c.chunk_id)}</span></div>
          <p>${esc(String(c.text || '').slice(0, 600))}${(c.text || '').length > 600 ? '…' : ''}</p>
        </div>`).join('') || '<p class="empty">Keine Chunks.</p>'}
      </div>

      <div class="tab-panel" data-panel="vectors">
        ${vectors.length ? `<div class="table-wrap"><table>
          <thead><tr><th>Index</th><th>Vektor-ID</th><th>Chunk-ID</th><th>Seite</th></tr></thead>
          <tbody>${vectors.map(v => `<tr>
            <td>${fmtNumber(v.chunk_index)}</td>
            <td class="mono">${esc(v.vector_id)}</td>
            <td class="mono">${esc(v.chunk_id)}</td>
            <td>${fmtNumber(v.page_start)}–${fmtNumber(v.page_end)}</td>
          </tr>`).join('')}</tbody></table></div>` : '<p class="empty">Keine Vektoren.</p>'}
      </div>

      <div class="tab-panel" data-panel="source">
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

      <div class="tab-panel" data-panel="metadata">
        ${metaEntries.length ? `<pre class="code">${esc(JSON.stringify(meta, null, 2))}</pre>` : '<p class="empty">Keine Metadaten.</p>'}
      </div>`;

    $('#modal-body').innerHTML = html;
    $$('#doc-tabs .tab').forEach(t => t.addEventListener('click', () => {
      $$('#doc-tabs .tab').forEach(x => x.classList.toggle('active', x === t));
      $$('#modal-body .tab-panel').forEach(p => p.classList.toggle('active', p.dataset.panel === t.dataset.tab));
    }));
  }

  async function deleteDocument(id) {
    if (!confirm('Dokument wirklich löschen? (inkl. Vektoren in Milvus)')) return;
    try { await api('/api/documents/' + id, { method: 'DELETE' }); notify('Dokument gelöscht.', 'success'); navigate('documents'); }
    catch (e) { notify(e.message, 'error'); }
  }

  async function renderBrowse(el) {
    await browse(el, state.browsePath);
  }

  async function browse(el, path) {
    const r = await api('/api/browse?path=' + encodeURIComponent(path));
    const entries = r.entries || [];
    const crumbs = buildCrumbs(r.path || '');
    renderInto(el, `
      <div class="card">
        <div class="flex mb">
          <nav class="mono muted" id="crumbs" style="flex-wrap:wrap">${crumbs}</nav>
        </div>
        <form id="upload-form" class="mb">
          <input type="file" name="files" multiple style="width:auto">
          <button class="btn btn-primary" type="submit">Hochladen</button>
        </form>
        <button class="btn mb" id="job-here">Auftrag aus diesem Ordner erstellen</button>
        <div class="table-wrap"><table>
          <thead><tr><th>Name</th><th>Typ</th><th>Größe</th><th>Geändert</th></tr></thead>
          <tbody>
          ${entries.map(en => `<tr class="clickable" data-path="${esc(en.path)}" data-type="${esc(en.type)}">
            <td>${en.type === 'dir' ? '📁 ' : '📄 '}${esc(en.name)}</td>
            <td>${esc(en.type)}</td>
            <td>${en.type === 'file' ? fmtBytes(en.size) : ''}</td>
            <td>${fmtDate(en.modified_at)}</td>
          </tr>`).join('') || '<tr><td colspan="4" class="muted">Ordner ist leer.</td></tr>'}
          </tbody>
        </table></div>
      </div>`);

    $$('#view tbody tr[data-path]').forEach(tr => tr.addEventListener('click', () => {
      if (tr.dataset.type === 'dir') { state.browsePath = tr.dataset.path; browse(el, tr.dataset.path); }
    }));

    $('#upload-form').addEventListener('submit', async ev => {
      ev.preventDefault();
      const input = $('input[type=file]');
      if (!input.files.length) return;
      const fd = new FormData();
      fd.append('path', path);
      for (const f of input.files) fd.append('files[]', f);
      try { await api('/api/upload', { method: 'POST', body: fd }); notify('Hochgeladen.', 'success'); browse(el, path); }
      catch (e) { notify(e.message, 'error'); }
    });

    $('#job-here').addEventListener('click', () => openJobModal(path));
  }

  function buildCrumbs(path) {
    const parts = (path || '').split('/').filter(Boolean);
    let acc = '';
    const items = ['<a href="#" class="crumb" data-path="">/ (root)</a>'];
    parts.forEach(p => {
      acc = acc ? acc + '/' + p : p;
      items.push('<a href="#" class="crumb" data-path="' + esc(acc) + '">' + esc(p) + '</a>');
    });
    const html = items.join(' / ');
    setTimeout(() => $$('#crumbs .crumb').forEach(a => a.addEventListener('click', ev => {
      ev.preventDefault();
      state.browsePath = a.dataset.path;
      browse($('#view'), a.dataset.path);
    })), 0);
    return html;
  }

  async function openJobModal(sourceDir) {
    const opts = await modelOptions('');
    openModal('Auftrag erstellen', `
      <label>Name</label><input id="job-name" placeholder="z. B. Handbuch-Import">
      <label>Quellordner (relativ zu ${esc('INPUT_ROOT')})</label>
      <input id="job-src" class="mono" value="${esc(sourceDir || '')}" placeholder="/">
      <div class="checkbox"><input type="checkbox" id="job-rec" checked> <span>Rekursiv durchsuchen</span></div>
      <label>Embedding-Modell</label><select id="job-model">${opts}</select>
      <div class="flex mt"><button class="btn btn-primary" id="job-create">Erstellen</button></div>`);
    $('#job-create').addEventListener('click', async () => {
      const body = {
        name: $('#job-name').value || 'Import ' + new Date().toLocaleString('de-DE'),
        source_directory: $('#job-src').value || '',
        recursive: $('#job-rec').checked,
        embedding_model: $('#job-model').value,
      };
      if (!body.embedding_model) { notify('Bitte ein Modell wählen.', 'error'); return; }
      try { await api('/api/jobs', { method: 'POST', body }); notify('Auftrag erstellt.', 'success'); closeModal(); navigate('jobs'); }
      catch (e) { notify(e.message, 'error'); }
    });
  }

  async function renderJobs(el) {
    const r = await api('/api/jobs?limit=200');
    const jobs = r.jobs || [];
    renderInto(el, `
      <div class="card">
        <div class="flex mb"><button class="btn btn-primary" id="job-new">Neuer Auftrag</button></div>
        ${jobsTable(jobs)}
      </div>`);
    $('#job-new').addEventListener('click', () => openJobModal(''));
    $$('#view tr[data-job]').forEach(tr => tr.addEventListener('click', async () => {
      const r2 = await api('/api/jobs/' + tr.dataset.job);
      showJob(r2.job);
    }));
  }

  function showJob(j) {
    openModal('Auftrag', `
      <div class="grid cards mb">
        <div class="card stat"><div class="value">${esc(j.name)}</div><div class="label">Name</div></div>
        <div class="card stat"><div class="value">${statusBadge(j.status)}</div><div class="label">Status</div></div>
        <div class="card stat"><div class="value">${fmtNumber(j.documents_processed)}/${fmtNumber(j.documents_total)}</div><div class="label">Dokumente</div></div>
      </div>
      <pre class="code">${esc(JSON.stringify(j, null, 2))}</pre>
      <div class="flex mt">${['RUNNING','CREATED','PENDING','PAUSED'].includes(j.status)
        ? '<button class="btn btn-danger" id="job-cancel">Abbrechen</button>' : ''}</div>`);
    const cancel = $('#job-cancel');
    if (cancel) cancel.addEventListener('click', async () => {
      try { await api('/api/jobs/' + j.job_id + '/cancel', { method: 'POST' }); notify('Auftrag abgebrochen.', 'success'); closeModal(); navigate('jobs'); }
      catch (e) { notify(e.message, 'error'); }
    });
  }

  async function renderSearch(el) {
    renderInto(el, `
      <div class="card">
        <h2>Semantische Suche</h2>
        <div class="flex">
          <input id="q" class="grow" placeholder="Suchanfrage eingeben…">
          <select id="q-limit" style="width:auto">
            <option value="5">5</option><option value="10" selected>10</option>
            <option value="20">20</option><option value="50">50</option>
          </select>
          <button class="btn btn-primary" id="q-go">Suchen</button>
        </div>
        <div id="q-results" class="mt"></div>
      </div>`);
    $('#q-go').addEventListener('click', runSearch);
    $('#q').addEventListener('keydown', e => { if (e.key === 'Enter') runSearch(); });
    async function runSearch() {
      $('#q-results').innerHTML = '<div class="spinner">Suche…</div>';
      try {
        const r = await api('/api/search', { method: 'POST', body: { query: $('#q').value, limit: Number($('#q-limit').value) } });
        const results = r.results || [];
        if (!results.length) { $('#q-results').innerHTML = '<p class="empty">Keine Treffer.</p>'; return; }
        $('#q-results').innerHTML = `<div class="table-wrap"><table>
          <thead><tr><th>#</th><th>Distanz</th><th>Dokument</th><th>Chunk</th></tr></thead>
          <tbody>${results.map((h, i) => `<tr>
            <td>${i + 1}</td>
            <td class="mono">${Number(h.distance).toFixed(4)}</td>
            <td class="mono">${esc(h.document_id || '')}</td>
            <td>${fmtNumber(h.chunk_index)}</td>
          </tr>`).join('')}</tbody>
        </table></div>`;
      } catch (e) { $('#q-results').innerHTML = '<p class="muted">Fehler: ' + esc(e.message) + '</p>'; }
    }
  }

  async function renderCollections(el) {
    try {
      const r = await api('/api/collections');
      const cols = r.collections || [];
      renderInto(el, `<div class="card"><h2>Milvus-Kollektionen</h2>
        <div class="table-wrap"><table>
          <thead><tr><th>Name</th><th></th></tr></thead>
          <tbody>${cols.map(c => `<tr><td class="mono">${esc(c)}</td>
            <td><button class="btn btn-sm" data-col="${esc(c)}">Statistik</button></td></tr>`).join('')
            || '<tr><td class="muted">Keine Kollektionen.</td></tr>'}</tbody>
        </table></div></div>`);
      $$('#view button[data-col]').forEach(b => b.addEventListener('click', async () => {
        openModal('Kollektion', '<div class="spinner">Lade…</div>');
        try {
          const s = await api('/api/collections/' + encodeURIComponent(b.dataset.col) + '/stats');
          $('#modal-body').innerHTML = '<pre class="code">' + esc(JSON.stringify(s, null, 2)) + '</pre>';
        } catch (e) { $('#modal-body').innerHTML = '<p class="muted">Fehler: ' + esc(e.message) + '</p>'; }
      }));
    } catch (e) {
      renderInto(el, '<div class="card"><p class="muted">Milvus nicht erreichbar: ' + esc(e.message) + '</p></div>');
    }
  }

  async function renderStatistics(el) {
    const [stats, ext] = await Promise.all([
      api('/api/statistics'),
      api('/api/statistics/extensions'),
    ]);
    const d = stats.documents || {};
    const j = stats.jobs || {};
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
      <div class="card mb">
        <h2>Speicher</h2>
        <div class="grid cards">
          <div class="card stat"><div class="value">${fmtBytes(st.original_bytes)}</div><div class="label">Originaldaten</div></div>
          <div class="card stat"><div class="value">${fmtBytes(st.base64_bytes)}</div><div class="label">Base64 (MySQL)</div></div>
          <div class="card stat"><div class="value">${fmtNumber(st.base64_overhead_percent)} %</div><div class="label">Base64-Overhead</div></div>
          <div class="card stat"><div class="value">${fmtBytes(st.mysql_bytes)}</div><div class="label">MySQL (Tabellen)</div></div>
          <div class="card stat"><div class="value">${st.milvus_bytes == null ? '–' : fmtBytes(st.milvus_bytes)}</div><div class="label">Milvus</div></div>
          <div class="card stat"><div class="value">${fmtBytes(st.total_bytes)}</div><div class="label">Gesamt (ohne Milvus)</div></div>
        </div>
        <p class="muted mt">Vektoren: ${fmtNumber(st.vectors ? st.vectors.total : 0)} gesamt, ${fmtNumber(st.vectors ? st.vectors.linked : 0)} verknüpft.</p>
      </div>
      <div class="card mb">
        <div class="flex">
          <h2 style="margin:0">Integrität</h2>
          <button class="btn btn-primary btn-sm" id="integrity-run">Prüfung ausführen</button>
        </div>
        <div id="integrity-result" class="mt"><p class="muted">Noch nicht geprüft.</p></div>
      </div>
      <div class="card"><h2>Dokumente nach Dateiendung</h2>
        <div class="table-wrap"><table>
          <thead><tr><th>Endung</th><th>Anzahl</th><th>Bytes</th></tr></thead>
          <tbody>${rows || '<tr><td colspan="3" class="muted">Keine Daten.</td></tr>'}</tbody>
        </table></div></div>`);

    $('#integrity-run').addEventListener('click', async () => {
      $('#integrity-result').innerHTML = '<div class="spinner">Prüfe…</div>';
      try {
        const r = await api('/api/integrity');
        const issues = (r.issues || []).map(i => `<li>${esc(i.type)}: ${esc(i.detail || '')}</li>`).join('');
        $('#integrity-result').innerHTML = `
          <div class="grid cards">
            <div class="card stat"><div class="value">${fmtNumber(r.inconsistencies)}</div><div class="label">Inkonsistenzen</div></div>
            <div class="card stat"><div class="value">${fmtNumber(r.documents_without_blob)}</div><div class="label">Ohne Original</div></div>
            <div class="card stat"><div class="value">${fmtNumber(r.hash_errors)}</div><div class="label">Hash-Fehler</div></div>
            <div class="card stat"><div class="value">${fmtNumber(r.vectors_without_document)}</div><div class="label">Verwaiste Vektoren</div></div>
            <div class="card stat"><div class="value">${fmtNumber(r.documents_without_vectors)}</div><div class="label">Dokumente ohne Vektoren</div></div>
          </div>
          ${issues ? '<h3>Details</h3><ul>' + issues + '</ul>' : ''}
          <p class="muted">Milvus: ${r.milvus_available ? 'erreichbar' : 'nicht erreichbar'} · Vektoren gesamt: ${fmtNumber(r.vectors)}</p>`;
      } catch (e) { $('#integrity-result').innerHTML = '<p class="muted">Fehler: ' + esc(e.message) + '</p>'; }
    });
  }

  async function renderExport(el) {
    const r = await api('/api/exports');
    const exports = r.exports || [];
    renderInto(el, `
      <div class="grid split">
        <div class="card">
          <h2>Exporte</h2>
          <button class="btn btn-primary mb" id="export-create">Neuen Export erstellen</button>
          <div class="table-wrap"><table>
            <thead><tr><th>Datei</th><th>Größe</th><th>Erstellt</th><th></th></tr></thead>
            <tbody>${exports.map(x => `<tr>
              <td class="mono">${esc(x.filename)}</td>
              <td>${fmtBytes(x.file_size)}</td>
              <td>${fmtDate(x.created_at)}</td>
              <td><a class="btn btn-sm" href="/api/exports/${esc(x.export_id)}/download">Download</a></td>
            </tr>`).join('') || '<tr><td colspan="4" class="muted">Keine Exporte.</td></tr>'}</tbody>
          </table></div>
        </div>
        <div class="card">
          <h2>Import</h2>
          <p class="muted">Lade ein Export-Archiv (.tar.gz) hoch, um Dokumente, Originale, Chunks und Vektoren vollständig und ID-erhaltend wiederherzustellen – oder füge ein Legacy-Manifest (JSON) ein.</p>
          <label>Export-Archiv (.tar.gz)</label>
          <input type="file" id="import-archive" accept=".tar.gz,.tgz,application/gzip">
          <label>Konfliktstrategie</label>
          <select id="import-strategy">
            <option value="skip">Überspringen (empfohlen)</option>
            <option value="overwrite">Überschreiben</option>
          </select>
          <div class="flex mt"><button class="btn btn-primary" id="import-archive-go">Archiv importieren</button></div>
          <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">
          <label>Manifest (JSON)</label>
          <textarea id="import-manifest" class="mono" placeholder='{"documents":[…] }'></textarea>
          <div class="flex mt"><button class="btn btn-primary" id="import-go">Manifest importieren</button></div>
        </div>
      </div>`);
    $('#export-create').addEventListener('click', async () => {
      try { await api('/api/exports', { method: 'POST' }); notify('Export erstellt.', 'success'); navigate('export'); }
      catch (e) { notify(e.message, 'error'); }
    });
    $('#import-archive-go').addEventListener('click', async () => {
      const input = $('#import-archive');
      if (!input.files.length) { notify('Bitte eine .tar.gz-Datei auswählen.', 'error'); return; }
      const fd = new FormData();
      fd.append('archive', input.files[0]);
      fd.append('strategy', $('#import-strategy').value);
      try {
        const res = await api('/api/import', { method: 'POST', body: fd });
        notify('Import abgeschlossen: ' + fmtNumber(res.imported) + ' importiert, ' + fmtNumber(res.reused || 0) + ' wiederverwendet, ' + fmtNumber(res.skipped || 0) + ' übersprungen.', 'success');
      } catch (e) { notify(e.message, 'error'); }
    });
    $('#import-go').addEventListener('click', async () => {
      let manifest;
      try { manifest = JSON.parse($('#import-manifest').value); }
      catch (e) { notify('Ungültiges JSON: ' + e.message, 'error'); return; }
      try {
        const res = await api('/api/import', { method: 'POST', body: { manifest } });
        notify('Import abgeschlossen: ' + fmtNumber(res.imported) + ' importiert, ' + fmtNumber(res.skipped) + ' übersprungen.', 'success');
      } catch (e) { notify(e.message, 'error'); }
    });
  }

  async function renderTls(el) {
    let status;
    try { status = await api('/api/tls'); } catch (e) { status = { served: null, records: [] }; }
    const served = status.served;
    const records = status.records || [];
    renderInto(el, `
      <div class="grid split">
        <div class="card">
          <h2>Aktuell ausgeliefert</h2>
          ${served ? `<div class="muted">CN: ${esc(JSON.stringify(served.subject))}</div>
            <div class="muted mt">Gültig bis: ${fmtDate(served.valid_to)}</div>` : '<p class="empty">Kein Zertifikat aktiv (Self-Signed-Fallback wird verwendet).</p>'}
          <h2 class="mt">Zertifikatsdatensätze</h2>
          <div class="table-wrap"><table>
            <thead><tr><th>CN</th><th>Typ</th><th>Schlüssel</th><th>Aktiv</th><th></th></tr></thead>
            <tbody>${records.map(r => `<tr>
              <td class="mono">${esc(r.common_name)}</td>
              <td>${esc(r.kind)}</td>
              <td>${esc(r.key_type)}</td>
              <td>${r.active ? '<span class="badge badge-green">aktiv</span>' : ''}</td>
              <td>${r.has_cert ? `<button class="btn btn-sm" data-act="${r.id}">Aktivieren</button>` : ''}</td>
            </tr>`).join('') || '<tr><td colspan="5" class="muted">Keine Datensätze.</td></tr>'}</tbody>
          </table></div>
        </div>
        <div class="card">
          <h2>CSR / Self-Signed erzeugen</h2>
          <label>Common Name (CN)</label><input id="tls-cn" value="localhost">
          <label>SAN (eine pro Zeile, z. B. DNS:example.com oder IP:192.168.1.10)</label>
          <textarea id="tls-san">DNS:localhost</textarea>
          <label>Schlüsseltyp</label>
          <select id="tls-key"><option value="rsa2048">RSA 2048</option><option value="rsa3072" selected>RSA 3072</option><option value="rsa4096">RSA 4096</option><option value="ec256">EC P-256</option><option value="ec384">EC P-384</option></select>
          <div class="flex mt">
            <button class="btn" id="tls-csr">CSR erzeugen</button>
            <button class="btn btn-primary" id="tls-self">Self-Signed erzeugen</button>
          </div>
          <div id="tls-out" class="mt"></div>
          <h2 class="mt">Zertifikat importieren (PEM)</h2>
          <textarea id="tls-import" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
          <div class="flex mt"><button class="btn" id="tls-import-go">Importieren</button></div>
        </div>
      </div>`);
    const san = () => $('#tls-san').value.split('\n').map(s => s.trim()).filter(Boolean);
    const doGen = async (endpoint) => {
      try {
        const r = await api(endpoint, { method: 'POST', body: { common_name: $('#tls-cn').value, san: san(), key_type: $('#tls-key').value } });
        const c = r.certificate || {};
        $('#tls-out').innerHTML = '<pre class="code">' + esc(c.csr_pem || JSON.stringify(c, null, 2)) + '</pre>';
        notify('Erzeugt.', 'success');
      } catch (e) { notify(e.message, 'error'); }
    };
    $('#tls-csr').addEventListener('click', () => doGen('/api/tls/csr'));
    $('#tls-self').addEventListener('click', () => doGen('/api/tls/selfsigned'));
    $('#tls-import-go').addEventListener('click', async () => {
      try { await api('/api/tls/import', { method: 'POST', body: { cert_pem: $('#tls-import').value } }); notify('Importiert.', 'success'); navigate('tls'); }
      catch (e) { notify(e.message, 'error'); }
    });
    $$('#view button[data-act]').forEach(b => b.addEventListener('click', async () => {
      try { await api('/api/tls/' + b.dataset.act + '/activate', { method: 'POST' }); notify('Zertifikat aktiviert (nginx lädt automatisch neu).', 'success'); navigate('tls'); }
      catch (e) { notify(e.message, 'error'); }
    }));
  }

  async function renderSettings(el) {
    const r = await api('/api/settings');
    const settings = r.settings || {};
    renderInto(el, `<div class="card"><h2>Einstellungen</h2>
      <form id="settings-form">
      ${Object.keys(settings).length
        ? Object.keys(settings).map(k => `<label>${esc(k)}</label><input name="${esc(k)}" value="${esc(settings[k])}">`).join('')
        : '<p class="empty">Keine Einstellungen.</p>'}
      <div class="flex mt"><button class="btn btn-primary" type="submit">Speichern</button></div>
      </form></div>`);
    $('#settings-form').addEventListener('submit', async ev => {
      ev.preventDefault();
      const values = {};
      $$('#settings-form input').forEach(i => values[i.name] = i.value);
      try { await api('/api/settings', { method: 'PUT', body: { settings: values } }); notify('Gespeichert.', 'success'); }
      catch (e) { notify(e.message, 'error'); }
    });
  }

  async function renderSystem(el) {
    const [info, health, models, metrics] = await Promise.all([
      api('/api/system'), api('/api/health'), api('/api/models'), api('/api/metrics?limit=30'),
    ]);
    const c = health.checks || {};
    const modelsList = models.models || [];
    renderInto(el, `
      <div class="grid cards mb">
        <div class="card stat"><div class="value">${esc(info.app.version)}</div><div class="label">App-Version</div></div>
        <div class="card stat"><div class="value">${esc(info.php.version)}</div><div class="label">PHP</div></div>
        <div class="card stat"><div class="value">${esc(info.app.timezone)}</div><div class="label">Zeitzone</div></div>
        <div class="card stat"><div class="value">${health.status === 'ok' ? 'OK' : 'Degraded'}</div><div class="label">Gesamtzustand</div></div>
      </div>
      <div class="grid split mb">
        <div class="card">
          <h2>Dienste</h2>
          <div class="table-wrap"><table>
            <tbody>
              <tr><td>Datenbank</td><td>${badgeForHealth(c.database)}</td></tr>
              <tr><td>Embedding</td><td>${badgeForHealth(c.embedding)}</td></tr>
              <tr><td>Converter</td><td>${badgeForHealth(c.converter)}</td></tr>
              <tr><td>Milvus</td><td>${badgeForHealth(c.milvus)}</td></tr>
            </tbody></table></div>
        </div>
        <div class="card">
          <h2>Speicherpfade</h2>
          <pre class="code">${esc(JSON.stringify(info.storage, null, 2))}</pre>
        </div>
      </div>
      <div class="card mb">
        <div class="flex"><h2 style="margin:0" class="grow">Embedding-Modelle</h2>
          <button class="btn" id="models-sync">Synchronisieren</button></div>
        <div class="table-wrap mt"><table>
          <thead><tr><th>Name</th><th>Dimension</th><th>Max. Tokens</th><th>Metrik</th><th>Aktiv</th><th></th></tr></thead>
          <tbody>${modelsList.map(m => `<tr>
            <td class="mono">${esc(m.name)}</td>
            <td>${fmtNumber(m.dimension)}</td>
            <td>${fmtNumber(m.max_input_tokens)}</td>
            <td>${esc(m.distance_metric)}</td>
            <td>${m.active ? '<span class="badge badge-green">aktiv</span>' : ''}</td>
            <td>${m.active ? '' : `<button class="btn btn-sm" data-model="${esc(m.name)}">Aktivieren</button>`}</td>
          </tr>`).join('') || '<tr><td colspan="6" class="muted">Keine Modelle synchronisiert.</td></tr>'}</tbody>
        </table></div>
      </div>
      <div class="card">
        <h2>System-Metriken</h2>
        <div class="table-wrap"><table>
          <thead><tr><th>Service</th><th>Metrik</th><th>Wert</th><th>Zeitpunkt</th></tr></thead>
          <tbody>${(metrics.metrics || []).map(m => `<tr>
            <td>${esc(m.service)}</td><td>${esc(m.metric)}</td>
            <td>${Number(m.value).toFixed(2)}</td><td>${fmtDate(m.recorded_at)}</td>
          </tr>`).join('') || '<tr><td colspan="4" class="muted">Keine Metriken.</td></tr>'}</tbody>
        </table></div>
      </div>`);
    $('#models-sync').addEventListener('click', async () => {
      try { const r = await api('/api/models/sync', { method: 'POST' }); notify(fmtNumber(r.synced) + ' Modelle synchronisiert.', 'success'); navigate('system'); }
      catch (e) { notify(e.message, 'error'); }
    });
    $$('#view button[data-model]').forEach(b => b.addEventListener('click', async () => {
      try { await api('/api/models/activate', { method: 'POST', body: { name: b.dataset.model } }); notify('Modell aktiviert.', 'success'); navigate('system'); }
      catch (e) { notify(e.message, 'error'); }
    }));
  }

  /* ---------- health polling ---------- */
  async function pollHealth() {
    try {
      const h = await api('/api/health');
      state.health = h;
      const dot = $('#health-dot');
      const label = $('#health-label');
      dot.className = 'dot ' + (h.status === 'ok' ? 'dot-ok' : 'dot-degraded');
      label.textContent = h.status === 'ok' ? 'System bereit' : 'Eingeschränkt';
    } catch (e) {
      $('#health-dot').className = 'dot dot-error';
      $('#health-label').textContent = 'Nicht erreichbar';
    }
  }

  /* ---------- init ---------- */
  async function init() {
    $('#refresh-btn').addEventListener('click', () => navigate(state.currentView));
    $('#modal-close').addEventListener('click', closeModal);
    $('#modal-backdrop').addEventListener('click', ev => { if (ev.target === $('#modal-backdrop')) closeModal(); });
    $$('#nav a').forEach(a => a.addEventListener('click', ev => {
      ev.preventDefault();
      location.hash = '#' + a.dataset.view;
    }));
    window.addEventListener('hashchange', () => {
      const v = (location.hash || '#dashboard').slice(1);
      if (VIEWS[v]) navigate(v);
    });
    try { const r = await api('/api/csrf'); state.csrf = r.csrf_token || null; } catch (e) { /* csrf optional */ }
    const initial = (location.hash || '#dashboard').slice(1);
    navigate(VIEWS[initial] ? initial : 'dashboard');
    await pollHealth();
    setInterval(pollHealth, 15000);
  }

  document.addEventListener('DOMContentLoaded', init);
})();
