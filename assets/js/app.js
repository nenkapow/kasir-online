// ===================== Kasir klasik – app.js =====================

const $ = (q) => document.querySelector(q);
const rupiah = (n) => 'Rp ' + Number(n || 0).toLocaleString('id-ID');

let PRODUCTS = [];
let CART = []; // {id, sku, name, price, stock, qty}

const els = {
  search: $('#search'),
  suggest: $('#suggest'),
  qty: $('#qty'),
  lines: $('#lines'),
  totalBig: $('#grand-total'),
  pay: $('#pay'),
  change: $('#change'),
  note: $('#note'),
  method: $('#method'),
  addBtn: $('#add-btn'),
  clearBtn: $('#clear-btn'),
  payBtn: $('#pay-btn'),
  mbBar: $('#mobile-bar'),
  mbTotal: $('#mb-total'),
  mbPay: $('#mb-pay'),
};

window.addEventListener('DOMContentLoaded', () => {
  // silent login (kalau ada session)
  fetch('/api/login.php', { method: 'POST' }).catch(()=>{});

  loadProducts();

  els.search.addEventListener('input', onSearchInput);
  els.search.addEventListener('keydown', onSearchKey);
  els.suggest.addEventListener('click', onSuggestClick);

  els.addBtn.addEventListener('click', addFromBar);
  els.clearBtn.addEventListener('click', clearAll);
  els.payBtn.addEventListener('click', checkout);
  els.mbPay.addEventListener('click', checkout);
  els.pay.addEventListener('input', updateChange);

  // keyboard shortcuts
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { clearBar(); }
    if (e.key.toLowerCase() === 'b' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); checkout(); }
  });

  // fokus ke search setiap load
  setTimeout(()=>els.search.focus(), 200);
});

// ---------- Data ----------
async function loadProducts(){
  try{
    const r = await fetch('/api/products.php?q=');
    const j = await r.json();
    if(!j.ok) throw new Error(j.error||'Gagal ambil produk');
    PRODUCTS = j.data || [];
  }catch(e){ alert('Gagal memuat produk'); }
}

// ---------- Search / Suggest ----------
let selIndex = -1;

function onSearchInput(){
  const q = (els.search.value || '').trim().toLowerCase();
  selIndex = -1;
  if(!q){ els.suggest.classList.add('d-none'); els.suggest.innerHTML=''; return; }

  const list = PRODUCTS
    .filter(p => (p.sku||'').toLowerCase().includes(q) || (p.name||'').toLowerCase().includes(q))
    .slice(0, 12);

  if(!list.length){ els.suggest.classList.add('d-none'); els.suggest.innerHTML=''; return; }

  els.suggest.innerHTML = list.map((p,i)=>`
    <button type="button" class="list-group-item list-group-item-action" data-id="${p.id}" data-idx="${i}">
      <div class="d-flex justify-content-between">
        <div class="text-truncate me-3"><strong>${p.name}</strong><div class="small text-muted">${p.sku} • Stok ${p.stock ?? 0}</div></div>
        <div class="text-end fw-semibold">${rupiah(p.price)}</div>
      </div>
    </button>
  `).join('');
  els.suggest.classList.remove('d-none');
}

function onSearchKey(e){
  const items = [...els.suggest.querySelectorAll('.list-group-item')];
  if(!items.length){
    if(e.key === 'Enter'){ addFromBar(); }
    return;
  }
  if(e.key === 'ArrowDown'){ e.preventDefault(); moveSel(1, items); }
  if(e.key === 'ArrowUp'){ e.preventDefault(); moveSel(-1, items); }
  if(e.key === 'Enter'){ e.preventDefault(); chooseSel(items); }
}

function moveSel(d, items){
  selIndex = (selIndex + d + items.length) % items.length;
  items.forEach(el => el.classList.remove('active'));
  items[selIndex].classList.add('active');
  items[selIndex].scrollIntoView({block:'nearest'});
}

function chooseSel(items){
  const el = items[selIndex>=0?selIndex:0];
  if(!el) return;
  addById(el.dataset.id);
}

function onSuggestClick(e){
  const btn = e.target.closest('.list-group-item');
  if(!btn) return;
  addById(btn.dataset.id);
}

// ---------- Cart ops ----------
function addFromBar(){
  const q = (els.search.value||'').trim().toLowerCase();
  const qty = Math.max(1, parseInt(els.qty.value||'1',10));

  if(!q) return;

  // Cari SKU persis dulu → baru nama
  let p = PRODUCTS.find(x => (x.sku||'').toLowerCase() === q);
  if(!p) p = PRODUCTS.find(x => (x.name||'').toLowerCase().includes(q));
  if(!p){ beep(); return; }

  addProduct(p, qty);
}

function addById(id){
  const p = PRODUCTS.find(x => String(x.id)===String(id));
  if(!p){ beep(); return; }
  const qty = Math.max(1, parseInt(els.qty.value||'1',10));
  addProduct(p, qty);
}

