const $ = (q)=>document.querySelector(q);
const API = (p)=>`api/${p}`;
let PIN='';

function rupiah(n){return (n||0).toLocaleString('id-ID');}

async function fetchJson(url){
  if(!PIN){ PIN = $('#pin').value.trim(); }
  const res = await fetch(url, {headers: {'X-APP-PIN': PIN}});
  const data = await res.json().catch(()=>({ok:false,error:'Invalid JSON'}));
  if(!res.ok || !data.ok){ throw new Error(data.error || res.statusText); }
  return data.data;
}

function today(){ const d = new Date(); return d.toISOString().slice(0,10); }

async function load(){
  try{
    const s = $('#start').value || today();
    const e = $('#end').value || s;
    const data = await fetchJson(API(`reports.php?start=${s}&end=${e}`));
    const tbody = $('#summary-body'); tbody.innerHTML='';
    data.summary.forEach(r=>{
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${r.day}</td><td>${r.tx_count}</td><td class="text-end">${rupiah(r.revenue)}</td>`;
      tbody.appendChild(tr);
    });
    const top = $('#top-body'); top.innerHTML='';
    data.top_products.forEach(r=>{
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${r.name}</td><td>${r.qty_sold}</td><td class="text-end">${rupiah(r.gross)}</td>`;
      top.appendChild(tr);
    });
  }catch(e){ alert('Gagal ambil laporan: '+e.message); }
}

function toCSV(){
  const rows = [['Tanggal','Transaksi','Omzet (Rp)']];
  document.querySelectorAll('#summary-body tr').forEach(tr=>{
    const tds = tr.querySelectorAll('td');
    rows.push([tds[0].innerText, tds[1].innerText, tds[2].innerText]);
  });
  const csv = rows.map(r=>r.map(x=>`"${String(x).replace(/"/g,'""')}"`).join(',')).join('\n');
  const blob = new Blob([csv], {type:'text/csv'});
  const a = document.createElement('a');
  a.href=URL.createObjectURL(blob);
  a.download='laporan_ringkas.csv';
  a.click();
}

$('#btn-load').addEventListener('click', load);
$('#btn-csv').addEventListener('click', toCSV);

const d = today();
document.getElementById('start').value = d;
document.getElementById('end').value = d;
