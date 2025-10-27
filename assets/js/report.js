// ====== Report Page (summary + top products) ======

const $ = (q) => document.querySelector(q);
const els = {
  start: $('#start'),
  end: $('#end'),
  btnLoad: $('#btn-load') || $('#btn_load') || $('#btnLoad'),
  btnCsv: $('#btn-csv') || $('#btn_csv') || $('#btnCsv'),
  sumBody: $('#summary-body'),
  topBody: $('#top-body'),
};

const todayStr = () => {
  const d = new Date();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${d.getFullYear()}-${m}-${day}`;
};

const rupiah = (n) =>
  'Rp ' + Number(n || 0).toLocaleString('id-ID', { maximumFractionDigits: 0 });

function setLoading(on) {
  if (els.btnLoad) els.btnLoad.disabled = on;
  if (els.btnCsv) els.btnCsv.disabled = on;
}

async function fetchReport(start, end) {
  const url = `/api/report.php?start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`;
  const res = await fetch(url, { cache: 'no-store' });
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); }
  catch { throw new Error(text || 'Respon bukan JSON'); }
  if (!data.ok) throw new Error(data.error || 'Gagal memuat laporan');
  return data;
}

function renderSummary(rows) {
  const tb = els.sumBody;
  tb.innerHTML = '';
  if (!rows || !rows.length) {
    tb.innerHTML = `<tr><td colspan="3" class="text-center text-muted">Tidak ada data</td></tr>`;
    return;
  }
  const html = rows.map(r => `
    <tr>
      <td>${r.date}</td>
      <td>${r.count}</td>
      <td class="text-end">${rupiah(r.total)}</td>
    </tr>
  `).join('');
  tb.innerHTML = html;
}

function renderTop(rows) {
  const tb = els.topBody;
  tb.innerHTML = '';
  if (!rows || !rows.length) {
    tb.innerHTML = `<tr><td colspan="3" class="text-center text-muted">Tidak ada data</td></tr>`;
    return;
  }
  const html = rows.map(r => `
    <tr>
      <td>${r.name}</td>
      <td>${r.qty}</td>
      <td class="text-end">${rupiah(r.revenue)}</td>
    </tr>
  `).join('');
  tb.innerHTML = html;
}

async function loadNow() {
  const start = (els.start?.value || '').trim();
  const end = (els.end?.value || '').trim();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(start) || !/^\d{4}-\d{2}-\d{2}$/.test(end)) {
    alert('Tanggal harus format YYYY-MM-DD'); return;
  }
  try {
    setLoading(true);
    const data = await fetchReport(start, end);
    renderSummary(data.summary || []);
    renderTop((data.top_products || []).map(p => ({
      name: p.name, qty: Number(p.qty||0), revenue: Number(p.revenue||0)
    })));
  } catch (e) {
    console.warn(e);
    alert(e.message || 'Gagal memuat laporan');
  } finally {
    setLoading(false);
  }
}

function exportCSV() {
  // kumpulkan data dari tabel yang sudah dirender
  const parseNum = (s) => Number(String(s).replace(/[^\d\-]/g, '')) || 0;

  // Summary
  const sumRows = [...els.sumBody.querySelectorAll('tr')].map(tr => {
    const tds = tr.querySelectorAll('td');
    if (tds.length !== 3) return null;
    const date = tds[0].textContent.trim();
    const count = tds[1].textContent.trim();
    const total = parseNum(tds[2].textContent);
    return { date, count, total };
  }).filter(Boolean);

  // Top products
  const topRows = [...els.topBody.querySelectorAll('tr')].map(tr => {
    const tds = tr.querySelectorAll('td');
    if (tds.length !== 3) return null;
    const name = tds[0].textContent.trim();
    const qty = tds[1].textContent.trim();
    const revenue = parseNum(tds[2].textContent);
    return { name, qty, revenue };
  }).filter(Boolean);

  let csv = [];
  csv.push('Ringkasan Harian');
  csv.push('Tanggal,Transaksi,Omzet (Rp)');
  if (sumRows.length) {
    sumRows.forEach(r => csv.push([r.date, r.count, r.total].join(',')));
  } else {
    csv.push('Tidak ada data,,');
  }
  csv.push('');
  csv.push('Produk Terlaris');
  csv.push('Produk,Qty,Penjualan (Rp)');
  if (topRows.length) {
    topRows.forEach(r => csv.push([`"${r.name.replace(/"/g,'""')}"`, r.qty, r.revenue].join(',')));
  } else {
    csv.push('Tidak ada data,,');
  }

  const blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
  const a = document.createElement('a');
  const start = (els.start?.value || todayStr());
  const end = (els.end?.value || todayStr());
  a.href = URL.createObjectURL(blob);
  a.download = `laporan-penjualan_${start}_to_${end}.csv`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(a.href);
}

document.addEventListener('DOMContentLoaded', () => {
  // default ke hari ini
  const today = todayStr();
  if (els.start && !els.start.value) els.start.value = today;
  if (els.end && !els.end.value) els.end.value = today;

  els.btnLoad?.addEventListener('click', loadNow);
  els.btnCsv?.addEventListener('click', exportCSV);

  // auto load pertama kali
  loadNow();
});
