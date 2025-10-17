// ==== util ====
const rupiah = (n=0) =>
  new Intl.NumberFormat('id-ID', { style:'currency', currency:'IDR', maximumFractionDigits:0 }).format(Number(n||0));

const qs = s => document.querySelector(s);
const qsa = s => [...document.querySelectorAll(s)];

// ==== state ====
let PRODUCTS = [];
let CART = [];

// ==== load awal ====
window.addEventListener('DOMContentLoaded', () => {
  loadProducts();

  qs('#search').addEventListener('input', (e) => {
    renderProducts(filterProducts(e.target.value));
  });

  qs('#form-produk').addEventListener('submit', onSaveProduk);

  // tombol checkout -> buka modal
  qs('#btn-checkout')?.addEventListener('click', () => {
    if (!CART.length) return alert('Keranjang masih kosong.');
    qs('#modal-total').textContent = qs('#total').textContent;
    new bootstrap.Modal('#checkoutModal').show();
  });

  // >>>> INI YANG KURANG: konfirmasi bayar
  qs('#confirm-pay')?.addEventListener('click', onConfirmPay);
});

// ==== Produk: fetch & render ====
async function loadProducts() {
  try {
    const r = await fetch('/api/products.php?q=');
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'Gagal ambil produk');
    PRODUCTS = j.data || [];
    renderProducts(PRODUCTS);
  } catch (e) {
    alert('Gagal koneksi ke server');
    console.error(e);
  }
}

function filterProducts(q) {
  q = (q || '').toLowerCase();
  if (!q) return PRODUCTS;
  return PRODUCTS.filter(p =>
    (p.name||'').toLowerCase().includes(q) ||
    (p.sku||'').toLowerCase().includes(q)
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
              <strong>${p.name}</strong>
            </div>
            <div class="ms-2 text-end">
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
      </div>
    `;
    wrap.appendChild(col);
  });

  // binding tombol
  wrap.querySelectorAll('[data-act="add"]').forEach(btn => {
    btn.addEventListener('click', () => addToCartById(btn.dataset.id));
  });
  wrap.querySelectorAll('[data-act="edit"]').forEach(btn => {
    btn.addEventListener('click', () => fillFormById(btn.dataset.id));
  });
  wrap.querySelectorAll('[data-act="del"]').forEach(btn => {
    btn.addEventListener('click', () => onDeleteProduk(btn.dataset.id));
  });
}

// ==== Form Produk: simpan / isi / hapus ====
async function onSaveProduk(e) {
  e.preventDefault();
  const id    = qs('#p-id').value.trim();
  const sku   = qs('#p-sku').value.trim();
  const name  = qs('#p-name').value.trim();
  const price = Number(qs('#p-price').value || 0);
  const stock = Number(qs('#p-stock').value || 0);

  if (!sku || !name) return showMsg('Harap isi SKU & Nama', 'danger');

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
  qs('#p-id').value = p.id;
  qs('#p-sku').value = p.sku || '';
  qs('#p-name').value = p.name || '';
  qs('#p-price').value = p.price || 0;
  qs('#p-stock').value = p.stock || 0;
  qs('#p-sku').focus();
  showMsg('Mode edit: ubah lalu klik Simpan. Kosongkan ID untuk tambah baru.', 'info');
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
    if (!j.ok) throw new Error(j.error || 'Gagal menghapus');

    showMsg('Produk dihapus', 'success');
    await loadProducts();
  } catch (e) {
    console.error(e);
    showMsg(e.message || 'Gagal menghapus', 'danger');
  }
}

function resetFormProduk() {
  qs('#form-produk').reset();
  qs('#p-id').value = '';
  showMsg('', '');
}

function showMsg(text, type='') {
  const el = qs('#produk-msg');
  el.className = 'small';
  if (type) el.classList.add(`text-${type}`);
  el.textContent = text || '';
}

// ==== Keranjang ====
function addToCartById(id) {
  const p = PRODUCTS.find(x => String(x.id) === String(id));
  if (!p) return;
  const item = CART.find(x => x.id === p.id);
  if (item) item.qty += 1; else CART.push({ ...p, qty:1 });
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
      </div>
    `;
    wrap.appendChild(row);
  });

  wrap.querySelectorAll('[data-act="minus"]').forEach(b => b.onclick = () => qty(b.dataset.id, -1));
  wrap.querySelectorAll('[data-act="plus"]').forEach(b => b.onclick = () => qty(b.dataset.id, +1));
  wrap.querySelectorAll('[data-act="remove"]').forEach(b => b.onclick = () => remove(b.dataset.id));

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

