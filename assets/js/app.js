// =====================================================
// Kasir Online - app.js (v7)  —  cocok untuk backend PHP $_POST
// =====================================================

// ---- util
const rupiah = n => 'Rp ' + Number(n || 0).toLocaleString('id-ID');
const qs = s => document.querySelector(s);
const qsa = s => [...document.querySelectorAll(s)];

// ---- state
let PRODUCTS = [];
let CART = [];

// ---- boot
window.addEventListener('DOMContentLoaded', () => {
  // auto-login (silent)
  fetch('/api/login.php', { method: 'POST' }).catch(()=>{});

  loadProducts();

  qs('#search')?.addEventListener('input', e => {
    renderProducts(filterProducts(e.target.value));
  });

  qs('#form-produk')?.addEventListener('submit', onSaveProduk);

  // tombol checkout -> buka modal
  qs('#btn-checkout')?.addEventListener('click', () => {
    if (!CART.length) return alert('Keranjang masih kosong.');
    const total = getCartTotal();
    qs('#modal-total').textContent = rupiah(total);
    qs('#payAmount').value = total;        // default: isi sama dengan total
    updateChange();                        // hitung kembalian awal
    new bootstrap.Modal('#checkoutModal').show();
  });

  // kembalian realtime
  qs('#payAmount')?.addEventListener('input', updateChange);

  // konfirmasi bayar
  qs('#confirm-pay')?.addEventListener('click', onConfirmPay);
});

// =====================================================
// Produk
// =====================================================
async function loadProducts() {
  try {
    const r = await fetch('/api/products.php?q=');
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'Gagal ambil produk');
    PRODUCTS = j.data || [];
    renderProducts(PRODUCTS);
  } catch (e) {
    console.error(e);
    alert('Gagal koneksi ke server');
  }
}

function filterProducts(q) {
  q = (q || '').toLowerCase();
  if (!q) return PRODUCTS;
  return PRODUCTS.filter(p =>
    (p.name || '').toLowerCase().includes(q) ||
    (p.sku  || '').toLowerCase().includes(q)
  );
}

function renderProducts(list) {
  const wrap = qs('#product-list');
  wrap.innerHTML = '';

  if (!list.length) {
    wrap.innerHTML = `<div class="col-12"><div class="alert alert-warning mb-0">Belum ada produk.</div></div>`;
    return;
  }

  list.forEach(p => {
    const col = document.createElement('div');
    col.className = 'col-12 col-md-6 col-lg-4';
    col.innerHTML = `
      <div class="card h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="small text-muted">${p.sku || '-'}</div>
              <strong>${p.name || '-'}</strong>
            </div>
            <div class="text-end ms-2">
              <div>${rupiah(p.price)}</div>
              <div class="small text-muted">Stok: ${p.stock}</div>
            </div>
          </div>
        </div>
        <div class="card-footer d-flex justify-content-between">
          <button class="btn btn-sm btn-outline-primary" data-act="add" data-id="${p.id}">+ Keranjang</button>
          <div class="btn-group">
            <button class="btn btn-sm btn-outline-secondary" data-act="edit" data-id="${p.id}">Edit</button>
            <button class="btn btn-sm btn-outline-danger" data-act="del" data-id="${p.id}">Hapus</button>
          </div>
        </div>
      </div>`;
    wrap.appendChild(col);
  });

  wrap.querySelectorAll('[data-act="add"]').forEach(b =>
    b.addEventListener('click', () => addToCartById(b.dataset.id))
  );
  wrap.querySelectorAll('[data-act="edit"]').forEach(b =>
    b.addEventListener('click', () => fillFormById(b.dataset.id))
  );
  wrap.querySelectorAll('[data-act="del"]').forEach(b =>
    b.addEventListener('click', () => onDeleteProduk(b.dataset.id))
  );
}

// ---- CRUD Produk
async function onSaveProduk(e) {
  e.preventDefault();
  const id    = qs('#p-id').value.trim();
  const sku   = qs('#p-sku').value.trim();
  const name  = qs('#p-name').value.trim();
  const price = Number(qs('#p-price').value || 0);
  const stock = Number(qs('#p-stock').value || 0);
  if (!sku || !name) return showMsg('Isi SKU & Nama', 'danger');

  try {
    const fd = new FormData();
    if (id) fd.append('id', id);
    fd.append('sku', sku);
    fd.append('name', name);
    fd.append('price', String(price));
    fd.append('stock', String(stock));

    const r = await fetch('/api/product_save.php', { method: 'POST', body: fd });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'Gagal simpan');

    showMsg('Produk tersimpan', 'success');
    resetFormProduk();
    await loadProducts();
  } catch (err) {
    console.error(err);
    showMsg(err.message || 'Gagal simpan', 'danger');
  }
}

function fillFormById(id) {
  const p = PRODUCTS.find(x => String(x.id) === String(id));
  if (!p) return;
  qs('#p-id').value    = p.id;
  qs('#p-sku').value   = p.sku || '';
  qs('#p-name').value  = p.name || '';
  qs('#p-price').value = p.price || 0;
  qs('#p-stock').value = p.stock || 0;
  showMsg('Mode edit aktif', 'info');
}

