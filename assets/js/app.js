// =====================================================
// Kasir Online - app.js (compact list + infinite scroll)
// =====================================================

// ---- util
const rupiah = n => 'Rp ' + Number(n || 0).toLocaleString('id-ID');
const qs = s => document.querySelector(s);

// ---- state
let PRODUCTS = [];
let CART = [];

// listing state (pagination/infinite)
const PAGE_SIZE = 20;               // tampil awal 20 item
let LIST = [];                      // hasil filter
let nextIndex = 0;                  // dari indeks mana lanjut render
let observer = null;                // IntersectionObserver sentinel

// ---- boot
window.addEventListener('DOMContentLoaded', () => {
  // auto-login (silent)
  fetch('/api/login.php', { method: 'POST' }).catch(()=>{});

  // load awal
  loadProducts();

  // cari realtime + reset paging
  const inputSearch = qs('#search');
  if (inputSearch) {
    inputSearch.addEventListener('input', e => {
      const q = e.target.value;
      startRender(filterProducts(q));
    });
  }

  // simpan produk
  qs('#form-produk')?.addEventListener('submit', onSaveProduk);

  // tombol checkout → modal
  qs('#btn-checkout')?.addEventListener('click', openCheckout);

  // kembalian realtime
  qs('#payAmount')?.addEventListener('input', updateChange);

  // konfirmasi bayar
  qs('#confirm-pay')?.addEventListener('click', onConfirmPay);

  // cleanup backdrop modal kalau perlu (PWA reload)
  document.addEventListener('DOMContentLoaded', cleanupBackdrops);
  window.addEventListener('pageshow', cleanupBackdrops);
});

// =====================================================
// Produk: load + filter + COMPACT RENDER
// =====================================================
async function loadProducts() {
  try {
    const r = await fetch('/api/products.php?q=');
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'Gagal ambil produk');
    PRODUCTS = j.data || [];
    startRender(PRODUCTS);
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

// ----- compact renderer (list-group) + pagination -----
function startRender(source) {
  LIST = Array.isArray(source) ? source : [];
  nextIndex = 0;

  const wrap = qs('#product-list');
  if (!wrap) return;

  // wadah list
  wrap.innerHTML = `
    <div id="lg" class="list-group"></div>
    <div class="d-grid mt-2">
      <button id="btn-more" class="btn btn-outline-secondary" style="display:none">Muat lebih…</button>
    </div>
    <div id="sentinel" class="py-2"></div>
  `;

  // event: tombol Muat lebih
  wrap.querySelector('#btn-more')?.addEventListener('click', appendMore);

  // event delegation utk tombol per baris (+/edit/hapus)
  wrap.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;
    const id = btn.dataset.id;
    const act = btn.dataset.act;
    if (act === 'add') addToCartById(id);
    else if (act === 'edit') fillFormById(id);
    else if (act === 'del') onDeleteProduk(id);
  });

  // render chunk pertama
  appendMore();

  // pasang infinite scroll (IntersectionObserver)
  setupObserver();
}

function setupObserver() {
  const sentinel = qs('#sentinel');
  if (!sentinel) return;

  if (observer) observer.disconnect();
  observer = new IntersectionObserver((entries) => {
    const entry = entries[0];
    if (entry && entry.isIntersecting) {
      appendMore();
    }
  }, { rootMargin: '400px 0px' });
  observer.observe(sentinel);
}

// render batch berikutnya
function appendMore() {
  const lg = qs('#lg');
  const btnMore = qs('#btn-more');
  if (!lg) return;

  // tidak ada data
  if (LIST.length === 0) {
    lg.innerHTML = `<div class="list-group-item text-muted">Belum ada produk.</div>`;
    btnMore && (btnMore.style.display = 'none');
    return;
  }

  const end = Math.min(nextIndex + PAGE_SIZE, LIST.length);
  const slice = LIST.slice(nextIndex, end);

  const frag = document.createDocumentFragment();
  slice.forEach(p => {
    const item = document.createElement('div');
    item.className = 'list-group-item d-flex align-items-center justify-content-between gap-2';

    // kiri: nama + sku + stok
    const left = document.createElement('div');
    left.className = 'flex-grow-1 text-truncate';
    left.innerHTML = `
      <div class="fw-semibold text-truncate">${p.name || '-'}</div>
      <div class="small text-muted text-truncate">${p.sku || '-'} • Stok ${p.stock ?? 0}</div>
    `;

    // tengah: harga
    const mid = document.createElement('div');
    mid.className = 'text-end';
    mid.innerHTML = `<div class="fw-semibold">${rupiah(p.price)}</div>`;

    // kanan: actions mini
    const right = document.createElement('div');
    right.className = 'btn-group btn-group-sm';
    right.innerHTML = `
      <button class="btn btn-outline-primary"   data-act="add"  data-id="${p.id}" title="Tambah">+</button>
      <button class="btn btn-outline-secondary" data-act="edit" data-id="${p.id}">Edit</button>
      <button class="btn btn-outline-danger"    data-act="del"  data-id="${p.id}">Hapus</button>
    `;

    item.appendChild(left);
    item.appendChild(mid);
    item.appendChild(right);
    frag.appendChild(item);
  });

  lg.appendChild(frag);
  nextIndex = end;

  // toggle tombol "Muat lebih…"
  const hasMore = nextIndex < LIST.length;
  if (btnMore) btnMore.style.display = hasMore ? '' : 'none';
}

