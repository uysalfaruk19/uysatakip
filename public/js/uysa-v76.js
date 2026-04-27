(function() {
  'use strict';

  /* ── Yardımcı: localStorage wrapper ─────────────────────────── */
  const _ls = {
    get(k, def) {
      try { const v = localStorage.getItem(k); return v !== null ? JSON.parse(v) : def; }
      catch(e) { return def; }
    },
    set(k, v) {
      try { localStorage.setItem(k, JSON.stringify(v)); return true; }
      catch(e) { return false; }
    }
  };

  /* ── Toast ─────────────────────────────────────────────────── */
  window._uysaToast = window._uysaToast || function(msg, ms) {
    let el = document.getElementById('uysa-toast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'uysa-toast';
      el.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:99999;background:linear-gradient(135deg,#1e3a5f,#1a56db);color:#fff;padding:12px 20px;border-radius:12px;font-size:13px;font-weight:600;box-shadow:0 8px 32px rgba(0,0,0,0.25);max-width:360px;pointer-events:none;transition:opacity .3s';
      document.body.appendChild(el);
    }
    el.textContent = msg;
    el.style.opacity = '1';
    clearTimeout(el._t);
    el._t = setTimeout(() => { el.style.opacity = '0'; }, ms || 3000);
  };
  // Global toast alias
  window.toast = window._uysaToast;

  /* ── Müşteri Listesi ────────────────────────────────────────── */
  function _getCustomers() {
    try {
      const raw = localStorage.getItem('uysa_customers_v1');
      if (!raw) return ['GENEL'];
      const obj = JSON.parse(raw);
      const all = (obj.customers || []).filter(x => x && x !== 'GENEL');
      all.sort((a, b) => a.localeCompare(b, 'tr'));
      return ['GENEL', ...all];
    } catch(e) { return ['GENEL']; }
  }

  function _fillSelect(sel, items) {
    if (!sel) return;
    const prev = sel.value;
    sel.innerHTML = '<option value="">Seçiniz...</option>' +
      items.map(x => `<option value="${x}">${x}</option>`).join('');
    if (prev && items.includes(prev)) sel.value = prev;
  }

  /* ── CRM: Müşteri senkronizasyonu — tüm kaynaklardan birleştir ── */
  function _syncCustomersFromAllSources() {
    try {
      const raw = localStorage.getItem('uysa_customers_v1');
      const store = raw ? JSON.parse(raw) : {customers:[]};
      const existing = new Set((store.customers||[]).map(c => c.toUpperCase()));
      let added = 0;

      // 1. Gelir kayıtlarından müşteri çek
      try {
        const gelirler = JSON.parse(localStorage.getItem('uysa_gelirler')||'[]');
        gelirler.forEach(g => { if(g.musteri && !existing.has(g.musteri.toUpperCase())){ store.customers.push(g.musteri); existing.add(g.musteri.toUpperCase()); added++; }});
      } catch(e){}

      // 2. Günlük üretim kayıtlarından
      try {
        const uretim = JSON.parse(localStorage.getItem('uysa_gunluk_uretim')||'[]');
        uretim.forEach(u => { if(u.musteri && !existing.has(u.musteri.toUpperCase())){ store.customers.push(u.musteri); existing.add(u.musteri.toUpperCase()); added++; }});
      } catch(e){}

      // 3. CRM config key'lerinden (uysa_crm_XXX)
      for (let i=0; i<localStorage.length; i++){
        const k = localStorage.key(i);
        if(k && k.startsWith('uysa_crm_')){
          const cust = k.replace('uysa_crm_','');
          if(cust && cust!=='GENEL' && !existing.has(cust.toUpperCase())){
            store.customers.push(cust); existing.add(cust.toUpperCase()); added++;
          }
        }
      }

      // 4. Alacak kayıtlarından
      try {
        const alacaklar = JSON.parse(localStorage.getItem('uysa_alacaklar')||'[]');
        alacaklar.forEach(a => { if(a.musteri && !existing.has(a.musteri.toUpperCase())){ store.customers.push(a.musteri); existing.add(a.musteri.toUpperCase()); added++; }});
      } catch(e){}

      if (added > 0) {
        store.customers.sort((a,b) => a.localeCompare(b,'tr'));
        localStorage.setItem('uysa_customers_v1', JSON.stringify(store));
        console.log('[CRM Sync] ' + added + ' yeni müşteri eklendi');
      }
    } catch(e) { console.error('[CRM Sync] Hata:', e); }
  }

  /* ── CRM: Müşteri dropdown senkronizasyonu ──────────────────── */
  function _syncCrmDropdown() {
    _syncCustomersFromAllSources();
    const custs = _getCustomers();
    _fillSelect(document.getElementById('crmCustomerSel'), custs);
    _fillSelect(document.getElementById('customerSelect'), custs);
  }

  /* ── CRM: Veri Yükle & Özet Render ─────────────────────────── */
  function _crmRenderOzet(cust) {
    const div = document.getElementById('crmOzetIcerik');
    if (!div) return;
    if (!cust) {
      div.innerHTML = '<div style="text-align:center;padding:20px;color:#94a3b8"><div style="font-size:32px;margin-bottom:8px">👤</div><div style="font-weight:600">Müşteri seçin</div></div>';
      return;
    }
    const d = _ls.get('uysa_crm_' + cust, {});
    const kisi  = parseInt(d.kisi)  || 0;
    const fiyat = parseFloat(d.fiyat) || 0;
    const ayTahmin = kisi * fiyat * 22;

    // Gelir hesapla
    let toplamTahsilat = 0;
    try {
      const gelirler = _ls.get('uysa_gelirler', []);
      toplamTahsilat = gelirler.filter(g => g.musteri === cust).reduce((s, g) => s + (g.tutar || 0), 0);
    } catch(e) {}

    const fmt = n => (Number(n) || 0).toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2});
    const badge = (text, col) => `<span style="background:${col||'#dbeafe'};color:#1e40af;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700;margin:2px;display:inline-block">${text}</span>`;

    const VARDIYA = {tek:'Tek Vardiya', cift:'Çift Vardiya', uc:'Üç Vardiya'};
    const AYRAN   = {menuye_gore:'Menüye Göre', donusumlu:'Dönüşümlü', yogurt_sabit:'Yoğurt Sabit', ayran_sabit:'Ayran Sabit'};
    const EKMEK   = {roll:'Roll Ekmek', somun:'Somun', ikisi:'Roll+Somun'};
    const ODEME   = {cek:'Çek', havale:'Havale/EFT', acik_hesap:'Açık Hesap', kredi_karti:'Kredi Kartı'};

    div.innerHTML = `
      <div style="display:grid;gap:10px">
        <!-- Başlık -->
        <div style="background:linear-gradient(135deg,#1e3a5f,#1a56db);color:#fff;border-radius:12px;padding:12px">
          <div style="font-size:16px;font-weight:900;margin-bottom:4px">👤 ${cust}</div>
          ${d.ilgili ? `<div style="font-size:11px;opacity:.85">📋 ${d.ilgili}</div>` : ''}
          ${d.telefon ? `<div style="font-size:11px;opacity:.85">📞 ${d.telefon}</div>` : ''}
        </div>

        <!-- Kişi & Fiyat -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <div style="background:#eff6ff;border-radius:10px;padding:10px;text-align:center;border:1px solid #bfdbfe">
            <div style="font-size:9px;color:#6b7280;font-weight:700;text-transform:uppercase">Kişi/Gün</div>
            <div style="font-size:28px;font-weight:900;color:#1d4ed8;line-height:1.1">${kisi || '—'}</div>
          </div>
          <div style="background:#f0fdf4;border-radius:10px;padding:10px;text-align:center;border:1px solid #86efac">
            <div style="font-size:9px;color:#6b7280;font-weight:700;text-transform:uppercase">₺/Kişi/Gün</div>
            <div style="font-size:28px;font-weight:900;color:#16a34a;line-height:1.1">${fiyat ? fiyat.toFixed(2) : '—'}</div>
          </div>
        </div>

        <!-- Aylık tahmin -->
        ${ayTahmin > 0 ? `
        <div style="background:#fefce8;border-radius:10px;padding:10px;border:1px solid #fde047">
          <div style="font-size:10px;color:#854d0e;font-weight:700">📊 AYLIK TAHMİN (22 gün)</div>
          <div style="font-size:20px;font-weight:900;color:#92400e">${fmt(ayTahmin)} ₺</div>
          ${d.alSat && d.alisFiyat ? `
          <div style="margin-top:6px;padding-top:6px;border-top:1px solid #fde047">
            <div style="font-size:11px;color:#dc2626;font-weight:700">Tahmini Maliyet: <span style="font-size:14px">${fmt(kisi * d.alisFiyat * 22)} ₺</span></div>
            <div style="font-size:11px;color:${(fiyat - d.alisFiyat) >= 0 ? '#166534' : '#dc2626'};font-weight:900;margin-top:2px">Tahmini Kâr: <span style="font-size:16px">${fmt((fiyat - d.alisFiyat) * kisi * 22)} ₺</span></div>
          </div>` : ''}
          ${toplamTahsilat > 0 ? `<div style="font-size:10px;color:#6b7280;margin-top:2px">Toplam Tahsilat: <b>${fmt(toplamTahsilat)} ₺</b></div>` : ''}
        </div>` : ''}

        <!-- Al/Sat Bilgisi -->
        ${d.alSat ? `
        <div style="background:#fef3c7;border-radius:10px;padding:10px;border:1px solid #fbbf24">
          <div style="font-size:10px;font-weight:800;color:#92400e;margin-bottom:4px">🔄 AL/SAT MÜŞTERİSİ</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
            <div style="text-align:center;background:#fff;border-radius:8px;padding:6px;border:1px solid #fde68a">
              <div style="font-size:9px;color:#92400e;font-weight:700">ALIŞ</div>
              <div style="font-size:18px;font-weight:900;color:#dc2626">${d.alisFiyat ? fmt(d.alisFiyat) : '—'}</div>
              <div style="font-size:9px;color:#6b7280">₺/kişi</div>
            </div>
            <div style="text-align:center;background:#fff;border-radius:8px;padding:6px;border:1px solid #fde68a">
              <div style="font-size:9px;color:#92400e;font-weight:700">BİRİM KÂR</div>
              <div style="font-size:18px;font-weight:900;color:${(fiyat-(d.alisFiyat||0))>=0?'#166534':'#dc2626'}">${fmt(fiyat-(d.alisFiyat||0))}</div>
              <div style="font-size:9px;color:#6b7280">₺/kişi</div>
            </div>
          </div>
          ${d.tedarikci ? `<div style="font-size:11px;color:#78350f;margin-top:6px">🏪 Tedarikçi: <b>${d.tedarikci}</b></div>` : ''}
        </div>` : ''}

        <!-- Servis Konfigürasyonu -->
        <div style="background:#f8fafc;border-radius:10px;padding:10px;border:1px solid #e2e8f0">
          <div style="font-size:10px;font-weight:800;color:#334155;margin-bottom:6px">🍽️ SERVİS KONFİGÜRASYONU</div>
          <div style="display:flex;flex-wrap:wrap;gap:3px">
            ${d.anaYemek  ? badge(`Ana: ${d.anaYemek} Çeşit`)        : ''}
            ${d.yardimci  ? badge(`Yardımcı: ${d.yardimci} Çeşit`, '#dcfce7') : ''}
            ${d.corba     ? badge(`Çorba: ${d.corba} Çeşit`, '#fef3c7')  : ''}
            ${d.vardiya   ? badge(VARDIYA[d.vardiya]  || d.vardiya, '#e0f2fe') : ''}
            ${d.ayran     ? badge(AYRAN[d.ayran]      || d.ayran,   '#f3e8ff') : ''}
            ${d.ekmek     ? badge(EKMEK[d.ekmek]      || d.ekmek,   '#fce7f3') : ''}
            ${d.odemeDurum? badge(ODEME[d.odemeDurum] || d.odemeDurum, '#fff7ed') : ''}
            ${d.personel  ? badge(`👷 ${d.personel} Personel`, '#f0fdf4') : ''}
          </div>
        </div>

        ${d.diet || d.diff ? `
        <div style="background:#fff7ed;border-radius:10px;padding:10px;border:1px solid #fed7aa">
          <div style="font-size:10px;font-weight:800;color:#9a3412;margin-bottom:4px">📝 DİYET & NOTLAR</div>
          ${d.diet ? `<div style="font-size:11px;color:#7c2d12;margin-bottom:3px">🥗 ${d.diet}</div>` : ''}
          ${d.diff ? `<div style="font-size:11px;color:#7c2d12">⚠️ ${d.diff}</div>` : ''}
        </div>` : ''}

        ${d.mevcutEkipman || d.talepEkipman ? `
        <div style="background:#f8fafc;border-radius:10px;padding:10px;border:1px solid #e2e8f0">
          <div style="font-size:10px;font-weight:800;color:#334155;margin-bottom:4px">🔧 EKİPMAN</div>
          ${d.mevcutEkipman ? `<div style="font-size:11px;color:#374151">✅ ${d.mevcutEkipman}</div>` : ''}
          ${d.talepEkipman  ? `<div style="font-size:11px;color:#374151">📦 ${d.talepEkipman}</div>`  : ''}
        </div>` : ''}

        ${d.ekstraTalep ? `
        <div style="background:#f0f9ff;border-radius:10px;padding:8px;border:1px solid #bae6fd">
          <div style="font-size:10px;font-weight:800;color:#0369a1;margin-bottom:2px">💬 EKSTRA TALEP</div>
          <div style="font-size:11px;color:#0c4a6e">${d.ekstraTalep}</div>
        </div>` : ''}

        <!-- Kayıt tarihi -->
        <div style="font-size:10px;color:#9ca3af;text-align:right">
          Son güncelleme: ${d._savedAt || '—'}
        </div>
      </div>`;
  }

  /* ── CRM: Veri Yükle ────────────────────────────────────────── */
  function _crmLoadData() {
    const sel = document.getElementById('crmCustomerSel');
    const cust = sel ? sel.value : '';
    if (!cust) return;
    const d = _ls.get('uysa_crm_' + cust, {});
    const set = (id, val) => {
      const el = document.getElementById(id);
      if (el) el.value = (val !== undefined && val !== null) ? String(val) : '';
    };
    set('crmDietNotes',      d.diet);
    set('crmDiffNotes',      d.diff);
    set('crmKisi',           d.kisi || '');
    set('crmFiyat',          d.fiyat || '');
    set('crmIlgili',         d.ilgili);
    set('crmTelefon',        d.telefon);
    set('crmPersonel',       d.personel || '');
    set('crmMevcutEkipman',  d.mevcutEkipman);
    set('crmTalepEkipman',   d.talepEkipman);
    set('crmEkstraTalep',    d.ekstraTalep);
    set('crmAnaYemek',       d.anaYemek  || '1');
    set('crmYardimci',       d.yardimci  || '1');
    set('crmCorba',          d.corba     || '1');
    set('crmSalata',         d.salata    || '1');
    set('crmEkmek',          d.ekmek     || 'roll');
    set('crmVardiya',        d.vardiya   || 'tek');
    set('crmAyran',          d.ayran     || 'menuye_gore');
    set('crmOdemeDurum',     d.odemeDurum || '');
    // Porsiyon ayarları
    set('crmPorsiyonTip',    d.porsiyonTip || 'standart');
    set('crmPorsiyonKatsayi', d.porsiyonKatsayi || '1.00');
    set('crmPorsiyonNot',    d.porsiyonNot || '');
    // Al/Sat alanları
    var alSatCb = document.getElementById('crmAlSat');
    if(alSatCb) alSatCb.checked = d.alSat || false;
    set('crmAlisFiyat', d.alisFiyat || '');
    set('crmTedarikci', d.tedarikci || '');
    if(typeof crmAlSatToggle === 'function') crmAlSatToggle();
    // Personel listesi
    window._crmSeciliPersoneller = d.personelListesi || [];
    if(typeof crmRenderSeciliPersoneller === 'function') crmRenderSeciliPersoneller();
    // Özeti güncelle
    _crmRenderOzet(cust);
    // Menü dropdown'ını senkronize et
    const menuSel = document.getElementById('customerSelect');
    if (menuSel) {
      const opts = Array.from(menuSel.options).map(o => o.value);
      if (opts.includes(cust)) {
        menuSel.value = cust;
        menuSel.dispatchEvent(new Event('change'));
      }
    }
  }

  /* ── CRM: Kaydet ────────────────────────────────────────────── */
  function _crmSave() {
    const cust = document.getElementById('crmCustomerSel')?.value;
    if (!cust) { window._uysaToast('⚠️ Önce müşteri seçiniz.'); return; }
    const get = id => document.getElementById(id)?.value || '';
    const data = {
      diet:           get('crmDietNotes'),
      diff:           get('crmDiffNotes'),
      kisi:           parseInt(get('crmKisi'))    || 0,
      fiyat:          parseFloat(get('crmFiyat')) || 0,
      alSat:          document.getElementById('crmAlSat')?.checked || false,
      alisFiyat:      parseFloat(get('crmAlisFiyat')) || 0,
      tedarikci:      get('crmTedarikci'),
      ilgili:         get('crmIlgili'),
      telefon:        get('crmTelefon'),
      personel:       parseInt(get('crmPersonel')) || 0,
      personelListesi: window._crmSeciliPersoneller || [],
      mevcutEkipman:  get('crmMevcutEkipman'),
      talepEkipman:   get('crmTalepEkipman'),
      ekstraTalep:    get('crmEkstraTalep'),
      anaYemek:       get('crmAnaYemek')  || '1',
      yardimci:       get('crmYardimci')  || '1',
      corba:          get('crmCorba')     || '1',
      salata:         get('crmSalata')    || '1',
      ekmek:          get('crmEkmek')     || 'roll',
      vardiya:        get('crmVardiya')   || 'tek',
      ayran:          get('crmAyran')     || 'menuye_gore',
      odemeDurum:     get('crmOdemeDurum'),
      porsiyonTip:    get('crmPorsiyonTip') || 'standart',
      porsiyonKatsayi: parseFloat(get('crmPorsiyonKatsayi')) || 1.0,
      porsiyonNot:    get('crmPorsiyonNot'),
      _savedAt:       new Date().toLocaleString('tr-TR')
    };
    // localStorage + IndexedDB'ye yaz
    localStorage.setItem('uysa_crm_' + cust, JSON.stringify(data));
    if (typeof window._idbSet === 'function') window._idbSet('uysa_crm_' + cust, data);
    // Özeti güncelle
    _crmRenderOzet(cust);
    // Menü satırlarını güncelle
    if (typeof window._menuRebuildRows === 'function') window._menuRebuildRows();
    if (typeof window._menuRenderTable === 'function') window._menuRenderTable();
    // Özet paneline scroll
    const panel = document.getElementById('crmOzetPanel');
    if (panel) panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    // Al/Sat müşterinin mevcut gelirlerini gidere otomatik yansıt
    if(data.alSat && data.alisFiyat > 0){
      _syncAlSatGiderler(cust, data.alisFiyat, data.tedarikci||'');
    }
    window._uysaToast('✅ Müşteri kaydedildi: ' + cust);
  }

  /* ── CRM: Yeni Müşteri Ekle ─────────────────────────────────── */
  function _crmMusteriEkle() {
    const inp = document.getElementById('crmYeniMusteriInp');
    const name = (inp?.value || '').trim();
    if (!name) { window._uysaToast('⚠️ Müşteri adı giriniz.'); return; }
    if (name === 'GENEL') { window._uysaToast('⚠️ GENEL adı kullanılamaz.'); return; }
    // Müşteri store'u oku
    let store = { customers: [] };
    try {
      const raw = localStorage.getItem('uysa_customers_v1');
      if (raw) store = JSON.parse(raw);
    } catch(e) {}
    store.customers = store.customers || [];
    if (store.customers.includes(name)) {
      window._uysaToast('⚠️ Bu müşteri zaten var: ' + name); return;
    }
    store.customers.push(name);
    store.customers.sort((a, b) => a.localeCompare(b, 'tr'));
    localStorage.setItem('uysa_customers_v1', JSON.stringify(store));
    if (typeof window._idbSet === 'function') window._idbSet('uysa_customers_v1', store);
    inp.value = '';
    // Tüm dropdown'ları güncelle
    _syncCrmDropdown();
    // Yeni müşteriyi seç
    const sel = document.getElementById('crmCustomerSel');
    if (sel) { sel.value = name; _crmLoadData(); }
    window._uysaToast('✅ Müşteri eklendi: ' + name);
  }

  /* ── Toggle Weekend ─────────────────────────────────────────── */
  window._weekendOn = _ls.get('uysa_weekend_mode', false);

  function _applyWeekendState(on) {
    window._weekendOn = on;
    _ls.set('uysa_weekend_mode', on);
    // Tabloyu re-render
    if (typeof window._menuRenderTable === 'function') window._menuRenderTable();
    // Inline style override (CSS class'a ek güvence)
    requestAnimationFrame(() => {
      document.querySelectorAll('.weekend-col').forEach(el => {
        el.style.setProperty('display', on ? '' : 'none', 'important');
      });
      const tbl = document.querySelector('table[aria-label="Haftalık menü grid"]');
      if (tbl) tbl.classList.toggle('weekend-visible', on);
    });
  }

  function _setupWeekend() {
    const cb = document.getElementById('weekendToggle');
    if (!cb) return;
    // Mevcut durumu yükle
    cb.checked = window._weekendOn;
    _applyWeekendState(window._weekendOn);
    // Event listener — önceki removeEventListener ile temizle
    const newCb = cb.cloneNode(true); // listener'ları temizle
    cb.parentNode.replaceChild(newCb, cb);
    newCb.addEventListener('change', () => {
      window._weekendOn = newCb.checked;
      _applyWeekendState(newCb.checked);
    });
  }

  /* ── Takvim Modal ───────────────────────────────────────────── */
  function _renderCalendar() {
    const yearEl  = document.getElementById('year');
    const monthEl = document.getElementById('month');
    const custEl  = document.getElementById('customerSelect');
    const y = parseInt(yearEl?.value  || new Date().getFullYear());
    const m = parseInt(monthEl?.value || (new Date().getMonth() + 1));
    const cust = custEl?.value || 'GENEL';

    // Menü store'u oku
    let menuStore = {};
    try {
      const raw = localStorage.getItem('uysa_menu_grid_v2_dates') || '{}';
      menuStore = JSON.parse(raw);
    } catch(e) {}

    const TR_M = ['','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
    const DOW  = ['Pzt','Sal','Çar','Per','Cum','Cmt','Paz'];

    // Ay içindeki Pazartesi'leri bul
    function mondaysForMonth(yr, mo0) {
      function mondayOf(date) {
        const d = new Date(date); const day = (d.getDay() + 6) % 7;
        d.setDate(d.getDate() - day); d.setHours(0,0,0,0); return d;
      }
      function hasInMonth(mon) {
        for (let i = 0; i < 7; i++) {
          const d = new Date(mon); d.setDate(mon.getDate() + i);
          if (d.getMonth() === mo0) return true;
        }
        return false;
      }
      const first = new Date(yr, mo0, 1); first.setHours(0,0,0,0);
      let fwm = mondayOf(first);
      if (!hasInMonth(fwm)) { fwm = new Date(fwm); fwm.setDate(fwm.getDate() + 7); }
      const result = [];
      for (let i = 0; i < 6; i++) {
        const d = new Date(fwm); d.setDate(fwm.getDate() + i * 7);
        result.push(d);
      }
      return result;
    }

    // Bir gün için menü verisi bul
    function menuForDay(dayNum) {
      const d = new Date(y, m-1, dayNum);
      const dow = (d.getDay() + 6) % 7; // Mon=0..Sun=6
      const mondays = mondaysForMonth(y, m-1);
      let wkIdx = -1;
      for (let i = 0; i < mondays.length; i++) {
        const wkEnd = new Date(mondays[i]); wkEnd.setDate(mondays[i].getDate() + 6);
        if (d >= mondays[i] && d <= wkEnd) { wkIdx = i + 1; break; }
      }
      if (wkIdx < 0) return null;
      const ymPad = String(m).padStart(2, '0');
      const ymk = `${y}-${ymPad}`;
      const wkKey = 'W' + wkIdx;
      // Önce müşteriye özel, sonra GENEL'e bak
      const keys = [`${ymk}::${cust}`, `${ymk}::GENEL`];
      let wk = null;
      for (const key of keys) {
        wk = menuStore[key]?.weeks?.[wkKey];
        if (wk) break;
      }
      if (!wk) return null;
      const s  = wk.soups?.[dow]  || '';
      const s2 = wk.soups2?.[dow] || '';
      const mv = wk.mains?.[dow]  || '';
      const m2 = wk.mains2?.[dow] || '';
      const si = wk.sides?.[dow]  || '';
      const de = wk.dessertFruit?.[dow] || '';
      return (s || mv || si) ? {s, s2, m: mv, m2, si, de} : null;
    }

    const _td = new Date(); const today_str = _td.getFullYear()+'-'+String(_td.getMonth()+1).padStart(2,'0')+'-'+String(_td.getDate()).padStart(2,'0');
    const firstD = new Date(y, m-1, 1);
    const lastD  = new Date(y, m, 0);
    const startDow = firstD.getDay() === 0 ? 6 : firstD.getDay() - 1;

    let cells = '';
    for (let i = 0; i < startDow; i++)
      cells += `<div style="min-height:75px;background:#f8fafc;border-radius:8px"></div>`;

    for (let dd = 1; dd <= lastD.getDate(); dd++) {
      const ds  = `${y}-${String(m).padStart(2,'0')}-${String(dd).padStart(2,'0')}`;
      const isT = ds === today_str;
      const dow = new Date(y, m-1, dd).getDay();
      const isW = dow === 0 || dow === 6;
      const mn  = menuForDay(dd);
      cells += `<div style="min-height:75px;background:${isT?'#eff6ff':isW?'#faf5ff':'#fff'};border:${isT?'2px solid #1a56db':'1px solid #e5e7eb'};border-radius:8px;padding:5px;font-size:10px">
        <div style="font-weight:800;color:${isT?'#1a56db':isW?'#6d28d9':'#374151'};margin-bottom:3px">${dd}${isW?`<span style="font-size:9px;color:#6d28d9;margin-left:2px">${dow===6?'C':'P'}</span>`:''}</div>
        ${mn ? `<div style="line-height:1.4;color:#166534">
          ${mn.s  ? `<div>🍲 ${mn.s}${mn.s2 ? ' <small style="opacity:.7">/ '+mn.s2+'</small>' : ''}</div>` : ''}
          ${mn.m  ? `<div>🍽️ ${mn.m}${mn.m2 ? ' <small style="opacity:.7">/ '+mn.m2+'</small>' : ''}</div>` : ''}
          ${mn.si ? `<div>🥗 ${mn.si}</div>` : ''}
          ${mn.de ? `<div>🍮 ${mn.de}</div>` : ''}
        </div>` : '<div style="color:#d1d5db;font-size:11px;margin-top:4px">—</div>'}
      </div>`;
    }

    // Modal oluştur
    document.getElementById('uysa-calendar-modal')?.remove();
    const modal = document.createElement('div');
    modal.id = 'uysa-calendar-modal';
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:10000;display:flex;align-items:flex-start;justify-content:center;padding:18px;overflow:auto';
    modal.innerHTML = `
      <div style="background:#fff;border-radius:16px;width:100%;max-width:1000px;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden">
        <div style="background:linear-gradient(135deg,#1e3a5f,#1a56db);color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center">
          <b style="font-size:16px">📆 ${TR_M[m]} ${y} — ${cust}</b>
          <button onclick="document.getElementById('uysa-calendar-modal').remove()" style="background:none;border:none;color:#fff;cursor:pointer;font-size:24px;line-height:1;padding:0">×</button>
        </div>
        <div style="padding:16px">
          <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:3px;margin-bottom:6px">
            ${DOW.map((d,i) => `<div style="text-align:center;font-size:11px;font-weight:700;color:${i>=5?'#6d28d9':'#6b7280'};padding:4px">${d}</div>`).join('')}
          </div>
          <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px">${cells}</div>
        </div>
      </div>`;
    modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
    document.body.appendChild(modal);
  }

  /* ── DOMContentLoaded sonrası her şeyi bağla ────────────────── */
  function _init() {
    // 1. CRM dropdown'ı doldur
    _syncCrmDropdown();

    // 2. CRM müşteri dropdown onChange
    const crmSel = document.getElementById('crmCustomerSel');
    if (crmSel) {
      // Önceki listener'ları temizlemek için clone
      const newSel = crmSel.cloneNode(true);
      crmSel.parentNode.replaceChild(newSel, crmSel);
      newSel.addEventListener('change', _crmLoadData);
    }

    // 3. CRM Kaydet butonu
    const saveBtn = document.getElementById('crmSaveBtn');
    if (saveBtn) {
      const newBtn = saveBtn.cloneNode(true);
      saveBtn.parentNode.replaceChild(newBtn, saveBtn);
      newBtn.removeAttribute('onclick');
      newBtn.addEventListener('click', _crmSave);
    }

    // 4. CRM Müşteri Ekle butonu
    document.querySelectorAll('[onclick*="crmMusteriEkle"]').forEach(btn => {
      const newBtn = btn.cloneNode(true);
      btn.parentNode.replaceChild(newBtn, btn);
      newBtn.removeAttribute('onclick');
      newBtn.addEventListener('click', _crmMusteriEkle);
    });
    window.crmMusteriEkle = _crmMusteriEkle;

    // 5. CRM global fonksiyonları override
    window.crmLoadData = _crmLoadData;
    window.crmSaveBtnClick = _crmSave;
    window.crmRenderOzetInline = () => {
      const c = document.getElementById('crmCustomerSel')?.value;
      if (c) _crmRenderOzet(c);
    };

    // 6. Weekend toggle
    _setupWeekend();

    // 7. Takvim butonu
    const calBtn = document.getElementById('calendarViewBtn');
    if (calBtn) {
      const newCalBtn = calBtn.cloneNode(true);
      calBtn.parentNode.replaceChild(newCalBtn, calBtn);
      newCalBtn.addEventListener('click', _renderCalendar);
    }
    window.renderCalendarModal = _renderCalendar;

    // 8. Satış sekmesine tıklanınca müşteri listesi doldur
    document.querySelectorAll('[data-module="satis"]').forEach(btn => {
      btn.addEventListener('click', () => {
        setTimeout(() => {
          _syncCrmDropdown();
          // İlk müşteriyi seç varsa
          const sel = document.getElementById('crmCustomerSel');
          if (sel && !sel.value && sel.options.length > 1) {
            sel.selectedIndex = 1;
            _crmLoadData();
          }
        }, 50);
      });
    });

    // 9. renderTable wrapper - weekend state'i koru
    const origRender = window._menuRenderTable;
    window._menuRenderTable = function() {
      if (origRender) origRender();
      requestAnimationFrame(() => {
        const on = window._weekendOn;
        document.querySelectorAll('.weekend-col').forEach(el => {
          el.style.setProperty('display', on ? '' : 'none', 'important');
        });
        const tbl = document.querySelector('table[aria-label="Haftalık menü grid"]');
        if (tbl) tbl.classList.toggle('weekend-visible', on);
      });
    };

    // 10. CRM Ana/Yardımcı/Çorba değişince menüyü güncelle
    ['crmAnaYemek','crmYardimci','crmCorba'].forEach(id => {
      document.getElementById(id)?.addEventListener('change', () => {
        const c = document.getElementById('crmCustomerSel')?.value;
        if (c) _crmRenderOzet(c);
        if (typeof window._menuRebuildRows === 'function') window._menuRebuildRows();
        if (typeof window._menuRenderTable === 'function') window._menuRenderTable();
      });
    });

    console.log('✅ UYSA v78 patch loaded');
  }

  // DOM hazır mı?
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _init);
  } else {
    _init();
  }

})();