async function onDeleteProduk(id) {
  const p = PRODUCTS.find(x => String(x.id) === String(id));
  if (!p) return;
  if (!confirm(`Hapus produk: ${p.name}?`)) return;

  try {
    const fd = new FormData();
    fd.append('id', id);
    const r = await fetch('/api/product_delete.php', { method: 'POST', body: fd });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'Gagal hapus');
    await loadProducts();
  } catch (e) {
    alert(e.message || 'Gagal menghapus');
  }
}

function resetFormProduk() {
  qs('#form-produk').reset();
  qs('#p-id').value = '';
  showMsg('');
}

function showMsg(t, type) {
  const el = qs('#produk-msg');
  el.className = 'small ' + (type ? `text-${type}` : 'text-muted');
  el.textContent = t || '';
}

// =====================================================
// Keranjang
// =====================================================
function addToCartById(id) {
  const p = PRODUCTS.find(x => String(x.id) === String(id));
  if (!p) return;
  const it = CART.find(x => x.id === p.id);
  if (it) it.qty += 1; else CART.push({ ...p, qty: 1 });
  renderCart();
}

function renderCart() {
  const wrap = qs('#cart-list');
  wrap.innerHTML = '';
  let total = 0;

  if (!CART.length) {
    wrap.innerHTML = `<div class="text-muted">Belum ada item.</div>`;
    qs('#total').textContent = rupiah(0);
    return;
  }

  CART.forEach(i => {
    total += i.qty * i.price;
    const row = document.createElement('div');
    row.className = 'd-flex justify-content-between align-items-center border rounded p-2 mb-2';
    row.innerHTML = `
      <div>
        <strong>${i.name}</strong>
        <div class="small text-muted">${i.sku} • ${rupiah(i.price)}</div>
      </div>
      <div class="d-flex align-items-center gap-2">
        <button class="btn btn-sm btn-outline-secondary" data-act="minus" data-id="${i.id}">-</button>
        <span>${i.qty}</span>
        <button class="btn btn-sm btn-outline-secondary" data-act="plus" data-id="${i.id}">+</button>
        <button class="btn btn-sm btn-outline-danger" data-act="remove" data-id="${i.id}">x</button>
      </div>`;
    wrap.appendChild(row);
  });

  wrap.querySelectorAll('[data-act]').forEach(b =>
    b.addEventListener('click', () => {
      const id = b.dataset.id;
      const act = b.dataset.act;
      if (act === 'minus') qty(id, -1);
      else if (act === 'plus') qty(id, 1);
      else remove(id);
    })
  );

  qs('#total').textContent = rupiah(total);
}

function qty(id, d) {
  const it = CART.find(x => String(x.id) === String(id));
  if (!it) return;
  it.qty += d;
  if (it.qty <= 0) CART = CART.filter(x => x !== it);
  renderCart();
}

function remove(id) {
  CART = CART.filter(x => String(x.id) !== String(id));
  renderCart();
}

function getCartTotal() {
  return CART.reduce((s, i) => s + i.qty * i.price, 0);
}

// =====================================================
// Checkout
// =====================================================
function updateChange() {
  const pay = Number(qs('#payAmount').value || 0);
  const total = getCartTotal();
  const change = Math.max(0, pay - total);
  qs('#change-label').textContent = rupiah(change);
}

async function onConfirmPay() {
  const pay = Number(qs('#payAmount').value || 0);
  const total = getCartTotal();
  const change = Math.max(0, pay - total);

  if (!CART.length) return alert('Keranjang masih kosong.');
  if (pay < total)  return alert('Nominal bayar kurang.');

  // Susun items sesuai yang diharapkan backend
  const items = CART.map(i => ({
    product_id: Number(i.id),            // backend pakai ID
    qty:        Number(i.qty),
    price:      Number(i.price),
    subtotal:   Number(i.qty * i.price)
  }));

  // Kirim sebagai FormData (bukan JSON)
  const fd = new FormData();
  fd.append('method',        qs('#payment').value || 'cash');
  fd.append('note',          (qs('#note').value || '').toString());
  fd.append('amount_paid',   String(pay));
  fd.append('change_amount', String(change));
  fd.append('total',         String(total));
  fd.append('items',         JSON.stringify(items)); // JSON string di field "items"

  const btn = qs('#confirm-pay');
  btn.disabled = true;
  btn.textContent = 'Memproses...';

  try {
    const res  = await fetch('/api/checkout.php', { method: 'POST', body: fd });
    const text = await res.text();
    let data; try { data = JSON.parse(text); } catch { throw new Error(text || 'Respon bukan JSON'); }
    if (!res.ok || !data?.ok) throw new Error(data?.error || 'Checkout gagal');

    // sukses
    CART = [];
    renderCart();
    await loadProducts();

    // tutup modal & bersihkan backdrop
    const m = bootstrap.Modal.getInstance(qs('#checkoutModal'));
    m?.hide();
    document.querySelectorAll('.modal-backdrop').forEach(e => e.remove());
    document.body.classList.remove('modal-open');

    alert(`Transaksi sukses!\nKembalian: ${rupiah(change)}`);
  } catch (err) {
    alert(err.message || 'Terjadi kesalahan saat checkout.');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Konfirmasi Bayar';
  }
}