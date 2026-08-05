<?php
/**
 * Extension Status - markup, styling and browser code.
 *
 * Required by extensionstatus.php at the very end of the request, and reads
 * these from the caller's scope:
 *   $es_rows            row data (see es_build_rows)
 *   $es_actions_public  brand -> action -> {label, confirm, danger}
 *   $es_csrf            CSRF token for the NOTIFY endpoint
 *   $es_json_flags      json_encode flags safe for embedding in <script>
 *   $es_mode            'uri' or 'endpoint' - how a NOTIFY is addressed
 *   $es_verify_enabled, $es_verify_window
 *   $es_refresh_seconds, $es_refresh_default_on, $es_showdebug, $astman
 *
 * The table body is built in JS with textContent rather than innerHTML: the
 * User-Agent is supplied by whatever registers, so it is attacker-controlled.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Extension Status</title>
<style>
  :root {
    --accent: #4CAF50;
    --border: #ddd;
    --hover: #f5f5f5;
    --muted: #777;
    --danger: #c62828;
  }
  html { height: 100%; }
  /* Column layout so the table area owns the vertical scrolling. That is what
     lets the header stick: a sticky th needs a scroll container to stick
     within, and if the page itself scrolls the header scrolls away with it. */
  body {
    margin: 0;
    padding: 16px;
    box-sizing: border-box;
    height: 100vh;
    display: flex;
    flex-direction: column;
    font: 14px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    color: #222;
  }
  .toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
  }
  .toolbar input[type="search"] {
    padding: 7px 10px;
    border: 1px solid var(--border);
    border-radius: 4px;
    min-width: 240px;
    font-size: 14px;
  }
  .toolbar label { display: flex; align-items: center; gap: 6px; }
  .spacer { flex: 1; }
  .meta { color: var(--muted); font-size: 13px; }
  .mode {
    font-size: 12px;
    padding: 3px 9px;
    border-radius: 10px;
    background: #eee;
    color: #555;
  }
  .mode.endpoint { background: #fff8e1; color: #7a5b00; }

  /* min-height:0 lets a flex child actually shrink and scroll. */
  .wrap {
    flex: 1 1 auto;
    min-height: 0;
    overflow: auto;
  }
  table { width: 100%; border-collapse: collapse; }
  th {
    position: sticky;
    top: 0;
    z-index: 2;
    height: 44px;
    text-align: left;
    background-color: var(--accent);
    color: #000;
    padding: 8px 12px;
    white-space: nowrap;
    /* border-collapse drops borders on sticky cells, so draw the edge with an
       inset shadow instead - otherwise rows bleed into the header when
       scrolled underneath it. */
    box-shadow: inset 0 -1px 0 rgba(0, 0, 0, .18);
  }
  th.sortable { cursor: pointer; user-select: none; }
  th.sortable:hover { filter: brightness(1.07); }
  th .arrow { opacity: .35; font-size: 11px; }
  th.sorted .arrow { opacity: 1; }
  td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--border);
    vertical-align: top;
  }
  tbody tr:hover { background-color: var(--hover); }

  .pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 12px;
    background: #eee;
  }
  .pill.udp { background: #e3f2fd; }
  .pill.tcp { background: #e8f5e9; }
  .pill.tls { background: #ede7f6; }
  .pill.ws, .pill.wss { background: #fff8e1; }
  .status-reachable { color: #2e7d32; }
  .status-unreachable, .status-unknown, .status-unregistered { color: var(--danger); }
  .status-unregistered { font-weight: 600; }
  tbody tr.down td { background: #fffafa; }
  tbody tr.down:hover td { background: #fff2f2; }

  .ips { white-space: nowrap; font-size: 13px; }
  .ips b { font-weight: 600; }
  .ips .none { color: var(--muted); }

  .actions { white-space: nowrap; }
  .actions button {
    font-size: 12px;
    padding: 5px 9px;
    margin-right: 4px;
    border: 1px solid #bbb;
    border-radius: 4px;
    background: #fafafa;
    cursor: pointer;
  }
  .actions button:hover:not(:disabled) { background: #f0f0f0; }
  .actions button:disabled { opacity: .5; cursor: default; }
  .actions button.danger { border-color: #e0b4b4; color: var(--danger); }
  .actions .dash { color: var(--muted); }
  .actions .verify {
    display: block;
    margin-top: 7px;
    font-size: 12px;
    white-space: normal;
    max-width: 230px;
  }
  .actions .verify.pending { color: var(--muted); }
  .actions .verify.good    { color: #2e7d32; }
  .actions .verify.bad     { color: var(--danger); }
  .actions .verify .spin {
    display: inline-block;
    width: 9px; height: 9px;
    margin-right: 5px;
    border: 2px solid #bbb;
    border-top-color: transparent;
    border-radius: 50%;
    animation: spin .7s linear infinite;
    vertical-align: -1px;
  }
  @keyframes spin { to { transform: rotate(360deg); } }

  #toast {
    position: fixed;
    right: 16px;
    bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    z-index: 10;
  }
  #toast div {
    padding: 10px 14px;
    border-radius: 4px;
    color: #fff;
    background: #333;
    box-shadow: 0 2px 8px rgba(0,0,0,.25);
    max-width: 380px;
  }
  #toast div.ok { background: #2e7d32; }
  #toast div.err { background: var(--danger); }

  /* Confirmation dialog for the destructive NOTIFY actions. */
  #modal[hidden] { display: none; }
  #modal {
    position: fixed;
    inset: 0;
    z-index: 20;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
  }
  #modal .backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,.45);
  }
  #modal .card {
    position: relative;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 12px 40px rgba(0,0,0,.35);
    width: 100%;
    max-width: 460px;
    overflow: hidden;
    animation: modal-in .12s ease-out;
  }
  @keyframes modal-in {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: none; }
  }
  #modal h2 {
    margin: 0;
    padding: 16px 20px;
    font-size: 16px;
    border-bottom: 1px solid var(--border);
  }
  #modal.is-danger h2 { color: var(--danger); }
  #modal .body { padding: 16px 20px; }
  #modal dl {
    margin: 0 0 14px;
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 4px 12px;
    font-size: 13px;
  }
  #modal dt { color: var(--muted); }
  #modal dd { margin: 0; }
  #modal .note { margin: 0; font-size: 13px; color: #444; }
  #modal .warn {
    margin: 12px 0 0;
    padding: 9px 12px;
    border-radius: 4px;
    background: #fdeaea;
    color: var(--danger);
    font-size: 13px;
  }
  #modal .foot {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 12px 20px 16px;
  }
  #modal .foot button {
    padding: 8px 16px;
    font-size: 14px;
    border-radius: 4px;
    border: 1px solid #bbb;
    background: #fafafa;
    cursor: pointer;
  }
  #modal .foot button:hover { background: #f0f0f0; }
  #modal .foot button:focus-visible { outline: 2px solid #1976d2; outline-offset: 2px; }
  #modal .foot button.go {
    border-color: var(--accent);
    background: var(--accent);
    color: #000;
  }
  #modal .foot button.go:hover { filter: brightness(1.06); }
  #modal.is-danger .foot button.go {
    border-color: var(--danger);
    background: var(--danger);
    color: #fff;
  }

  .empty { padding: 24px; color: var(--muted); }
  pre.debug { background: #f7f7f7; padding: 12px; overflow-x: auto; }
</style>
</head>
<body>

<div class="toolbar">
  <input type="search" id="filter" placeholder="Filter (extension, name, brand, IP...)" autocomplete="off">
  <label><input type="checkbox" id="autorefresh"<?php echo $es_refresh_default_on ? ' checked' : ''; ?>> Auto-refresh every <?php echo (int) $es_refresh_seconds; ?>s</label>
  <button type="button" id="refreshnow">Refresh now</button>
  <span class="mode <?php echo es_h($es_mode); ?>" id="mode" title="<?php
    echo $es_mode === 'uri'
      ? 'A NOTIFY is addressed to the contact URI, so only the handset whose row you click is reached.'
      : 'A NOTIFY is addressed to the extension, so it reaches every device registered to it. Set $es_notify_target to "uri" to reach one handset at a time.';
  ?>"><?php
    echo $es_mode === 'uri' ? 'NOTIFY: this handset only' : 'NOTIFY: whole extension';
  ?></span>
  <div class="spacer"></div>
  <span class="meta" id="meta"></span>
</div>

<div class="wrap">
  <table>
    <thead><tr id="head"></tr></thead>
    <tbody id="body"></tbody>
  </table>
  <div class="empty" id="empty" hidden>No registered contacts.</div>
</div>

<div id="toast"></div>

<div id="modal" hidden>
  <div class="backdrop" data-dismiss></div>
  <div class="card" role="dialog" aria-modal="true" aria-labelledby="modal-title" aria-describedby="modal-body">
    <h2 id="modal-title"></h2>
    <div class="body" id="modal-body"></div>
    <div class="foot">
      <button type="button" id="modal-cancel" data-dismiss>Cancel</button>
      <button type="button" id="modal-go" class="go"></button>
    </div>
  </div>
</div>

<?php if ($es_showdebug): ?>
<h3>Raw AMI response</h3>
<pre class="debug"><?php echo es_h(print_r(es_fetch_contacts($astman), true)); ?></pre>
<?php endif; ?>

<script>
(function () {
  "use strict";

  var CSRF     = <?php echo json_encode($es_csrf, $es_json_flags); ?>;
  // Brand -> action id -> {label, confirm, danger}. The NOTIFY headers stay
  // server-side; the browser only ever names an action.
  var ACTIONS  = <?php echo json_encode($es_actions_public, $es_json_flags); ?>;
  var INTERVAL = <?php echo (int) $es_refresh_seconds; ?> * 1000;
  var VERIFY_WINDOW  = <?php echo (int) $es_verify_window; ?>;
  // False when no access log is readable by the web server. That is a
  // supported setup, so the page just never offers verification - no warning.
  var VERIFY_ENABLED = <?php echo $es_verify_enabled ? 'true' : 'false'; ?>;
  // 'uri' reaches one handset, 'endpoint' reaches every device on the
  // extension. The server decides; this only words the confirmation right.
  var MODE     = <?php echo json_encode($es_mode, $es_json_flags); ?>;
  var rows     = <?php echo json_encode($es_rows, $es_json_flags); ?>;

  var COLUMNS = [
    { key: 'aor',        label: 'Extension',            type: 'num'  },
    { key: 'name',       label: 'Name',                 type: 'text' },
    { key: 'brand',      label: 'Brand',                type: 'text' },
    { key: 'model',      label: 'Model',                type: 'text' },
    { key: 'firmware',   label: 'Firmware',             type: 'text' },
    { key: 'transport',  label: 'Transport',            type: 'text' },
    { key: 'status',     label: 'Status',               type: 'text' },
    { key: 'rtt_ms',     label: 'Response Time',        type: 'num'  },
    { key: 'ips',        label: 'Known Device IPs',     sort: false  },
    { key: 'expire',     label: 'Registration Expires', type: 'num'  },
    { key: 'actions',    label: 'Actions',              sort: false  }
  ];

  // Spelled out in the dialog so the consequence is stated before the click,
  // not inferred from the button label.
  var WARNINGS = {
    restart: 'The phone reboots immediately. Any call in progress on it drops.',
    reset:   'The phone is reset to factory defaults. Local contacts, account '
             + 'settings and any manual configuration on the handset are erased.'
  };

  var sortKey = 'aor', sortAsc = true, filter = '';
  var verifyLines = {};   // contact URI -> the status line under its buttons
  var $head = document.getElementById('head');
  var $body = document.getElementById('body');
  var $empty = document.getElementById('empty');
  var $meta = document.getElementById('meta');
  var timer = null;

  function el(tag, text, cls) {
    var n = document.createElement(tag);
    // textContent throughout: the User-Agent is attacker-controlled.
    if (text !== undefined && text !== null) { n.textContent = String(text); }
    if (cls) { n.className = cls; }
    return n;
  }

  function toast(msg, ok) {
    var box = document.getElementById('toast');
    var t = el('div', msg, ok ? 'ok' : 'err');
    box.appendChild(t);
    setTimeout(function () { t.remove(); }, 6000);
  }

  // How many devices this click actually reaches, in words.
  function blastRadius(r) {
    if (MODE === 'endpoint' && r.siblings > 1) {
      return 'This reaches ALL ' + r.siblings + ' devices registered to extension '
             + r.aor + ', not just this one.';
    }
    if (MODE !== 'endpoint' && r.siblings > 1) {
      return 'Only this handset. Extension ' + r.aor + ' has ' + r.siblings
             + ' registered devices; the others are not touched.';
    }
    return 'This is the only device registered to extension ' + r.aor + '.';
  }

  // -- confirmation dialog --------------------------------------------------
  // Resolves true if confirmed, false otherwise. Cancel is focused on open and
  // Escape dismisses, so the destructive actions need a deliberate click.
  var $modal  = document.getElementById('modal');
  var $mTitle = document.getElementById('modal-title');
  var $mBody  = document.getElementById('modal-body');
  var $mGo    = document.getElementById('modal-go');
  var $mCancel = document.getElementById('modal-cancel');
  var modalState = null;

  function closeModal(result) {
    if (!modalState) { return; }
    var s = modalState;
    modalState = null;
    $modal.hidden = true;
    $modal.classList.remove('is-danger');
    document.removeEventListener('keydown', onModalKey, true);
    if (s.origin && document.contains(s.origin)) { s.origin.focus(); }
    s.resolve(result);
  }

  function onModalKey(e) {
    if (!modalState) { return; }
    if (e.key === 'Escape') { e.preventDefault(); closeModal(false); return; }
    if (e.key !== 'Tab') { return; }
    // Keep focus inside the dialog.
    var f = [$mCancel, $mGo];
    var i = f.indexOf(document.activeElement);
    e.preventDefault();
    var next = e.shiftKey ? (i <= 0 ? f.length - 1 : i - 1) : (i === f.length - 1 ? 0 : i + 1);
    f[next].focus();
  }

  function confirmAction(opts) {
    return new Promise(function (resolve) {
      closeModal(false);
      modalState = { resolve: resolve, origin: opts.origin || null };

      $mTitle.textContent = opts.title;
      $mGo.textContent = opts.confirmLabel || 'Confirm';
      $modal.classList.toggle('is-danger', !!opts.danger);

      $mBody.textContent = '';
      var dl = document.createElement('dl');
      (opts.details || []).forEach(function (pair) {
        dl.appendChild(el('dt', pair[0]));
        dl.appendChild(el('dd', pair[1]));
      });
      $mBody.appendChild(dl);
      if (opts.note) { $mBody.appendChild(el('p', opts.note, 'note')); }
      if (opts.warning) { $mBody.appendChild(el('p', opts.warning, 'warn')); }

      $modal.hidden = false;
      document.addEventListener('keydown', onModalKey, true);
      $mCancel.focus();
    });
  }

  $mGo.addEventListener('click', function () { closeModal(true); });
  $modal.addEventListener('click', function (e) {
    if (e.target.hasAttribute('data-dismiss')) { closeModal(false); }
  });

  function renderHead() {
    $head.textContent = '';
    COLUMNS.forEach(function (c) {
      var th = el('th', c.label);
      if (c.sort !== false) {
        th.className = 'sortable' + (sortKey === c.key ? ' sorted' : '');
        var a = el('span', sortKey === c.key ? (sortAsc ? ' ▲' : ' ▼') : ' ▵', 'arrow');
        th.appendChild(a);
        th.addEventListener('click', function () {
          if (sortKey === c.key) { sortAsc = !sortAsc; } else { sortKey = c.key; sortAsc = true; }
          render();
        });
      }
      $head.appendChild(th);
    });
  }

  function matches(r) {
    if (!filter) { return true; }
    var hay = [r.aor, r.name, r.brand, r.model, r.firmware, r.transport,
               r.status, r.uri_ip, r.via_ip, r.callid_ip, r.useragent]
              .join(' ').toLowerCase();
    return hay.indexOf(filter) !== -1;
  }

  function compare(a, b) {
    var col = COLUMNS.filter(function (c) { return c.key === sortKey; })[0];
    var av = a[sortKey], bv = b[sortKey];
    var r;
    if (col && col.type === 'num') {
      var an = av === null || av === '' ? NaN : Number(av);
      var bn = bv === null || bv === '' ? NaN : Number(bv);
      // Fall back to a string compare when the value is not numeric (e.g. a
      // non-numeric extension), and sort blanks last either way.
      if (isNaN(an) && isNaN(bn)) { r = String(av || '').localeCompare(String(bv || '')); }
      else if (isNaN(an)) { return 1; }
      else if (isNaN(bn)) { return -1; }
      else { r = an - bn; }
    } else {
      r = String(av === null ? '' : av).localeCompare(String(bv === null ? '' : bv));
    }
    return sortAsc ? r : -r;
  }

  function ipCell(r) {
    var td = el('td', null, 'ips');
    if (!r.registered) {
      // Nothing is registered, so there are no addresses to report - three
      // lines of "Not an IP" would be noise.
      td.appendChild(el('span', '—', 'none'));
      return td;
    }
    [['URI', r.uri_ip], ['Via', r.via_ip], ['CallID', r.callid_ip]].forEach(function (pair) {
      td.appendChild(el('b', pair[0] + ':'));
      td.appendChild(document.createTextNode(' '));
      if (pair[1] === 'Not an IP') {
        td.appendChild(el('span', pair[1], 'none'));
      } else {
        td.appendChild(document.createTextNode(pair[1]));
      }
      td.appendChild(document.createElement('br'));
    });
    return td;
  }

  function actionCell(r) {
    var td = el('td', null, 'actions');
    var available = ACTIONS[r.brand];
    if (!available) {
      // Softphone or unrecognised brand - nothing sensible to send it.
      td.appendChild(el('span', '—', 'dash'));
      return td;
    }
    Object.keys(available).forEach(function (id) {
      var spec = available[id];
      var b = el('button', spec.label);
      if (spec.danger) { b.className = 'danger'; }
      b.addEventListener('click', function () {
        if (!spec.confirm) { sendNotify(r, id, b); return; }
        // Name the specific handset, and say how many devices are actually
        // reached - that depends on the addressing mode, not just the row.
        confirmAction({
          title: spec.label,
          confirmLabel: spec.label,
          danger: spec.danger,
          origin: b,
          details: [
            ['Extension', r.aor + (r.name ? ' — ' + r.name : '')],
            ['Device',    (r.brand + ' ' + r.model).trim()],
            ['Address',   r.uri_ip + ' · ' + r.transport]
          ],
          note: blastRadius(r) + ' The phone typically acts on it within ~10s.',
          warning: WARNINGS[id] || null
        }).then(function (go) {
          if (go) { sendNotify(r, id, b); }
        });
      });
      td.appendChild(b);
    });
    // Re-attach any verification result for this contact. Appending an existing
    // node moves it, so it survives the auto-refresh rebuilding the table.
    if (verifyLines[r.uri]) { td.appendChild(verifyLines[r.uri]); }
    return td;
  }

  // After a NOTIFY, poll the server until the handset shows up in the access
  // log re-fetching its config. That is the only end-to-end confirmation there
  // is: Asterisk reports the NOTIFY dispatched, not that the phone acted.
  // Only ever runs on demand - never on page load or auto-refresh.
  function watchForFetch(r, cell) {
    var old = verifyLines[r.uri];
    if (old) { old.remove(); }

    var line = el('span', null, 'verify pending');
    // Kept by contact URI so the auto-refresh re-render can put it back rather
    // than discarding a verification that is still running.
    verifyLines[r.uri] = line;
    line.appendChild(el('span', null, 'spin'));
    line.appendChild(document.createTextNode('Verifying…'));
    cell.appendChild(line);

    var since = Math.floor(Date.now() / 1000);
    var deadline = since + VERIFY_WINDOW;
    var timer = setInterval(poll, 2000);
    poll();

    function stop(cls, text) {
      clearInterval(timer);
      line.className = 'verify ' + cls;
      line.textContent = text;
    }

    // Verification became unavailable (log rotated away from us, or turned
    // off). Not the operator's problem mid-click - remove the line silently.
    function abandon() {
      clearInterval(timer);
      line.remove();
      delete verifyLines[r.uri];
    }

    function poll() {
      if (Math.floor(Date.now() / 1000) > deadline) {
        stop('bad', '✕ No config fetch in ' + VERIFY_WINDOW + 's');
        return;
      }
      var q = '?action=verify&ip=' + encodeURIComponent(r.uri_ip) +
              '&since=' + since;
      fetch(window.location.pathname + q, { credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (j) {
          if (!j.ok || j.readable === false) { abandon(); return; }
          if (j.seen) { stop('good', '✓ Config fetched ' + j.at); }
        })
        .catch(function () { /* transient - the deadline ends it */ });
    }
  }

  function sendNotify(r, actionId, button) {
    var siblings = button.parentNode.querySelectorAll('button');
    Array.prototype.forEach.call(siblings, function (b) { b.disabled = true; });
    // Immediate feedback while the request is in flight.
    var restore = button.textContent;
    button.textContent = 'Sending…';

    var body = new URLSearchParams();
    body.set('action', 'notify');
    body.set('csrf', CSRF);
    body.set('uri', r.uri);
    body.set('action_id', actionId);

    fetch(window.location.pathname, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      credentials: 'same-origin'
    })
    .then(function (res) { return res.json().catch(function () {
      throw new Error('HTTP ' + res.status);
    }); })
    .then(function (j) {
      // Asterisk confirms dispatch, not delivery - say only what is true.
      toast(j.message, j.ok);
      if (j.ok && VERIFY_ENABLED) { watchForFetch(r, button.parentNode); }
    })
    .catch(function (e) {
      toast('Request failed: ' + e.message, false);
    })
    .finally(function () {
      button.textContent = restore;
      Array.prototype.forEach.call(siblings, function (b) { b.disabled = false; });
    });
  }

  function render() {
    renderHead();
    var view = rows.filter(matches).slice().sort(compare);

    $body.textContent = '';
    view.forEach(function (r) {
      var tr = document.createElement('tr');
      if (!r.registered) { tr.className = 'down'; }
      tr.appendChild(el('td', r.aor));
      tr.appendChild(el('td', r.name));
      tr.appendChild(el('td', r.brand));
      tr.appendChild(el('td', r.model));
      tr.appendChild(el('td', r.firmware));

      var tt = el('td');
      if (r.transport) {
        tt.appendChild(el('span', r.transport, 'pill ' + r.transport.toLowerCase()));
      }
      tr.appendChild(tt);

      tr.appendChild(el('td', r.status, 'status-' + String(r.status).toLowerCase()));
      tr.appendChild(el('td', r.rtt_ms === null ? '-' : r.rtt_ms + ' ms'));
      tr.appendChild(ipCell(r));
      tr.appendChild(el('td', r.expire_str));
      tr.appendChild(actionCell(r));
      $body.appendChild(tr);
    });

    $empty.hidden = view.length !== 0;
    var down = view.filter(function (r) { return !r.registered; }).length;
    $meta.textContent = view.length + ' of ' + rows.length + ' device' +
                        (rows.length === 1 ? '' : 's') +
                        (down ? ' · ' + down + ' unregistered' : '');
  }

  function refresh() {
    fetch(window.location.pathname + '?action=data', { credentials: 'same-origin' })
      .then(function (res) {
        if (res.status === 403) { throw new Error('session expired - reload the page'); }
        return res.json();
      })
      .then(function (j) {
        if (!j.ok) { throw new Error(j.message || 'refresh failed'); }
        rows = j.rows;
        render();
        $meta.textContent += ' · updated ' + j.generated;
      })
      .catch(function (e) {
        toast('Refresh failed: ' + e.message, false);
        stopTimer();
        document.getElementById('autorefresh').checked = false;
      });
  }

  function startTimer() { stopTimer(); timer = setInterval(refresh, INTERVAL); }
  function stopTimer() { if (timer) { clearInterval(timer); timer = null; } }

  document.getElementById('filter').addEventListener('input', function (e) {
    filter = e.target.value.trim().toLowerCase();
    render();
  });
  document.getElementById('refreshnow').addEventListener('click', refresh);
  document.getElementById('autorefresh').addEventListener('change', function (e) {
    if (e.target.checked) { startTimer(); } else { stopTimer(); }
  });

  render();
  if (document.getElementById('autorefresh').checked) { startTimer(); }
})();
</script>
</body>
</html>
