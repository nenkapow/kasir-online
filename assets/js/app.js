// ===================== Kasir klasik – app.js (Product Manager + Barcode Scanner + Barcode field) =====================

const $ = (q) => document.querySelector(q);
const rupiah = (n) => 'Rp ' + Number(n || 0).toLocaleString('id-ID');

let PRODUCTS = [];
let CART = []; // {id, sku, name, price(sell), stock, cost_price, barcode, qty}

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

  // product manager
  openProdBtn: $('#btn-open-products'),
  prodModal: $('#productModal'),
  prodForm: $('#prod-form'),
  prodId: $('#prod-id'),
  prodSku: $('#prod-sku'),
  prodName: $('#prod-name'),
  prodPrice: $('#prod-price'),   // SELL
  prodCost: $('#prod-cost'),     // modal/read-only
  prodStock: $('#prod-stock'),   // read-only
  prodBarcode: $('#prod-barcode'),
  prodReset: $('#prod-reset'),
  prodSearch: $('#prod-search'),
  prodRows: $('#prod-rows'),
  genSkuBtn: $('#btn-gen-sku'),
  scanBarcodeBtn: $('#btn-scan-barcode'),

  // scanner reusable
  scanBtn: $('#scan-btn'),
  scanModal: $('#scanModal'),
  scanVideo: $('#scanVideo'),
  scanStatus: $('#scanStatus'),
  toggleCamera: $('#toggle-camera'),
  toggleTorch: $('#toggle-torch'),
};

window.addEventListener('DOMContentLoaded', () => {
  // silent login (kalau ada session)
  fetch('/api/login.php', { method: 'POST' }).catch(()=>{});

  loadProducts();

  // kasir
  els.search?.addEventListener('input', onSearchInput);
  els.search?.addEventListener('keydown', onSearchKey);
  els.suggest?.addEventListener('click', onSuggestClick);

  els.addBtn?.addEventListener('click', addFromBar);
  els.clearBtn?.addEventListener('click', clearAll);
  els.payBtn?.addEventListener('click', checkout);
  els.mbPay?.addEventListener('click', checkout);
  els.pay?.addEventListener('input', updateChange);

  // keyboard shortcuts
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { clearBar(); }
    if (e.key.toLowerCase() === 'b' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); checkout(); }
  });

  // manager produk
  els.openProdBtn?.addEventListener('click', openProductModal);
  els.prodForm?.addEventListener('submit', onProdSave);
  els.prodReset?.addEventListener('click', resetProdForm);
  els.prodSearch?.addEventListener('input', renderProductRows);
  els.genSkuBtn?.addEventListener('click', () => {
    const sku = generateSKU(els.prodName.value || '');
    if (sku) els.prodSku.value = sku;
  });

  // scanner
  setupScanner();

  // fokus awal
  setTimeout(()=>els.search?.focus(), 200);
});

// ---------- Data ----------
async function loadProducts(){
  try{
    const r = await fetch('/api/products.php?q=');
    const j = await r.json();
    if(!j.ok) throw new Error(j.error||'Gagal ambil produk');

    // Map: POS pakai price = sell_price
    PRODUCTS = (j.data || []).map(p => ({
      ...p,
      price: Number(p.sell_price || p.price || 0), // untuk kasir
      sell_price: Number(p.sell_price || 0),
      cost_price: Number(p.cost_price || 0),
      stock: Number(p.stock || 0),
      barcode: p.barcode || null,
    }));

    renderProductRows(); // refresh modal list
  }catch(e){ alert('Gagal memuat produk'); }
}

// ---------- Search / Suggest ----------
let selIndex = -1;

