// ===================== Report JS (ringkasan + filter + CSV + print) =====================

(function () {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const fmtRp = (n) => 'Rp ' + Number(n || 0).toLocaleString('id-ID');
  const toISODate = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  const parseDate = (v) => new Date(v + 'T00:00:00');

  // ------ Root UI (auto build kalau belum ada) ------
  let root = $('#reportRoot');
  if (!root) {
    root = document.createElement('div');
    root.id = 'reportRoot';
    root.innerHTML = `
      <div class="container py-3">
        <div class="d-flex align-items-end gap-2 flex-wrap mb-3">
          <div>
            <label class="form-label mb-1">Dari</label>
            <input id="dateFrom" type="date" class="form-control">
          </div>
          <div>
            <label class="form-label mb-1">Sampai</label>
            <input id="dateTo" type="date" class="form-control">
          </div>
          <div class="d-flex gap-2">
            <button id="btnReload" class="btn btn-primary">Terapkan</button>
            <button id="btnToday" class="btn btn-outline-secondary">Hari ini</button>
            <button id="btnMonth" class="btn btn-outline-secondary">Bulan ini</button>
          </div>
          <div class="ms-auto d-flex gap-2">
            <button id="btnExportSales" class="btn btn-outline-success">Export Penjualan (CSV)</button>
            <button id="btnExportPurch" class="btn btn-outline-success">Export Pembelian (CSV)</button>
            <button id="btnPrint" class="btn btn-outline-dark">Print</button>
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-12 col-md-4">
            <div class="card shadow-sm">
              <div class="card-body">
                <div class="text-muted">Omzet (Penjualan)</div>
                <div id="sumSales" class="h4 m-0">Rp 0</div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <div class="card shadow-sm">
              <div class="card-body">
                <div class="text-muted">Belanja (Pembelian)</div>
                <div id="sumPurch" class="h4 m-0">Rp 0</div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <div class="card shadow-sm">
              <div class="card-body">
                <div class="text-muted">Profit (kasar)</div>
                <div id="sumProfit" class="h4 m-0">Rp 0</div>
              </div>
            </div>
          </div>
        </div>

        <div class="card shadow-sm mb-3">
          <div class="card-header fw-bold">Penjualan</div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Kode/Inv</th>
                  <th>Keterangan</th>
                  <th class="text-end">Total</th>
                  <th>Tanggal</th>
                </tr>
              </thead>
              <tbody id="salesBody"><tr><td colspan="4" class="text-muted text-center py-3">Memuat…</td></tr></tbody>
            </table>
          </div>
        </div>

        <div class="card shadow-sm">
          <div class="card-header fw-bold">Pembelian</div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Invoice</th>
                  <th>Supplier</th>
                  <th class="text-end">Total</th>
                  <th>Tanggal</th>
                </tr>
              </thead>
              <tbody id="purchasesBody"><tr><td colspan="4" class="text-muted text-center py-3">Memuat…</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>
    `;
    document.body.appendChild(root);
  }

  // Ambil elemen (pakai yang sudah ada di HTML, atau yang kita buat)
  const el = {
    dateFrom: $('#dateFrom'),
    dateTo: $('#dateTo'),
    btnReload: $('#btnReload'),
    btnToday: $('#btnToday'),
    btnMonth: $('#btnMonth'),
    btnExportSales: $('#btnExportSales'),
    btnExportPurch: $('#btnExportPurch'),
    btnPrint: $('#btnPrint'),
    sumSales: $('#sumSales'),
    sumPurch: $('#sumPurch'),
    sumProfit: $('#sumProfit'),
    salesBody: $('#salesBody'),
    purchasesBody: $('#purchasesBody'),
  };

  // ----- Default range: hari ini -----
  const today = new Date();
  el.dateFrom.value = el.dateFrom.value || toISODate(today);
  el.dateTo.value = el.dateTo.value || toISODate(today);

  el.btnToday?.addEventListener('click', () => {
    const t = new Date();
    el.dateFrom.value = toISODate(t);
    el.dateTo.value = toISODate(t);
    loadAll();
  });

  el.btnMonth?.addEventListener('click', () => {
    const t = new Date();
    const first = new Date(t.getFullYear(), t.getMonth(), 1);
    const last = new Date(t.getFullYear(), t.getMonth() + 1, 0);
    el.dateFrom.value = toISODate(first);
    el.dateTo.value = toISODate(last);
    loadAll();
  });

  el.btnReload?.addEventListener('click', loadAll);
  el.btnPrint?.addEventListener('click', () => window.print());

  // ----- Networking helpers -----
  async function getJSON(url) {
    const r = await fetch(url);
    const text = await r.text();
    let j;
    try { j = JSON.parse(text); } catch { throw new Error(text || 'Respon bukan JSON'); }
    if (!j.ok) throw new Error(j.error || 'Permintaan gagal');
    return j;
  }

  // Sales: coba beberapa endpoint umum biar kompatibel
  async function fetchSales(from, to) {
    const params = `?start=${encodeURIComponent(from)}&end=${encodeURIComponent(to)}`;
    const candidates = [
      '/api/sales_list.php',        // kalau ada
      '/api/report_sales.php',
      '/api/sales.php',
      '/api/transactions.php'
    ];
    for (const base of candidates) {
      try {
        const j = await getJSON(base + params);
        if (Array.isArray(j.data)) return j.data;
        if (Array.isArray(j.rows)) return j.rows;
      } catch (_) { /* coba endpoint berikutnya */ }
    }
    // fallback: kalau tidak ada endpoint, kembalikan kosong
    return [];
  }

  // Purchases: kita tahu purchase_list.php sudah ada di proyek kamu
  async function fetchPurchases(from, to) {
    const url = `/api/purchase_list.php?start=${encodeURIComponent(from)}&end=${encodeURIComponent(to)}`;
    try {
      const j = await getJSON(url);
      if (Array.isArray(j.data)) return j.data;
    } catch (_) {}
    // fallback tanpa filter tanggal
    try {
      const j = await getJSON('/api/purchase_list.php');
      if (Array.isArray(j.data)) return j.data;
    } catch (_) {}
    return [];
  }

  // ----- Renderers -----
  function renderSales(rows) {
    if (!el.salesBody) return;
    if (!rows.length) {
      el.salesBody.innerHTML = `<tr><td colspan="4" class="text-muted text-center py-3">Tidak ada data.</td></tr>`;
      return;
    }
    el.salesBody.innerHTML = rows.map(r => {
      const code = r.invoice_code || r.code || r.order_code || r.id || '-';
      const note = (r.note || r.customer_name || r.method || '-') + '';
      const total = Number(r.total || r.amount || r.grand_total || 0);
      const when = r.created_at_wib || r.created_at || r.date || '';
      return `
        <tr>
          <td class="text-monospace">${escapeHTML(code)}</td>
          <td>${escapeHTML(note)}</td>
          <td class="text-end">${fmtRp(total)}</td>
          <td>${escapeHTML(when)}</td>
        </tr>
      `;
    }).join('');
  }

  function renderPurchases(rows) {
    if (!el.purchasesBody) return;
    if (!rows.length) {
      el.purchasesBody.innerHTML = `<tr><td colspan="4" class="text-muted text-center py-3">Tidak ada data.</td></tr>`;
      return;
    }
    el.purchasesBody.innerHTML = rows.map(r => {
      const code = r.invoice_code || r.code || r.id || '-';
      const supp = r.supplier_name || '-';
      const total = Number(r.total || 0);
      const when = r.created_at_wib || r.created_at || r.date || '';
      return `
        <tr>
          <td class="text-monospace">${escapeHTML(code)}</td>
          <td>${escapeHTML(supp)}</td>
          <td class="text-end">${fmtRp(total)}</td>
          <td>${escapeHTML(when)}</td>
        </tr>
      `;
    }).join('');
  }

  function summarize(rows, keyCandidates) {
    let sum = 0;
    for (const r of rows) {
      for (const k of keyCandidates) {
        if (r[k] != null) { sum += Number(r[k]) || 0; break; }
      }
    }
    return sum;
  }

  function setSums({ salesRows, purchRows }) {
    const salesSum = summarize(salesRows, ['total', 'amount', 'grand_total']);
    const purchSum = summarize(purchRows, ['total']);
    const profit = salesSum - purchSum; // kasar; profit detail bisa pakai (qty*(sell-cost)) per item kalau endpoint dukung
    el.sumSales && (el.sumSales.textContent = fmtRp(salesSum));
    el.sumPurch && (el.sumPurch.textContent = fmtRp(purchSum));
    el.sumProfit && (el.sumProfit.textContent = fmtRp(profit));
  }

  // ----- CSV Export -----
  function toCSV(rows, headerMap) {
    const headers = Object.keys(headerMap);
    const cols = headers.map(h => headerMap[h]);
    const esc = (v) => {
      const s = (v == null ? '' : String(v));
      return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
    };
    const head = headers.join(',');
    const body = rows.map(r => cols.map(c => esc(resolveDeep(r, c))).join(',')).join('\n');
    return head + '\n' + body;
  }
  function resolveDeep(obj, path) {
    if (!path) return '';
    const parts = path.split('.');
    let cur = obj;
    for (const p of parts) {
      if (cur && Object.prototype.hasOwnProperty.call(cur, p)) cur = cur[p];
      else return '';
    }
    return cur;
  }
  function download(name, text, mime = 'text/csv;charset=utf-8') {
    const blob = new Blob([text], { type: mime });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = name;
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 0);
  }

  el.btnExportSales?.addEventListener('click', async () => {
    el.btnExportSales.disabled = true;
    try {
      const { from, to } = getRange();
      const sales = await fetchSales(from, to);
      const csv = toCSV(sales, {
        'Kode/Inv': 'invoice_code',
        'Keterangan': 'note',
        'Total': 'total',
        'Tanggal': 'created_at_wib'
      });
      download(`penjualan_${from}_sd_${to}.csv`, csv);
    } catch (e) {
      alert(e.message || 'Gagal export penjualan');
    } finally { el.btnExportSales.disabled = false; }
  });

  el.btnExportPurch?.addEventListener('click', async () => {
    el.btnExportPurch.disabled = true;
    try {
      const { from, to } = getRange();
      const purch = await fetchPurchases(from, to);
      const csv = toCSV(purch, {
        'Invoice': 'invoice_code',
        'Supplier': 'supplier_name',
        'Total': 'total',
        'Tanggal': 'created_at_wib'
      });
      download(`pembelian_${from}_sd_${to}.csv`, csv);
    } catch (e) {
      alert(e.message || 'Gagal export pembelian');
    } finally { el.btnExportPurch.disabled = false; }
  });

  // ----- Utilities -----
  function getRange() {
    const from = el.dateFrom?.value || toISODate(new Date());
    const to = el.dateTo?.value || from;
    // normalisasi: pastikan from <= to
    const df = parseDate(from), dt = parseDate(to);
    if (df > dt) return { from: toISODate(dt), to: toISODate(df) };
    return { from, to };
  }

  function escapeHTML(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  // ----- Loader utama -----
  async function loadAll() {
    const { from, to } = getRange();
    if (el.salesBody) el.salesBody.innerHTML = `<tr><td colspan="4" class="text-muted text-center py-3">Memuat…</td></tr>`;
    if (el.purchasesBody) el.purchasesBody.innerHTML = `<tr><td colspan="4" class="text-muted text-center py-3">Memuat…</td></tr>`;

    try {
      const [salesRows, purchRows] = await Promise.all([
        fetchSales(from, to),
        fetchPurchases(from, to)
      ]);
      renderSales(salesRows);
      renderPurchases(purchRows);
      setSums({ salesRows, purchRows });
    } catch (e) {
      alert(e.message || 'Gagal memuat laporan');
      if (el.salesBody) el.salesBody.innerHTML = `<tr><td colspan="4" class="text-danger text-center py-3">Gagal memuat.</td></tr>`;
      if (el.purchasesBody) el.purchasesBody.innerHTML = `<tr><td colspan="4" class="text-danger text-center py-3">Gagal memuat.</td></tr>`;
    }
  }

  // Jalankan awal
  loadAll();
})();
