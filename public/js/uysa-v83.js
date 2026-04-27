(function() {
'use strict';

// ─── Yardımcı: localStorage ───────────────────────────────────────────────
function lsGet(k, def) {
  try { const v = localStorage.getItem(k); return v ? JSON.parse(v) : def; } catch(e){ return def; }
}
function lsSet(k, v) {
  try { localStorage.setItem(k, JSON.stringify(v)); return true; } catch(e){ return false; }
}

// ─── Aktif personel getir ─────────────────────────────────────────────────
window._personellerGetir = function(sadecAktif) {
  const p = lsGet('uysa_personeller', []);
  return sadecAktif ? p.filter(x => !x.pasif) : p;
};

// ─── Toast ────────────────────────────────────────────────────────────────
function toast(msg, tip) {
  const el = document.createElement('div');
  el.textContent = msg;
  el.style.cssText = `position:fixed;bottom:24px;right:24px;z-index:99999;
    padding:12px 20px;border-radius:8px;font-weight:600;font-size:14px;
    background:${tip==='error'?'#e74c3c':tip==='warn'?'#f39c12':'#27ae60'};
    color:#fff;box-shadow:0 4px 16px rgba(0,0,0,.3);
    animation:slideInRight .3s ease;pointer-events:none;`;
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 3200);
}

// ─── CV Kaydet ────────────────────────────────────────────────────────────
window.uysaCvEkle = function() {
  // HTML'de id="cv-isim" kullanılıyor
  const ad    = (document.getElementById('cv-isim')||document.getElementById('cv-ad')||{}).value?.trim();
  const poz   = (document.getElementById('cv-pozisyon')||{}).value?.trim();
  const tel   = (document.getElementById('cv-tel')||{}).value?.trim();
  const tec   = (document.getElementById('cv-tecr')||{}).value?.trim();
  const not_  = (document.getElementById('cv-not')||{}).value?.trim();
  if (!ad) { toast('Ad Soyad zorunludur!','error'); return; }
  const cvler = lsGet('uysa_cv', []);
  cvler.push({ ad, poz: poz||'', tel: tel||'', tec: tec||'', not: not_||'', tarih: new Date().toLocaleDateString('tr-TR') });
  lsSet('uysa_cv', cvler);
  // Formu temizle
  ['cv-isim','cv-ad','cv-pozisyon','cv-tel','cv-tecr','cv-not'].forEach(id => {
    const el = document.getElementById(id); if (el) el.value = '';
  });
  toast('CV kaydedildi ✓');
  // Render
  if (typeof window.uysaRenderCv === 'function') window.uysaRenderCv();
  else {
    // Basit render
    const div = document.getElementById('cvListDiv');
    if (div) {
      const all = lsGet('uysa_cv', []);
      div.innerHTML = all.length ? all.map((c,i) => `
        <div style="background:#f8f9fa;border-radius:8px;padding:12px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;">
          <div><strong>${c.ad}</strong> ${c.poz?'— '+c.poz:''}<br>
          <small style="color:#666;">${c.tel||''} ${c.tec?'| '+c.tec+' yıl':''} ${c.tarih?'| '+c.tarih:''}</small>
          ${c.not?'<br><small>'+c.not+'</small>':''}</div>
          <button onclick="window.uysaCvSil(${i})" style="background:#e74c3c;color:#fff;border:none;border-radius:6px;padding:4px 10px;cursor:pointer;">🗑</button>
        </div>`).join('') : '<p style="color:#888;text-align:center;">CV kaydı yok</p>';
    }
  }
};

window.uysaCvSil = function(i) {
  const cvler = lsGet('uysa_cv', []);
  cvler.splice(i, 1);
  lsSet('uysa_cv', cvler);
  window.uysaCvEkle_render && window.uysaCvEkle_render();
  // Re-render
  const div = document.getElementById('cvListDiv');
  if (div) {
    const all = lsGet('uysa_cv', []);
    div.innerHTML = all.length ? all.map((c,j) => `
      <div style="background:#f8f9fa;border-radius:8px;padding:12px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;">
        <div><strong>${c.ad}</strong> ${c.poz?'— '+c.poz:''}<br>
        <small style="color:#666;">${c.tel||''} ${c.tec?'| '+c.tec+' yıl':''} ${c.tarih?'| '+c.tarih:''}</small>
        ${c.not?'<br><small>'+c.not+'</small>':''}</div>
        <button onclick="window.uysaCvSil(${j})" style="background:#e74c3c;color:#fff;border:none;border-radius:6px;padding:4px 10px;cursor:pointer;">🗑</button>
      </div>`).join('') : '<p style="color:#888;text-align:center;">CV kaydı yok</p>';
  }
  toast('CV silindi','warn');
};

// ─── Personel Maliyet Dağılımı - tüm müşterileri göster ──────────────────
window.uysaPerMalHesapla = function() {
  const secilenMus = (document.getElementById('perMalMusteriSel')||{}).value;
  const personeller = (function(){try{return JSON.parse(localStorage.getItem('uysa_personeller')||'[]');}catch(e){return[];}}()).filter(p => !p.pasif);
  const div = document.getElementById('perMalDiv');
  if (!div) return;

  if (!personeller.length) {
    div.innerHTML = '<p style="color:#888;text-align:center;">Aktif personel yok</p>';
    return;
  }

  // Müşteri bazlı gruplama
  const musGruplari = {};
  personeller.forEach(p => {
    const proje = p.proje || 'MERKEZ';
    if (!musGruplari[proje]) musGruplari[proje] = [];
    musGruplari[proje].push(p);
  });

  const gosterilecek = secilenMus && secilenMus !== 'TUMU'
    ? { [secilenMus]: musGruplari[secilenMus] || [] }
    : musGruplari;

  let html_out = '';
  let genelToplam = 0;

  Object.entries(gosterilecek).forEach(([mus, perList]) => {
    if (!perList || !perList.length) return;
    const toplam = perList.reduce((s, p) => s + (parseFloat(p.maas)||0), 0);
    genelToplam += toplam;
    html_out += `
      <div style="background:linear-gradient(135deg,#667eea22,#764ba222);border-radius:10px;padding:14px;margin-bottom:10px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
          <strong style="color:#4a3f8a;font-size:15px;">📍 ${mus}</strong>
          <span style="background:#667eea;color:#fff;padding:4px 12px;border-radius:20px;font-weight:700;">${toplam.toLocaleString('tr-TR')} ₺</span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:6px;">
          ${perList.map(p => `
            <div style="background:#fff;border-radius:6px;padding:8px;font-size:13px;">
              <div style="font-weight:600;">${p.ad} ${p.soyad}</div>
              <div style="color:#666;">${p.gorev||p.proje||''}</div>
              <div style="color:#27ae60;font-weight:700;">${parseFloat(p.maas||0).toLocaleString('tr-TR')} ₺</div>
            </div>`).join('')}
        </div>
      </div>`;
  });

  if (!html_out) {
    html_out = '<p style="color:#888;text-align:center;">Seçilen müşteri için personel yok</p>';
  } else {
    html_out = `<div style="text-align:right;font-weight:700;color:#4a3f8a;margin-bottom:10px;font-size:16px;">
      Toplam Maliyet: ${genelToplam.toLocaleString('tr-TR')} ₺</div>` + html_out;
  }

  div.innerHTML = html_out;
};

// perMalHesaplaBtn'a bağla
document.addEventListener('DOMContentLoaded', function() {
  const btn = document.getElementById('perMalHesaplaBtn');
  if (btn) btn.onclick = window.uysaPerMalHesapla;
});

// CRM sabit panel JS kaldırıldı (pure CSS sticky aktif)

// ─── refreshIkSelectors - aktif personel filtreli final versiyon ──────────
window.refreshIkSelectors = function() {
  const aktifPer = lsGet('uysa_personeller', []).filter(p => !p.pasif);
  const perAdlari = aktifPer.map(p => ((p.ad||'') + ' ' + (p.soyad||'')).trim());

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

  // Proje dropdown
  try {
    const projeEl = document.getElementById('per-proje');
    if (projeEl) {
      const obj = JSON.parse(localStorage.getItem('uysa_customers_v1')||'{}');
      const pasifler = JSON.parse(localStorage.getItem('uysa_pasif_musteriler')||'[]');
      const musteriler = (obj.customers||[]).filter(c=>c&&c!=='GENEL'&&!pasifler.includes(c));
      const prev = projeEl.value;
      projeEl.innerHTML = '<option value="MERKEZ">MERKEZ</option>' +
        musteriler.map(m => `<option value="${m}">${m}</option>`).join('');
      if ([...projeEl.options].some(o => o.value === prev)) projeEl.value = prev;
    }
  } catch(e) {}

  // Maliyet dağılımı müşteri seçici
  try {
    const malEl = document.getElementById('perMalMusteriSel');
    if (malEl) {
      const obj = JSON.parse(localStorage.getItem('uysa_customers_v1')||'{}');
      const pasifler = JSON.parse(localStorage.getItem('uysa_pasif_musteriler')||'[]');
      const musteriler = (obj.customers||[]).filter(c=>c&&c!=='GENEL'&&!pasifler.includes(c));
      const prev = malEl.value;
      malEl.innerHTML = '<option value="TUMU">Tüm Müşteriler</option>' +
        musteriler.map(m => `<option value="${m}">${m}</option>`).join('');
      if (prev && [...malEl.options].some(o => o.value === prev)) malEl.value = prev;
    }
  } catch(e) {}
};

// Sayfa yüklenince çalıştır
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', function() {
    setTimeout(window.refreshIkSelectors, 400);
  });
} else {
  setTimeout(window.refreshIkSelectors, 400);
}

console.log('✅ UYSA v83 yüklendi');
})();