function onSearchInput(){
  const q = (els.search.value || '').trim().toLowerCase();
  selIndex = -1;
  if(!q){ els.suggest.classList.add('d-none'); els.suggest.innerHTML=''; return; }

  // filter by name/sku/startsWith barcode
  const list = PRODUCTS
    .filter(p =>
      (p.sku||'').toLowerCase().includes(q) ||
      (p.name||'').toLowerCase().includes(q) ||
      (p.barcode||'').toLowerCase().startsWith(q)
    )
    .slice(0, 12);

  if(!list.length){ els.suggest.classList.add('d-none'); els.suggest.innerHTML=''; return; }

  els.suggest.innerHTML = list.map((p,i)=>`
    <button type="button" class="list-group-item list-group-item-action" data-id="${p.id}" data-idx="${i}">
      <div class="d-flex justify-content-between">
        <div class="text-truncate me-3">
          <strong>${p.name}</strong>
          <div class="small text-muted">${p.sku} • Stok ${p.stock}${p.barcode ? ' • ' + p.barcode : ''}</div>
        </div>
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
  const qRaw = (els.search.value||'').trim();
  const q = qRaw.toLowerCase();
  const qty = Math.max(1, parseInt(els.qty.value||'1',10));
  if(!q) return;

  // 1) by exact barcode
  let p = PRODUCTS.find(x => (x.barcode||'').toLowerCase() === q);
  // 2) by exact SKU
  if(!p) p = PRODUCTS.find(x => (x.sku||'').toLowerCase() === q);
  // 3) by name includes
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
  els.mbBar.style.display = total()>0 ? 'flex' : 'none';
}

// ---------- Checkout ----------
async function checkout(){
  if(!CART.length){ alert('Belum ada item.'); return; }
  const t = total();
  const pay = Number(els.pay.value||0);
  if(pay < t && els.method.value==='cash'){ alert('Nominal bayar kurang.'); return; }

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
    await loadProducts();
  }catch(e){
    alert(e.message || 'Gagal melakukan checkout.');
  }finally{
    btns.forEach(b=>{ b.disabled=false; b.textContent='Bayar'; });
  }
}

// ---------- PRODUCT MANAGER ----------
let prodModalInst = null;

function openProductModal(){
  resetProdForm();
  renderProductRows();
  prodModalInst = bootstrap.Modal.getOrCreateInstance(els.prodModal);
  prodModalInst.show();
}

function resetProdForm(){
  els.prodForm?.reset();
  els.prodId.value = '';
  els.prodPrice.value = 0;
  els.prodCost.value = 0;
  els.prodStock.value = 0; // hanya tampil
  els.prodBarcode.value = '';
}

function renderProductRows(){
  if(!els.prodRows) return;
  const q = (els.prodSearch?.value || '').toLowerCase();
  const list = !q ? PRODUCTS : PRODUCTS.filter(p =>
    (p.name||'').toLowerCase().includes(q) ||
    (p.sku||'').toLowerCase().includes(q) ||
    (p.barcode||'').toLowerCase().includes(q)
  );

  els.prodRows.innerHTML = list.map(p=>`
    <tr>
      <td class="text-monospace">${p.sku||'-'}</td>
      <td>${p.name||'-'}${p.barcode ? `<div class="small text-muted">${p.barcode}</div>`:''}</td>
      <td class="text-end">${rupiah(p.sell_price||p.price||0)}</td>
      <td class="text-end">${rupiah(p.cost_price||0)}</td>
      <td class="text-center">${p.stock ?? 0}</td>
      <td class="text-end">
        <div class="btn-group btn-group-sm">
          <button class="btn btn-outline-primary" data-act="edit" data-id="${p.id}">Edit</button>
          <button class="btn btn-outline-danger" data-act="del" data-id="${p.id}">Hapus</button>
        </div>
      </td>
    </tr>
  `).join('');

  // delegasi klik
  els.prodRows.querySelectorAll('button[data-act]').forEach(btn=>{
    const id = btn.dataset.id;
    const act = btn.dataset.act;
    btn.onclick = () => {
      if(act==='edit') fillProdForm(id);
      else if(act==='del') deleteProduct(id);
    };
  });
}

function fillProdForm(id){
  const p = PRODUCTS.find(x=>String(x.id)===String(id));
  if(!p) return;
  els.prodId.value = p.id;
  els.prodSku.value = p.sku || '';
  els.prodName.value = p.name || '';
  els.prodPrice.value = Number(p.sell_price || p.price || 0);
  els.prodCost.value  = Number(p.cost_price || 0);
  els.prodStock.value = Number(p.stock || 0);
  els.prodBarcode.value = p.barcode || '';
}

async function onProdSave(e){
  e.preventDefault();
  const id = (els.prodId.value||'').trim();
  const sku = (els.prodSku.value||'').trim();
  const name = (els.prodName.value||'').trim();
  const sellPrice = Number(els.prodPrice.value||0);
  const barcode = (els.prodBarcode.value||'').trim();
  if(!sku || !name) return alert('SKU & Nama wajib diisi');

  const fd = new FormData();
  if(id) fd.append('id', id);
  fd.append('sku', sku);
  fd.append('name', name);
  fd.append('sell_price', String(sellPrice)); // SELL PRICE
  if(barcode) fd.append('barcode', barcode);  // BARCODE (unik, boleh kosong)

  try{
    const r = await fetch('/api/product_save.php', { method:'POST', body: fd });
    const j = await r.json();
    if(!j.ok) throw new Error(j.error || 'Gagal simpan');
    await loadProducts();
    renderProductRows();
    resetProdForm();
  }catch(err){
    alert(err.message || 'Gagal menyimpan produk');
  }
}

async function deleteProduct(id){
  const p = PRODUCTS.find(x=>String(x.id)===String(id));
  if(!p) return;
  if(!confirm(`Hapus produk "${p.name}"?`)) return;
  const fd = new FormData();
  fd.append('id', id);
  try{
    const r = await fetch('/api/product_delete.php', { method:'POST', body: fd });
    const j = await r.json();
    if(!j.ok) throw new Error(j.error || 'Gagal hapus');
    await loadProducts();
    renderProductRows();
  }catch(err){ alert(err.message || 'Gagal menghapus'); }
}

// ---------- SKU generator ----------
function generateSKU(name){
  // contoh: "Indomie Goreng Spesial" -> "IND-GRS-123"
  const clean = (name || '').replace(/[^A-Za-z0-9\s]/g,' ').trim();
  if(!clean) return '';
  const words = clean.split(/\s+/);
  const p1 = (words[0]||'').substring(0,3).toUpperCase();
  const p2 = (words.slice(1).map(w=>w[0]).join('') || 'X').substring(0,3).toUpperCase();
  // hindari tabrakan sederhana dengan 3 digit waktu
  const p3 = String(Date.now()).slice(-3);
  let sku = `${p1}-${p2}-${p3}`;
  // pastikan tidak duplikat di memory (seadanya; validasi unik final di server)
  if (PRODUCTS.some(p=>String(p.sku).toUpperCase()===sku)) {
    sku = `${p1}-${p2}-${Math.floor(Math.random()*900+100)}`;
  }
  return sku;
}

// ---------- Barcode Scanner (reusable) ----------
let scanModalInst = null;
let codeReader = null;               // ZXing.BrowserMultiFormatReader
let currentDeviceId = null;
let torchOn = false;
let activeStream = null;
// target: 'pos' atau element input (barcode field)
let scanTarget = 'pos';

function setupScanner(){
  if(els.scanBtn){
    els.scanBtn.addEventListener('click', async ()=>{
      scanTarget = 'pos';
      openScanner();
    });
  }
  if(els.scanBarcodeBtn){
    els.scanBarcodeBtn.addEventListener('click', async ()=>{
      scanTarget = els.prodBarcode; // element
      openScanner();
    });
  }

  els.scanModal?.addEventListener('hidden.bs.modal', stopScanner);
  els.toggleCamera?.addEventListener('click', switchCamera);
  els.toggleTorch?.addEventListener('click', toggleTorch);
}

async function openScanner(){
  scanModalInst = bootstrap.Modal.getOrCreateInstance(els.scanModal);
  scanModalInst.show();

  try{
    if (!isSecureContextOK()) {
      els.scanStatus.textContent = 'Kamera hanya bisa diakses lewat HTTPS atau localhost.';
      return;
    }
    els.scanStatus.textContent = 'Meminta izin kamera…';
    await ensureCameraPermission();
    await startScanner();
  }catch(err){
    els.scanStatus.textContent = 'Gagal akses kamera: ' + err.message;
  }
}

// helper: cek secure context (HTTPS) & dukungan API
function isSecureContextOK() {
  return (location.protocol === 'https:' || location.hostname === 'localhost') &&
         !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
}

// helper: minta izin kamera dulu biar enumerateDevices return label/deviceId
async function ensureCameraPermission() {
  try {
    const tmp = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
    tmp.getTracks().forEach(t => t.stop());
  } catch (err) {
    throw new Error('Izin kamera ditolak atau tidak tersedia');
  }
}

// helper: ambil daftar kamera
async function getVideoInputs() {
  if (!(navigator.mediaDevices && navigator.mediaDevices.enumerateDevices)) {
    if (window.ZXing && ZXing.BrowserVideoReader?.listVideoInputDevices) {
      return await ZXing.BrowserVideoReader.listVideoInputDevices();
    }
    throw new Error('Perangkat tidak mendukung enumerateDevices');
  }
  const devices = await navigator.mediaDevices.enumerateDevices();
  return devices.filter(d => d.kind === 'videoinput');
}

async function startScanner(){
  if(!window.ZXing){ els.scanStatus.textContent = 'Library scanner belum termuat.'; return; }
  codeReader = codeReader || new ZXing.BrowserMultiFormatReader();

  els.scanStatus.textContent = 'Menyiapkan kamera…';
  const devices = await getVideoInputs();
  if(!devices.length){ els.scanStatus.textContent = 'Kamera tidak ditemukan.'; return; }

  // pilih kamera belakang jika ada
  const backCam = devices.find(d => /back|rear|belakang/i.test(d.label || ''));
  currentDeviceId = (backCam || devices[0]).deviceId;

  await startDecodeFromDevice(currentDeviceId);
}

async function startDecodeFromDevice(deviceId){
  els.scanStatus.textContent = 'Membuka kamera…';
  await stopScanner(); // pastikan bersih

  const constraints = {
    video: {
      deviceId: deviceId ? { exact: deviceId } : undefined,
      focusMode: 'continuous',
      width: { ideal: 1280 },
      height: { ideal: 720 }
    },
    audio: false
  };

  const stream = await navigator.mediaDevices.getUserMedia(constraints);
  activeStream = stream;
  els.scanVideo.srcObject = stream;
  await els.scanVideo.play();

  els.scanStatus.textContent = 'Memindai…';

  codeReader.decodeFromVideoDevice(deviceId || null, els.scanVideo, (result, err) => {
    if(result){
      const text = String(result.getText() || '').trim();
      if(text){
        if (scanTarget === 'pos') {
          els.search.value = text; // isi ke kolom kasir
          scanModalInst?.hide();
          addFromBar();
        } else if (scanTarget && scanTarget.tagName === 'INPUT') {
          scanTarget.value = text; // isi ke input barcode
          scanModalInst?.hide();
        }
      }
    }
    // NotFoundError saat frame belum ada kode — abaikan
  });
}

async function switchCamera(){
  try{
    const devices = await getVideoInputs();
    if(devices.length < 2){ els.scanStatus.textContent = 'Kamera ganda tidak tersedia.'; return; }
    const idx = devices.findIndex(d => d.deviceId === currentDeviceId);
    const next = devices[(idx + 1) % devices.length];
    currentDeviceId = next.deviceId;
    await startDecodeFromDevice(currentDeviceId);
  }catch(err){
    els.scanStatus.textContent = 'Gagal ganti kamera: ' + err.message;
  }
}

async function toggleTorch(){
  try{
    const track = activeStream?.getVideoTracks?.()[0];
    if(!track) return;
    const caps = track.getCapabilities?.() || {};
    if(!caps.torch){ els.scanStatus.textContent = 'Senter tidak didukung kamera ini.'; return; }
    const on = !track.getConstraints()?.advanced?.some?.(a=>a.torch);
    await track.applyConstraints({ advanced: [{ torch: on }] });
    els.scanStatus.textContent = on ? 'Senter ON' : 'Senter OFF';
  }catch(err){
    els.scanStatus.textContent = 'Gagal set senter: ' + err.message;
  }
}

async function stopScanner(){
  try{ codeReader?.reset(); }catch(_){}
  try{
    if(activeStream){
      activeStream.getTracks().forEach(t => t.stop());
      activeStream = null;
    }
  }catch(_){}
  els.scanVideo.srcObject = null;
  els.scanStatus.textContent = '';
}

// ---------- misc ----------
function beep(){ try{ new AudioContext().close(); }catch(_){ /* nothing */ } }
