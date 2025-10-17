const $ = (q)=>document.querySelector(q);
const $$ = (q)=>document.querySelectorAll(q);
const API = (path)=>`api/${path}`;

let PIN = '';
let products = [];
let cart = {}; // {product_id: {id,name,price,qty}}

function rupiah(n){return 'Rp' + (n||0).toLocaleString('id-ID');}

async function fetchJson(url, opts={}){
  if(!PIN){PIN = $('#pin').value.trim();}
  opts.headers = Object.assign({'X-APP-PIN': PIN}, opts.headers||{});
  const res = await fetch(url, opts);
  const data = await res.json().catch(()=>({ok:false,error:'Invalid JSON'}));
  if(!res.ok || !data.ok){ throw new Error(data.error || res.statusText); }
  return data.data;
}

async function loadProducts(q=''){
  try{
    products = await fetchJson(API(`products.php?q=${encodeURIComponent(q)}`));
    renderProducts();
  }catch(e){ alert('Gagal ambil data produk: '+e.message); }
}

function renderProducts(){
  const wrap = $('#product-list'); wrap.innerHTML = '';
  products.forEach(p=>{
    const col = document.createElement('div');
    col.className='col-6 col-md-4';
    col.innerHTML = `<div class="card h-100 shadow-sm">
      <div class="card-body p-2">
        <h6 class="card-title mb-1">${p.name}</h6>
        <div class="text-muted small">SKU: ${p.sku||'-'}</div>
        <div class="price mt-1">${rupiah(p.price)}</div>
        <button class="btn btn-primary btn-sm mt-2 w-100">Tambah</button>
      </div>
    </div>`;
    col.querySelector('button').onclick=()=>addToCart(p);
    wrap.appendChild(col);
  });
}

function addToCart(p){
  if(!cart[p.id]) cart[p.id] = {id:p.id, name:p.name, price:p.price, qty:0};
  cart[p.id].qty++;
  renderCart();
}

function renderCart(){
  const wrap = $('#cart-list'); wrap.innerHTML='';
  let total = 0;
  Object.values(cart).forEach(it=>{
    total += it.qty * it.price;
    const row = document.createElement('div');
    row.className='item shadow-sm';
    row.innerHTML = `
      <div>
        <div><strong>${it.name}</strong></div>
        <div class="small text-muted">${rupiah(it.price)}</div>
      </div>
      <div class="d-flex align-items-center gap-2">
        <button class="btn btn-outline-secondary btn-qty">-</button>
        <span>${it.qty}</span>
        <button class="btn btn-outline-secondary btn-qty">+</button>
        <button class="btn btn-outline-danger btn-sm">x</button>
      </div>`;
    const [btnMinus, , btnPlus, btnDel] = row.querySelectorAll('button');
    btnMinus.onclick=()=>{ it.qty=Math.max(0,it.qty-1); if(it.qty==0) delete cart[it.id]; renderCart(); };
    btnPlus.onclick=()=>{ it.qty++; renderCart(); };
    btnDel.onclick=()=>{ delete cart[it.id]; renderCart(); };
    wrap.appendChild(row);
  });
  $('#total').textContent = rupiah(total);
  $('#modal-total').textContent = rupiah(total);
}

$('#search').addEventListener('input', e=>{
  const q = e.target.value.trim();
  loadProducts(q);
});

$('#btn-checkout').addEventListener('click', ()=>{
  const total = Object.values(cart).reduce((s,it)=>s+it.qty*it.price,0);
  if(total<=0) return alert('Keranjang kosong');
  new bootstrap.Modal('#checkoutModal').show();
});

$('#confirm-pay').addEventListener('click', async ()=>{
  try{
    const items = Object.values(cart).map(it=>({product_id: it.id, qty: it.qty, price: it.price}));
    const payload = {items, payment_method: $('#payment').value, note: $('#note').value};
    await fetchJson(API('sales.php'), {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    cart = {};
    renderCart();
    bootstrap.Modal.getInstance($('#checkoutModal')).hide();
    alert('Transaksi berhasil disimpan');
    loadProducts($('#search').value.trim());
  }catch(e){ alert('Gagal simpan: '+e.message); }
});

// init
loadProducts();
