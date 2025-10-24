// purchase-report.js
const fmtRp = n => 'Rp ' + Number(n||0).toLocaleString('id-ID');

const els = {
  start: document.querySelector('#start'),
  end: document.querySelector('#end'),
  supplier: document.querySelector('#supplier'),
  q: document.querySelector('#q'),
  btnLoad: document.querySelector('#btn-load'),
  btnCsv: document.querySelector('#btn-csv'),
  sumBody: document.querySelector('#sum-body'),
  supBody: document.querySelector('#sup-body'),
  itemBody: document.querySelector('#item-body'),
  invBody: document.querySelector('#inv-body'),
};

(function setDefaultRange(){
  const today = new Date();
  const y = today.toISOString().slice(0,10);
  els.start.value = y;
  els.end.value = y;
})();

els.btnLoad.addEventListener('click', loadReport);
els.btnCsv.addEventListener('click', exportCSV);

async function loadReport(){
  const params = new URLSearchParams({
    start: els.start.value,
    end: els.end.value,
  });
  if (els.supplier.value.trim()) params.append('supplier', els.supplier.value.trim());
  if (els.q.value.trim()) params.append('q', els.q.value.trim());

  try{
    const res = await fetch('api/purchase_report.php?' + params.toString());
    const j = await res.json();
    if(!j.ok) throw new Error(j.error || 'Gagal ambil laporan');

    // summary
    if (!j.summary || !j.summary.length) {
      els.sumBody.innerHTML = `<tr><td colspan="3" class="text-muted">Tidak ada data</td></tr>`;
    } else {
      els.sumBody.innerHTML = j.summary.map(r=>`
        <tr><td>${r.date}</td><td>${r.invoices}</td><td class="text-end">${fmtRp(r.total)}</td></tr>
      `).join('');
    }

    // suppliers
    if (!j.suppliers || !j.suppliers.length) {
      els.supBody.innerHTML = `<tr><td colspan="3" class="text-muted">Tidak ada data</td></tr>`;
    } else {
      els.supBody.innerHTML = j.suppliers.map(r=>`
        <tr><td>${r.supplier||'-'}</td><td>${r.invoices}</td><td class="text-end">${fmtRp(r.total)}</td></tr>
      `).join('');
    }

    // items
    if (!j.items || !j.items.length) {
      els.itemBody.innerHTML = `<tr><td colspan="6" class="text-muted">Tidak ada data</td></tr>`;
    } else {
      els.itemBody.innerHTML = j.items.map(r=>`
        <tr>
          <td class="text-monospace">${r.sku||'-'}</td>
          <td>${r.name||'-'}</td>
          <td>${r.qty}</td>
          <td class="text-end">${fmtRp(r.total)}</td>
          <td class="text-end">${fmtRp(r.avg_cost)}</td>
          <td class="text-end">${fmtRp(r.last_cost)}</td>
        </tr>
      `).join('');
    }

    // invoices
    if (!j.invoices || !j.invoices.length) {
      els.invBody.innerHTML = `<tr><td colspan="4" class="text-muted">Tidak ada data</td></tr>`;
    } else {
      els.invBody.innerHTML = j.invoices.map(inv => `
        <tr class="table-secondary">
          <td class="text-monospace">${inv.invoice_code}</td>
          <td>${inv.supplier||'-'}</td>
          <td>${inv.created_at_wib}</td>
          <td class="text-end">${fmtRp(inv.total)}</td>
        </tr>
        ${inv.items.map(it=>`
          <tr>
            <td colspan="2" class="ps-4">
              <span class="text-monospace">${it.sku}</span> — ${it.name}
            </td>
            <td>Qty ${it.qty} @ ${fmtRp(it.price)}</td>
            <td class="text-end">${fmtRp(it.subtotal)}</td>
          </tr>
        `).join('')}
      `).join('');
    }

  }catch(err){
    alert(err.message || 'Gagal memuat laporan.');
  }
}

function exportCSV(){
  // pakai data dari tabel item (detail)
  const rows = [...document.querySelectorAll('#item-body tr')];
  if (!rows.length || rows[0].querySelector('.text-muted')) {
    alert('Tidak ada data untuk diekspor.');
    return;
  }
  const header = ['SKU','Nama','Qty','Total (Rp)','Avg Cost','Last Cost'];
  const body = rows.map(tr => [...tr.children].map(td => td.innerText.replace(/\s+/g,' ').trim()));
  const csv = [header].concat(body).map(r=>r.map(s=>`"${s.replace(/"/g,'""')}"`).join(',')).join('\r\n');

  const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `laporan-pembelian_${els.start.value}_${els.end.value}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}
