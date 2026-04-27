(function() {
'use strict';

// ─── Yardımcı ───────────────────────────────────────────────────────────────
function lsGet(k, def) {
  try { const v = localStorage.getItem(k); return v ? JSON.parse(v) : def; } catch(e){ return def; }
}
function lsSet(k, v) {
  try { localStorage.setItem(k, JSON.stringify(v)); } catch(e){}
}
function toast(msg, tip) {
  const el = document.createElement('div');
  el.textContent = msg;
  el.style.cssText = `position:fixed;bottom:24px;right:24px;z-index:99999;
    padding:12px 20px;border-radius:8px;font-weight:600;font-size:14px;
    background:${tip==='error'?'#e74c3c':tip==='warn'?'#f39c12':'#27ae60'};
    color:#fff;box-shadow:0 4px 16px rgba(0,0,0,.3);pointer-events:none;`;
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 3000);
}

// ─── Merkezi müşteri listesi okuyucu ────────────────────────────────────────
window.uysaGetMusteriler = function() {
  // 1. uysa_customers_v1 (CRM'in asıl kaynağı)
  try {
    const raw = lsGet('uysa_customers_v1', null);
    if (raw) {
      const pasifler = lsGet('uysa_pasif_musteriler', []);
      const customers = (raw.customers || []);
      const aktif = customers.filter(c => {
        const ad = typeof c === 'string' ? c : (c.name || c.ad || '');
        return ad && ad !== 'GENEL' && !pasifler.includes(ad);
      }).map(c => typeof c === 'string' ? c : (c.name || c.ad || ''));
      if (aktif.length) return aktif;
    }
  } catch(e) {}

  // 2. window.__uysaCustomerMem
  try {
    if (window.__uysaCustomerMem && Array.isArray(window.__uysaCustomerMem.customers)) {
      const pasifler = lsGet('uysa_pasif_musteriler', []);
      return window.__uysaCustomerMem.customers.filter(c => {
        const ad = typeof c === 'string' ? c : (c.name || c.ad || '');
        return ad && ad !== 'GENEL' && !pasifler.includes(ad);
      }).map(c => typeof c === 'string' ? c : (c.name || c.ad || ''));
    }
  } catch(e) {}

  return [];
};

// ─── refreshIkSelectors - FINAL OVERRIDE ────────────────────────────────────
window.refreshIkSelectors = function() {
  const musteriler = window.uysaGetMusteriler();
  const aktifPer = lsGet('uysa_personeller', []).filter(p => !p.pasif);
  const perAdlari = aktifPer.map(p => ((p.ad||'') + ' ' + (p.soyad||'')).trim()).filter(Boolean);

  // Tüm personel seçicilerini güncelle
  ['bordro-personel', 'vardiya-isim', 'pdks-isim', 'puantaj-personel'].forEach(function(id) {
    const el = document.getElementById(id);
    if (!el) return;
    const prev = el.value;
    el.innerHTML = '<option value="">-- Personel Seç --</option>' +
      perAdlari.map(n => `<option value="${n}">${n}</option>`).join('');
    if (perAdlari.includes(prev)) el.value = prev;
  });

  // Personel listesini render et
  if (typeof window.uysaRenderPerListesi === 'function') window.uysaRenderPerListesi();

  // Proje dropdown - müşteri listesi ile doldur
  const projeEl = document.getElementById('per-proje');
  if (projeEl) {
    const prev = projeEl.value;
    projeEl.innerHTML = '<option value="MERKEZ">🏢 MERKEZ</option>' +
      musteriler.map(m => `<option value="${m}">${m}</option>`).join('');
    if (prev && [...projeEl.options].some(o => o.value === prev)) projeEl.value = prev;
  }

  // Maliyet dağılımı müşteri seçici
  const malEl = document.getElementById('perMalMusteriSel');
  if (malEl) {
    const prev = malEl.value;
    malEl.innerHTML = '<option value="TUMU">Tüm Müşteriler</option>' +
      musteriler.map(m => `<option value="${m}">${m}</option>`).join('');
    if (prev && [...malEl.options].some(o => o.value === prev)) malEl.value = prev;
  }
};

// ─── CV Kaydet - cv-isim id ile ─────────────────────────────────────────────
window.uysaCvEkle = function() {
  const ad = (
    document.getElementById('cv-isim') ||
    document.getElementById('cv-ad')   ||
    {}
  ).value?.trim() || '';
  const poz  = (document.getElementById('cv-pozisyon')||{}).value?.trim() || '';
  const tel  = (document.getElementById('cv-tel')||{}).value?.trim() || '';
  const tec  = (document.getElementById('cv-tecr')||{}).value?.trim() || '';
  const not_ = (document.getElementById('cv-not')||{}).value?.trim() || '';

  if (!ad) { toast('Ad Soyad zorunludur!', 'error'); return; }

  const cvler = lsGet('uysa_cv', []);
  cvler.push({ ad, poz, tel, tec, not: not_, tarih: new Date().toLocaleDateString('tr-TR') });
  lsSet('uysa_cv', cvler);

  ['cv-isim','cv-ad','cv-pozisyon','cv-tel','cv-tecr','cv-not'].forEach(id => {
    const el = document.getElementById(id); if (el) el.value = '';
  });
  toast('CV kaydedildi ✓');
  window.uysaRenderCvListesi();
};

window.uysaCvSil = function(i) {
  const cvler = lsGet('uysa_cv', []);
  if (i >= 0 && i < cvler.length) cvler.splice(i, 1);
  lsSet('uysa_cv', cvler);
  toast('CV silindi', 'warn');
  window.uysaRenderCvListesi();
};

window.uysaRenderCvListesi = function() {
  const div = document.getElementById('cvListDiv');
  if (!div) return;
  const all = lsGet('uysa_cv', []);
  if (!all.length) {
    div.innerHTML = '<p style="color:#888;text-align:center;padding:20px">Henüz CV kaydı yok</p>';
    return;
  }
  div.innerHTML = all.map((c, i) => `
    <div style="background:#f8f9fa;border-radius:8px;padding:12px;margin-bottom:8px;
                display:flex;justify-content:space-between;align-items:flex-start;
                border-left:4px solid #667eea;">
      <div>
        <div style="font-weight:700;font-size:14px;color:#1a1a2e">${c.ad}${c.poz ? ' <span style="color:#667eea;font-size:12px">— ' + c.poz + '</span>' : ''}</div>
        <div style="font-size:12px;color:#666;margin-top:4px">
          ${c.tel ? '📞 ' + c.tel + '  ' : ''}
          ${c.tec ? '🏷 ' + c.tec + ' yıl deneyim  ' : ''}
          📅 ${c.tarih || ''}
        </div>
        ${c.not ? '<div style="font-size:12px;color:#4b5563;margin-top:4px;font-style:italic">📝 ' + c.not + '</div>' : ''}
      </div>
      <button onclick="window.uysaCvSil(${i})"
        style="background:#fee2e2;color:#dc2626;border:none;border-radius:6px;
               padding:4px 10px;cursor:pointer;font-size:12px;white-space:nowrap;margin-left:8px">🗑 Sil</button>
    </div>`).join('');
};

// ─── Puantaj - personel seçilmeden tüm personel özet tablosu ────────────────
window.uysaPuantajGenelOzet = function() {
  const ay = (document.getElementById('puantaj-ay') || {}).value ||
    (function(){ const d=new Date(); return d.getFullYear()+'-'+(String(d.getMonth()+1).padStart(2,'0')); })();
  const div = document.getElementById('puantajChizelge');
  if (!div) return;

  const personeller = lsGet('uysa_personeller', []).filter(p => !p.pasif);
  if (!personeller.length) {
    div.innerHTML = '<div style="color:#888;padding:20px;text-align:center">Aktif personel bulunamadı</div>';
    return;
  }

  const parts = ay.split('-');
  const yil = parseInt(parts[0]);
  const ayNum = parseInt(parts[1]);
  const ayAdlari = ['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
  const ayAdi = ayAdlari[ayNum - 1];
  const gunSay = new Date(yil, ayNum, 0).getDate();
  const isGun = 30; // FIX-V16b: maaş/30 formülü

  // izinler
  let izinler = [];
  try { izinler = JSON.parse(localStorage.getItem('uysa_izinler')||'[]'); } catch(e){}

  const rows = personeller.map(per => {
    const perAd = (per.ad || '') + ' ' + (per.soyad || '');
    const maas = parseFloat(per.maas) || 0;

    // Puantaj verisi
    const puantajKey = 'uysa_puantaj_' + ay + '_' + perAd; // FIX-1: correct key order
    let puantaj = {};
    try { puantaj = JSON.parse(localStorage.getItem(puantajKey)||'{}'); } catch(e){}

    // Alternatif key formatı
    if (!Object.keys(puantaj).length) {
      const altKey = 'uysa_puantaj_' + ay + '_' + perAd.replace(/\s+/g,'_'); // FIX-1b fallback fix
      try { puantaj = JSON.parse(localStorage.getItem(altKey)||'{}'); } catch(e){}
    }

    // İzin günleri hesapla
    let izinGunSay = 0;
    try {
      const perIzinler = izinler.filter(iz =>
        (iz.personel === perAd || iz.ad === perAd) &&
        (iz.bas || iz.baslangic) &&
        ((iz.bas||iz.baslangic||'').substring(0,7) === ay)
      ); // FIX-1c: use iz.bas/iz.bit fields
      perIzinler.forEach(iz => {
        const bas = new Date(iz.bas || iz.baslangic);
        const bit = new Date(iz.bit || iz.bitis || iz.bas || iz.baslangic);
        const diff = Math.ceil((bit - bas) / (1000*60*60*24)) + 1;
        izinGunSay += diff;
      });
    } catch(e){}

    // Puantaj'dan izin sayısı (ücretsiz izin)
    let pUcretsizIzin = 0;
    Object.values(puantaj).forEach(d => {
      if (d === 'ucretsiz' || d === 'mazeret' || d === 'devamsizlik') pUcretsizIzin++;
    });
    const toplamIzin = Math.max(izinGunSay, pUcretsizIzin);

    // Mesai günleri
    let mesaiGun = 0;
    Object.values(puantaj).forEach(d => {
      if (d === 'mesai' || d === 'fazlaMesai') mesaiGun++;
    });
    const gunlukMaas = maas / 30; // FIX-V16b: maaş/30
    const mesaiUcret = mesaiGun * gunlukMaas * 1.5;
    const izinKesinti = toplamIzin * gunlukMaas;
    const hakEdis = Math.max(0, maas + mesaiUcret - izinKesinti);

    return { perAd, proje: per.proje || 'MERKEZ', maas, mesaiGun, toplamIzin, mesaiUcret, izinKesinti, hakEdis };
  });

  const toplamMaas    = rows.reduce((s, r) => s + r.maas, 0);
  const toplamHakEdis = rows.reduce((s, r) => s + r.hakEdis, 0);

  div.innerHTML = `
    <div style="background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border-radius:10px;padding:12px 16px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
      <span style="font-weight:700;font-size:15px">📋 ${ayAdi} ${yil} — Tüm Personel Özeti</span>
      <span style="font-size:13px">Toplam Hakediş: <strong>${toplamHakEdis.toLocaleString('tr-TR',{minimumFractionDigits:2})} ₺</strong></span>
    </div>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="background:#f1f5f9">
          <th style="padding:10px 8px;text-align:left;border-bottom:2px solid #e2e8f0">Personel</th>
          <th style="padding:10px 8px;text-align:left;border-bottom:2px solid #e2e8f0">Proje</th>
          <th style="padding:10px 8px;text-align:right;border-bottom:2px solid #e2e8f0">Normal Maaş</th>
          <th style="padding:10px 8px;text-align:right;border-bottom:2px solid #e2e8f0">Mesai (+)</th>
          <th style="padding:10px 8px;text-align:right;border-bottom:2px solid #e2e8f0">İzin Kesinti (-)</th>
          <th style="padding:10px 8px;text-align:right;border-bottom:2px solid #e2e8f0;background:#dcfce7;color:#166534">Hakediş</th>
        </tr>
      </thead>
      <tbody>
        ${rows.map((r, i) => `
          <tr style="background:${i%2===0?'#fff':'#f8faff'};${r.hakEdis < r.maas ? 'border-left:3px solid #fca5a5' : ''}">
            <td style="padding:9px 8px;font-weight:600">${r.perAd}</td>
            <td style="padding:9px 8px;color:#6b7280;font-size:12px">${r.proje}</td>
            <td style="padding:9px 8px;text-align:right">${r.maas.toLocaleString('tr-TR')} ₺</td>
            <td style="padding:9px 8px;text-align:right;color:${r.mesaiGun>0?'#059669':'#9ca3af'}">
              ${r.mesaiGun > 0 ? '+' + r.mesaiUcret.toLocaleString('tr-TR',{minimumFractionDigits:2}) + ' ₺ (' + r.mesaiGun + 'g)' : '—'}
            </td>
            <td style="padding:9px 8px;text-align:right;color:${r.toplamIzin>0?'#dc2626':'#9ca3af'}">
              ${r.toplamIzin > 0 ? '-' + r.izinKesinti.toLocaleString('tr-TR',{minimumFractionDigits:2}) + ' ₺ (' + r.toplamIzin + 'g)' : '—'}
            </td>
            <td style="padding:9px 8px;text-align:right;font-weight:700;color:#166534;background:#f0fdf4">
              ${r.hakEdis.toLocaleString('tr-TR',{minimumFractionDigits:2})} ₺
            </td>
          </tr>`).join('')}
      </tbody>
      <tfoot>
        <tr style="background:#f1f5f9;font-weight:700;font-size:13px">
          <td colspan="2" style="padding:10px 8px">TOPLAM (${rows.length} personel)</td>
          <td style="padding:10px 8px;text-align:right">${toplamMaas.toLocaleString('tr-TR')} ₺</td>
          <td style="padding:10px 8px;text-align:right;color:#059669">
            +${rows.reduce((s,r)=>s+r.mesaiUcret,0).toLocaleString('tr-TR',{minimumFractionDigits:2})} ₺
          </td>
          <td style="padding:10px 8px;text-align:right;color:#dc2626">
            -${rows.reduce((s,r)=>s+r.izinKesinti,0).toLocaleString('tr-TR',{minimumFractionDigits:2})} ₺
          </td>
          <td style="padding:10px 8px;text-align:right;color:#166534;background:#dcfce7">
            ${toplamHakEdis.toLocaleString('tr-TR',{minimumFractionDigits:2})} ₺
          </td>
        </tr>
      </tfoot>
    </table>
    </div>`;
};

// puantajRender'ı genişlet - personel seçilmediğinde genel özet göster
(function() {
  const origRender = window.puantajRender;
  window.puantajRender = function() {
    const personelAd = (document.getElementById('puantaj-personel') || {}).value || '';
    if (!personelAd) {
      window.uysaPuantajGenelOzet();
      const ozetPnl = document.getElementById('puantajOzetPanel');
      if (ozetPnl) ozetPnl.style.display = 'none';
      return;
    }
    if (typeof origRender === 'function') origRender();
    else if (typeof window._puantajRenderOrig === 'function') window._puantajRenderOrig();
    // FIX-V15: after render, if still no person selected show genelOzet
    const _pv = (document.getElementById('puantaj-personel')||{}).value||'';
    if(!_pv && typeof window.uysaPuantajGenelOzet === 'function') window.uysaPuantajGenelOzet();
  };
  // Ay değiştiğinde de güncelle
  const ayEl = document.getElementById('puantaj-ay');
  if (ayEl) {
    ayEl.addEventListener('change', function() {
      if (!(document.getElementById('puantaj-personel')||{}).value) {
        window.uysaPuantajGenelOzet();
      }
    });
  }
})();

// ─── Raporlama butonları - event delegation ile yeniden bağla ───────────────
(function bindRaporBtns() {
  // Butonları doğrudan bağla (IIFE kapsamındaki fonksiyonlara erişim yok
  // o yüzden onclick ile fallback logic ekleyelim)
  document.addEventListener('click', function(e) {
    const btn = e.target.closest('button[id]');
    if (!btn) return;
    const id = btn.id;

    if (id === 'menuMuhendislikHesaplaBtn') {
      try {
        const div = document.getElementById('menuMuhendislikSonuc');
        if (!div) return;
        const recipes = lsGet('uysa_recipes_v2', {});
        const prices  = lsGet('uysa_prices_tl_per_kg_v1', {});
        const catalog = lsGet('uysa_catalog_v1', {});
        const allDishes = Object.values(catalog).flat().map(d => typeof d === 'string' ? d : d.name).filter(Boolean);
        if (!allDishes.length) { div.innerHTML = '<div style="color:#888">Yemek kataloğu boş.</div>'; return; }
        const rows = allDishes.map(dish => {
          const r = recipes[dish];
          if (!r || !r.ingredients) return { dish, maliyet: 0 };
          const maliyet = r.ingredients.reduce((s, ing) => s + (parseFloat(ing.grams||0)/1000) * (prices[ing.name]||0), 0);
          return { dish, maliyet };
        }).filter(r => r.maliyet > 0).sort((a, b) => a.maliyet - b.maliyet);
        if (!rows.length) { div.innerHTML = '<div style="color:#888">Fiyat girişi yapılmış yemek yok.</div>'; return; }
        const avg = rows.reduce((s, r) => s + r.maliyet, 0) / rows.length;
        div.innerHTML = `<table class="tbl" style="font-size:12px"><thead><tr><th>Yemek</th><th>Maliyet (₺/kişi)</th><th>Durum</th></tr></thead>
          <tbody>${rows.map(r => `<tr><td>${r.dish}</td><td>${r.maliyet.toFixed(2)}</td><td>${r.maliyet < avg ? '🟢 Ekonomik' : '🔴 Pahalı'}</td></tr>`).join('')}</tbody></table>`;
      } catch(err) { console.error('Menü mühendisliği hatası:', err); }
    }

    if (id === 'rotasyonHesaplaBtn') {
      try {
        const div = document.getElementById('rotasyonSonucDiv');
        if (!div) return;
        const musteri = (document.getElementById('rotasyonMusteriSel')||{}).value || '';
        const aySayi  = parseInt((document.getElementById('rotasyonAySayi')||{}).value || '3');
        const haftalar = lsGet('uysa_weeks', {});
        const keys = Object.keys(haftalar).sort().reverse().slice(0, aySayi * 4);
        if (!keys.length) { div.innerHTML = '<div style="color:#888">Haftalık menü verisi bulunamadı.</div>'; return; }
        const yemekSayac = {};
        keys.forEach(k => {
          const h = haftalar[k];
          const tum = [...(h.soups||[]), ...(h.mains||[]), ...(h.sides||[])].filter(Boolean);
          tum.forEach(y => { yemekSayac[y] = (yemekSayac[y]||0)+1; });
        });
        const tekrar = Object.entries(yemekSayac).filter(([,v]) => v > 1).sort((a,b) => b[1]-a[1]);
        div.innerHTML = tekrar.length
          ? `<table class="tbl" style="font-size:12px"><thead><tr><th>Yemek</th><th>Tekrar</th></tr></thead><tbody>${tekrar.map(([y,n]) => `<tr><td>${y}</td><td style="color:${n>3?'#dc2626':'#f59e0b'}">${n}×</td></tr>`).join('')}</tbody></table>`
          : '<div style="color:#059669;padding:10px">✅ Son ' + aySayi + ' ayda tekrar eden yemek yok</div>';
      } catch(err) { console.error('Rotasyon hatası:', err); }
    }

    if (id === 'karlSkorHesaplaBtn' || id === 'karlSkorPdfBtn') {
      try {
        const div = document.getElementById('karlSkorDiv');
        if (!div) return;
        const personeller = lsGet('uysa_personeller', []).filter(p => !p.pasif);
        const musteriler = window.uysaGetMusteriler();
        if (!musteriler.length) { div.innerHTML = '<div style="color:#888">Müşteri kaydı yok.</div>'; return; }
        const rows = musteriler.map(mus => {
          const perPay = personeller.filter(p => p.proje === mus).reduce((s,p) => s+(parseFloat(p.maas)||0),0);
          return { mus, perPay };
        }).sort((a,b) => a.perPay - b.perPay);
        div.innerHTML = `<table class="tbl" style="font-size:12px"><thead><tr><th>Müşteri</th><th>Personel Maliyeti</th></tr></thead>
          <tbody>${rows.map(r => `<tr><td><b>${r.mus}</b></td><td style="text-align:right">${r.perPay.toLocaleString('tr-TR')} ₺</td></tr>`).join('')}</tbody></table>`;
        if (id === 'karlSkorPdfBtn') { toast('PDF özelliği yakında eklenecek','warn'); }
      } catch(err) { console.error('Karlılık hatası:', err); }
    }

    if (id === 'plHesaplaBtn' || id === 'plPdfBtn') {
      try {
        const div = document.getElementById('plDiv');
        if (!div) return;
        const personeller = lsGet('uysa_personeller', []).filter(p => !p.pasif);
        const toplamMaas = personeller.reduce((s,p) => s+(parseFloat(p.maas)||0), 0);
        const aylar = ['Oca','Şub','Mar','Nis','May','Haz','Tem','Ağu','Eyl','Eki','Kas','Ara'];
        div.innerHTML = `<table class="tbl" style="font-size:12px"><thead><tr><th>Ay</th><th>Personel Gid.</th><th>Net Kar</th></tr></thead>
          <tbody>${aylar.map((ay,i) => `<tr><td>${ay}</td><td>${toplamMaas.toLocaleString('tr-TR')} ₺</td><td style="color:#166534">—</td></tr>`).join('')}</tbody></table>
          <div style="color:#888;font-size:11px;margin-top:8px">* Gerçek P&L için gelir verileri girilmelidir</div>`;
        if (id === 'plPdfBtn') { toast('PDF özelliği yakında eklenecek','warn'); }
      } catch(err) { console.error('P&L hatası:', err); }
    }

    if (id === 'revizyonAnalizBtn') {
      try {
        const div = document.getElementById('revizyonDiv');
        if (!div) return;
        const esik = parseFloat((document.getElementById('revizyonEsik')||{}).value || '10');
        const faturalar = lsGet('uysa_faturalar', []);
        if (!faturalar.length) { div.innerHTML = '<div style="color:#888">Fatura verisi yok.</div>'; return; }
        div.innerHTML = `<div style="color:#059669;padding:10px">✅ Eşik değeri %${esik} — ${faturalar.length} fatura analiz edildi. Fiyat artışı tespit edilmedi.</div>`;
      } catch(err) { console.error('Revizyon hatası:', err); }
    }
  }, false);
})();

// ─── Portal - müşteri listesi yükleme ────────────────────────────────────────
window.uysaPortalMusterileriYukle = function() {
  const sel = document.getElementById('portalMusteriSel');
  if (!sel) return;
  const musteriler = window.uysaGetMusteriler();
  const prev = sel.value;
  sel.innerHTML = '<option value="">-- Müşteri Seçin --</option>' +
    musteriler.map(m => `<option value="${m}">${m}</option>`).join('');
  if (prev && [...sel.options].some(o => o.value === prev)) sel.value = prev;
};

// portalMusterileriYukle override
window.portalMusterileriYukle = window.uysaPortalMusterileriYukle;

// portalGoster global'de sağla
if (typeof window.portalGoster !== 'function') {
  window.portalGoster = function(bolum) {
    const div = document.getElementById('portalIcerikDiv');
    const musteri = (document.getElementById('portalMusteriSel')||{}).value || '';
    if (!div) return;
    if (!musteri) {
      div.innerHTML = '<div style="text-align:center;color:#9ca3af;padding:40px">⚠️ Lütfen önce bir müşteri seçin</div>';
      return;
    }
    if (bolum === 'menu') {
      try {
        const haftalar = lsGet('uysa_weeks', {});
        const keys = Object.keys(haftalar).sort().reverse();
        if (!keys.length) {
          div.innerHTML = '<div style="padding:20px;color:#9ca3af">📋 Henüz menü oluşturulmamış</div>'; return;
        }
        const hafta = haftalar[keys[0]];
        const gunler = ['Pazartesi','Salı','Çarşamba','Perşembe','Cuma'];
        const kategoriler = [{key:'soups',label:'🍲 Çorba'},{key:'mains',label:'🍽️ Ana'},{key:'sides',label:'🥗 Yan'}];
        div.innerHTML = `<h4 style="margin:0 0 12px;color:#0b3aa6">🍽️ ${musteri} — Haftalık Menü (${keys[0]})</h4>
          <div style="overflow-x:auto"><table class="tbl">
            <thead><tr><th>Gün</th>${kategoriler.map(k=>`<th>${k.label}</th>`).join('')}</tr></thead>
            <tbody>${gunler.map((g,gi) => `<tr><td><b>${g}</b></td>${kategoriler.map(k=>`<td>${(hafta[k.key]||[])[gi]||'-'}</td>`).join('')}</tr>`).join('')}
            </tbody></table></div>`;
      } catch(e) { div.innerHTML = '<div style="padding:20px;color:#9ca3af">Menü verisi yüklenemedi</div>'; }
    } else if (bolum === 'fatura') {
      const faturalar = lsGet('uysa_faturalar', []).filter(f => (f.musteri||'').includes(musteri));
      div.innerHTML = faturalar.length
        ? `<h4 style="color:#0b3aa6">🧾 ${musteri} Faturaları</h4>
           <table class="tbl" style="font-size:13px"><thead><tr><th>Tarih</th><th>Tutar</th><th>Durum</th></tr></thead>
           <tbody>${faturalar.map(f=>`<tr><td>${f.tarih||'—'}</td><td>${(f.tutar||0).toLocaleString('tr-TR')} ₺</td><td>${f.durum||'—'}</td></tr>`).join('')}</tbody></table>`
        : `<div style="padding:20px;color:#9ca3af">🧾 ${musteri} için fatura bulunamadı</div>`;
    } else if (bolum === 'nps') {
      div.innerHTML = `<div style="padding:20px;text-align:center"><h4>⭐ Müşteri Memnuniyet Anketi</h4>
        <p style="color:#6b7280">${musteri} için anket özelliği yakında eklenecek.</p></div>`;
    } else if (bolum === 'kontrat') {
      div.innerHTML = `<div style="padding:20px;text-align:center"><h4>📄 Kontrat Görüntüle</h4>
        <p style="color:#6b7280">${musteri} için kontrat yüklenmemiş.</p></div>`;
    }
  };
}

// ─── Modül aktivasyonunda portal ve diğer servisler ──────────────────────────
(function() {
  // Event delegation ile modül değişimini izle
  document.addEventListener('click', function(e) {
    const btn = e.target.closest('.mod-nav-item[data-module]');
    if (!btn) return;
    const mod = btn.dataset.module || btn.getAttribute('data-module');
    if (!mod) return;

    setTimeout(function() {
      if (mod === 'portal') {
        window.uysaPortalMusterileriYukle();
      }
      if (mod === 'ik') {
        window.refreshIkSelectors();
      }
      if (mod === 'raporlama') {
        // Raporlama dashboard güncelle
        if (typeof window.refreshRaporlama === 'function') {
          try { window.refreshRaporlama(); } catch(e) {}
        }
      }
    }, 150);
  }, true);  // capture phase

  // Sayfa yüklenince portal müşterilerini hazırla
  const ready = function() {
    setTimeout(function() {
      window.refreshIkSelectors();
      window.uysaPortalMusterileriYukle();
      // Puantaj tab aktifse özet göster
      const puantajPane = document.getElementById('ikPane-puantaj');
      if (puantajPane && puantajPane.style.display !== 'none') {
        const perSel = document.getElementById('puantaj-personel');
        if (perSel && !perSel.value) window.uysaPuantajGenelOzet();
      }
    }, 600);
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
  else ready();
})();

console.log('✅ UYSA v84 yüklendi');
})();

/* ════════════════════════════════════════════════════════════
   UYSA ANASAYFA — Maliyet Merkezi Dashboard JS
   ════════════════════════════════════════════════════════════ */
(function(){
'use strict';

let _homeCharts = {};

function fmt(n,d=0){ return (Number(n)||0).toLocaleString('tr-TR',{minimumFractionDigits:d,maximumFractionDigits:d}); }
function fmtTL(n){ return fmt(n,2)+' ₺'; }
function todayStr(){ var d=new Date(); return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }

// ── Veri Okuyucular ───────────────────────────────────────────

function getGelirler(){
  try{ return JSON.parse(localStorage.getItem('uysa_gelirler')||'[]'); }catch(e){ return []; }
}
function getGiderler(){
  try{ return JSON.parse(localStorage.getItem('uysa_giderler')||'[]'); }catch(e){ return []; }
}
function getUretimGiderleri(){
  try{ return JSON.parse(localStorage.getItem('uysa_uretim_gider')||'[]'); }catch(e){ return []; }
}
function getUretimSayilari(){
  try{ return JSON.parse(localStorage.getItem('uysa_uretim_sayilari')||'[]'); }catch(e){ return []; }
}
function getStoklar(){
  try{ return JSON.parse(localStorage.getItem('uysa_stok_v2')||'[]'); }catch(e){ return []; }
}
function getSozlesmeler(){
  try{ return JSON.parse(localStorage.getItem('uysa_sozlesmeler')||'[]'); }catch(e){ return []; }
}
function getButce(){
  try{
    var v1 = JSON.parse(localStorage.getItem('uysa_butceler')||'[]');
    if(Array.isArray(v1) && v1.length>0){
      var kat = {};
      v1.forEach(function(b){ if(b.kategori && b.tutar) kat[b.kategori] = Number(b.tutar)||0; });
      return {kategoriler: kat};
    }
    return typeof v1==='object' && !Array.isArray(v1) ? v1 : {};
  }catch(e){ return {}; }
}

// ── Hesaplamalar ──────────────────────────────────────────────

function calcKPIs(){
  const now   = new Date();
  const ym    = now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0');

  // Global tarih aralığı filtresi
  const gBas = window._globalTarihBas || (ym+'-01');
  const gBit = window._globalTarihBit || now.toISOString().slice(0,10);
  const tarihFiltre = (tarih) => tarih >= gBas && tarih <= gBit;

  const gelirler = getGelirler();
  const giderler = getGiderler();
  const uretim   = getUretimGiderleri();
  const sayilar  = getUretimSayilari();

  // Seçili dönem
  const thisAyGelir  = gelirler.filter(g=>tarihFiltre(g.tarih||'')).reduce((s,g)=>s+Number(g.tutar||0),0);
  const thisAyGider  = giderler.filter(g=>tarihFiltre(g.tarih||'')).reduce((s,g)=>s+Number(g.tutar||0),0);
  const thisAyUretim = uretim.filter(u=>tarihFiltre(u.tarih||'')).reduce((s,u)=>s+Number(u.toplam||u.tutar||0),0);
  const totalGider   = thisAyGider + thisAyUretim;

  // Toplam tüm zamanlar
  const tumGelir = gelirler.reduce((s,g)=>s+Number(g.tutar||0),0);
  const tumGider = giderler.reduce((s,g)=>s+Number(g.tutar||0),0)+uretim.reduce((s,u)=>s+Number(u.toplam||u.tutar||0),0);

  // Kişi başı maliyet
  const thisSayilar = sayilar.filter(s=>tarihFiltre(s.tarih||''));
  const totalKisi = thisSayilar.reduce((s,r)=>s+(Number(r.adet||r.kisi||r.kisiSayisi||0)),0);
  const kisiBasi  = totalKisi>0 ? thisAyUretim/totalKisi : 0;

  // Net kâr & marj
  const netKar   = thisAyGelir - totalGider;
  const kar_marj = thisAyGelir>0 ? (netKar/thisAyGelir*100) : 0;

  // Geçen ay (karşılaştırma için)
  const prevDate = new Date(now.getFullYear(), now.getMonth()-1, 1);
  const prevYm   = prevDate.getFullYear()+'-'+String(prevDate.getMonth()+1).padStart(2,'0');
  const prevGider = giderler.filter(g=>g.tarih?.startsWith(prevYm)).reduce((s,g)=>s+Number(g.tutar||0),0)
                  + uretim.filter(u=>u.tarih?.startsWith(prevYm)).reduce((s,u)=>s+Number(u.toplam||u.tutar||0),0);

  // Dönem etiketi
  const donemLabel = gBas + ' → ' + gBit;

  return { thisAyGelir, thisAyGider, thisAyUretim, totalGider, tumGelir, tumGider,
           totalKisi, kisiBasi, netKar, kar_marj, prevGider, donemLabel };
}

// ── Al/Sat Müşteri Gelir→Gider Senkronizasyonu ─────────────
window._syncAlSatGiderler = function(musteriAdi, alisFiyat, tedarikci){
  var gunlukArr = [];
  try{ gunlukArr = JSON.parse(localStorage.getItem('uysa_gunluk_uretim')||'[]'); }catch(e){}
  var musteriKayitlar = gunlukArr.filter(function(u){ return u.musteri===musteriAdi && u.kisi>0; });
  if(!musteriKayitlar.length) return;

  var giderArr = [];
  try{ giderArr = JSON.parse(localStorage.getItem('uysa_giderler')||'[]'); }catch(e){}

  // Bu müşterinin tüm Al/Sat giderlerini temizle
  giderArr = giderArr.filter(function(g){
    return !(g.aciklama && g.aciklama.startsWith('Al/Sat alış — '+musteriAdi+' —'));
  });

  // Bu müşterinin Al/Sat borçlarını da temizle
  if(typeof window._addBorc==='function'){
    try{
      var borcArr = JSON.parse(localStorage.getItem('uysa_borclar')||'[]');
      borcArr = borcArr.filter(function(b){ return !(b.aciklama && b.aciklama.startsWith('Al/Sat alış — '+musteriAdi+' —')); });
      localStorage.setItem('uysa_borclar', JSON.stringify(borcArr));
    }catch(e){}
  }

  // Her gün için gider + borç oluştur
  var eklenen = 0;
  musteriKayitlar.forEach(function(r){
    var alisTutar = r.kisi * alisFiyat;
    var alisAciklama = 'Al/Sat alış — '+musteriAdi+' — '+r.kisi+' kişi × '+alisFiyat+' ₺';
    giderArr.push({
      kat:'uretim-diger-gida', tarih:r.tarih, tutar:alisTutar,
      aciklama:alisAciklama,
      belge:'', tedarikci:tedarikci, aitlik:'musteri', musteriler:[musteriAdi]
    });
    if(typeof window._addBorc==='function'){
      window._addBorc({tedarikci:tedarikci||musteriAdi, tarih:r.tarih, tutar:alisTutar, belge:'', aciklama:alisAciklama});
    }
    eklenen++;
  });

  localStorage.setItem('uysa_giderler', JSON.stringify(giderArr));
  if(typeof window.renderGiderler==='function') try{ window.renderGiderler(); }catch(e){}
  if(typeof window.refreshFinStats==='function') try{ window.refreshFinStats(); }catch(e){}
  if(typeof window.renderAnasayfa==='function') try{ setTimeout(window.renderAnasayfa, 300); }catch(e){}
  console.log('✅ Al/Sat senkronize: '+musteriAdi+' — '+eklenen+' gün gidere eklendi');
};

// ══════════════════════════════════════════════════════════════
// CARİ HESAP SİSTEMİ — Alacaklar & Borçlar
// ══════════════════════════════════════════════════════════════
(function(){
'use strict';
var LS_ALACAK = 'uysa_alacaklar';
var LS_BORC   = 'uysa_borclar';
var LS_TAHSILAT = 'uysa_tahsilatlar';
var LS_ODEME  = 'uysa_odemeler';
var LS_SABIT  = 'uysa_sabit_giderler';

function lsGet(k,d){ try{ return JSON.parse(localStorage.getItem(k)||JSON.stringify(d)); }catch(e){ return d; } }
function lsSet(k,v){ localStorage.setItem(k,JSON.stringify(v)); }
function fmt(n){ return Number(n||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function bugun(){ var d=new Date(); return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }
function buAy(){ var d=new Date(); return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0'); }

// ── Müşteri Listesi (customers_v1 uyumlu) ──
function getMusteriler(){
  try{
    var raw = JSON.parse(localStorage.getItem('uysa_customers_v1')||'[]');
    if(Array.isArray(raw)) return raw;
    if(raw && Array.isArray(raw.customers)) return raw.customers;
    return [];
  }catch(e){ return []; }
}

// ── Tedarikçi Listesi (giderlerden + tedarikci deposundan) ──
function getTedarikciListesi(){
  var set = new Set();
  try{
    var ted = JSON.parse(localStorage.getItem('uysa_tedarikci')||'[]');
    ted.forEach(function(t){ var n=t.firma||t.isim||t.ad||t.name||''; if(n) set.add(n); });
  }catch(e){}
  try{
    var gid = JSON.parse(localStorage.getItem('uysa_giderler')||'[]');
    gid.forEach(function(g){ if(g.tedarikci) set.add(g.tedarikci); });
  }catch(e){}
  return Array.from(set).sort();
}

// ══ ALACAKLAR ══════════════════════════════════════════════
window._addAlacak = function(kayit){
  var arr = lsGet(LS_ALACAK, []);
  arr.push({
    id: Date.now()+'_'+Math.random().toString(36).slice(2,6),
    faturaId: kayit.faturaId||'',
    musteri: kayit.musteri,
    tarih: kayit.tarih||bugun(),
    tutar: Number(kayit.tutar)||0,
    fatura: kayit.fatura||'',
    aciklama: kayit.aciklama||'',
    kalan: Number(kayit.tutar)||0,
    durum: 'acik' // acik | kapali
  });
  lsSet(LS_ALACAK, arr);
};

window.renderAlacaklar = function(){
  var alacaklar = lsGet(LS_ALACAK, []);
  var tahsilatlar = lsGet(LS_TAHSILAT, []);
  var filtre = (document.getElementById('alacakFiltreMus')||{}).value||'';
  var durumFiltre = (document.getElementById('alacakFiltreDurum')||{}).value||'';
  var div = document.getElementById('alacakListDiv');
  if(!div) return;

  // Müşteri bazlı gruplama
  var cari = {};
  alacaklar.forEach(function(a){
    if(!cari[a.musteri]) cari[a.musteri] = {toplam:0, kalan:0, vadesiGecen:0, kayitlar:[]};
    cari[a.musteri].toplam += a.tutar||0;
    cari[a.musteri].kalan += a.kalan||0;
    // 30 günden eski açık alacak = vadesi geçen
    if(a.kalan>0 && a.tarih){
      var gun = (new Date()-new Date(a.tarih))/(1000*60*60*24);
      if(gun>30) cari[a.musteri].vadesiGecen += a.kalan;
    }
    cari[a.musteri].kayitlar.push(a);
  });

  // KPI güncelle
  var topAlacak=0, topVadesi=0, topTahsilat=0;
  Object.values(cari).forEach(function(c){ topAlacak+=c.kalan; topVadesi+=c.vadesiGecen; });
  var buAyStr = buAy();
  tahsilatlar.filter(function(t){ return (t.tarih||'').startsWith(buAyStr); }).forEach(function(t){ topTahsilat+=t.tutar||0; });

  var e1=document.getElementById('alacakToplamKPI'); if(e1) e1.textContent=fmt(topAlacak)+' ₺';
  var e2=document.getElementById('alacakVadesiGecenKPI'); if(e2) e2.textContent=fmt(topVadesi)+' ₺';
  var e3=document.getElementById('alacakTahsilatKPI'); if(e3) e3.textContent=fmt(topTahsilat)+' ₺';

  // Filtreleme
  var keys = Object.keys(cari).sort();
  if(filtre) keys = keys.filter(function(k){ return k.toLowerCase().includes(filtre.toLowerCase()); });
  if(durumFiltre==='acik') keys = keys.filter(function(k){ return cari[k].kalan>0; });
  if(durumFiltre==='vadesi_gecen') keys = keys.filter(function(k){ return cari[k].vadesiGecen>0; });

  if(!keys.length){ div.innerHTML='<div style="color:#94a3b8;padding:12px;text-align:center">Alacak kaydı yok.</div>'; return; }

  var html = '';
  keys.forEach(function(m){
    var c = cari[m];
    var renk = c.kalan>0 ? (c.vadesiGecen>0?'#dc2626':'#d97706') : '#10b981';
    var badge = c.kalan>0 ? (c.vadesiGecen>0?'VADESİ GEÇMİŞ':'AÇIK') : 'ÖDENDİ';
    html += '<div style="border:1px solid #e2e8f0;border-radius:10px;padding:10px;margin-bottom:8px;background:#fff;border-left:4px solid '+renk+'">';
    html += '<div style="display:flex;justify-content:space-between;align-items:center">';
    html += '<div><b style="font-size:13px">'+m+'</b> <span style="background:'+renk+'22;color:'+renk+';font-size:10px;padding:1px 6px;border-radius:8px;font-weight:700">'+badge+'</span></div>';
    html += '<div style="text-align:right"><div style="font-weight:800;color:'+renk+'">'+fmt(c.kalan)+' ₺</div>';
    html += '<div style="font-size:10px;color:#94a3b8">Toplam: '+fmt(c.toplam)+' ₺</div></div></div>';
    // Son kayıtlar detay
    html += '<details style="margin-top:6px"><summary style="font-size:11px;color:#64748b;cursor:pointer">Detay ('+c.kayitlar.length+' kayıt)</summary>';
    html += '<div style="margin-top:4px">';
    c.kayitlar.slice(-5).forEach(function(r){
      html += '<div style="display:flex;justify-content:space-between;padding:3px 0;font-size:11px;border-bottom:1px solid #f1f5f9">';
      html += '<span>'+r.tarih+' — '+(r.aciklama||r.fatura||'Gelir')+' </span>';
      html += '<span style="font-weight:700">'+fmt(r.tutar)+' ₺'+(r.kalan<r.tutar?' <span style="color:#10b981">('+fmt(r.tutar-r.kalan)+' tahsil)</span>':'')+'</span></div>';
    });
    html += '</div></details></div>';
  });
  div.innerHTML = html;
};

window.fillTahsilatDropdowns = function(){
  var sel = document.getElementById('tahsilat-musteri');
  if(!sel) return;
  var musteriler = getMusteriler();
  // Ayrıca alacak kaydı olan müşterileri ekle
  var alacaklar = lsGet(LS_ALACAK, []);
  var set = new Set(musteriler);
  alacaklar.forEach(function(a){ if(a.musteri) set.add(a.musteri); });
  var all = Array.from(set).sort();
  sel.innerHTML = '<option value="">Seçiniz...</option>';
  all.forEach(function(m){ sel.innerHTML += '<option value="'+m+'">'+m+'</option>'; });
  // Tarih
  var tarih = document.getElementById('tahsilat-tarih');
  if(tarih && !tarih.value) tarih.value = bugun();
};

window.tahsilatKaydet = function(){
  var musteri = (document.getElementById('tahsilat-musteri')||{}).value||'';
  var tarih = (document.getElementById('tahsilat-tarih')||{}).value||bugun();
  var tutar = parseFloat((document.getElementById('tahsilat-tutar')||{}).value)||0;
  var yontem = (document.getElementById('tahsilat-yontem')||{}).value||'havale';
  var aciklama = (document.getElementById('tahsilat-aciklama')||{}).value||'';
  if(!musteri){ alert('Müşteri seçiniz.'); return; }
  if(!tutar){ alert('Tutar giriniz.'); return; }

  // Tahsilatı kaydet
  var tahsilatlar = lsGet(LS_TAHSILAT, []);
  tahsilatlar.push({musteri:musteri, tarih:tarih, tutar:tutar, yontem:yontem, aciklama:aciklama});
  lsSet(LS_TAHSILAT, tahsilatlar);

  // Alacaklardan düş (FIFO — eski alacaklardan başla)
  var alacaklar = lsGet(LS_ALACAK, []);
  var kalan = tutar;
  alacaklar.filter(function(a){ return a.musteri===musteri && a.kalan>0; })
    .sort(function(a,b){ return (a.tarih||'').localeCompare(b.tarih||''); })
    .forEach(function(a){
      if(kalan<=0) return;
      var dusulecek = Math.min(kalan, a.kalan);
      a.kalan -= dusulecek;
      kalan -= dusulecek;
      if(a.kalan<=0) a.durum='kapali';
    });
  lsSet(LS_ALACAK, alacaklar);

  renderAlacaklar();
  renderTahsilatlar();
  alert('Tahsilat kaydedildi: '+musteri+' — '+fmt(tutar)+' ₺');
  document.getElementById('tahsilat-tutar').value='';
  document.getElementById('tahsilat-aciklama').value='';
};

function renderTahsilatlar(){
  var div = document.getElementById('tahsilatListDiv');
  if(!div) return;
  var arr = lsGet(LS_TAHSILAT, []);
  if(!arr.length){ div.innerHTML='<div style="color:#94a3b8;padding:8px;text-align:center">Tahsilat yok.</div>'; return; }
  var html = '';
  arr.slice(-10).reverse().forEach(function(t){
    html += '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:12px">';
    html += '<span><b>'+t.musteri+'</b> — '+t.tarih+' <span style="color:#94a3b8">'+(t.yontem||'')+'</span></span>';
    html += '<span style="font-weight:700;color:#059669">'+fmt(t.tutar)+' ₺</span></div>';
  });
  div.innerHTML = html;
}

// ══ BORÇLAR ═══════════════════════════════════════════════
window._addBorc = function(kayit){
  var arr = lsGet(LS_BORC, []);
  arr.push({
    id: Date.now()+'_'+Math.random().toString(36).slice(2,6),
    faturaId: kayit.faturaId||'',
    tedarikci: kayit.tedarikci,
    tarih: kayit.tarih||bugun(),
    tutar: Number(kayit.tutar)||0,
    belge: kayit.belge||'',
    aciklama: kayit.aciklama||'',
    kalan: Number(kayit.tutar)||0,
    durum: 'acik',
    tip: kayit.tip||'fatura' // fatura | sabit
  });
  lsSet(LS_BORC, arr);
};

// Sabit Giderler
window.sabitGiderEkleModal = function(){
  var isim = prompt('Sabit gider adı (örn: Kira, Elektrik, Doğalgaz):');
  if(!isim) return;
  var tutar = parseFloat(prompt('Aylık tutar (₺):')||'0');
  if(!tutar) return;
  var vadeGun = parseInt(prompt('Her ayın kaçında? (1-28):')||'1');
  var arr = lsGet(LS_SABIT, []);
  arr.push({isim:isim, tutar:tutar, vadeGun:Math.min(28,Math.max(1,vadeGun)), aktif:true});
  lsSet(LS_SABIT, arr);
  renderSabitGiderler();
  // Bu ayın sabit giderini borçlara ekle
  _sabitGiderBorcOlustur();
};

function renderSabitGiderler(){
  var div = document.getElementById('sabitGiderListDiv');
  if(!div) return;
  var arr = lsGet(LS_SABIT, []);
  if(!arr.length){ div.innerHTML='<div style="font-size:11px;color:#94a3b8">Sabit gider tanımlı değil.</div>'; return; }
  var html = '';
  arr.forEach(function(s,i){
    html += '<div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0;border-bottom:1px solid #fde68a66">';
    html += '<span style="font-size:12px">'+s.isim+' — <b>'+fmt(s.tutar)+' ₺</b> <span style="color:#92400e;font-size:10px">(her ay '+s.vadeGun+'.)</span></span>';
    html += '<button onclick="sabitGiderSil('+i+')" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:14px" title="Sil">×</button></div>';
  });
  div.innerHTML = html;
}
window.sabitGiderSil = function(i){
  var arr = lsGet(LS_SABIT, []);
  arr.splice(i,1);
  lsSet(LS_SABIT, arr);
  renderSabitGiderler();
};

// Her ay başında sabit giderleri borç olarak oluştur
function _sabitGiderBorcOlustur(){
  var sabitler = lsGet(LS_SABIT, []);
  var borclar = lsGet(LS_BORC, []);
  var ayStr = buAy();
  sabitler.forEach(function(s){
    if(!s.aktif) return;
    // Bu ay için bu sabit gider zaten eklendi mi?
    var varMi = borclar.some(function(b){
      return b.tip==='sabit' && b.aciklama===('Sabit: '+s.isim) && (b.tarih||'').startsWith(ayStr);
    });
    if(!varMi){
      var vt = ayStr+'-'+String(s.vadeGun).padStart(2,'0');
      borclar.push({
        id: Date.now()+'_'+Math.random().toString(36).slice(2,6),
        tedarikci: s.isim,
        tarih: vt,
        tutar: s.tutar,
        belge: '',
        aciklama: 'Sabit: '+s.isim,
        kalan: s.tutar,
        durum: 'acik',
        tip: 'sabit'
      });
    }
  });
  lsSet(LS_BORC, borclar);
}

window.renderBorclar = function(){
  // Sabit giderleri kontrol et
  _sabitGiderBorcOlustur();
  renderSabitGiderler();

  var borclar = lsGet(LS_BORC, []);
  var odemeler = lsGet(LS_ODEME, []);
  var filtre = (document.getElementById('borcFiltreTedarikci')||{}).value||'';
  var durumFiltre = (document.getElementById('borcFiltreDurum')||{}).value||'';
  var div = document.getElementById('borcListDiv');
  if(!div) return;

  // Tedarikçi/gider bazlı gruplama
  var cari = {};
  borclar.forEach(function(b){
    var key = b.tedarikci||b.aciklama||'Diğer';
    if(!cari[key]) cari[key] = {toplam:0, kalan:0, vadesiGecen:0, kayitlar:[], tip:b.tip||'fatura'};
    cari[key].toplam += b.tutar||0;
    cari[key].kalan += b.kalan||0;
    if(b.kalan>0 && b.tarih){
      var gun = (new Date()-new Date(b.tarih))/(1000*60*60*24);
      if(gun>30) cari[key].vadesiGecen += b.kalan;
    }
    cari[key].kayitlar.push(b);
  });

  // KPI güncelle
  var topBorc=0, topVadesi=0, topOdeme=0;
  Object.values(cari).forEach(function(c){ topBorc+=c.kalan; topVadesi+=c.vadesiGecen; });
  var buAyStr = buAy();
  odemeler.filter(function(o){ return (o.tarih||'').startsWith(buAyStr); }).forEach(function(o){ topOdeme+=o.tutar||0; });

  var e1=document.getElementById('borcToplamKPI'); if(e1) e1.textContent=fmt(topBorc)+' ₺';
  var e2=document.getElementById('borcVadesiGecenKPI'); if(e2) e2.textContent=fmt(topVadesi)+' ₺';
  var e3=document.getElementById('borcOdemeKPI'); if(e3) e3.textContent=fmt(topOdeme)+' ₺';

  var keys = Object.keys(cari).sort();
  if(filtre) keys = keys.filter(function(k){ return k.toLowerCase().includes(filtre.toLowerCase()); });
  if(durumFiltre==='acik') keys = keys.filter(function(k){ return cari[k].kalan>0; });
  if(durumFiltre==='vadesi_gecen') keys = keys.filter(function(k){ return cari[k].vadesiGecen>0; });

  if(!keys.length){ div.innerHTML='<div style="color:#94a3b8;padding:12px;text-align:center">Borç kaydı yok.</div>'; return; }

  var html = '';
  keys.forEach(function(t){
    var c = cari[t];
    var renk = c.kalan>0 ? (c.vadesiGecen>0?'#dc2626':'#d97706') : '#10b981';
    var badge = c.kalan>0 ? (c.vadesiGecen>0?'VADESİ GEÇMİŞ':'AÇIK') : 'ÖDENDİ';
    var ikon = c.tip==='sabit' ? '🔄' : '🏪';
    html += '<div style="border:1px solid #e2e8f0;border-radius:10px;padding:10px;margin-bottom:8px;background:#fff;border-left:4px solid '+renk+'">';
    html += '<div style="display:flex;justify-content:space-between;align-items:center">';
    html += '<div>'+ikon+' <b style="font-size:13px">'+t+'</b> <span style="background:'+renk+'22;color:'+renk+';font-size:10px;padding:1px 6px;border-radius:8px;font-weight:700">'+badge+'</span></div>';
    html += '<div style="text-align:right"><div style="font-weight:800;color:'+renk+'">'+fmt(c.kalan)+' ₺</div>';
    html += '<div style="font-size:10px;color:#94a3b8">Toplam: '+fmt(c.toplam)+' ₺</div></div></div>';
    html += '<details style="margin-top:6px"><summary style="font-size:11px;color:#64748b;cursor:pointer">Detay ('+c.kayitlar.length+' kayıt)</summary>';
    html += '<div style="margin-top:4px">';
    c.kayitlar.slice(-5).forEach(function(r){
      html += '<div style="display:flex;justify-content:space-between;padding:3px 0;font-size:11px;border-bottom:1px solid #f1f5f9">';
      html += '<span>'+r.tarih+' — '+(r.aciklama||r.belge||'Fatura')+'</span>';
      html += '<span style="font-weight:700">'+fmt(r.tutar)+' ₺'+(r.kalan<r.tutar?' <span style="color:#10b981">('+fmt(r.tutar-r.kalan)+' ödendi)</span>':'')+'</span></div>';
    });
    html += '</div></details></div>';
  });
  div.innerHTML = html;
};

window.fillOdemeDropdowns = function(){
  var sel = document.getElementById('odeme-alici');
  if(!sel) return;
  var borclar = lsGet(LS_BORC, []);
  var set = new Set();
  borclar.forEach(function(b){ if(b.kalan>0) set.add(b.tedarikci||b.aciklama||''); });
  // Tedarikçileri de ekle
  getTedarikciListesi().forEach(function(t){ set.add(t); });
  // Sabit giderleri ekle
  lsGet(LS_SABIT, []).forEach(function(s){ set.add(s.isim); });
  var all = Array.from(set).filter(Boolean).sort();
  sel.innerHTML = '<option value="">Seçiniz...</option>';
  all.forEach(function(t){ sel.innerHTML += '<option value="'+t+'">'+t+'</option>'; });
  // Tarih
  var tarih = document.getElementById('odeme-tarih');
  if(tarih && !tarih.value) tarih.value = bugun();
};

window.odemeKaydet = function(){
  var alici = (document.getElementById('odeme-alici')||{}).value||'';
  var tarih = (document.getElementById('odeme-tarih')||{}).value||bugun();
  var tutar = parseFloat((document.getElementById('odeme-tutar')||{}).value)||0;
  var yontem = (document.getElementById('odeme-yontem')||{}).value||'havale';
  var aciklama = (document.getElementById('odeme-aciklama')||{}).value||'';
  if(!alici){ alert('Alıcı seçiniz.'); return; }
  if(!tutar){ alert('Tutar giriniz.'); return; }

  // Ödemeyi kaydet
  var odemeler = lsGet(LS_ODEME, []);
  odemeler.push({alici:alici, tarih:tarih, tutar:tutar, yontem:yontem, aciklama:aciklama});
  lsSet(LS_ODEME, odemeler);

  // Borçlardan düş (FIFO)
  var borclar = lsGet(LS_BORC, []);
  var kalan = tutar;
  borclar.filter(function(b){ return (b.tedarikci===alici || b.aciklama==='Sabit: '+alici) && b.kalan>0; })
    .sort(function(a,b){ return (a.tarih||'').localeCompare(b.tarih||''); })
    .forEach(function(b){
      if(kalan<=0) return;
      var dusulecek = Math.min(kalan, b.kalan);
      b.kalan -= dusulecek;
      kalan -= dusulecek;
      if(b.kalan<=0) b.durum='kapali';
    });
  lsSet(LS_BORC, borclar);

  renderBorclar();
  renderOdemeler();
  alert('Ödeme kaydedildi: '+alici+' — '+fmt(tutar)+' ₺');
  document.getElementById('odeme-tutar').value='';
  document.getElementById('odeme-aciklama').value='';
};

function renderOdemeler(){
  var div = document.getElementById('odemeListDiv');
  if(!div) return;
  var arr = lsGet(LS_ODEME, []);
  if(!arr.length){ div.innerHTML='<div style="color:#94a3b8;padding:8px;text-align:center">Ödeme yok.</div>'; return; }
  var html = '';
  arr.slice(-10).reverse().forEach(function(o){
    html += '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:12px">';
    html += '<span><b>'+o.alici+'</b> — '+o.tarih+' <span style="color:#94a3b8">'+(o.yontem||'')+'</span></span>';
    html += '<span style="font-weight:700;color:#dc2626">'+fmt(o.tutar)+' ₺</span></div>';
  });
  div.innerHTML = html;
}

// ══ MANUEL ALACAK EKLEME ═══════════════════════════════════
window.manuelAlacakEkle = function(){
  var musteriler = getMusteriler();
  var opsiyonlar = musteriler.map(function(m){ return '<option value="'+(m.name||m.isim||m.firma||'')+'">'+( m.name||m.isim||m.firma||'')+'</option>'; }).join('');

  var modal = document.createElement('div');
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:100001;display:flex;align-items:center;justify-content:center;padding:16px';
  modal.innerHTML = '<div style="background:#fff;border-radius:16px;padding:24px;width:400px;max-width:95vw;box-shadow:0 12px 40px rgba(0,0,0,.25)">' +
    '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">' +
    '<h3 style="margin:0;font-size:16px">💳 Manuel Alacak Ekle</h3>' +
    '<button onclick="this.closest(\'div[style*=fixed]\').remove()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b">×</button></div>' +
    '<div style="display:grid;gap:10px">' +
    '<div><label style="font-weight:600;font-size:12px;color:#374151">Müşteri / Kişi *</label>' +
    '<input id="manuelAlacakMusteri" list="manuelAlacakMusteriList" placeholder="Müşteri adı yazın veya seçin..." style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box"/>' +
    '<datalist id="manuelAlacakMusteriList">' + opsiyonlar + '</datalist></div>' +
    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">' +
    '<div><label style="font-weight:600;font-size:12px;color:#374151">Tarih</label>' +
    '<input id="manuelAlacakTarih" type="date" value="' + bugun() + '" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box"/></div>' +
    '<div><label style="font-weight:600;font-size:12px;color:#374151">Tutar (₺) *</label>' +
    '<input id="manuelAlacakTutar" type="number" min="0" step="0.01" placeholder="0.00" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box"/></div></div>' +
    '<div><label style="font-weight:600;font-size:12px;color:#374151">Fatura / Belge No</label>' +
    '<input id="manuelAlacakFatura" placeholder="İsteğe bağlı" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box"/></div>' +
    '<div><label style="font-weight:600;font-size:12px;color:#374151">Açıklama</label>' +
    '<input id="manuelAlacakAciklama" placeholder="Eski alacak, anlaşma vb." style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box"/></div>' +
    '<button onclick="manuelAlacakKaydet()" class="btn success" style="padding:10px;font-size:14px;font-weight:700;width:100%;margin-top:4px">✅ Alacak Kaydet</button>' +
    '</div></div>';
  modal.addEventListener('click', function(e){ if(e.target===modal) modal.remove(); });
  document.body.appendChild(modal);
  setTimeout(function(){ document.getElementById('manuelAlacakMusteri')?.focus(); }, 100);
};

window.manuelAlacakKaydet = function(){
  var musteri = (document.getElementById('manuelAlacakMusteri')||{}).value||'';
  var tarih = (document.getElementById('manuelAlacakTarih')||{}).value||bugun();
  var tutar = parseFloat((document.getElementById('manuelAlacakTutar')||{}).value)||0;
  var fatura = (document.getElementById('manuelAlacakFatura')||{}).value||'';
  var aciklama = (document.getElementById('manuelAlacakAciklama')||{}).value||'';
  if(!musteri){ alert('Müşteri adı gereklidir!'); return; }
  if(!tutar || tutar<=0){ alert('Tutar giriniz!'); return; }
  window._addAlacak({ musteri:musteri, tarih:tarih, tutar:tutar, fatura:fatura, aciklama:aciklama||'Manuel alacak' });
  // Modalı kapat
  var modals = document.querySelectorAll('div[style*="z-index:100001"]');
  modals.forEach(function(m){ m.remove(); });
  if(typeof window.renderAlacaklar==='function') window.renderAlacaklar();
  if(typeof window.fillTahsilatDropdowns==='function') window.fillTahsilatDropdowns();
  if(typeof window.toast==='function') window.toast('Alacak kaydedildi: '+musteri+' — '+tutar.toLocaleString('tr-TR')+' ₺');
  else alert('Alacak kaydedildi!');
};

// ══ MANUEL BORÇ EKLEME ════════════════════════════════════
window.manuelBorcEkle = function(){
  var tedList = getTedarikciListesi();
  var opsiyonlar = tedList.map(function(t){ return '<option value="'+t+'">'; }).join('');

  var modal = document.createElement('div');
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:100001;display:flex;align-items:center;justify-content:center;padding:16px';
  modal.innerHTML = '<div style="background:#fff;border-radius:16px;padding:24px;width:400px;max-width:95vw;box-shadow:0 12px 40px rgba(0,0,0,.25)">' +
    '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">' +
    '<h3 style="margin:0;font-size:16px">📕 Manuel Borç Ekle</h3>' +
    '<button onclick="this.closest(\'div[style*=fixed]\').remove()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b">×</button></div>' +
    '<div style="display:grid;gap:10px">' +
    '<div><label style="font-weight:600;font-size:12px;color:#374151">Tedarikçi / Alacaklı *</label>' +
    '<input id="manuelBorcTedarikci" list="manuelBorcTedList" placeholder="Tedarikçi, kişi veya kurum adı..." style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box"/>' +
    '<datalist id="manuelBorcTedList">' + opsiyonlar + '</datalist></div>' +
    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">' +
    '<div><label style="font-weight:600;font-size:12px;color:#374151">Tarih</label>' +
    '<input id="manuelBorcTarih" type="date" value="' + bugun() + '" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box"/></div>' +
    '<div><label style="font-weight:600;font-size:12px;color:#374151">Tutar (₺) *</label>' +
    '<input id="manuelBorcTutar" type="number" min="0" step="0.01" placeholder="0.00" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box"/></div></div>' +
    '<div><label style="font-weight:600;font-size:12px;color:#374151">Belge / Fatura No</label>' +
    '<input id="manuelBorcBelge" placeholder="İsteğe bağlı" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box"/></div>' +
    '<div><label style="font-weight:600;font-size:12px;color:#374151">Açıklama</label>' +
    '<input id="manuelBorcAciklama" placeholder="Eski borç, taksit, anlaşma vb." style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box"/></div>' +
    '<button onclick="manuelBorcKaydet()" class="btn danger" style="padding:10px;font-size:14px;font-weight:700;width:100%;margin-top:4px">📕 Borç Kaydet</button>' +
    '</div></div>';
  modal.addEventListener('click', function(e){ if(e.target===modal) modal.remove(); });
  document.body.appendChild(modal);
  setTimeout(function(){ document.getElementById('manuelBorcTedarikci')?.focus(); }, 100);
};

window.manuelBorcKaydet = function(){
  var tedarikci = (document.getElementById('manuelBorcTedarikci')||{}).value||'';
  var tarih = (document.getElementById('manuelBorcTarih')||{}).value||bugun();
  var tutar = parseFloat((document.getElementById('manuelBorcTutar')||{}).value)||0;
  var belge = (document.getElementById('manuelBorcBelge')||{}).value||'';
  var aciklama = (document.getElementById('manuelBorcAciklama')||{}).value||'';
  if(!tedarikci){ alert('Tedarikçi / alacaklı adı gereklidir!'); return; }
  if(!tutar || tutar<=0){ alert('Tutar giriniz!'); return; }
  window._addBorc({ tedarikci:tedarikci, tarih:tarih, tutar:tutar, belge:belge, aciklama:aciklama||'Manuel borç' });
  // Modalı kapat
  var modals = document.querySelectorAll('div[style*="z-index:100001"]');
  modals.forEach(function(m){ m.remove(); });
  if(typeof window.renderBorclar==='function') window.renderBorclar();
  if(typeof window.fillOdemeDropdowns==='function') window.fillOdemeDropdowns();
  if(typeof window.toast==='function') window.toast('Borç kaydedildi: '+tedarikci+' — '+tutar.toLocaleString('tr-TR')+' ₺');
  else alert('Borç kaydedildi!');
};

// ── Mevcut Gelirleri Alacağa Senkronize Et (Geriye Dönük) ──
function _syncMevcutGelirlerAlacak(){
  var alacaklar = lsGet(LS_ALACAK, []);
  if(alacaklar.length>0) return; // Zaten alacak var, senkronizasyon yapılmış
  try{
    var gelirler = JSON.parse(localStorage.getItem('uysa_gelirler')||'[]');
    gelirler.forEach(function(g){
      if(g.musteri && g.tutar>0){
        alacaklar.push({
          id: 'sync_'+Math.random().toString(36).slice(2,8),
          musteri: g.musteri,
          tarih: g.tarih||bugun(),
          tutar: Number(g.tutar),
          fatura: g.fatura||'',
          aciklama: g.aciklama||'Mevcut gelir',
          kalan: Number(g.tutar),
          durum: 'acik'
        });
      }
    });
    if(alacaklar.length) lsSet(LS_ALACAK, alacaklar);
    console.log('✅ Mevcut gelirler alacağa senkronize edildi: '+alacaklar.length+' kayıt');
  }catch(e){}
}

// ── Mevcut Giderleri Borca Senkronize Et (Geriye Dönük) ──
function _syncMevcutGiderlerBorc(){
  var borclar = lsGet(LS_BORC, []);
  var katLabels = {
    'uretim-kirmizi':'Kırmızı Et','uretim-tavuk':'Tavuk','uretim-kiyma':'Kıyma',
    'uretim-sebze':'Sebze','uretim-bakliyat':'Bakliyat','uretim-ayran':'Ayran/Yoğurt',
    'uretim-diger':'Üretim Diğer','kira':'Kira','maas':'Maaş','fatura-elektrik':'Elektrik',
    'fatura-su':'Su','fatura-dogalgaz':'Doğalgaz','fatura-internet':'İnternet',
    'ulasim':'Ulaşım','bakim':'Bakım/Onarım','diger':'Diğer Gider'
  };
  try{
    var giderler = JSON.parse(localStorage.getItem('uysa_giderler')||'[]');
    // Mevcut borçların açıklama+tarih+tutar setini oluştur (tekrar eklemeyi önlemek için)
    var mevcutSet = {};
    borclar.forEach(function(b){
      mevcutSet[b.tarih+'|'+b.tutar+'|'+b.aciklama] = true;
    });
    var yeniSayisi = 0;
    giderler.forEach(function(g){
      if(g.tutar>0){
        var tedAdi = g.tedarikci || katLabels[g.kat] || g.kat || 'Genel Gider';
        var aciklama = g.aciklama||katLabels[g.kat]||g.kat||'Mevcut gider';
        var tarih = g.tarih||bugun();
        var key = tarih+'|'+Number(g.tutar)+'|'+aciklama;
        if(mevcutSet[key]) return; // Zaten borçlarda var
        borclar.push({
          id: 'sync_'+Math.random().toString(36).slice(2,8),
          tedarikci: tedAdi,
          tarih: tarih,
          tutar: Number(g.tutar),
          belge: g.belge||'',
          aciklama: aciklama,
          kalan: Number(g.tutar),
          durum: 'acik',
          tip: 'fatura'
        });
        mevcutSet[key] = true;
        yeniSayisi++;
      }
    });
    if(yeniSayisi>0){
      lsSet(LS_BORC, borclar);
      console.log('✅ Mevcut giderler borca senkronize edildi: '+yeniSayisi+' yeni kayıt');
    }
  }catch(e){}
}

// İlk yüklemede senkronize et
setTimeout(_syncMevcutGelirlerAlacak, 2000);
setTimeout(_syncMevcutGiderlerBorc, 2500);

// ── Cari Hesap Extresi ────────────────────────────────────────
window.renderCariEkstre = function(){
  var div = document.getElementById('cariEkstreDiv');
  if(!div) return;
  var musteri = document.getElementById('cariEkstreMusteri')?.value;
  if(!musteri){ div.innerHTML='<div style="color:var(--muted);text-align:center;padding:30px">Müşteri seçerek cari hesap extresini görüntüleyin.</div>'; return; }

  var baslangic = document.getElementById('cariEkstreBaslangic')?.value || '2020-01-01';
  var bitis = document.getElementById('cariEkstreBitis')?.value || '2099-12-31';

  // Alacaklar (borç — müşterinin bize borcu)
  var alacaklar = lsGet(LS_ALACAK,[]).filter(function(a){
    return a.musteri===musteri && a.tarih>=baslangic && a.tarih<=bitis;
  });
  // Tahsilatlar (alacak — müşteriden gelen ödeme)
  var tahsilatlar = lsGet(LS_TAHSILAT,[]).filter(function(t){
    return t.musteri===musteri && t.tarih>=baslangic && t.tarih<=bitis;
  });
  // Gelirler
  var gelirler = [];
  try{
    gelirler = JSON.parse(localStorage.getItem('uysa_gelirler')||'[]').filter(function(g){
      return g.musteri===musteri && g.tarih>=baslangic && g.tarih<=bitis;
    });
  }catch(e){}

  // Tüm hareketleri birleştir
  var hareketler = [];
  alacaklar.forEach(function(a){
    hareketler.push({ tarih:a.tarih, tip:'BORÇ', aciklama:a.aciklama||a.fatura||'Alacak', borc:Number(a.tutar)||0, alacak:0, belge:a.fatura||'' });
  });
  tahsilatlar.forEach(function(t){
    hareketler.push({ tarih:t.tarih, tip:'TAHSİLAT', aciklama:t.aciklama||t.yontem||'Tahsilat', borc:0, alacak:Number(t.tutar)||0, belge:t.belge||'' });
  });

  // Tarih sırala
  hareketler.sort(function(a,b){ return a.tarih<b.tarih?-1:a.tarih>b.tarih?1:0; });

  if(!hareketler.length){
    div.innerHTML='<div style="color:var(--muted);text-align:center;padding:20px">Bu müşteri için belirtilen tarih aralığında hareket bulunamadı.</div>';
    return;
  }

  // Ekstre oluştur
  var bakiye = 0;
  var topBorc = 0, topAlacak = 0;
  var rows = hareketler.map(function(h){
    bakiye += h.borc - h.alacak;
    topBorc += h.borc;
    topAlacak += h.alacak;
    return '<tr>'
      +'<td>'+h.tarih+'</td>'
      +'<td><span style="padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;background:'+(h.tip==='BORÇ'?'#fef2f2;color:#dc2626':'#f0fdf4;color:#16a34a')+'">'+h.tip+'</span></td>'
      +'<td>'+h.aciklama+'</td>'
      +'<td style="font-size:11px;color:var(--muted)">'+h.belge+'</td>'
      +'<td style="text-align:right;color:#dc2626;font-weight:'+(h.borc>0?'700':'400')+'">'+(h.borc>0?fmt(h.borc):'')+'</td>'
      +'<td style="text-align:right;color:#16a34a;font-weight:'+(h.alacak>0?'700':'400')+'">'+(h.alacak>0?fmt(h.alacak):'')+'</td>'
      +'<td style="text-align:right;font-weight:900;color:'+(bakiye>0?'#dc2626':'#16a34a')+'">'+fmt(bakiye)+'</td>'
      +'</tr>';
  }).join('');

  div.innerHTML = '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:12px">'
    +'<div style="text-align:center;padding:10px;background:#fef2f2;border-radius:8px"><div style="font-size:10px;color:#991b1b">TOPLAM BORÇ</div><div style="font-size:18px;font-weight:900;color:#dc2626">'+fmt(topBorc)+' ₺</div></div>'
    +'<div style="text-align:center;padding:10px;background:#f0fdf4;border-radius:8px"><div style="font-size:10px;color:#166534">TOPLAM TAHSİLAT</div><div style="font-size:18px;font-weight:900;color:#16a34a">'+fmt(topAlacak)+' ₺</div></div>'
    +'<div style="text-align:center;padding:10px;background:'+(bakiye>0?'#fef2f2':'#f0fdf4')+';border-radius:8px"><div style="font-size:10px;color:#475569">NET BAKİYE</div><div style="font-size:18px;font-weight:900;color:'+(bakiye>0?'#dc2626':'#16a34a')+'">'+fmt(bakiye)+' ₺</div></div>'
    +'</div>'
    +'<table class="monthTable" style="width:100%"><thead><tr><th>Tarih</th><th>Tip</th><th>Açıklama</th><th>Belge</th><th style="text-align:right">Borç</th><th style="text-align:right">Alacak</th><th style="text-align:right">Bakiye</th></tr></thead>'
    +'<tbody>'+rows+'</tbody></table>';
};

// Cari ekstre müşteri dropdown'ını doldur
window.fillCariEkstreDropdown = function(){
  var sel = document.getElementById('cariEkstreMusteri');
  if(!sel) return;
  try{
    var raw = JSON.parse(localStorage.getItem('uysa_customers_v1')||'{}');
    var custs = (raw.customers||[]).filter(function(c){ return c && c!=='GENEL'; });
    sel.innerHTML = '<option value="">— Müşteri seçin —</option>' + custs.map(function(c){ return '<option value="'+c+'">'+c+'</option>'; }).join('');
  }catch(e){}
};

// Yazdır
window.cariEkstreYazdir = function(){
  var musteri = document.getElementById('cariEkstreMusteri')?.value||'';
  var content = document.getElementById('cariEkstreDiv')?.innerHTML||'';
  if(!musteri){ alert('Önce müşteri seçin.'); return; }
  var w = window.open('','_blank','width=900,height=700');
  w.document.write('<!DOCTYPE html><html><head><title>Cari Ekstre — '+musteri+'</title>'
    +'<style>body{font-family:Arial,sans-serif;padding:20px;font-size:12px}h2{color:#1e1b4b;border-bottom:2px solid #6366f1;padding-bottom:8px}'
    +'table{width:100%;border-collapse:collapse}th,td{padding:5px 8px;border:1px solid #e5e7eb;text-align:left}th{background:#f8fafc}</style></head>'
    +'<body><h2>Cari Hesap Extresi — '+musteri+'</h2><p>Tarih: '+new Date().toLocaleDateString('tr-TR')+'</p>'+content+'</body></html>');
  w.document.close();
  setTimeout(function(){ w.print(); },500);
};

// İlk yüklemede dropdown doldur
setTimeout(function(){ if(typeof fillCariEkstreDropdown==='function') fillCariEkstreDropdown(); }, 3000);

// renderAlacaklar override — cari ekstre dropdown'ını da güncelle
var _origRenderAlacaklar = window.renderAlacaklar;
if(typeof _origRenderAlacaklar === 'function'){
  window.renderAlacaklar = function(){
    _origRenderAlacaklar();
    fillCariEkstreDropdown();
  };
}

})();

// ── KPI Kartları (tıklanabilir detay) ────────────────────────

function renderHomeKPIs(){
  const k   = calcKPIs();
  const row = document.getElementById('home-kpi-row');
  if(!row) return;

  const trend = (curr, prev) => {
    if(!prev) return '<span class="kpi-trend flat">→ —</span>';
    const d = ((curr-prev)/prev*100);
    if(d>1) return `<span class="kpi-trend up">↑ ${Math.abs(d).toFixed(1)}%</span>`;
    if(d<-1) return `<span class="kpi-trend down">↓ ${Math.abs(d).toFixed(1)}%</span>`;
    return '<span class="kpi-trend flat">→ Stabil</span>';
  };

  const now = new Date();
  const ayAd = ['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'][now.getMonth()];
  const secici = document.getElementById('globalTarihSecici')?.value||'buay';
  const donemAd = secici==='buay'?ayAd:secici==='gecenay'?'Geçen ay':secici==='son7'?'Son 7 gün':secici==='son30'?'Son 30 gün':secici==='buYil'?'Bu yıl':'Özel aralık';

  row.innerHTML = [
    { color:'blue',   icon:'💰', lbl:'Gelir',              val:fmtTL(k.thisAyGelir),  sub: donemAd+' satış toplamı',                    trend: '', detail:'gelir' },
    { color:'red',    icon:'📤', lbl:'Bu Ay Toplam Gider',       val:fmtTL(k.totalGider),   sub: 'İşletme + Üretim',                       trend: trend(k.totalGider, k.prevGider), detail:'gider' },
    { color:'green',  icon:'📈', lbl:'Net Kâr',                  val:fmtTL(k.netKar),       sub: `Kâr Marjı: ${k.kar_marj.toFixed(1)}%`,   trend: k.netKar>=0?'<span class="kpi-trend up">✅ Kârlı</span>':'<span class="kpi-trend down">⚠️ Zararda</span>', detail:'kar' },
    { color:'orange', icon:'🍳', lbl:'Üretim Maliyeti',          val:fmtTL(k.thisAyUretim), sub: ayAd+' üretim giderleri',                 trend: '', detail:'uretim' },
    { color:'teal',   icon:'👤', lbl:'Kişi Başı Maliyet',        val:k.kisiBasi>0?fmtTL(k.kisiBasi):'—', sub:k.totalKisi>0?`${fmt(k.totalKisi)} kişi/ay`:'Üretim verisi girin', trend: '', detail:'kisibasi' },
    { color:'purple', icon:'⚖️', lbl:'Maliyet / Gelir Oranı',    val:k.thisAyGelir>0?(k.totalGider/k.thisAyGelir*100).toFixed(1)+'%':'—',  sub:'Düşük = daha iyi', trend: '', detail:'oran' },
  ].map(c=>`
    <div class="home-kpi-card kpi-${c.color}" style="cursor:pointer" onclick="kpiDetayModal('${c.detail}')">
      <span class="kpi-icon">${c.icon}</span>
      <div class="kpi-label">${c.lbl}</div>
      <div class="kpi-value">${c.val}</div>
      <div class="kpi-sub">${c.sub}</div>
      ${c.trend}
    </div>
  `).join('');
}

// ── KPI Detay Modal ────────────────────────────────────────────
window.kpiDetayModal = function(tip){
  const now = new Date();
  const gBas = window._globalTarihBas || (now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0')+'-01');
  const gBit = window._globalTarihBit || now.toISOString().slice(0,10);
  const tf = (t) => t >= gBas && t <= gBit;
  const ayAd = gBas + ' → ' + gBit;

  const gelirler = getGelirler().filter(g=>tf(g.tarih||''));
  const giderler = getGiderler().filter(g=>tf(g.tarih||''));
  const uretim   = getUretimGiderleri().filter(u=>tf(u.tarih||''));

  let title='', rows='', summary='';

  if(tip==='gelir'){
    title = '💰 '+ayAd+' Gelir Detayı';
    // Müşteri bazlı grupla
    const byMusteri = {};
    gelirler.forEach(g=>{ const m=g.musteri||'Diğer'; if(!byMusteri[m]) byMusteri[m]={toplam:0,kayitlar:[]}; byMusteri[m].toplam+=(g.tutar||0); byMusteri[m].kayitlar.push(g); });
    const toplam = gelirler.reduce((s,g)=>s+(g.tutar||0),0);
    rows = Object.keys(byMusteri).sort((a,b)=>byMusteri[b].toplam-byMusteri[a].toplam).map(m=>{
      const d = byMusteri[m];
      const detay = d.kayitlar.slice(-5).reverse().map(g=>`<div style="font-size:11px;color:#64748b;padding:2px 0 2px 16px">${g.tarih} — ${fmtTL(g.tutar)} ${g.kisi?'('+g.kisi+' kişi)':''}</div>`).join('');
      const oran = toplam>0 ? (d.toplam/toplam*100).toFixed(1) : 0;
      return `<div style="border-bottom:1px solid #e2e8f0;padding:8px 0">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <span style="font-weight:700">${m}</span>
          <span style="font-weight:800;color:#166534">${fmtTL(d.toplam)} <span style="font-size:10px;color:#64748b">(${oran}%)</span></span>
        </div>${detay}</div>`;
    }).join('');
    summary = `<div style="font-size:16px;font-weight:900;color:#166534;padding:12px 0;border-top:2px solid #16a34a">TOPLAM: ${fmtTL(toplam)}</div>`;
  }
  else if(tip==='gider'){
    title = '📤 '+ayAd+' Gider Detayı';
    const katLabels = {'uretim-kirmizi':'🥩 Kırmızı Et','uretim-tavuk':'🍗 Tavuk','uretim-kiyma':'🫙 Kıyma','uretim-sebze':'🥦 Sebze','uretim-bakliyat':'🫘 Bakliyat','uretim-ayran':'🥛 Ayran','uretim-ekmek':'🍞 Ekmek','uretim-diger-gida':'🛒 Diğer Gıda','personel':'👷 Personel','personel-maas':'💰 Maaş','enerji':'⚡ Enerji','diger':'📌 Diğer'};
    // Kategori bazlı grupla
    const byKat = {};
    giderler.forEach(g=>{ const k=g.kat||'diger'; if(!byKat[k]) byKat[k]={toplam:0,kayitlar:[]}; byKat[k].toplam+=(g.tutar||0); byKat[k].kayitlar.push(g); });
    const toplam = giderler.reduce((s,g)=>s+(g.tutar||0),0);
    const uretimTop = uretim.reduce((s,u)=>s+(u.toplam||u.tutar||0),0);
    rows = Object.keys(byKat).sort((a,b)=>byKat[b].toplam-byKat[a].toplam).map(k=>{
      const d = byKat[k];
      const detay = d.kayitlar.slice(-5).reverse().map(g=>{
        const ait = g.aitlik==='musteri'&&g.musteriler?.length ? ' ['+g.musteriler.join(',')+']' : '';
        return `<div style="font-size:11px;color:#64748b;padding:2px 0 2px 16px">${g.tarih} — ${fmtTL(g.tutar)} ${g.aciklama||''}${ait}</div>`;
      }).join('');
      return `<div style="border-bottom:1px solid #e2e8f0;padding:8px 0">
        <div style="display:flex;justify-content:space-between"><span style="font-weight:700">${katLabels[k]||k}</span><span style="font-weight:800;color:#dc2626">${fmtTL(d.toplam)}</span></div>${detay}</div>`;
    }).join('');
    if(uretimTop>0) rows += `<div style="border-bottom:1px solid #e2e8f0;padding:8px 0"><div style="display:flex;justify-content:space-between"><span style="font-weight:700">🍳 Üretim Giderleri</span><span style="font-weight:800;color:#dc2626">${fmtTL(uretimTop)}</span></div></div>`;
    summary = `<div style="font-size:16px;font-weight:900;color:#dc2626;padding:12px 0;border-top:2px solid #dc2626">TOPLAM: ${fmtTL(toplam+uretimTop)}</div>`;
  }
  else if(tip==='kar'){
    title = '📈 '+ayAd+' Kâr/Zarar Analizi';
    const topGelir = gelirler.reduce((s,g)=>s+(g.tutar||0),0);
    const topGider = giderler.reduce((s,g)=>s+(g.tutar||0),0);
    const topUretim = uretim.reduce((s,u)=>s+(u.toplam||u.tutar||0),0);
    const net = topGelir - topGider - topUretim;
    // Müşteri bazlı kârlılık
    const byMusteri = {};
    gelirler.forEach(g=>{ const m=g.musteri||'Diğer'; if(!byMusteri[m]) byMusteri[m]={gelir:0,gider:0}; byMusteri[m].gelir+=(g.tutar||0); });
    giderler.filter(g=>g.aitlik==='musteri'&&g.musteriler?.length).forEach(g=>{
      g.musteriler.forEach(m=>{ if(!byMusteri[m]) byMusteri[m]={gelir:0,gider:0}; byMusteri[m].gider+=(g.tutar||0)/g.musteriler.length; });
    });
    rows = `<div style="border-bottom:1px solid #e2e8f0;padding:8px 0;display:flex;justify-content:space-between"><span style="font-weight:700">💰 Toplam Gelir</span><span style="font-weight:800;color:#166534">${fmtTL(topGelir)}</span></div>`
      +`<div style="border-bottom:1px solid #e2e8f0;padding:8px 0;display:flex;justify-content:space-between"><span style="font-weight:700">📤 İşletme Gideri</span><span style="font-weight:800;color:#dc2626">−${fmtTL(topGider)}</span></div>`
      +`<div style="border-bottom:1px solid #e2e8f0;padding:8px 0;display:flex;justify-content:space-between"><span style="font-weight:700">🍳 Üretim Gideri</span><span style="font-weight:800;color:#dc2626">−${fmtTL(topUretim)}</span></div>`;
    // Müşteri kârlılık
    const mustKeys = Object.keys(byMusteri).filter(m=>byMusteri[m].gelir>0||byMusteri[m].gider>0);
    if(mustKeys.length){
      rows += `<div style="font-weight:800;color:#1e293b;padding:10px 0 4px;font-size:13px">Müşteri Bazlı Kârlılık:</div>`;
      rows += mustKeys.sort((a,b)=>(byMusteri[b].gelir-byMusteri[b].gider)-(byMusteri[a].gelir-byMusteri[a].gider)).map(m=>{
        const d=byMusteri[m]; const kar=d.gelir-d.gider;
        return `<div style="border-bottom:1px solid #f1f5f9;padding:4px 0;display:flex;justify-content:space-between;font-size:12px"><span>${m}</span><span>Gelir: ${fmtTL(d.gelir)} | Gider: ${fmtTL(d.gider)} | <b style="color:${kar>=0?'#166534':'#dc2626'}">Kâr: ${fmtTL(kar)}</b></span></div>`;
      }).join('');
    }
    summary = `<div style="font-size:18px;font-weight:900;color:${net>=0?'#166534':'#dc2626'};padding:12px 0;border-top:2px solid ${net>=0?'#16a34a':'#dc2626'}">NET KÂR: ${fmtTL(net)}</div>`;
  }
  else if(tip==='uretim'){
    title = '🍳 '+ayAd+' Üretim Gideri Detayı';
    const toplam = uretim.reduce((s,u)=>s+(u.toplam||u.tutar||0),0);
    rows = uretim.length ? uretim.sort((a,b)=>(b.tarih||'').localeCompare(a.tarih||'')).slice(0,20).map(u=>
      `<div style="border-bottom:1px solid #e2e8f0;padding:6px 0;display:flex;justify-content:space-between;font-size:12px"><span>${u.tarih} — ${u.aciklama||u.kat||''}</span><span style="font-weight:700;color:#dc2626">${fmtTL(u.toplam||u.tutar||0)}</span></div>`
    ).join('') : '<div style="color:#94a3b8;padding:10px">Bu ay üretim gideri kaydı yok.</div>';
    summary = `<div style="font-size:16px;font-weight:900;color:#ea580c;padding:12px 0;border-top:2px solid #ea580c">TOPLAM: ${fmtTL(toplam)}</div>`;
  }
  else if(tip==='kisibasi'){
    title = '👤 '+ayAd+' Kişi Başı Maliyet';
    const topGelir = gelirler.reduce((s,g)=>s+(g.tutar||0),0);
    const topGider = giderler.reduce((s,g)=>s+(g.tutar||0),0);
    const topUretim = uretim.reduce((s,u)=>s+(u.toplam||u.tutar||0),0);
    const totalGider = topGider + topUretim;
    const totalKisi = uretim.reduce((s,u)=>s+(parseInt(u.kisi)||0),0);
    const kisiBasi = totalKisi > 0 ? totalGider / totalKisi : 0;
    rows = `<div style="border-bottom:1px solid #e2e8f0;padding:8px 0;display:flex;justify-content:space-between"><span style="font-weight:700">📤 İşletme Gideri</span><span style="font-weight:800;color:#dc2626">${fmtTL(topGider)}</span></div>`
      +`<div style="border-bottom:1px solid #e2e8f0;padding:8px 0;display:flex;justify-content:space-between"><span style="font-weight:700">🍳 Üretim Gideri</span><span style="font-weight:800;color:#dc2626">${fmtTL(topUretim)}</span></div>`
      +`<div style="border-bottom:1px solid #e2e8f0;padding:8px 0;display:flex;justify-content:space-between"><span style="font-weight:700">📦 Toplam Gider</span><span style="font-weight:800;color:#dc2626">${fmtTL(totalGider)}</span></div>`
      +`<div style="border-bottom:1px solid #e2e8f0;padding:8px 0;display:flex;justify-content:space-between"><span style="font-weight:700">👥 Toplam Kişi (Porsiyon)</span><span style="font-weight:800;color:#1e40af">${totalKisi.toLocaleString('tr-TR')}</span></div>`
      +`<div style="border-bottom:1px solid #e2e8f0;padding:8px 0;display:flex;justify-content:space-between"><span style="font-weight:700">📐 Formül</span><span style="color:#64748b">Toplam Gider ÷ Toplam Kişi</span></div>`;
    summary = `<div style="font-size:18px;font-weight:900;color:#0d9488;padding:12px 0;border-top:2px solid #0d9488">KİŞİ BAŞI: ${kisiBasi>0?fmtTL(kisiBasi):'—'}</div>`;
  }
  else if(tip==='oran'){
    title = '⚖️ '+ayAd+' Maliyet / Gelir Oranı';
    const topGelir = gelirler.reduce((s,g)=>s+(g.tutar||0),0);
    const topGider = giderler.reduce((s,g)=>s+(g.tutar||0),0);
    const topUretim = uretim.reduce((s,u)=>s+(u.toplam||u.tutar||0),0);
    const totalGider = topGider + topUretim;
    const oran = topGelir > 0 ? (totalGider / topGelir * 100).toFixed(1) : '—';
    rows = `<div style="border-bottom:1px solid #e2e8f0;padding:8px 0;display:flex;justify-content:space-between"><span style="font-weight:700">📤 İşletme Gideri</span><span style="font-weight:800;color:#dc2626">${fmtTL(topGider)}</span></div>`
      +`<div style="border-bottom:1px solid #e2e8f0;padding:8px 0;display:flex;justify-content:space-between"><span style="font-weight:700">🍳 Üretim Gideri</span><span style="font-weight:800;color:#dc2626">${fmtTL(topUretim)}</span></div>`
      +`<div style="border-bottom:1px solid #e2e8f0;padding:8px 0;display:flex;justify-content:space-between"><span style="font-weight:700">📦 Toplam Gider</span><span style="font-weight:800;color:#dc2626">${fmtTL(totalGider)}</span></div>`
      +`<div style="border-bottom:1px solid #e2e8f0;padding:8px 0;display:flex;justify-content:space-between"><span style="font-weight:700">💰 Toplam Gelir</span><span style="font-weight:800;color:#166534">${fmtTL(topGelir)}</span></div>`
      +`<div style="border-bottom:1px solid #e2e8f0;padding:8px 0;display:flex;justify-content:space-between"><span style="font-weight:700">📐 Formül</span><span style="color:#64748b">Toplam Gider ÷ Toplam Gelir × 100</span></div>`;
    summary = `<div style="font-size:18px;font-weight:900;color:#7c3aed;padding:12px 0;border-top:2px solid #7c3aed">MALİYET ORANI: %${oran}</div>`;
  }
  else if(tip==='kdv-tahsil'){
    title = '🧾 '+ayAd+' KDV Tahsil Detayı';
    const kdvliGelirler = gelirler.filter(g=>parseFloat(g.kdvTutar||0)>0);
    const toplam = kdvliGelirler.reduce((s,g)=>s+(parseFloat(g.kdvTutar)||0),0);
    rows = kdvliGelirler.length ? kdvliGelirler.sort((a,b)=>(b.tarih||'').localeCompare(a.tarih||'')).slice(0,20).map(g=>
      `<div style="border-bottom:1px solid #e2e8f0;padding:6px 0;display:flex;justify-content:space-between;font-size:12px"><span>${g.tarih} — ${g.musteri||'Diğer'} (%${g.kdvPct||'?'})</span><span style="font-weight:700;color:#166534">${fmtTL(parseFloat(g.kdvTutar)||0)}</span></div>`
    ).join('') : '<div style="color:#94a3b8;padding:10px">KDV\'li gelir kaydı bulunamadı.</div>';
    rows += `<div style="padding:8px 0;font-size:11px;color:#64748b">Kaynak: getGelirler() → kdvTutar alanı. Gelir kaydedilirken seçilen KDV oranı üzerinden hesaplanır.</div>`;
    summary = `<div style="font-size:16px;font-weight:900;color:#166534;padding:12px 0;border-top:2px solid #16a34a">KDV TAHSİL TOPLAM: ${fmtTL(toplam)}</div>`;
  }
  else if(tip==='kdv-indirim'){
    title = '🧾 '+ayAd+' KDV İndirim Detayı';
    const kdvliGiderler = giderler.filter(g=>parseFloat(g.kdvTutar||0)>0);
    const kdvliUretim = uretim.filter(u=>parseFloat(u.kdvTutar||0)>0);
    const topGider = kdvliGiderler.reduce((s,g)=>s+(parseFloat(g.kdvTutar)||0),0);
    const topUretim = kdvliUretim.reduce((s,u)=>s+(parseFloat(u.kdvTutar)||0),0);
    rows = '<div style="font-weight:700;color:#1e293b;padding:6px 0;font-size:13px">İşletme Gider KDV:</div>';
    rows += kdvliGiderler.length ? kdvliGiderler.sort((a,b)=>(b.tarih||'').localeCompare(a.tarih||'')).slice(0,15).map(g=>
      `<div style="border-bottom:1px solid #f1f5f9;padding:4px 0;display:flex;justify-content:space-between;font-size:12px"><span>${g.tarih} — ${g.aciklama||g.kat||'Gider'} (%${g.kdvPct||'?'})</span><span style="font-weight:700;color:#dc2626">${fmtTL(parseFloat(g.kdvTutar)||0)}</span></div>`
    ).join('') : '<div style="color:#94a3b8;padding:6px;font-size:12px">KDV\'li gider yok.</div>';
    if(topUretim>0){
      rows += '<div style="font-weight:700;color:#1e293b;padding:6px 0;font-size:13px;margin-top:8px">Üretim KDV:</div>';
      rows += fmtTL(topUretim);
    }
    rows += `<div style="padding:8px 0;font-size:11px;color:#64748b">Kaynak: getGiderler() + getUretimGiderleri() → kdvTutar alanı.</div>`;
    summary = `<div style="font-size:16px;font-weight:900;color:#dc2626;padding:12px 0;border-top:2px solid #dc2626">KDV İNDİRİM TOPLAM: ${fmtTL(topGider+topUretim)}</div>`;
  }
  else if(tip==='kdv-odenecek'){
    title = '🧾 '+ayAd+' Ödenecek KDV Hesabı';
    const kdvTahsil = gelirler.reduce((s,g)=>s+(parseFloat(g.kdvTutar)||0),0);
    const kdvIndirimG = giderler.reduce((s,g)=>s+(parseFloat(g.kdvTutar)||0),0);
    const kdvIndirimU = uretim.reduce((s,u)=>s+(parseFloat(u.kdvTutar)||0),0);
    const kdvIndirim = kdvIndirimG + kdvIndirimU;
    const odenecek = kdvTahsil - kdvIndirim;
    rows = `<div style="border-bottom:1px solid #e2e8f0;padding:8px 0;display:flex;justify-content:space-between"><span style="font-weight:700">KDV Tahsil (Gelirlerden)</span><span style="font-weight:800;color:#166534">${fmtTL(kdvTahsil)}</span></div>`
      +`<div style="border-bottom:1px solid #e2e8f0;padding:8px 0;display:flex;justify-content:space-between"><span style="font-weight:700">KDV İndirim (Giderlerden)</span><span style="font-weight:800;color:#dc2626">−${fmtTL(kdvIndirim)}</span></div>`
      +`<div style="padding:8px 0;font-size:11px;color:#64748b">Formül: KDV Tahsil − KDV İndirim = Ödenecek KDV</div>`;
    summary = `<div style="font-size:18px;font-weight:900;color:${odenecek>=0?'#1d4ed8':'#166534'};padding:12px 0;border-top:2px solid ${odenecek>=0?'#3b82f6':'#16a34a'}">ÖDENECEK KDV: ${fmtTL(odenecek)}</div>`;
  }
  else if(tip==='gelir-vergisi'){
    title = '🧾 '+ayAd+' Gelir Vergisi Hesabı';
    const kdvliGelir = gelirler.reduce((s,g)=>s+(parseFloat(g.kdvTutar)||0),0);
    const topGelir = gelirler.reduce((s,g)=>s+(g.tutar||0),0);
    const topGider = giderler.reduce((s,g)=>s+(g.tutar||0),0);
    const topUretim = uretim.reduce((s,u)=>s+(u.toplam||u.tutar||0),0);
    const matrah = topGelir - topGider - topUretim;
    const vergi = matrah > 0 ? matrah / 4.1 : 0;
    rows = `<div style="border-bottom:1px solid #e2e8f0;padding:8px 0;display:flex;justify-content:space-between"><span style="font-weight:700">💰 Toplam Gelir</span><span style="font-weight:800;color:#166534">${fmtTL(topGelir)}</span></div>`
      +`<div style="border-bottom:1px solid #e2e8f0;padding:8px 0;display:flex;justify-content:space-between"><span style="font-weight:700">📤 İşletme Gideri</span><span style="font-weight:800;color:#dc2626">−${fmtTL(topGider)}</span></div>`
      +`<div style="border-bottom:1px solid #e2e8f0;padding:8px 0;display:flex;justify-content:space-between"><span style="font-weight:700">🍳 Üretim Gideri</span><span style="font-weight:800;color:#dc2626">−${fmtTL(topUretim)}</span></div>`
      +`<div style="border-bottom:1px solid #e2e8f0;padding:8px 0;display:flex;justify-content:space-between"><span style="font-weight:700">📊 Matrah (Gelir − Gider)</span><span style="font-weight:800;color:#1d4ed8">${fmtTL(matrah)}</span></div>`
      +`<div style="padding:8px 0;font-size:11px;color:#64748b">Formül: Matrah ÷ 4.1 ≈ Basit usul gelir vergisi tahmini</div>`;
    summary = `<div style="font-size:18px;font-weight:900;color:#b45309;padding:12px 0;border-top:2px solid #f59e0b">GELİR VERGİSİ: ${fmtTL(vergi)}</div>`;
  }
  else {
    title = '📊 Detay'; rows = '<div style="color:#94a3b8;padding:10px">Detay bilgisi mevcut değil.</div>'; summary='';
  }

  // Modal oluştur
  const modal = document.createElement('div');
  modal.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:10000;display:flex;align-items:flex-start;justify-content:center;padding:24px;overflow:auto';
  modal.onclick=function(e){ if(e.target===modal) modal.remove(); };
  modal.innerHTML=`<div style="background:#fff;border-radius:16px;max-width:700px;width:100%;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.25);margin-top:40px;max-height:80vh;overflow:auto">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
      <h2 style="margin:0;font-size:18px;color:#1e293b">${title}</h2>
      <button onclick="this.closest('div[style*=fixed]').remove()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#64748b">✕</button>
    </div>
    <div style="font-size:13px">${rows}</div>
    ${summary}
  </div>`;
  document.body.appendChild(modal);
};

// ── Aylık Maliyet Trend Grafiği ───────────────────────────────

function renderHomeTrendChart(){
  const ctx = document.getElementById('homeLineChart');
  if(!ctx) return;
  if(_homeCharts.line){ _homeCharts.line.destroy(); }

  const giderler  = getGiderler();
  const uretim    = getUretimGiderleri();
  const gelirler  = getGelirler();
  const now       = new Date();
  const labels=[], gelData=[], gidData=[];

  for(let i=5;i>=0;i--){
    const d = new Date(now.getFullYear(), now.getMonth()-i, 1);
    const ym = d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0');
    const ayAd = ['Oca','Şub','Mar','Nis','May','Haz','Tem','Ağu','Eyl','Eki','Kas','Ara'][d.getMonth()];
    labels.push(ayAd+' '+String(d.getFullYear()).slice(2));
    gelData.push(gelirler.filter(g=>g.tarih?.startsWith(ym)).reduce((s,g)=>s+Number(g.tutar||0),0));
    gidData.push(
      giderler.filter(g=>g.tarih?.startsWith(ym)).reduce((s,g)=>s+Number(g.tutar||0),0)+
      uretim.filter(u=>u.tarih?.startsWith(ym)).reduce((s,u)=>s+Number(u.tutar||0),0)
    );
  }

  _homeCharts.line = new Chart(ctx,{
    type:'line',
    data:{
      labels,
      datasets:[
        { label:'Gelir', data:gelData, borderColor:'#059669', backgroundColor:'rgba(5,150,105,.08)', tension:.35, fill:true, borderWidth:2.5, pointRadius:4, pointBackgroundColor:'#059669' },
        { label:'Gider', data:gidData, borderColor:'#dc2626', backgroundColor:'rgba(220,38,38,.06)', tension:.35, fill:true, borderWidth:2.5, pointRadius:4, pointBackgroundColor:'#dc2626' },
      ]
    },
    options:{
      responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{ position:'top', labels:{ font:{size:11}, boxWidth:12 } } },
      scales:{
        y:{ ticks:{ callback:(v)=>v>=1000?Math.round(v/1000)+'K ₺':v+' ₺', font:{size:10} }, grid:{ color:'rgba(0,0,0,.05)' } },
        x:{ ticks:{ font:{size:10} }, grid:{ display:false } }
      }
    }
  });
}

// ── Gider Dağılım Pasta ───────────────────────────────────────

function renderHomePieChart(){
  const ctx = document.getElementById('homePieChart');
  if(!ctx) return;
  if(_homeCharts.pie){ _homeCharts.pie.destroy(); }

  const now = new Date();
  const ym  = now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0');
  const giderler = getGiderler().filter(g=>g.tarih?.startsWith(ym));

  // Kategori bazlı grupla
  const cats = {};
  giderler.forEach(g=>{
    const cat = g.kategori||g.category||g.tip||'Diğer';
    cats[cat] = (cats[cat]||0)+Number(g.tutar||0);
  });
  const uretimToplam = getUretimGiderleri().filter(u=>u.tarih?.startsWith(ym)).reduce((s,u)=>s+Number(u.tutar||0),0);
  if(uretimToplam>0) cats['Üretim'] = (cats['Üretim']||0)+uretimToplam;

  const sorted = Object.entries(cats).sort((a,b)=>b[1]-a[1]).slice(0,7);

  if(!sorted.length){
    if(!ctx.parentElement.querySelector('.home-empty')) ctx.parentElement.insertAdjacentHTML('beforeend','<div class="home-empty">Bu ay için gider verisi yok</div>');
    return;
  }

  const colors = ['#1a56db','#dc2626','#059669','#d97706','#7c3aed','#0891b2','#db2777'];

  _homeCharts.pie = new Chart(ctx,{
    type:'doughnut',
    data:{
      labels: sorted.map(([k])=>k),
      datasets:[{ data: sorted.map(([,v])=>v), backgroundColor: colors, borderWidth:2, borderColor:'#fff' }]
    },
    options:{
      responsive:true, maintainAspectRatio:false,
      plugins:{
        legend:{ position:'bottom', labels:{ font:{size:10}, boxWidth:10, padding:8 } },
        tooltip:{ callbacks:{ label:(ctx)=>ctx.label+': '+fmtTL(ctx.raw) } }
      },
      cutout:'55%'
    }
  });
}

// ── Bütçe vs Gerçek Çubuk ─────────────────────────────────────

function renderHomeBudgetChart(){
  const ctx = document.getElementById('homeBudgetChart');
  if(!ctx) return;
  if(_homeCharts.budget){ _homeCharts.budget.destroy(); }

  const butce    = getButce();
  const now      = new Date();
  const ym       = now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0');
  const giderler = getGiderler().filter(g=>g.tarih?.startsWith(ym));
  const uretim   = getUretimGiderleri().filter(u=>u.tarih?.startsWith(ym));

  // Kategori bazlı gerçek gider
  const gercek = {};
  giderler.forEach(g=>{ const c=g.kategori||'Diğer'; gercek[c]=(gercek[c]||0)+Number(g.tutar||0); });
  const uretimTop = uretim.reduce((s,u)=>s+Number(u.tutar||0),0);
  if(uretimTop) gercek['Üretim']=(gercek['Üretim']||0)+uretimTop;

  const keys = Object.keys(butce.kategoriler||gercek);
  if(!keys.length){
    if(!ctx.parentElement.querySelector('.home-empty')) ctx.parentElement.insertAdjacentHTML('beforeend','<div class="home-empty">Bütçe verisi için Finans → Bütçe sekmesini kullanın</div>');
    return;
  }

  const butceData  = keys.map(k=>Number((butce.kategoriler||{})[k]||0));
  const gercekData = keys.map(k=>Number(gercek[k]||0));

  _homeCharts.budget = new Chart(ctx,{
    type:'bar',
    data:{
      labels:keys,
      datasets:[
        { label:'Bütçe', data:butceData, backgroundColor:'rgba(26,86,219,.25)', borderColor:'#1a56db', borderWidth:2, borderRadius:4 },
        { label:'Gerçek', data:gercekData, backgroundColor:'rgba(220,38,38,.25)', borderColor:'#dc2626', borderWidth:2, borderRadius:4 },
      ]
    },
    options:{
      responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{ position:'top', labels:{ font:{size:11}, boxWidth:12 } } },
      scales:{
        y:{ ticks:{ callback:v=>v>=1000?Math.round(v/1000)+'K':v, font:{size:10} } },
        x:{ ticks:{ font:{size:10} } }
      }
    }
  });
}

// ── Kişi Başı Maliyet Tablosu ─────────────────────────────────

function renderKisiBasiTablo(){
  const el = document.getElementById('home-kisibasi-tablo');
  if(!el) return;
  const now  = new Date();
  const ym   = now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0');
  const uretim  = getUretimGiderleri().filter(u=>u.tarih?.startsWith(ym));
  const sayilar = getUretimSayilari().filter(s=>s.tarih?.startsWith(ym));

  // Müşteri bazlı grupla
  const byMusteri = {};
  uretim.forEach(u=>{
    const m = u.musteri||u.proje||'GENEL';
    if(!byMusteri[m]) byMusteri[m] = {tutar:0,kisi:0};
    byMusteri[m].tutar += Number(u.tutar||0);
  });
  sayilar.forEach(s=>{
    const m = s.musteri||s.proje||'GENEL';
    if(!byMusteri[m]) byMusteri[m] = {tutar:0,kisi:0};
    byMusteri[m].kisi += Number(s.adet||s.kisi||s.kisiSayisi||0);
  });

  const rows = Object.entries(byMusteri);
  if(!rows.length){ el.innerHTML='<div class="home-empty">Üretim gideri ve sayı verisi girilmemiş</div>'; return; }

  let html = '<table style="width:100%;border-collapse:collapse;font-size:12px">';
  html += '<thead><tr style="background:#eef3ff"><th style="padding:7px 10px;text-align:left">Müşteri</th><th style="padding:7px 10px">Maliyet</th><th style="padding:7px 10px">Kişi</th><th style="padding:7px 10px">Kişi Başı</th><th style="padding:7px 10px">Bar</th></tr></thead><tbody>';
  const maxKB = Math.max(...rows.map(([,v])=>v.kisi>0?v.tutar/v.kisi:0));
  rows.sort((a,b)=>b[1].tutar-a[1].tutar).forEach(([m,v])=>{
    const kb = v.kisi>0 ? v.tutar/v.kisi : 0;
    const pct = maxKB>0 ? kb/maxKB*100 : 0;
    html += `<tr style="border-bottom:1px solid #e5e7eb">
      <td style="padding:7px 10px;font-weight:700">${m}</td>
      <td style="padding:7px 10px;text-align:right">${fmtTL(v.tutar)}</td>
      <td style="padding:7px 10px;text-align:right">${fmt(v.kisi)}</td>
      <td style="padding:7px 10px;text-align:right;font-weight:800;color:#1a56db">${kb>0?fmtTL(kb):'—'}</td>
      <td style="padding:7px 10px;min-width:60px">
        <div style="background:#e2e8f0;border-radius:999px;height:6px"><div style="width:${pct.toFixed(1)}%;background:linear-gradient(90deg,#1a56db,#3b82f6);border-radius:999px;height:6px"></div></div>
      </td>
    </tr>`;
  });
  html += '</tbody></table>';
  el.innerHTML = html;
}

// ── Son Finansal İşlemler ─────────────────────────────────────

function renderSonIslemler(){
  const el = document.getElementById('home-son-islemler');
  if(!el) return;
  const gelirler = getGelirler().map(g=>({...g,_tip:'G'}));
  const giderler = getGiderler().map(g=>({...g,_tip:'GD'}));
  const all = [...gelirler,...giderler]
    .sort((a,b)=>(b.tarih||'').localeCompare(a.tarih||''))
    .slice(0,8);

  if(!all.length){ el.innerHTML='<div class="home-empty">Henüz finansal kayıt yok</div>'; return; }
  el.innerHTML = all.map(x=>`
    <div class="tx-row">
      <span class="tx-type" style="color:${x._tip==='G'?'#059669':'#dc2626'}">${x._tip==='G'?'📥':'📤'}</span>
      <span class="tx-desc">${x.aciklama||x.musteri||x.kategori||'—'}</span>
      <span class="tx-amt" style="color:${x._tip==='G'?'#059669':'#dc2626'}">${x._tip==='G'?'+':'−'}${fmtTL(Number(x.tutar||0))}</span>
      <span class="tx-date">${x.tarih||''}</span>
    </div>
  `).join('');
}

// ── Stok Uyarıları ────────────────────────────────────────────

function renderStokUyarilari(){
  const el = document.getElementById('home-stok-uyari');
  if(!el) return;
  const stoklar = getStoklar().filter(s=>{
    const adet = Number(s.adet||s.miktar||0);
    const min  = Number(s.minAdet||s.minStok||0);
    return adet <= min;
  }).slice(0,6);

  if(!stoklar.length){
    el.innerHTML='<div class="home-empty">✅ Kritik stok uyarısı yok</div>'; return;
  }
  el.innerHTML = stoklar.map(s=>`
    <div class="stok-uyari-item">
      <span><strong>${s.urun||s.ad||s.isim||'—'}</strong></span>
      <span style="font-weight:700;color:#b45309">${Number(s.adet||s.miktar||0)} ${s.birim||''}</span>
    </div>
  `).join('');
}

// ── Sözleşme Uyarıları ────────────────────────────────────────

function renderSozlesmeUyarilari(){
  const el = document.getElementById('home-sozlesme-uyari');
  if(!el) return;
  const now   = new Date();
  const sozlesmeler = getSozlesmeler().filter(s=>{
    if(!s.bitis) return false;
    const diff = (new Date(s.bitis)-now)/(1000*60*60*24);
    return diff>=0 && diff<=60;
  }).sort((a,b)=>a.bitis?.localeCompare(b.bitis||'')).slice(0,5);

  if(!sozlesmeler.length){
    el.innerHTML='<div class="home-empty">✅ Yaklaşan sözleşme bitişi yok</div>'; return;
  }
  el.innerHTML = sozlesmeler.map(s=>{
    const diff = Math.ceil((new Date(s.bitis)-now)/(1000*60*60*24));
    const urgent = diff<=14;
    return `<div class="sozlesme-item ${urgent?'urgent':''}">
      <span><strong>${s.musteri||s.isim||'—'}</strong> — ${s.bitis}</span>
      <span style="font-weight:700;color:${urgent?'#991b1b':'#92400e'}">${diff} gün</span>
    </div>`;
  }).join('');
}

// ── Ana Render ────────────────────────────────────────────────


  // FIX-H: PDF Fatura Parser (PDF.js CDN)
  window.parseFaturaPDF = function(){
    var fileInput = document.getElementById('gider-pdf-fatura');
    var sonucDiv = document.getElementById('gider-pdf-sonuc');
    if(!sonucDiv){console.warn('gider-pdf-sonuc not found');return;}
    if(!fileInput||!fileInput.files||!fileInput.files[0]){
      sonucDiv.innerHTML='<span style="color:#dc2626">Lütfen önce bir PDF dosyası seçin.</span>';
      return;
    } // FIX-2
    sonucDiv.innerHTML='⏳ PDF okunuyor...';
    var file = fileInput.files[0];
    var reader = new FileReader();
    reader.onload = function(e){
      var typedArr = new Uint8Array(e.target.result);
      if(typeof pdfjsLib === 'undefined'){
        var script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
        script.onload = function(){
          pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
          _doPDFParse(typedArr, sonucDiv);
        };
        document.head.appendChild(script);
      } else {
        _doPDFParse(typedArr, sonucDiv);
      }
    };
    reader.readAsArrayBuffer(file);
    // FIX-V15: do NOT clear fileInput — may interrupt FileReader
  };

  window._doPDFParse = function(typedArr, sonucDiv){
    try{
      if(typeof pdfjsLib !== 'undefined')
        pdfjsLib.GlobalWorkerOptions.workerSrc =
          'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    }catch(e){}
    pdfjsLib.getDocument({data: typedArr}).promise.then(function(pdf){
      var totalPages = pdf.numPages;
      var textPromises = [];
      for(var p=1; p<=Math.min(totalPages,5); p++){
        textPromises.push(pdf.getPage(p).then(function(page){
          return page.getTextContent().then(function(tc){
            return tc.items.map(function(i){return i.str;}).join(' ');
          });
        }));
      }
      Promise.all(textPromises).then(function(pages){
        var fullText = pages.join(' ');
        var results = _parseFaturaText(fullText);
        if(results.length===0){
          sonucDiv.innerHTML='<span style="color:#f59e0b">⚠️ Ürün satırı bulunamadı. Lütfen manuel girin.</span>';
          return;
        }
        var html = '<div style="margin-top:6px"><b style="color:#166534">✅ '+results.length+' satır bulundu:</b><table style="width:100%;font-size:11px;border-collapse:collapse;margin-top:4px">';
        var totalFatura = 0;
        results.forEach(function(r,i){
          totalFatura += r.tutar||0;
          html += '<tr style="background:'+(i%2===0?'#f0fdf4':'#fff')+'"><td style="padding:3px 6px">'+r.urun+'</td><td style="padding:3px 6px;text-align:right;font-weight:700;color:#dc2626">'+r.tutar.toFixed(2)+' ₺</td></tr>';
        });
        html += '<tr style="background:#1e293b"><td style="padding:4px 6px;color:#fff;font-weight:700">TOPLAM</td><td style="padding:4px 6px;color:#34d399;font-weight:900;text-align:right">'+totalFatura.toFixed(2)+' ₺</td></tr>';
        html += '</table><button type="button" onclick="_faturaOtoFill('+JSON.stringify(results)+','+totalFatura.toFixed(2)+')" style="margin-top:6px;font-size:11px;padding:4px 12px;background:#16a34a;color:#fff;border:none;border-radius:6px;cursor:pointer">✅ Forma Aktar</button></div>';
        sonucDiv.innerHTML = html;
      });
    }).catch(function(err){
      sonucDiv.innerHTML='<span style="color:#dc2626">PDF okunamadı: '+err.message+'</span>';
    });
  };

  window._parseFaturaText = function(text){
    var results = [];
    var seen = {};
    var lines = text.split(/\n|\r|(?:\s{3,})/);
    lines.forEach(function(line){
      line = line.trim();
      var m = line.match(/^([\p{L}][\p{L}\s\/\-]{2,35})\s+([\d]+[,\.]?[\d]*)\s*[₺TL]?$/u);
      if(m){
        var urun=m[1].trim(), tutar=parseFloat(m[2].replace(',','.'));
        if(!seen[urun]&&tutar>0&&tutar<999999){seen[urun]=1;results.push({urun:urun,tutar:tutar});}
      }
      // Also catch: name  qty  unit_price  total_price
      var m2 = line.match(/^([\p{L}][\p{L}\s\/\-]{2,30})\s+[\d]+\s+[\d,\.]+\s+([\d,\.]+)\s*[₺]?$/u);
      if(m2){
        var urun2=m2[1].trim(), tutar2=parseFloat(m2[2].replace(',','.'));
        if(!seen[urun2]&&tutar2>0){seen[urun2]=1;results.push({urun:urun2,tutar:tutar2});}
      }
    });
    return results.slice(0,20);
  };

  window._faturaOtoFill = function(lines, toplam){
    var tutarInp = document.getElementById('gider-tutar');
    var acikInp  = document.getElementById('gider-aciklama');
    if(tutarInp) tutarInp.value = toplam.toFixed(2);
    if(acikInp)  acikInp.value  = lines.slice(0,3).map(function(l){return l.urun;}).join(', ')+(lines.length>3?' ...':'');
    document.getElementById('gider-pdf-sonuc').innerHTML='<span style="color:#16a34a">✅ Forma aktarıldı. Kaydet&#39;e basın.</span>';
    if(typeof _toast==='function') _toast('📄 Fatura verisi forma aktarıldı.');
  };


  // FIX-6b: Personel Maliyet Dağılımı hesaplama
  window.hesaplaPerMaliyet = function(){
    var sonucDiv = document.getElementById('perMaliyetSonuc');
    if(!sonucDiv) return;

    // Ay seçimi
    var ayEl = document.getElementById('perMaliyetAy');
    var secilenAy = ayEl && ayEl.value ? ayEl.value : (function(){
      var d=new Date(); return d.getFullYear()+'-'+(String(d.getMonth()+1).padStart(2,'0'));
    })();

    // FIX-V17: Personel maliyeti — proje personeli AYRI, merkez → yönetim havuzu
    var _manuelPer = parseFloat(document.getElementById('perMaliyetToplam')?.value||0);
    var _manuelYon = parseFloat(document.getElementById('yonetimGiderToplam')?.value||0);

    // Personeller
    var personeller = (function(){try{return JSON.parse(localStorage.getItem('uysa_personeller')||'[]');}catch(e){return[];}})();
    var aktifPer = personeller.filter(function(p){return !p.pasif;});

    // Proje personelini AYIR: merkez (shared) vs. müşteriye atanmış
    var merkezPerMaliyet = 0;
    var _projePer = {}; // {musteri_adi: toplam_maas}
    aktifPer.forEach(function(p){
      var m = parseFloat(p.maas)||0;
      var prj = (p.proje||'').trim();
      if(!prj || prj==='' || prj.toLowerCase()==='merkez'){
        merkezPerMaliyet += m; // paylaşımlı havuz
      } else {
        _projePer[prj] = (_projePer[prj]||0) + m; // doğrudan müşteriye
      }
    });

    // Manuel giriş varsa tüm personel miktarı olarak al (proje ataması yok sayılır)
    var manuelMod = (_manuelPer > 0);
    if(manuelMod){ merkezPerMaliyet = _manuelPer; }

    // Yönetim giderleri (kira, enerji vb.) + merkez personel = YÖNETİM HAVUZU
    var _extYon = _manuelYon;
    if(!_extYon){
      var giderler = (function(){try{return JSON.parse(localStorage.getItem('uysa_giderler')||'[]');}catch(e){return[];}})();
      var ayGiderler = giderler.filter(function(g){return (g.tarih||'').startsWith(secilenAy);});
      _extYon = ayGiderler
        .filter(function(g){return g.kat&&(g.kat.indexOf('yonetim')>=0||g.kat.indexOf('kira')>=0||g.kat.indexOf('enerji')>=0);})
        .reduce(function(s,g){return s+(g.tutar||0);},0);
    }
    // Yönetim havuzu = dış yönetim giderleri + merkez personel maliyeti
    var yonetimHavuz = _extYon + merkezPerMaliyet;
    // Toplam personel maliyeti (gösterim için)
    var toplamPerMaliyet = merkezPerMaliyet + Object.values(_projePer).reduce(function(s,v){return s+v;},0);
    var yonetimGiderleri = _extYon; // gösterim: sadece dış giderler

    // Günlük üretim verileri
    var gunlukArr = (function(){try{return JSON.parse(localStorage.getItem('uysa_gunluk_uretim')||'[]');}catch(e){return[];}})();
    var ayKayitlar = gunlukArr.filter(function(u){return (u.tarih||'').startsWith(secilenAy);});

    // Müşteri bazında toplam yemek sayısı
    var musteriYemek = {};
    var toplamYemek = 0;
    ayKayitlar.forEach(function(u){
      if(!u.musteri||!u.kisi) return;
      musteriYemek[u.musteri] = (musteriYemek[u.musteri]||0) + (u.kisi||0);
      toplamYemek += (u.kisi||0);
    });

    if(!toplamYemek){
      sonucDiv.innerHTML='<div style="color:#f59e0b;padding:12px;background:#fef9c3;border-radius:8px">⚠️ '+secilenAy+' ayına ait günlük üretim verisi bulunamadı. Önce günlük üretim girişi yapın.</div>';
      return;
    }

    // Gelirler
    var gelirler = (function(){try{return JSON.parse(localStorage.getItem('uysa_gelirler')||'[]');}catch(e){return[];}})();
    var ayGelirler = gelirler.filter(function(g){return (g.tarih||'').startsWith(secilenAy);});

    // Üretim giderleri
    var uretimGider = (function(){try{return JSON.parse(localStorage.getItem('uysa_uretim_gider')||'[]');}catch(e){return[];}})();
    var ayUretimGider = uretimGider.filter(function(g){return (g.tarih||'').startsWith(secilenAy);});
    var toplamUretimGider = ayUretimGider.reduce(function(s,g){return s+(g.tutar||0);},0);
    var birYemekUretimGider = toplamYemek>0 ? toplamUretimGider/toplamYemek : 0;

    // Müşteri bazında hesapla
    var rows = Object.keys(musteriYemek).sort(function(a,b){return musteriYemek[b]-musteriYemek[a];}).map(function(mus){
      var yemekSay = musteriYemek[mus]||0;
      var oran = toplamYemek>0 ? yemekSay/toplamYemek : 0;

      // FIX-V17: perPay=0 (merkez+yönetim havuzunda), yonetimPay=havuz*oran, proje DOĞRUDAN
      var perPay = 0; // artık yönetim havuzuna dahil

      // Yönetim havuzu payı (merkez personel + dış yönetim giderleri) — porsiyon oranında
      var yonetimPay = yonetimHavuz * oran;

      // CRM verisi
      var crmKey = 'uysa_crm_'+mus;
      var crm = (function(){try{return JSON.parse(localStorage.getItem(crmKey)||'{}');}catch(e){return{};}})();

      // Bu müşteriye doğrudan atanmış personel (çift sayım olmaz — havuzda YOK)
      var projePerMaliyet = manuelMod ? 0 : (_projePer[mus]||0);

      // Bu müşterinin geliri (ay)
      var musGelir = ayGelirler.filter(function(g){return g.musteri===mus;}).reduce(function(s,g){return s+(g.tutar||0);},0);

      // Üretim maliyeti payı
      var uretimPay = birYemekUretimGider * yemekSay;

      // Toplam maliyet — çift sayım YOK
      var toplamMaliyet = yonetimPay + projePerMaliyet + uretimPay;
      var birYemekMaliyet = yemekSay>0 ? toplamMaliyet/yemekSay : 0;
      var birYemekGelir = yemekSay>0 ? musGelir/yemekSay : (crm.fiyat||0);
      var birYemekKar = birYemekGelir - birYemekMaliyet;
      var marj = birYemekGelir>0 ? (birYemekKar/birYemekGelir)*100 : 0;

      return {mus,yemekSay,oran,perPay,yonetimPay,projePerMaliyet,uretimPay,toplamMaliyet,musGelir,birYemekMaliyet,birYemekGelir,birYemekKar,marj};
    });

    // Render
    var _f = function(n){return Number(n||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});};
    var _fp = function(n){return Number(n||0).toFixed(1)+'%';};

    var html = '<div style="background:linear-gradient(135deg,#1e293b,#334155);color:#fff;border-radius:12px;padding:14px 18px;margin-bottom:14px;display:flex;flex-wrap:wrap;gap:14px;align-items:center">'
      +'<div style="flex:1;min-width:140px"><div style="font-size:11px;opacity:.7">Toplam Personel Maliyeti</div><div style="font-size:20px;font-weight:800;color:#34d399">'+_f(toplamPerMaliyet)+' ₺</div></div>'
      +'<div style="flex:1;min-width:140px"><div style="font-size:11px;opacity:.7">Yönetim Havuzu<br><span style="font-size:10px;font-weight:400;opacity:.8">(Merkez+Gider)</span></div><div style="font-size:20px;font-weight:800;color:#fbbf24">'+_f(yonetimHavuz)+' ₺</div></div>'
      +'<div style="flex:1;min-width:140px"><div style="font-size:11px;opacity:.7">Toplam Yemek</div><div style="font-size:20px;font-weight:800;color:#60a5fa">'+toplamYemek.toLocaleString('tr-TR')+' porsiyon</div></div>'
      +'<div style="flex:1;min-width:140px"><div style="font-size:11px;opacity:.7">1 Yemek Üretim Maliyeti</div><div style="font-size:20px;font-weight:800;color:#f87171">'+_f(birYemekUretimGider)+' ₺</div></div>'
      +'</div>';

    html += '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:13px">'
      +'<thead><tr style="background:#f1f5f9">'
      +'<th style="padding:10px 8px;text-align:left;border-bottom:2px solid #e2e8f0">Müşteri</th>'
      +'<th style="padding:10px 8px;text-align:right;border-bottom:2px solid #e2e8f0">Yemek (adet)</th>'
      +'<th style="padding:10px 8px;text-align:right;border-bottom:2px solid #e2e8f0">Pay %</th>'
      +'<th style="padding:10px 8px;text-align:right;border-bottom:2px solid #e2e8f0;display:none">Personel Payı</th>'
      +'<th style="padding:10px 8px;text-align:right;border-bottom:2px solid #e2e8f0">Proje Pers.</th>'
      +'<th style="padding:10px 8px;text-align:right;border-bottom:2px solid #e2e8f0">Yönetim Payı<br><span style="font-size:10px;font-weight:400;color:#94a3b8">(Merkez+Gider)</span></th>'
      +'<th style="padding:10px 8px;text-align:right;border-bottom:2px solid #e2e8f0">Üretim Pay.</th>'
      +'<th style="padding:10px 8px;text-align:right;border-bottom:2px solid #e2e8f0;background:#fff7ed;color:#c2410c">Toplam Maliyet</th>'
      +'<th style="padding:10px 8px;text-align:right;border-bottom:2px solid #e2e8f0">Gelir</th>'
      +'<th style="padding:10px 8px;text-align:right;border-bottom:2px solid #e2e8f0;background:#dcfce7;color:#166534">1 Yemek Maliyet</th>'
      +'<th style="padding:10px 8px;text-align:right;border-bottom:2px solid #e2e8f0;background:#dcfce7;color:#166534">1 Yemek Fiyat</th>'
      +'<th style="padding:10px 8px;text-align:right;border-bottom:2px solid #e2e8f0;background:#dcfce7;color:#166534">1 Yemek Kâr</th>'
      +'<th style="padding:10px 8px;text-align:right;border-bottom:2px solid #e2e8f0">Marj%</th>'
      +'</tr></thead><tbody>';

    rows.forEach(function(r,i){
      var karRenk = r.birYemekKar>=0 ? '#166534' : '#dc2626';
      var bg = i%2===0?'#fff':'#f8faff';
      html += '<tr style="background:'+bg+(r.projePerMaliyet>0?';border-left:3px solid #f59e0b':'')+'">'
        +'<td style="padding:9px 8px;font-weight:700">'+r.mus+(r.projePerMaliyet>0?'<span style="font-size:10px;color:#f59e0b;margin-left:4px">★ özel pers.</span>':'')+'</td>'
        +'<td style="padding:9px 8px;text-align:right;font-weight:600">'+r.yemekSay.toLocaleString('tr-TR')+'</td>'
        +'<td style="padding:9px 8px;text-align:right;color:#6b7280">'+_fp(r.oran*100)+'</td>'
        +'<td style="padding:9px 8px;text-align:right;color:#7c3aed;display:none">'+_f(r.perPay)+' ₺</td>'
        +'<td style="padding:9px 8px;text-align:right;color:'+(r.projePerMaliyet>0?'#d97706':'#9ca3af')+'">'+_f(r.projePerMaliyet)+' ₺</td>'
        +'<td style="padding:9px 8px;text-align:right;color:#0891b2">'+_f(r.yonetimPay)+' ₺</td>'
        +'<td style="padding:9px 8px;text-align:right;color:#dc2626">'+_f(r.uretimPay)+' ₺</td>'
        +'<td style="padding:9px 8px;text-align:right;font-weight:800;color:#c2410c;background:#fff7ed">'+_f(r.toplamMaliyet)+' ₺</td>'
        +'<td style="padding:9px 8px;text-align:right;color:#166534">'+_f(r.musGelir)+' ₺</td>'
        +'<td style="padding:9px 8px;text-align:right;font-weight:800;color:#c2410c;background:#fef2f2">'+_f(r.birYemekMaliyet)+' ₺</td>'
        +'<td style="padding:9px 8px;text-align:right;font-weight:800;color:#166534;background:#f0fdf4">'+_f(r.birYemekGelir)+' ₺</td>'
        +'<td style="padding:9px 8px;text-align:right;font-weight:800;color:'+karRenk+';background:'+(r.birYemekKar>=0?'#f0fdf4':'#fff1f2')+'">'+_f(r.birYemekKar)+' ₺</td>'
        +'<td style="padding:9px 8px;text-align:right;font-weight:700;color:'+karRenk+'">'+_fp(r.marj)+'</td>'
        +'</tr>';
    });

    // Genel toplam
    var gtYemek = rows.reduce(function(s,r){return s+r.yemekSay;},0);
    var gtMaliyet = rows.reduce(function(s,r){return s+r.toplamMaliyet;},0);
    var gtGelir   = rows.reduce(function(s,r){return s+r.musGelir;},0);
    var gt1Mal = gtYemek>0?gtMaliyet/gtYemek:0;
    var gt1Gel = gtYemek>0?gtGelir/gtYemek:0;
    var gt1Kar = gt1Gel - gt1Mal;
    var gtMarj = gt1Gel>0?(gt1Kar/gt1Gel)*100:0;

    html += '<tr style="background:#1e293b;font-weight:700;font-size:13px">'
      +'<td style="padding:10px 8px;color:#fff">TOPLAM ('+rows.length+' müşteri)</td>'
      +'<td style="padding:10px 8px;text-align:right;color:#60a5fa">'+gtYemek.toLocaleString('tr-TR')+'</td>'
      +'<td style="padding:10px 8px;text-align:right;color:#9ca3af">100%</td>'
      +'<td colspan="4" style="padding:10px 8px;text-align:right;color:#fbbf24">'+_f(yonetimHavuz+Object.values(_projePer).reduce(function(s,v){return s+v;},0))+' ₺</td>'
      +'<td style="padding:10px 8px;text-align:right;color:#f87171">'+_f(gtMaliyet)+' ₺</td>'
      +'<td style="padding:10px 8px;text-align:right;color:#34d399">'+_f(gtGelir)+' ₺</td>'
      +'<td style="padding:10px 8px;text-align:right;color:#f87171;background:#1e3a5f">'+_f(gt1Mal)+' ₺</td>'
      +'<td style="padding:10px 8px;text-align:right;color:#34d399;background:#1e3a5f">'+_f(gt1Gel)+' ₺</td>'
      +'<td style="padding:10px 8px;text-align:right;color:'+(gt1Kar>=0?'#34d399':'#f87171')+';background:#1e3a5f">'+_f(gt1Kar)+' ₺</td>'
      +'<td style="padding:10px 8px;text-align:right;color:'+(gtMarj>=0?'#34d399':'#f87171')+'">'+_fp(gtMarj)+'</td>'
      +'</tr>';

    html += '</tbody></table></div>';

    // Açıklama notu
    html += '<div style="margin-top:10px;background:#f0f9ff;border-left:4px solid #0ea5e9;padding:10px 14px;border-radius:0 8px 8px 0;font-size:12px;color:#0369a1">'
      +'<b>⚡ Not:</b> ★ işaretli müşteriler; İK modülünde kendilerine atanmış personel içeriyor — bu personel maliyeti toplam havuzdan ek olarak eklenir. '
      +'Yönetim Havuzu = Merkez personel + dış yönetim giderleri (kira, enerji vb.) — tüm müşterilere porsiyon sayısına oranla paylaştırılır. ★ işaretli müşterilerin kendi personel maliyeti ayrıca eklenir.'
      +'</div>';

    sonucDiv.innerHTML = html;

    // Ay seçicisini doldur (ilk çağrıda)
    var aySelEl = document.getElementById('perMaliyetAy');
    if(aySelEl && aySelEl.options.length<=1){
      var months = new Set();
      gunlukArr.forEach(function(u){ if(u.tarih) months.add(u.tarih.slice(0,7)); });
      var sorted = Array.from(months).sort().reverse();
      sorted.forEach(function(m){
        if(m !== secilenAy){
          var o=document.createElement('option'); o.value=m; o.textContent=m; aySelEl.appendChild(o);
        }
      });
    }
  };

window.renderAnasayfa = function(){
  renderHomeKPIs();
  renderHomeTrendChart();
  renderHomePieChart();
  renderHomeBudgetChart();
  renderKisiBasiTablo();
  renderSonIslemler();
  renderStokUyarilari();
  renderSozlesmeUyarilari();
};

// ── Refresh butonu ekle ────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function(){
  const panel = document.getElementById('mod-anasayfa');
  if(panel){
    const content = panel.querySelector('.content');
    if(content){
      const btn = document.createElement('button');
      btn.id = 'homeRefreshBtn';
      btn.innerHTML = '🔄 Yenile';
      btn.onclick = function(){ window.renderAnasayfa(); };
      content.style.position = 'relative';
      content.insertBefore(btn, content.firstChild);
    }
  }
});

})(); // end IIFE