function addProduct(p, qty){
  // cek stok jika ada
  if (typeof p.stock === 'number'){
    const inCart = CART.find(x => x.id===p.id)?.qty || 0;
    if(inCart + qty > p.stock){
      alert(`Stok ${p.name} tidak cukup.`);
      return;
    }
  }
  const row = CART.find(x => x.id===p.id);
  if(row) row.qty += qty; else CART.push({...p, qty});
  renderLines();
  clearBar();
}

function clearBar(){
  els.search.value = '';
  els.suggest.classList.add('d-none'); els.suggest.innerHTML='';
  els.qty.value = '1';
  setTimeout(()=>els.search.focus(), 50);
}

function clearAll(){
  if(!CART.length) return;
  if(!confirm('Kosongkan transaksi ini?')) return;
  CART = [];
  renderLines();
  clearBar();
}

function renderLines(){
  els.lines.innerHTML = '';
  let total = 0;

  if(!CART.length){
    els.lines.innerHTML = `<tr><td colspan="6" class="text-center text-muted py-4">Belum ada item.</td></tr>`;
  }else{
    CART.forEach(it=>{
      const sub = it.qty * it.price;
      total += sub;

      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td class="text-monospace">${it.sku || '-'}</td>
        <td class="fw-semibold">${it.name}</td>
        <td class="text-end">${rupiah(it.price)}</td>
        <td class="text-center">
          <div class="qty-group">
            <button class="btn btn-outline-secondary btn-sm" data-act="minus">-</button>
            <input data-role="qty" type="number" min="1" step="1" value="${it.qty}" class="form-control form-control-sm" style="width:70px">
            <button class="btn btn-outline-secondary btn-sm" data-act="plus">+</button>
          </div>
        </td>
        <td class="text-end">${rupiah(sub)}</td>
        <td><button class="btn btn-outline-danger btn-sm" data-act="del">x</button></td>
      `;

      tr.querySelector('[data-act="minus"]').onclick = ()=> changeQty(it.id, -1);
      tr.querySelector('[data-act="plus"]').onclick  = ()=> changeQty(it.id, +1);
      tr.querySelector('[data-role="qty"]').oninput  = (e)=>{
        let v = parseInt(e.target.value||'1',10);
        if(isNaN(v)||v<1) v=1;
        setQty(it.id, v);
      };
      tr.querySelector('[data-act="del"]').onclick   = ()=> removeItem(it.id);

      els.lines.appendChild(tr);
    });
  }

  els.totalBig.textContent = rupiah(total);
  els.mbTotal.textContent = rupiah(total);
  updateChange();
}

function changeQty(id, d){
  const it = CART.find(x=>x.id===id);
  if(!it) return;
  const after = it.qty + d;
  if(after < 1) return;
  // stok
  if(typeof it.stock==='number' && after>it.stock){
    alert('Stok tidak cukup'); return;
  }
  it.qty = after; renderLines();
}
function setQty(id, v){
  const it = CART.find(x=>x.id===id);
  if(!it) return;
  if(typeof it.stock==='number' && v>it.stock){ alert('Stok tidak cukup'); return; }
  it.qty = v; renderLines();
}
function removeItem(id){
  CART = CART.filter(x=>x.id!==id);
  renderLines();
}

function total(){
  return CART.reduce((s,i)=>s+i.qty*i.price,0);
}

function updateChange(){
  const pay = Number(els.pay.value||0);
  const chg = Math.max(0, pay - total());
  els.change.value = rupiah(chg);
  // mobile bar show/hide
  els.mbBar.style.display = total()>0 ? 'flex' : 'none';
}

// ---------- Checkout ----------
async function checkout(){
  if(!CART.length){ alert('Belum ada item.'); return; }
  const t = total();
  const pay = Number(els.pay.value||0);
  if(pay < t && els.method.value==='cash'){ alert('Nominal bayar kurang.'); return; }

  // siapkan payload (kedua kunci id & product_id untuk kompatibilitas)
  const items = CART.map(i=>({
    id: Number(i.id),
    product_id: Number(i.id),
    qty: Number(i.qty),
    price: Number(i.price),
    subtotal: Number(i.qty*i.price)
  }));

  const fd = new FormData();
  fd.append('method', els.method.value || 'cash');
  fd.append('note', (els.note.value||'').toString());
  fd.append('amount_paid', String(pay));
  fd.append('total', String(t));
  fd.append('items', JSON.stringify(items));

  const btns = [els.payBtn, els.mbPay];
  btns.forEach(b=>{ b.disabled=true; b.textContent='Memproses…'; });

  try{
    const res = await fetch('/api/checkout.php', { method:'POST', body: fd });
    const text = await res.text();
    let data; try{ data=JSON.parse(text); }catch{ throw new Error(text||'Respon bukan JSON'); }
    if(!res.ok || !data?.ok) throw new Error(data?.error || 'Checkout gagal');

    alert('Transaksi sukses!');
    CART = []; renderLines(); clearBar(); els.pay.value = ''; els.change.value = 'Rp 0';
    // refresh stok list
    await loadProducts();
  }catch(e){
    alert(e.message || 'Gagal melakukan checkout.');
  }finally{
    btns.forEach(b=>{ b.disabled=false; b.textContent='Bayar'; });
  }
}

// ---------- misc ----------
function beep(){ try{ new AudioContext().close(); }catch(_){ /* nothing */ } }