// =====================================================
// CRUD Produk (tetap sama)
// =====================================================
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
    await loadProducts();           // reload + reset paging otomatis
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
  if (!el) return;
  el.className = 'small ' + (type ? `text-${type}` : 'text-muted');
  el.textContent = t || '';
}

// =====================================================
// Keranjang (tetap)
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
  if (!wrap) return;
  wrap.innerHTML = '';
  let total = 0;

  if (!CART.length) {
    wrap.innerHTML = `<div class="text-muted">Belum ada item.</div>`;
    qs('#total') && (qs('#total').textContent = rupiah(0));
    return;
  }

  CART.forEach(i => {
    total += i.qty * i.price;
    const row = document.createElement('div');
    row.className = 'd-flex justify-content-between align-items-center border rounded p-2 mb-2';
    row.innerHTML = `
      <div class="me-2">
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

  qs('#total') && (qs('#total').textContent = rupiah(total));
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
// Checkout (tetap, plus defender UI modal)
// =====================================================
function updateChange() {
  const pay = Number(qs('#payAmount')?.value || 0);
  const total = getCartTotal();
  const change = Math.max(0, pay - total);
  qs('#change-label') && (qs('#change-label').textContent = rupiah(change));
}

async function onConfirmPay() {
  const pay = Number(qs('#payAmount')?.value || 0);
  const total = getCartTotal();
  const change = Math.max(0, pay - total);

  if (!CART.length) return alert('Keranjang masih kosong.');
  if (pay < total)  return alert('Nominal bayar kurang.');

  const items = CART.map(i => ({
    product_id: Number(i.id),
    qty:        Number(i.qty),
    price:      Number(i.price),
    subtotal:   Number(i.qty * i.price)
  }));

  const fd = new FormData();
  fd.append('method',        qs('#payment')?.value || 'cash');
  fd.append('note',          (qs('#note')?.value || '').toString());
  fd.append('amount_paid',   String(pay));
  fd.append('change_amount', String(change));
  fd.append('total',         String(total));
  fd.append('items',         JSON.stringify(items));

  const btn = qs('#confirm-pay');
  if (btn) { btn.disabled = true; btn.textContent = 'Memproses...'; }

  try {
    const res  = await fetch('/api/checkout.php', { method: 'POST', body: fd });
    const text = await res.text();
    let data; try { data = JSON.parse(text); } catch { throw new Error(text || 'Respon bukan JSON'); }
    if (!res.ok || !data?.ok) throw new Error(data?.error || 'Checkout gagal');

    CART = [];
    renderCart();
    await loadProducts();

    // tutup modal & bersihkan backdrop
    const m = bootstrap.Modal.getInstance(qs('#checkoutModal')) || bootstrap.Modal.getOrCreateInstance(qs('#checkoutModal'));
    m?.hide();
    cleanupBackdrops();

    alert(`Transaksi sukses!\nKembalian: ${rupiah(change)}`);
  } catch (err) {
    alert(err.message || 'Terjadi kesalahan saat checkout.');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = 'Konfirmasi Bayar'; }
  }
}

// ------- Modal helpers -------
function cleanupBackdrops() {
  try {
    document.body.classList.remove('modal-open');
    document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
  } catch (_) {}
}

function openCheckout() {
  if (!Array.isArray(CART) || CART.length === 0) {
    alert('Keranjang masih kosong.');
    return;
  }
  const total = getCartTotal();
  qs('#modal-total')  && (qs('#modal-total').textContent = rupiah(total));
  qs('#payAmount')    && (qs('#payAmount').value = total);
  qs('#change-label') && (qs('#change-label').textContent = rupiah(0));

  const modalEl = qs('#checkoutModal');
  if (!modalEl) return alert('Elemen modal tidak ditemukan.');
  cleanupBackdrops();
  bootstrap.Modal.getOrCreateInstance(modalEl).show();
}
