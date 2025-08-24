<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Plan {{ $plan }} – {{ ucfirst($view) }} (Test)</title>

  <style>
    /* ====== Fonts aus /public/fonts ====== */
    @font-face {
      font-family: 'Poppins';
      src: url('{{ asset('fonts/Poppins-Regular.ttf') }}') format('truetype');
      font-weight: 400;
      font-style: normal;
      font-display: swap;
    }
    @font-face {
      font-family: 'Poppins';
      src: url('{{ asset('fonts/Poppins-Medium.ttf') }}') format('truetype');
      font-weight: 600;
      font-style: normal;
      font-display: swap;
    }
    @font-face {
      font-family: 'Poppins';
      src: url('{{ asset('fonts/Poppins-Bold.ttf') }}') format('truetype');
      font-weight: 800;
      font-style: normal;
      font-display: swap;
    }
    @font-face {
      font-family: 'Uniform';
      src: url('{{ asset('fonts/Uniform-Regular.otf') }}') format('opentype');
      font-weight: 400;
      font-style: normal;
      font-display: swap;
    }
    @font-face {
      font-family: 'Uniform';
      src: url('{{ asset('fonts/Uniform-Bold.otf') }}') format('opentype');
      font-weight: 700;
      font-style: normal;
      font-display: swap;
    }
    @font-face {
      font-family: 'Uniform Condensed';
      src: url('{{ asset('fonts/UniformCondensed-Regular.otf') }}') format('opentype');
      font-weight: 400;
      font-style: normal;
      font-display: swap;
    }

    /* ====== Grund-Styles ====== */
    :root {
      --border: #e5e7eb;
      --bg1: #fafafa;
      --bg2: #f7f7f7;
      --txt-dim: #555;
    }

    body {
      margin: 16px;
      font-family: 'Poppins', system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
      font-weight: 400;
      color: #111827;
    }

    h1 {
      margin: 0 0 6px 0;
      font-family: 'Uniform', 'Poppins', sans-serif;
      font-weight: 700; /* Uniform Bold */
      font-size: 22px;
      line-height: 1.25;
    }

    .hint {
      font-size: 12px;
      color: var(--txt-dim);
      margin: 2px 0 12px 0;
    }

    .buttons {
      margin-bottom: 12px;
    }
    .buttons button {
      appearance: none;
      border: 1px solid var(--border);
      background: #fff;
      padding: 6px 10px;
      border-radius: 6px;
      cursor: pointer;
      margin-right: 6px;
      font-family: inherit;
      font-weight: 400;
    }
    .buttons button:hover { background: var(--bg1); }

    /* ====== Tabelle ====== */
    table {
      border-collapse: collapse;
      width: 100%;
      table-layout: fixed;    /* alle Spalten gleich breit */
      font-family: inherit;   /* Poppins */
      font-size: 14px;
    }
    th, td {
      border: 1px solid var(--border);
      padding: 6px 8px;
      vertical-align: top;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: pre-line;  /* erlaubt Zeilenumbrüche im Text */
      font-weight: normal;    /* kein Bold in Header & Zellen */
    }
    th {
      position: sticky;
      top: 0;
      background: var(--bg1);
      text-align: left;
    }
    .time { font-weight: normal; }

    /* Zebra-Stripes */
    tbody tr:nth-child(odd)  { background: var(--bg1); }
    tbody tr:nth-child(even) { background: var(--bg2); }

    /* Separator-Zeile */
    tr.sep td {
      border: 0;
      height: 8px;
      background: #ffffff;
    }

    /* Belegte Zellen immer weiß (über Stripe) */
    td.filled { background: #ffffff; }

    /* Fehlerausgabe */
    .err {
      margin-top: 12px;
      color: #b00020;
      font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
      white-space: pre-wrap;
      font-size: 12px;
    }
  </style>
</head>
<body>
  <h1>Plan {{ $plan }}</h1>
  <div class="hint">Freie Blöcke werden hier nicht angezeigt, weil sie den Ablauf nicht beeinflussen.</div>

  <div class="buttons">
    <button onclick="switchView('roles')">Rollen</button>
    <button onclick="switchView('teams')">Teams</button>
    <button onclick="switchView('rooms')">Räume</button>
  </div>

  <table id="tbl"><thead></thead><tbody></tbody></table>
  <pre id="err" class="err"></pre>

<script>
  const plan = {{ $plan }};
  const view = "{{ $view }}";

  function switchView(newView) {
    window.location.href = `/test/plans/${plan}/${newView}`;
  }

  (async function() {
    try {
      const resp = await fetch(`/api/test/plans/${plan}/schedule/${view}`);
      if (!resp.ok) {
        document.getElementById('err').textContent = `API error ${resp.status}: ${await resp.text()}`;
        return;
      }
      const data = await resp.json();
      const tbl = document.getElementById('tbl');
      const thead = tbl.querySelector('thead');
      const tbody = tbl.querySelector('tbody');
      thead.innerHTML = '';
      tbody.innerHTML = '';

      // Header
      const trh = document.createElement('tr');
      data.headers.forEach(h => {
        const th = document.createElement('th');
        th.textContent = h.title;
        trh.appendChild(th);
      });
      thead.appendChild(trh);

      const keys = data.headers.map(h => h.key);

      // Rows
      data.rows.forEach(r => {
        if (r.separator) {
          const tr = document.createElement('tr');
          tr.className = 'sep';
          const td = document.createElement('td');
          td.colSpan = data.headers.length;
          td.innerHTML = '&nbsp;';
          tr.appendChild(td);
          tbody.appendChild(tr);
          return;
        }

        const tr = document.createElement('tr');

        // Zeit (zweizeilig: Datum + Uhrzeit)
        const tdTime = document.createElement('td');
        tdTime.className = 'time';
        if (r.timeLabel) {
          const parts = r.timeLabel.split(' ');
          tdTime.innerHTML = (parts.length >= 2) ? `${parts[0]}\n${parts[1]}` : r.timeLabel;
        }
        tr.appendChild(tdTime);

        // restliche Spalten
        for (let i = 1; i < keys.length; i++) {
          const key = keys[i];
          const cell = (r.cells && r.cells[key]) ? r.cells[key] : { render: true, rowspan: 1, colspan: 1, text: '' };
          if (!cell.render) continue;

          const td = document.createElement('td');
          if (cell.rowspan && cell.rowspan > 1) td.rowSpan = cell.rowspan;
          if (cell.colspan && cell.colspan > 1) td.colSpan = cell.colspan;

          if (cell.text && cell.text.trim() !== '') {
            td.classList.add('filled');
            td.textContent = cell.text; // white-space: pre-line erlaubt Umbrüche
          } else {
            td.textContent = '';
          }

          tr.appendChild(td);
        }

        tbody.appendChild(tr);
      });
    } catch (e) {
      document.getElementById('err').textContent = e?.stack || String(e);
    }
  })();
</script>
</body>
</html>