// ===================== REPORT.JS (versi cocok dengan report.html kamu) =====================

const $ = (q) => document.querySelector(q);
const rupiah = (n) => 'Rp ' + Number(n || 0).toLocaleString('id-ID');

window.addEventListener('DOMContentLoaded', () => {
  const els = {
    start: $('#start'),
    end: $('#end'),
    btnLoad: $('#btn-load'),
    btnCsv: $('#btn-csv'),
    sumBody: $('#summary-body'),
    topBody: $('#top-body'),
  };

  const today = new Date();
  const toDateInput = (d) =>
    new Date(d.getTime() - d.getTimezoneOffset() * 60000)
      .toISOString()
      .slice(0, 10);

  els.start.value = els.start.value || toDateInput(today);
  els.end.value = els.end.value || toDateInput(today);

  els.btnLoad.addEventListener('click', loadReport);
  els.btnCsv.addEventListener('click', exportCSV);

  async function loadReport() {
    const start = els.start.value;
    const end = els.end.value;
    els.sumBody.innerHTML = `<tr><td colspan="3" class="text-center text-muted">Memuat...</td></tr>`;
    els.topBody.innerHTML = `<tr><td colspan="3" class="text-center text-muted">Memuat...</td></tr>`;

    try {
      const [sales, products] = await Promise.all([
        fetch(`/api/report_sales.php?start=${start}&end=${end}`).then((r) => r.json()).catch(() => ({ ok: false })),
        fetch(`/api/report_top_products.php?start=${start}&end=${end}`).then((r) => r.json()).catch(() => ({ ok: false })),
      ]);

      renderSummary(sales.ok ? sales.data || [] : []);
      renderTop(products.ok ? products.data || [] : []);
    } catch (e) {
      els.sumBody.innerHTML = `<tr><td colspan="3" class="text-danger text-center">Gagal memuat data.</td></tr>`;
      els.topBody.innerHTML = `<tr><td colspan="3" class="text-danger text-center">Gagal memuat data.</td></tr>`;
    }
  }

  function renderSummary(rows) {
    if (!rows.length) {
      els.sumBody.innerHTML = `<tr><td colspan="3" class="text-center text-muted">Tidak ada data</td></tr>`;
      return;
    }
    els.sumBody.innerHTML = rows
      .map(
        (r) => `
      <tr>
        <td>${r.date || '-'}</td>
        <td>${r.transactions || 0}</td>
        <td class="text-end">${rupiah(r.total || 0)}</td>
      </tr>`
      )
      .join('');
  }

  function renderTop(rows) {
    if (!rows.length) {
      els.topBody.innerHTML = `<tr><td colspan="3" class="text-center text-muted">Tidak ada data</td></tr>`;
      return;
    }
    els.topBody.innerHTML = rows
      .map(
        (r) => `
      <tr>
        <td>${r.product_name || '-'}</td>
        <td>${r.qty || 0}</td>
        <td class="text-end">${rupiah(r.total || 0)}</td>
      </tr>`
      )
      .join('');
  }

  async function exportCSV() {
    const start = els.start.value;
    const end = els.end.value;

    try {
      const res = await fetch(`/api/report_sales.php?start=${start}&end=${end}`);
      const j = await res.json();
      if (!j.ok) throw new Error(j.error || 'Gagal mengambil data');

      const rows = j.data || [];
      if (!rows.length) return alert('Tidak ada data untuk diexport');

      const header = ['Tanggal', 'Transaksi', 'Omzet (Rp)'];
      const csv =
        header.join(',') +
        '\n' +
        rows.map((r) => [r.date, r.transactions, r.total].join(',')).join('\n');

      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = `laporan_${start}_sd_${end}.csv`;
      document.body.appendChild(link);
      link.click();
      link.remove();
    } catch (e) {
      alert('Gagal export CSV: ' + e.message);
    }
  }

  loadReport();
});
