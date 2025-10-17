// assets/js/app.js
// Simple cashier UI logic (initApp will run automatically after script load)
// Assumes server-side session auth is active (we called /api/login.php earlier)

const $ = s => document.querySelector(s);
const $$ = s => Array.from(document.querySelectorAll(s));

let CART = [];

function formatRp(v) {
  return 'Rp' + (Number(v || 0)).toLocaleString('id-ID');
}

async function loadProducts(q = '') {
  try {
    const url = '/api/products.php?q=' + encodeURIComponent(q);
    const r = await fetch(url, { credentials: 'same-origin' });
    const j = await r.json();
    if (!j.ok) {
      alert('Gagal ambil data produk: ' + (j.error || ''));
      return [];
    }
    return j.data || [];
  } catch (e) {
    console.error(e);
    alert('Gagal koneksi ke server');
    return [];
  }
}

function renderProducts(list) {
  const el = $('#product-list');
  el.innerHTML = '';
  if (!list.length) {
    el.innerHTML = '<div class="col-12"><p class="text-muted">Tidak ada produk</p></div>';
    return;
  }
  list.forEach(p => {
    const col = document.createElement('div');
    col.className = 'col-6 col-md-4';
    col.innerHTML = `
      <div class="card">
        <div class="card-body">
          <h6 class="card-title mb-1">${escapeHtml(p.name || p.sku || 'Produk')}</h6>
          <p class="card-text small text-muted mb-1">SKU: ${escapeHtml(p.sku || '')}</p>
          <p class="card-text fw-bold">${formatRp(p.price)}</p>
          <div class="d-flex justify-content-between">
            <button class="btn btn-sm btn-primary btn-add" data-sku="${escapeHtml(p.sku)}">Tambah</button>
            <small class="text-muted">Stok: ${p.stock ?? '-'}</small>
          </div>
        </div>
      </div>
    `;
    el.appendChild(col);
  });

  $$('.btn-add').forEach(btn => btn.addEventListener('click', e => {
    const sku = e.currentTarget.dataset.sku;
    const prod = list.find(x => x.sku === sku);
    if (prod) addToCart(prod);
  }));
}

function escapeHtml(s) {
  if (!s) return '';
  return String(s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

function addToCart(prod) {
  const existing = CART.find(x => x.sku === prod.sku);
  if (existing) existing.qty++;
  else CART.push({ sku: prod.sku, name: prod.name, price: Number(prod.price), qty: 1 });
  renderCart();
}

function renderCart() {
  const el = $('#cart-list');
  el.innerHTML = '';
  let total = 0;
  if (!CART.length) el.innerHTML = '<p class="text-muted">Keranjang kosong</p>';
  CART.forEach(item => {
    const row = document.createElement('div');
    row.className = 'd-flex justify-content-between align-items-center py-1';
    row.innerHTML = `
      <div>
        <div class="fw-bold">${escapeHtml(item.name)}</div>
        <div class="small text-muted">${escapeHtml(item.sku)}</div>
      </div>
      <div class="text-end">
        <div>${formatRp(item.price)} x ${item.qty}</div>
        <div class="mt-1">
          <button class="btn btn-sm btn-outline-secondary btn-decr">-</button>
          <button class="btn btn-sm btn-outline-secondary btn-incr">+</button>
        </div>
      </div>
    `;
    el.appendChild(row);

    row.querySelector('.btn-decr').addEventListener('click', () => {
      item.qty = Math.max(0, item.qty - 1);
      if (item.qty === 0) CART = CART.filter(c => c.sku !== item.sku);
      renderCart();
    });
    row.querySelector('.btn-incr').addEventListener('click', () => {
      item.qty++;
      renderCart();
    });

    total += item.price * item.qty;
  });
  $('#total').textContent = formatRp(total);
  $('#modal-total').textContent = formatRp(total);
}

async function doCheckout() {
  if (!CART.length) return alert('Keranjang kosong');
  const payment = $('#payment').value;
  const note = $('#note').value || '';
  const payload = {
    items: CART,
    payment,
    note
  };
  try {
    const r = await fetch('/api/sales.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    });
    const j = await r.json();
    if (!j.ok) {
      alert('Gagal simpan transaksi: ' + (j.error || ''));
      return;
    }
    // sukses
    CART = [];
    renderCart();
    bootstrap.Modal.getInstance($('#checkoutModal')).hide();
    alert('Transaksi tersimpan. ID: ' + (j.id || '-'));
  } catch (e) {
    console.error(e);
    alert('Gagal menyimpan transaksi (koneksi).');
  }
}

// initApp dipanggil setelah auto-login di index.html (atau bisa dipanggil langsung)
async function initApp() {
  // search handler
  const search = $('#search');
  let lastQ = '';
  async function doSearch(q) {
    const list = await loadProducts(q);
    renderProducts(list);
    lastQ = q;
  }
  search.addEventListener('input', (e) => {
    const q = e.target.value.trim();
    // debounce sederhana
    clearTimeout(search._t);
    search._t = setTimeout(() => doSearch(q), 250);
  });

  // tombol checkout
  $('#btn-checkout').addEventListener('click', () => {
    const modal = new bootstrap.Modal($('#checkoutModal'));
    $('#modal-total').textContent = $('#total').textContent;
    modal.show();
  });
  $('#confirm-pay').addEventListener('click', doCheckout);

  // initial load (tampilkan semua)
  await doSearch('');
  renderCart();
}

// Start app automatically if available (index.html calls login earlier, session should be created)
// If index.html didn't call login, still init app (server may allow access with APP_REQUIRE_PIN=0)
document.addEventListener('DOMContentLoaded', () => {
  // small delay to allow auto-login request to settle
  setTimeout(() => {
    if (typeof initApp === 'function') initApp();
  }, 250);
});