// ==== Checkout ====
async function onConfirmPay() {
  if (!CART.length) return alert('Keranjang masih kosong.');
  const method = qs('#payment').value;
  const note   = qs('#note').value || '';

  // payload ringkas: id, qty, price
  const items = CART.map(it => ({ id: it.id, qty: it.qty, price: it.price }));

  try {
    const r = await fetch('/api/checkout.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ method, note, items })
    });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'Gagal menyimpan transaksi');

    // sukses: kosongkan keranjang, refresh produk (stok berkurang), tutup modal
    CART = [];
    renderCart();
    await loadProducts();

    const modalEl = document.getElementById('checkoutModal');
    const modal = bootstrap.Modal.getInstance(modalEl);
    modal?.hide();

    alert('Transaksi berhasil.\nNo. Struk: ' + j.data?.sale_id);
    qs('#note').value = '';
  } catch (e) {
    console.error(e);
    alert(e.message || 'Gagal menyimpan transaksi');
  }
}

// helper: konversi "Rp 12.345" -> 12345
const parseRupiahInt = (s='') => Number(String(s).replace(/[^\d]/g,'') || 0);

// ---- saat klik tombol Checkout: set default bayar = total & kembalian 0
qs('#btn-checkout')?.addEventListener('click', () => {
  if (!CART.length) return alert('Keranjang masih kosong.');
  const totalNum = CART.reduce((a,b)=>a + b.qty*b.price, 0);
  qs('#modal-total').textContent = rupiah(totalNum);
  const pay = qs('#pay-amount');
  pay.value = totalNum;                       // default = pas
  qs('#change-label').textContent = rupiah(0);
  new bootstrap.Modal('#checkoutModal').show();
});

// ---- hitung kembalian live
qs('#pay-amount')?.addEventListener('input', () => {
  const total = CART.reduce((a,b)=>a + b.qty*b.price, 0);
  const bayar = Number(qs('#pay-amount').value || 0);
  const kembali = Math.max(0, bayar - total);
  qs('#change-label').textContent = rupiah(kembali);
});

// ====== CHECKOUT ======
const modalEl = document.getElementById('checkoutModal');
const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
const btnConfirm = document.getElementById('confirm-pay');
const inputPay   = document.getElementById('payAmount');
const inputNote  = document.getElementById('note');
const selMethod  = document.getElementById('payment');

function parseJsonSafe(res) {
  return res.text().then(t => {
    try { return JSON.parse(t); }
    catch (e) { throw new Error('Invalid JSON dari server: ' + t.slice(0, 180)); }
  });
}

btnConfirm.addEventListener('click', async () => {
  try {
    // Kunci tombol
    btnConfirm.disabled = true;
    const oldTxt = btnConfirm.textContent;
    btnConfirm.textContent = 'Memproses...';

    // Hitung total & payload
    const total = getCartTotal(); // fungsi kamu yang sudah ada
    const amount_paid = Number(inputPay.value || 0);
    const change_amount = Math.max(0, amount_paid - total);

    const payload = {
      method: selMethod.value || 'cash',
      amount_paid,
      change_amount,
      note: (inputNote.value || '').trim(),
      items: cart.map(i => ({ sku: i.sku, qty: i.qty, price: i.price, subtotal: i.qty * i.price }))
    };

    if (payload.items.length === 0) {
      alert('Keranjang masih kosong.');
      return;
    }

    const res = await fetch('api/checkout.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    const json = await parseJsonSafe(res);

    if (!json.ok) {
      throw new Error(json.error || 'Checkout gagal.');
    }

    // Sukses: kosongkan keranjang, reload produk & tutup modal
    cart.length = 0;
    saveCart();
    renderCart();
    await loadProducts();

    // Tutup modal rapi
    bsModal.hide();

    // Info sukses + kembalian
    alert(`Transaksi sukses!\nKembalian: Rp ${Number(json.data?.change_amount ?? change_amount).toLocaleString('id-ID')}`);
  } catch (err) {
    console.error(err);
    alert(err.message || 'Terjadi kesalahan saat checkout.');
  } finally {
    // Selalu reset tombol & bersihkan backdrop yang mungkin tertinggal
    btnConfirm.disabled = false;
    btnConfirm.textContent = 'Konfirmasi Bayar';

    // Guard-rail: hapus backdrop nyangkut & modal-open
    document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('paddingRight');
  }
});

