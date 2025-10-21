// helper
const $ = (q) => document.querySelector(q);
const API = (p) => `api/${p}`;
const today = () => new Date().toISOString().slice(0, 10);
const rupiah = (n) => (Number(n) || 0).toLocaleString('id-ID');

// fetch JSON sederhana (tanpa PIN)
async function fetchJson(url) {
  const res = await fetch(url, { cache: 'no-store' });
  const data = await res.json().catch(() => ({ ok: false, error: 'Invalid JSON' }));
  if (!res.ok || data.ok === false) {
    throw new Error(data.error || res.statusText);
  }
  return data;
}

async function load() {
  try {
    const s = $('#start').value || today();
    const e = $('#end').value || s;

    // panggil endpoint report.php (param: from,to)
    const data = await fetchJson(API(`report.php?from=${s}&to=${e}`));

    // kompatibel: pakai summary|daily dan bestsellers|top
    const summary = data.summary || data.daily || [];
    const top = data.bestsellers || data.top || [];

    // render ringkasan harian
    const tb1 = $('#summary-body');
    tb1.innerHTML = summary.length
      ? summary.map(r => `
          <tr>
            <td>${r.tgl}</td>
            <td>${r.trx}</td>
            <td class="text-end">${rupiah(r.omzet)}</td>
          </tr>`).join('')
      : `<tr><td colspan="3" class="text-center text-muted">Tidak ada data</td></tr>`;

    // render produk terlaris
    const tb2 = $('#top-body');
    tb2.innerHTML = top.length
      ? top.map(r => `
          <tr>
            <td>${r.produk}</td>
            <td>${r.qty}</td>
            <td class="text-end">${rupiah(r.penjualan)}</td>
          </tr>`).join('')
      : `<tr><td colspan="3" class="text-center text-muted">Tidak ada data</td></tr>`;
  } catch (e) {
    alert('Gagal ambil laporan: ' + e.message);
  }
}

function toCSV() {
  const rows = [['Tanggal','Transaksi','Omzet (Rp)']];
  document.querySelectorAll('#summary-body tr').forEach(tr => {
    const cells = tr.querySelectorAll('td');
    if (cells.length === 3) {
      rows.push([cells[0].innerText, cells[1].innerText, cells[2].innerText]);
    }
  });
  const csv = rows.map(r => r.map(x => `"${String(x).replace(/"/g,'""')}"`).join(',')).join('\n');
  const blob = new Blob([csv], { type:'text/csv' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'laporan_ringkas.csv';
  a.click();
}

// init
$('#btn-load').addEventListener('click', load);
$('#btn-csv').addEventListener('click', toCSV);

const d = today();
$('#start').value = d;
$('#end').value = d;

// auto-load pertama kali
load();
