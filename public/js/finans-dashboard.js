/* =====================================================
   UYSA v63 - FİNANS DASHBOARD JavaScript
   Bağımlılık yok — saf SVG/HTML grafikler
   ===================================================== */
(function() {

// ─── Yardımcı: Para formatı ───
function fmtPara(n) {
  if (isNaN(n)) return '0 ₺';
  return Math.round(n).toLocaleString('tr-TR') + ' ₺';
}
function fmtYuzde(n) {
  if (isNaN(n) || !isFinite(n)) return '—';
  return (n >= 0 ? '+' : '') + n.toFixed(1) + '%';
}

// ─── Veri çekiciler ───
function getGelirler() {
  try { return JSON.parse(localStorage.getItem('uysa_gelirler') || '[]'); } catch(e) { return []; }
}
function getGiderler() {
  try { return JSON.parse(localStorage.getItem('uysa_giderler') || '[]'); } catch(e) { return []; }
}
function getButceler() {
  try { return JSON.parse(localStorage.getItem('uysa_butceler') || '[]'); } catch(e) { return []; }
}
function saveButceler(d) {
  localStorage.setItem('uysa_butceler', JSON.stringify(d));
  if (window.autoSaveToFolder) window.autoSaveToFolder();
}

// ─── Yıl dropdown'larını doldur ───
function initYilDropdowns() {
  const gelirler = getGelirler();
  const giderler = getGiderler();
  const butceler = getButceler();
  const allDates = [
    ...gelirler.map(g => g.tarih),
    ...giderler.map(g => g.tarih),
    ...butceler.map(b => b.yil + '-01')
  ].filter(Boolean);

  const yillar = [...new Set(allDates.map(d => d.slice(0,4)))].sort().reverse();
  const curYear = new Date().getFullYear().toString();
  if (!yillar.includes(curYear)) yillar.unshift(curYear);

  ['dashYil', 'butceYil'].forEach(id => {
    const sel = document.getElementById(id);
    if (!sel) return;
    const cur = sel.value || curYear;
    sel.innerHTML = yillar.map(y => `<option value="${y}" ${y===cur?'selected':''}>${y}</option>`).join('');
  });

  // Bütçe ay dropdown - mevcut ay
  const butceAySel = document.getElementById('butceAy');
  if (butceAySel) butceAySel.value = new Date().getMonth() + 1;
}

// ─── Ana Dashboard Render ───
// ── KPI Detay Popup — tıklanınca kaynak ve hesaplama gösterir ──
window.showKpiDetay = function(title, rows) {
  // rows: [{label, value, sub}]
  let old = document.getElementById('kpiDetayPopup');
  if (old) old.remove();
  const div = document.createElement('div');
  div.id = 'kpiDetayPopup';
  div.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:999;display:flex;align-items:center;justify-content:center;padding:20px';
  div.onclick = function(e){ if(e.target===div) div.remove(); };
  const fmtP = n => (Number(n)||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});
  div.innerHTML = `<div style="background:#fff;border-radius:14px;max-width:600px;width:100%;max-height:80vh;overflow:auto;box-shadow:0 10px 30px rgba(0,0,0,.25)">
    <div style="padding:14px 18px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center">
      <div style="font-weight:900;font-size:15px;color:#1e293b">${title}</div>
      <button onclick="this.closest('#kpiDetayPopup').remove()" style="border:none;background:#f1f5f9;width:30px;height:30px;border-radius:8px;cursor:pointer;font-size:16px">✕</button>
    </div>
    <div style="padding:14px 18px">
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        ${rows.map(r => `<tr style="border-bottom:1px solid #f1f5f9">
          <td style="padding:8px 4px;color:#64748b;font-weight:600">${r.label}</td>
          <td style="padding:8px 4px;text-align:right;font-weight:800;color:${r.color||'#1e293b'}">${typeof r.value==='number'?fmtP(r.value)+' ₺':r.value}</td>
          ${r.sub?'<td style="padding:8px 4px;font-size:11px;color:#94a3b8">'+r.sub+'</td>':''}
        </tr>`).join('')}
      </table>
    </div>
  </div>`;
  document.body.appendChild(div);
};

window.dashKpiDetay = function(idx) {
  const yil = document.getElementById('dashYil')?.value || new Date().getFullYear().toString();
  const ayNo = parseInt(document.getElementById('dashAy')?.value || '0');
  const donem = ayNo > 0 ? (['','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'][ayNo]+' '+yil) : (yil+' Tüm Yıl');
  const tarihF = g => { if(!g.tarih) return false; if(!g.tarih.startsWith(yil)) return false; if(ayNo>0 && parseInt(g.tarih.slice(5,7))!==ayNo) return false; return true; };

  const gelirler = (typeof getGelirler==='function') ? getGelirler().filter(tarihF) : [];
  const giderler = (typeof getGiderler==='function') ? getGiderler().filter(tarihF) : [];
  const ugiderler = (typeof getUretimGider==='function') ? getUretimGider().filter(tarihF) : [];
  const topGelir = gelirler.reduce((s,g) => s+(parseFloat(g.tutar)||0), 0);
  const opGider = giderler.reduce((s,g) => s+(parseFloat(g.tutar)||0), 0);
  const uretimTop = ugiderler.reduce((s,u) => s+(parseFloat(u.toplam||u.tutar)||0), 0);
  const topGider = opGider + uretimTop;

  if (idx === 0) { // Toplam Gelir
    const byMusteri = {};
    gelirler.forEach(g => { const m=g.musteri||'Belirtilmemiş'; byMusteri[m]=(byMusteri[m]||0)+(parseFloat(g.tutar)||0); });
    const rows = [{label:'Dönem', value:donem, sub:'Filtre'}, {label:'Toplam Gelir Kaydı', value:gelirler.length+' adet', sub:'getGelirler()'}];
    Object.entries(byMusteri).sort((a,b)=>b[1]-a[1]).forEach(([m,v]) => rows.push({label:m, value:v, color:'#166534'}));
    rows.push({label:'TOPLAM', value:topGelir, color:'#1e40af', sub:'Tüm müşteri satışları toplamı'});
    showKpiDetay('Toplam Gelir Detay', rows);
  } else if (idx === 1) { // Toplam Gider
    const byKat = {};
    giderler.forEach(g => { const k=g.kategori||g.kat||'Diğer'; byKat[k]=(byKat[k]||0)+(parseFloat(g.tutar)||0); });
    const rows = [{label:'Dönem', value:donem, sub:'Filtre'}, {label:'İşletme Giderleri', value:opGider, color:'#dc2626', sub:giderler.length+' kayıt (getGiderler)'}];
    Object.entries(byKat).sort((a,b)=>b[1]-a[1]).forEach(([k,v]) => rows.push({label:'  '+k, value:v, color:'#b91c1c'}));
    rows.push({label:'Üretim Giderleri', value:uretimTop, color:'#dc2626', sub:ugiderler.length+' kayıt (getUretimGider)'});
    rows.push({label:'TOPLAM GİDER', value:topGider, color:'#dc2626', sub:'İşletme + Üretim'});
    showKpiDetay('Toplam Gider Detay', rows);
  } else if (idx === 2) { // Net Kâr
    const netKar = topGelir - topGider;
    showKpiDetay('Net Kâr Detay', [
      {label:'Dönem', value:donem, sub:'Filtre'},
      {label:'Toplam Gelir', value:topGelir, color:'#166534', sub:'getGelirler()'},
      {label:'İşletme Giderleri', value:opGider, color:'#dc2626', sub:'getGiderler()'},
      {label:'Üretim Giderleri', value:uretimTop, color:'#dc2626', sub:'getUretimGider()'},
      {label:'Toplam Gider', value:topGider, color:'#dc2626', sub:'İşletme + Üretim'},
      {label:'NET KÂR', value:netKar, color:netKar>=0?'#166534':'#dc2626', sub:'Gelir − Gider'}
    ]);
  } else if (idx === 3) { // Kâr Marjı
    const netKar = topGelir - topGider;
    const marj = topGelir>0?(netKar/topGelir*100):0;
    showKpiDetay('Kâr Marjı Detay', [
      {label:'Dönem', value:donem, sub:'Filtre'},
      {label:'Net Kâr', value:topGelir-topGider, color:netKar>=0?'#166534':'#dc2626'},
      {label:'Toplam Gelir', value:topGelir, color:'#1e40af'},
      {label:'Formül', value:'Net Kâr ÷ Toplam Gelir × 100', sub:''},
      {label:'KÂR MARJI', value:marj.toFixed(1)+'%', color:'#1e40af'}
    ]);
  } else if (idx === 4) { // Porsiyon Başı
    const kisi = gelirler.reduce((s,g)=>s+(parseInt(g.kisi)||0), 0);
    showKpiDetay('Porsiyon Başı Gelir Detay', [
      {label:'Dönem', value:donem, sub:'Filtre'},
      {label:'Toplam Porsiyon', value:kisi.toLocaleString('tr-TR')+' kişi', sub:'Gelir kayıtlarındaki kişi sayıları'},
      {label:'Toplam Gelir', value:topGelir, color:'#166534'},
      {label:'Formül', value:'Toplam Gelir ÷ Toplam Porsiyon', sub:''},
      {label:'PORSİYON BAŞI', value:kisi>0?(topGelir/kisi):0, color:'#7c3aed'}
    ]);
  } else if (idx === 5) { // Maliyet Oranı
    showKpiDetay('Maliyet Oranı Detay', [
      {label:'Dönem', value:donem, sub:'Filtre'},
      {label:'Toplam Gider', value:topGider, color:'#dc2626'},
      {label:'Toplam Gelir', value:topGelir, color:'#166534'},
      {label:'Formül', value:'Toplam Gider ÷ Toplam Gelir × 100', sub:''},
      {label:'MALİYET ORANI', value:topGelir>0?(topGider/topGelir*100).toFixed(1)+'%':'—', color:'#1e40af'}
    ]);
  }
};

window.renderFinDashboard = function() {
  initYilDropdowns();

  const yil  = document.getElementById('dashYil')?.value  || new Date().getFullYear().toString();
  const ayNo = parseInt(document.getElementById('dashAy')?.value || '0');

  const tarihF = g => {
    if (!g.tarih) return false;
    if (!g.tarih.startsWith(yil)) return false;
    if (ayNo > 0 && parseInt(g.tarih.slice(5,7)) !== ayNo) return false;
    return true;
  };
  const gelirler = getGelirler().filter(tarihF);
  const giderler = getGiderler().filter(tarihF);
  // Üretim giderleri de dahil (üst KPI ile tutarlı olması için)
  const ugiderler = (typeof getUretimGider === 'function') ? getUretimGider().filter(tarihF) : [];
  const uretimTop = ugiderler.reduce((s,u) => s + (parseFloat(u.toplam||u.tutar)||0), 0);

  const topGelir = gelirler.reduce((s,g) => s + (parseFloat(g.tutar)||0), 0);
  const opGider  = giderler.reduce((s,g) => s + (parseFloat(g.tutar)||0), 0);
  const topGider = opGider + uretimTop;
  const netKar   = topGelir - topGider;
  const marj     = topGelir > 0 ? (netKar / topGelir * 100) : 0;

  // Geçen dönem karşılaştırması
  const prevYil = (parseInt(yil) - 1).toString();
  const prevTarihF = g => g.tarih && g.tarih.startsWith(prevYil) && (ayNo === 0 || parseInt(g.tarih.slice(5,7)) === ayNo);
  const prevGelirler = getGelirler().filter(prevTarihF);
  const prevGiderler = getGiderler().filter(prevTarihF);
  const prevUretim = (typeof getUretimGider === 'function') ? getUretimGider().filter(prevTarihF).reduce((s,u) => s + (parseFloat(u.toplam||u.tutar)||0), 0) : 0;
  const prevGelir = prevGelirler.reduce((s,g) => s + (parseFloat(g.tutar)||0), 0);
  const prevGider = prevGiderler.reduce((s,g) => s + (parseFloat(g.tutar)||0), 0) + prevUretim;
  const prevNet   = prevGelir - prevGider;

  // ── KPI Satırı ──
  const kpiDiv = document.getElementById('dashKpiRow');
  if (kpiDiv) {
    const kpis = [
      {
        icon: '💰', label: 'Toplam Gelir', val: fmtPara(topGelir),
        sub: prevGelir > 0 ? `Geçen yıl: ${fmtPara(prevGelir)}` : 'Geçen yıl: —',
        delta: prevGelir > 0 ? ((topGelir - prevGelir) / prevGelir * 100) : null,
        bg: 'linear-gradient(135deg,#1a56db,#1e40af)', color: '#fff'
      },
      {
        icon: '📤', label: 'Toplam Gider', val: fmtPara(topGider),
        sub: prevGider > 0 ? `Geçen yıl: ${fmtPara(prevGider)}` : 'Geçen yıl: —',
        delta: prevGider > 0 ? ((topGider - prevGider) / prevGider * 100) : null,
        bg: 'linear-gradient(135deg,#dc2626,#b91c1c)', color: '#fff', invertDelta: true
      },
      {
        icon: netKar >= 0 ? '📈' : '📉', label: 'Net Kâr',
        val: fmtPara(netKar),
        sub: prevNet !== 0 ? `Geçen yıl: ${fmtPara(prevNet)}` : 'Geçen yıl: —',
        delta: prevNet !== 0 ? ((netKar - prevNet) / Math.abs(prevNet) * 100) : null,
        bg: netKar >= 0 ? 'linear-gradient(135deg,#16a34a,#15803d)' : 'linear-gradient(135deg,#dc2626,#b91c1c)',
        color: '#fff'
      },
      {
        icon: '📊', label: 'Kâr Marjı', val: marj.toFixed(1) + '%',
        sub: topGelir > 0 ? `${gelirler.length} gelir kaydı` : 'Veri yok',
        delta: null,
        bg: marj >= 20 ? 'linear-gradient(135deg,#059669,#047857)' :
            marj >= 10 ? 'linear-gradient(135deg,#d97706,#b45309)' :
            'linear-gradient(135deg,#6b7280,#4b5563)',
        color: '#fff'
      },
      {
        icon: '🍽️', label: 'Porsiyon Başı Gelir',
        val: (() => {
          const kisi = gelirler.reduce((s,g) => s + (parseInt(g.kisi)||0), 0);
          return kisi > 0 ? fmtPara(topGelir / kisi) : '—';
        })(),
        sub: (() => {
          const kisi = gelirler.reduce((s,g) => s + (parseInt(g.kisi)||0), 0);
          return kisi > 0 ? `${kisi.toLocaleString('tr-TR')} toplam porsiyon` : 'Porsiyon verisi yok';
        })(),
        delta: null,
        bg: 'linear-gradient(135deg,#7c3aed,#6d28d9)', color: '#fff'
      },
      {
        icon: '💧', label: 'Maliyet Oranı',
        val: topGelir > 0 ? (topGider / topGelir * 100).toFixed(1) + '%' : '—',
        sub: topGelir > 0 ? (topGider / topGelir * 100 < 70 ? '✅ Sağlıklı' : '⚠️ Yüksek') : 'Veri yok',
        delta: null,
        bg: topGelir > 0 && (topGider / topGelir * 100) < 70
            ? 'linear-gradient(135deg,#0891b2,#0e7490)'
            : 'linear-gradient(135deg,#f59e0b,#d97706)',
        color: '#fff'
      }
    ];
    kpiDiv.innerHTML = kpis.map((k,idx) => `
      <div style="background:${k.bg};color:${k.color};padding:14px 16px;border-radius:12px;min-width:0;cursor:pointer;transition:transform .15s,box-shadow .15s"
           onmouseenter="this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 16px rgba(0,0,0,.2)'"
           onmouseleave="this.style.transform='';this.style.boxShadow=''"
           onclick="dashKpiDetay(${idx})">
        <div style="font-size:20px;margin-bottom:4px">${k.icon}</div>
        <div style="font-size:18px;font-weight:900;line-height:1.2">${k.val}</div>
        <div style="font-size:11px;opacity:.9;margin-top:2px">${k.label}</div>
        <div style="font-size:11px;opacity:.75;margin-top:2px">${k.sub}</div>
        ${k.delta !== null ? `<div style="font-size:11px;margin-top:3px;font-weight:700;color:${
          (k.invertDelta ? k.delta < 0 : k.delta >= 0) ? 'rgba(255,255,255,.95)' : 'rgba(255,200,200,.95)'}">
          ${k.delta >= 0 ? '▲' : '▼'} ${Math.abs(k.delta).toFixed(1)}% YoY</div>` : ''}
        <div style="font-size:9px;opacity:.6;margin-top:4px">Detay için tıklayın</div>
      </div>`).join('');
  }

  // ── Aylık Veriyi Hesapla ──
  const ayIsim = ['','Oca','Şub','Mar','Nis','May','Haz','Tem','Ağu','Eyl','Eki','Kas','Ara'];
  const ayData = Array.from({length: 12}, (_, i) => {
    const ay = i + 1;
    const ayStr = yil + '-' + String(ay).padStart(2,'0');
    const g = getGelirler().filter(x => x.tarih && x.tarih.startsWith(ayStr)).reduce((s,x) => s + (parseFloat(x.tutar)||0), 0);
    const gi = getGiderler().filter(x => x.tarih && x.tarih.startsWith(ayStr)).reduce((s,x) => s + (parseFloat(x.tutar)||0), 0);
    const ur = (typeof getUretimGider==='function') ? getUretimGider().filter(x => x.tarih && x.tarih.startsWith(ayStr)).reduce((s,x) => s + (parseFloat(x.toplam||x.tutar)||0), 0) : 0;
    return { ay, isim: ayIsim[ay], gelir: g, gider: gi + ur, net: g - (gi + ur) };
  });

  // ── Bar Grafiği (Aylık Gelir/Gider) ──
  renderBarChart('dashBarChart', ayData);

  // ── Pasta Grafiği (Gider Dağılımı) ──
  renderPieChart('dashPieChart', giderler);

  // ── Waterfall (Nakit Akışı) ──
  renderWaterfallChart('dashWaterfallChart', ayData);

  // ── Trend Çizgi Grafiği (Karlılık %) ──
  renderLineChart('dashLineChart', ayData);

  // ── Müşteri Bazlı Bar ──
  renderMusteriBar('dashMusteriBar', gelirler);

  // ── Karlılık Analiz Tablosu ──
  renderKarlilikTablo('dashKarlilikTablo', gelirler, giderler);
};

// ─────────────────────────────────────────
// SVG Bar Grafiği: Aylık Gelir/Gider/Net
// ─────────────────────────────────────────
function renderBarChart(containerId, ayData) {
  const div = document.getElementById(containerId);
  if (!div) return;

  const W = 640, H = 220, padL = 60, padR = 10, padT = 10, padB = 40;
  const chartW = W - padL - padR;
  const chartH = H - padT - padB;
  const n = 12;
  const barGroupW = chartW / n;
  const barW = barGroupW * 0.28;

  const maxVal = Math.max(...ayData.map(d => Math.max(d.gelir, d.gider, 1)));
  const scale = v => chartH - (v / maxVal) * chartH;

  // Y ekseni tick'leri
  const ticks = 5;
  const tickLines = Array.from({length: ticks+1}, (_,i) => {
    const v = (maxVal / ticks) * i;
    const y = padT + scale(v);
    const label = v >= 1e6 ? (v/1e6).toFixed(1)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : v.toFixed(0);
    return `
      <line x1="${padL}" y1="${y}" x2="${W-padR}" y2="${y}" stroke="#f3f4f6" stroke-width="1"/>
      <text x="${padL-5}" y="${y+4}" text-anchor="end" font-size="10" fill="#9ca3af">${label}</text>`;
  }).join('');

  const bars = ayData.map((d, i) => {
    const x = padL + i * barGroupW;
    const gH = (d.gelir / maxVal) * chartH;
    const giH = (d.gider / maxVal) * chartH;
    const netH = Math.abs(d.net / maxVal) * chartH;
    const netY = d.net >= 0 ? padT + scale(d.net) : padT + chartH;

    return `
      <!-- ${d.isim} -->
      <rect x="${x + barGroupW*0.05}" y="${padT + scale(d.gelir)}" width="${barW}" height="${gH}"
        fill="#1a56db" opacity="0.85" rx="2"
        style="cursor:pointer">
        <title>${d.isim}: Gelir ${Math.round(d.gelir).toLocaleString('tr-TR')} ₺</title>
      </rect>
      <rect x="${x + barGroupW*0.05 + barW + 2}" y="${padT + scale(d.gider)}" width="${barW}" height="${giH}"
        fill="#dc2626" opacity="0.85" rx="2">
        <title>${d.isim}: Gider ${Math.round(d.gider).toLocaleString('tr-TR')} ₺</title>
      </rect>
      <rect x="${x + barGroupW*0.05 + barW*2 + 4}" y="${netY}" width="${barW}" height="${netH}"
        fill="${d.net >= 0 ? '#16a34a' : '#f87171'}" opacity="0.9" rx="2">
        <title>${d.isim}: Net ${Math.round(d.net).toLocaleString('tr-TR')} ₺</title>
      </rect>
      <text x="${x + barGroupW*0.5}" y="${padT + chartH + 14}" text-anchor="middle" font-size="10" fill="#6b7280">${d.isim}</text>`;
  }).join('');

  div.innerHTML = `<svg viewBox="0 0 ${W} ${H}" style="width:100%;max-width:${W}px;height:auto;display:block">
    ${tickLines}
    <line x1="${padL}" y1="${padT}" x2="${padL}" y2="${padT+chartH}" stroke="#d1d5db" stroke-width="1"/>
    <line x1="${padL}" y1="${padT+chartH}" x2="${W-padR}" y2="${padT+chartH}" stroke="#d1d5db" stroke-width="1"/>
    ${bars}
  </svg>`;
}

// ─────────────────────────────────────────
// SVG Pie/Donut Grafiği: Gider Kategorileri
// ─────────────────────────────────────────
function renderPieChart(containerId, giderler) {
  const div = document.getElementById(containerId);
  if (!div) return;

  const katMap = {
    'uretim-kirmizi': '🥩 Kırmızı Et', 'uretim-tavuk': '🍗 Tavuk', 'uretim-kiyma': '🫙 Kıyma',
    'uretim-sebze': '🥦 Sebze', 'uretim-bakliyat': '🫘 Bakliyat', 'uretim-ayran': '🥛 Süt Ürünleri',
    'uretim-ekmek': '🍞 Ekmek', 'uretim-diger-gida': '🛒 Diğer Gıda',
    'personel': '👷 Personel', 'enerji': '⚡ Enerji', 'diger': '📌 Diğer'
  };
  const renkler = ['#1a56db','#dc2626','#16a34a','#d97706','#7c3aed','#0891b2','#db2777','#059669','#6b7280','#92400e','#b45309'];

  // Kategori toplamları
  const katToplam = {};
  giderler.forEach(g => {
    const kat = g.kategori || 'diger';
    // Üretim alt kategorilerini grupla
    const anaKat = kat.startsWith('uretim-') ? kat : kat;
    katToplam[anaKat] = (katToplam[anaKat] || 0) + (parseFloat(g.tutar)||0);
  });

  const entries = Object.entries(katToplam).filter(([,v]) => v > 0).sort((a,b) => b[1]-a[1]);
  const topGider = entries.reduce((s,[,v]) => s+v, 0);

  if (!entries.length || topGider === 0) {
    div.innerHTML = '<div style="color:#9ca3af;font-size:13px;text-align:center;padding:20px">Gider verisi yok</div>';
    return;
  }

  // Donut SVG
  const cx = 80, cy = 80, R = 65, r = 38;
  let angle = -Math.PI/2;
  const slices = entries.slice(0,8).map(([kat, val], i) => {
    const pct = val / topGider;
    const a1 = angle, a2 = angle + pct * 2 * Math.PI;
    const x1 = cx + R * Math.cos(a1), y1 = cy + R * Math.sin(a1);
    const x2 = cx + R * Math.cos(a2), y2 = cy + R * Math.sin(a2);
    const ix1 = cx + r * Math.cos(a1), iy1 = cy + r * Math.sin(a1);
    const ix2 = cx + r * Math.cos(a2), iy2 = cy + r * Math.sin(a2);
    const large = pct > 0.5 ? 1 : 0;
    const path = `M ${x1} ${y1} A ${R} ${R} 0 ${large} 1 ${x2} ${y2} L ${ix2} ${iy2} A ${r} ${r} 0 ${large} 0 ${ix1} ${iy1} Z`;
    angle = a2;
    return `<path d="${path}" fill="${renkler[i % renkler.length]}" opacity="0.9" stroke="#fff" stroke-width="1.5">
      <title>${katMap[kat]||kat}: ${fmtPara(val)} (${(pct*100).toFixed(1)}%)</title>
    </path>`;
  });

  const svg = `<svg viewBox="0 0 160 160" style="width:130px;height:130px;display:block">
    ${slices.join('')}
    <text x="${cx}" y="${cy-6}" text-anchor="middle" font-size="9" fill="#6b7280">Toplam</text>
    <text x="${cx}" y="${cy+6}" text-anchor="middle" font-size="8" fill="#374151" font-weight="700">
      ${topGider >= 1e6 ? (topGider/1e6).toFixed(1)+'M' : topGider >= 1e3 ? (topGider/1e3).toFixed(0)+'K' : Math.round(topGider)} ₺
    </text>
  </svg>`;

  const legend = entries.slice(0,8).map(([kat, val], i) => `
    <div style="display:flex;align-items:center;gap:6px;font-size:11px;margin-bottom:3px">
      <span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:${renkler[i % renkler.length]};flex-shrink:0"></span>
      <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${katMap[kat]||kat}</span>
      <span style="font-weight:700;color:#374151">${(val/topGider*100).toFixed(0)}%</span>
    </div>`).join('');

  div.innerHTML = svg + `<div style="width:100%;margin-top:8px">${legend}</div>`;
}

// ─────────────────────────────────────────
// SVG Waterfall: Aylık Net Nakit Akışı
// ─────────────────────────────────────────
function renderWaterfallChart(containerId, ayData) {
  const div = document.getElementById(containerId);
  if (!div) return;

  const W = 420, H = 180, padL = 55, padR = 10, padT = 10, padB = 35;
  const chartW = W - padL - padR;
  const chartH = H - padT - padB;
  const n = 12;
  const barGroupW = chartW / n;
  const barW = barGroupW * 0.65;

  const nets = ayData.map(d => d.net);
  const maxAbs = Math.max(...nets.map(Math.abs), 1);
  const midY = padT + chartH / 2;

  // Sıfır çizgisi
  const zeroLine = `<line x1="${padL}" y1="${midY}" x2="${W-padR}" y2="${midY}" stroke="#9ca3af" stroke-width="1" stroke-dasharray="3,3"/>`;

  // Grid
  const gridLines = [-1, -0.5, 0, 0.5, 1].map(f => {
    const y = midY - f * chartH / 2;
    const v = f * maxAbs;
    const label = v >= 1e6 ? (v/1e6).toFixed(1)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : v.toFixed(0);
    return `<line x1="${padL}" y1="${y}" x2="${W-padR}" y2="${y}" stroke="#f3f4f6" stroke-width="1"/>
            <text x="${padL-4}" y="${y+3}" text-anchor="end" font-size="9" fill="#9ca3af">${label}</text>`;
  }).join('');

  // Kümülatif çizgi
  let cum = 0;
  const cumPoints = ayData.map((d, i) => {
    cum += d.net;
    const x = padL + (i + 0.5) * barGroupW;
    const y = midY - (cum / maxAbs) * (chartH / 2);
    return { x, y, cum };
  });
  const cumPath = cumPoints.map((p, i) => (i === 0 ? `M ${p.x} ${p.y}` : `L ${p.x} ${p.y}`)).join(' ');
  const cumLine = `<path d="${cumPath}" fill="none" stroke="#7c3aed" stroke-width="2" stroke-dasharray="4,2"/>`;
  const cumDots = cumPoints.map(p =>
    `<circle cx="${p.x}" cy="${p.y}" r="3" fill="#7c3aed" stroke="#fff" stroke-width="1">
      <title>Kümülatif: ${Math.round(p.cum).toLocaleString('tr-TR')} ₺</title>
    </circle>`).join('');

  const bars = ayData.map((d, i) => {
    const x = padL + i * barGroupW + (barGroupW - barW) / 2;
    const barH = Math.abs(d.net / maxAbs) * (chartH / 2);
    const y = d.net >= 0 ? midY - barH : midY;
    const fill = d.net >= 0 ? '#16a34a' : '#dc2626';
    const ayIsim = ['','Oca','Şub','Mar','Nis','May','Haz','Tem','Ağu','Eyl','Eki','Kas','Ara'];
    return `
      <rect x="${x}" y="${y}" width="${barW}" height="${Math.max(barH, 1)}" fill="${fill}" opacity="0.85" rx="2">
        <title>${ayIsim[d.ay]}: ${Math.round(d.net).toLocaleString('tr-TR')} ₺</title>
      </rect>
      <text x="${x + barW/2}" y="${padT+chartH+14}" text-anchor="middle" font-size="9" fill="#6b7280">${ayIsim[d.ay]}</text>`;
  }).join('');

  div.innerHTML = `<svg viewBox="0 0 ${W} ${H}" style="width:100%;max-width:${W}px;height:auto;display:block">
    ${gridLines}
    ${zeroLine}
    <line x1="${padL}" y1="${padT}" x2="${padL}" y2="${padT+chartH}" stroke="#d1d5db" stroke-width="1"/>
    ${bars}
    ${cumLine}
    ${cumDots}
    <text x="${W-padR-2}" y="${midY - chartH/2 * 0.8}" text-anchor="end" font-size="9" fill="#7c3aed">● Kümülatif</text>
  </svg>`;
}

// ─────────────────────────────────────────
// SVG Çizgi Grafiği: Aylık Karlılık %
// ─────────────────────────────────────────
function renderLineChart(containerId, ayData) {
  const div = document.getElementById(containerId);
  if (!div) return;

  const W = 420, H = 180, padL = 45, padR = 10, padT = 15, padB = 35;
  const chartW = W - padL - padR;
  const chartH = H - padT - padB;

  const marjlar = ayData.map(d => d.gelir > 0 ? (d.net / d.gelir * 100) : null);
  const validMarj = marjlar.filter(m => m !== null);
  if (!validMarj.length) {
    div.innerHTML = '<div style="color:#9ca3af;font-size:13px;padding:20px;text-align:center">Yeterli veri yok</div>';
    return;
  }

  const minM = Math.min(...validMarj, -5);
  const maxM = Math.max(...validMarj, 5);
  const rangeM = maxM - minM || 10;
  const scaleY = v => padT + chartH - ((v - minM) / rangeM) * chartH;
  const scaleX = i => padL + (i / 11) * chartW;

  // Grid yatay
  const ticks = [-20, -10, 0, 10, 20, 30, 40].filter(t => t >= minM - 5 && t <= maxM + 5);
  const gridLines = ticks.map(t => {
    const y = scaleY(t);
    return `<line x1="${padL}" y1="${y}" x2="${W-padR}" y2="${y}" stroke="${t===0?'#9ca3af':'#f3f4f6'}" stroke-width="${t===0?1.5:1}" stroke-dasharray="${t!==0?'3,3':''}"/>
            <text x="${padL-4}" y="${y+3}" text-anchor="end" font-size="9" fill="${t===0?'#374151':'#9ca3af'}">${t}%</text>`;
  }).join('');

  // Çizgi path
  const points = marjlar.map((m, i) => m !== null ? `${scaleX(i)},${scaleY(m)}` : null).filter(Boolean);
  // Kopuklu path (null değerlerde kesil)
  let pathD = '';
  marjlar.forEach((m, i) => {
    if (m !== null) {
      pathD += (pathD === '' || marjlar[i-1] === null ? 'M' : 'L') + ` ${scaleX(i)} ${scaleY(m)} `;
    }
  });

  // Alan dolgusu (0 çizgisine kadar)
  let areaD = '';
  let inPath = false;
  marjlar.forEach((m, i) => {
    if (m !== null) {
      if (!inPath) { areaD += `M ${scaleX(i)} ${scaleY(0)} L ${scaleX(i)} ${scaleY(m)} `; inPath = true; }
      else areaD += `L ${scaleX(i)} ${scaleY(m)} `;
    } else if (inPath) {
      areaD += `L ${scaleX(i-1)} ${scaleY(0)} Z `;
      inPath = false;
    }
  });
  if (inPath) {
    const lastValid = marjlar.reduce((last, m, i) => m !== null ? i : last, 0);
    areaD += `L ${scaleX(lastValid)} ${scaleY(0)} Z`;
  }

  const dots = marjlar.map((m, i) => {
    if (m === null) return '';
    const ayIsim = ['Oca','Şub','Mar','Nis','May','Haz','Tem','Ağu','Eyl','Eki','Kas','Ara'];
    return `<circle cx="${scaleX(i)}" cy="${scaleY(m)}" r="4"
      fill="${m >= 20 ? '#16a34a' : m >= 0 ? '#1a56db' : '#dc2626'}"
      stroke="#fff" stroke-width="1.5">
      <title>${ayIsim[i]}: %${m.toFixed(1)}</title>
    </circle>`;
  }).join('');

  // X ekseni etiketleri
  const xLabels = ['Oca','Şub','Mar','Nis','May','Haz','Tem','Ağu','Eyl','Eki','Kas','Ara'].map((lbl,i) =>
    `<text x="${scaleX(i)}" y="${padT+chartH+14}" text-anchor="middle" font-size="9" fill="#9ca3af">${lbl}</text>`
  ).join('');

  div.innerHTML = `<svg viewBox="0 0 ${W} ${H}" style="width:100%;max-width:${W}px;height:auto;display:block">
    ${gridLines}
    <line x1="${padL}" y1="${padT}" x2="${padL}" y2="${padT+chartH}" stroke="#d1d5db" stroke-width="1"/>
    <path d="${areaD}" fill="#1a56db" opacity="0.08"/>
    <path d="${pathD}" fill="none" stroke="#1a56db" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
    ${dots}
    ${xLabels}
  </svg>`;
}

// ─────────────────────────────────────────
// Müşteri Bazlı Yatay Bar
// ─────────────────────────────────────────
function renderMusteriBar(containerId, gelirler) {
  const div = document.getElementById(containerId);
  if (!div) return;

  const musteriToplam = {};
  gelirler.forEach(g => {
    const m = g.musteri || 'Diğer';
    musteriToplam[m] = (musteriToplam[m] || 0) + (parseFloat(g.tutar)||0);
  });

  const entries = Object.entries(musteriToplam).sort((a,b) => b[1]-a[1]).slice(0,10);
  const topGelir = entries.reduce((s,[,v]) => s+v, 0);

  if (!entries.length) {
    div.innerHTML = '<div style="color:#9ca3af;font-size:13px;padding:10px">Gelir verisi yok</div>';
    return;
  }

  const maxVal = entries[0][1];
  const renkler = ['#1a56db','#1e40af','#2563eb','#3b82f6','#60a5fa','#93c5fd','#bfdbfe','#7c3aed','#8b5cf6','#a78bfa'];

  const bars = entries.map(([musteri, val], i) => {
    const pct = (val / maxVal * 100).toFixed(1);
    const sharePct = (val / topGelir * 100).toFixed(1);
    return `
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
        <div style="width:120px;font-size:12px;font-weight:600;color:#374151;text-align:right;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${musteri}">${musteri}</div>
        <div style="flex:1;background:#f3f4f6;border-radius:4px;height:22px;overflow:hidden">
          <div style="height:100%;width:${pct}%;background:${renkler[i]};border-radius:4px;display:flex;align-items:center;padding-left:6px;transition:width .3s">
            <span style="font-size:10px;color:#fff;font-weight:700;white-space:nowrap">${fmtPara(val)}</span>
          </div>
        </div>
        <div style="width:40px;font-size:11px;color:#6b7280;text-align:right">%${sharePct}</div>
      </div>`;
  }).join('');

  div.innerHTML = `<div style="max-height:280px;overflow-y:auto">${bars}</div>`;
}

// ─────────────────────────────────────────
// Karlılık Analiz Tablosu
// ─────────────────────────────────────────
function renderKarlilikTablo(containerId, gelirler, giderler) {
  const div = document.getElementById(containerId);
  if (!div) return;

  const ayIsim = ['','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];

  // Aylık grupla
  const ayMap = {};
  gelirler.forEach(g => {
    if (!g.tarih) return;
    const key = g.tarih.slice(0,7);
    if (!ayMap[key]) ayMap[key] = { gelir:0, gider:0, kisi:0 };
    ayMap[key].gelir += parseFloat(g.tutar)||0;
    ayMap[key].kisi  += parseInt(g.kisi)||0;
  });
  giderler.forEach(g => {
    if (!g.tarih) return;
    const key = g.tarih.slice(0,7);
    if (!ayMap[key]) ayMap[key] = { gelir:0, gider:0, kisi:0 };
    ayMap[key].gider += parseFloat(g.tutar)||0;
  });

  const keys = Object.keys(ayMap).sort().reverse().slice(0,12);
  if (!keys.length) {
    div.innerHTML = '<div style="color:#9ca3af;padding:20px;text-align:center">Veri yok</div>';
    return;
  }

  const rows = keys.map(key => {
    const d = ayMap[key];
    const net = d.gelir - d.gider;
    const marj = d.gelir > 0 ? (net / d.gelir * 100) : 0;
    const malOran = d.gelir > 0 ? (d.gider / d.gelir * 100) : 0;
    const pp = d.kisi > 0 ? d.gelir / d.kisi : 0;
    const [yilStr, ayStr] = key.split('-');
    const ayAdi = ayIsim[parseInt(ayStr)] || ayStr;

    const netColor = net >= 0 ? '#16a34a' : '#dc2626';
    const marjBg = marj >= 25 ? '#d1fae5' : marj >= 15 ? '#fef3c7' : marj >= 0 ? '#fde8d8' : '#fee2e2';
    const malBg  = malOran <= 65 ? '#d1fae5' : malOran <= 75 ? '#fef3c7' : '#fee2e2';

    return `<tr>
      <td style="font-weight:700">${ayAdi} ${yilStr}</td>
      <td style="text-align:right;color:#1a56db;font-weight:700">${fmtPara(d.gelir)}</td>
      <td style="text-align:right;color:#dc2626">${fmtPara(d.gider)}</td>
      <td style="text-align:right;color:${netColor};font-weight:700">${fmtPara(net)}</td>
      <td style="text-align:right;background:${marjBg};font-weight:700">%${marj.toFixed(1)}</td>
      <td style="text-align:right;background:${malBg}">%${malOran.toFixed(1)}</td>
      <td style="text-align:right;color:#7c3aed">${pp > 0 ? fmtPara(pp) : '—'}</td>
      <td style="text-align:right;color:#6b7280">${d.kisi > 0 ? d.kisi.toLocaleString('tr-TR') : '—'}</td>
    </tr>`;
  }).join('');

  div.innerHTML = `<table class="tbl">
    <thead>
      <tr style="background:#eef3ff">
        <th>Dönem</th>
        <th style="text-align:right">Gelir</th>
        <th style="text-align:right">Gider</th>
        <th style="text-align:right">Net Kâr</th>
        <th style="text-align:right">Kâr %</th>
        <th style="text-align:right">Maliyet %</th>
        <th style="text-align:right">Porsiyon Geliri</th>
        <th style="text-align:right">Porsiyon</th>
      </tr>
    </thead>
    <tbody>${rows}</tbody>
  </table>`;
}

// ─────────────────────────────────────────
// Bütçe Yönetimi
// ─────────────────────────────────────────
window.initButceYillar = function() {
  initYilDropdowns();
  const curAy = new Date().getMonth() + 1;
  const butceAySel = document.getElementById('butceAy');
  if (butceAySel) butceAySel.value = curAy;
  renderButceSapma();
};

window.butceKaydet = function() {
  const yil  = document.getElementById('butceYil')?.value;
  const ay   = parseInt(document.getElementById('butceAy')?.value);
  const gelirHedef = parseFloat(document.getElementById('butceHedefGelir')?.value)||0;
  const giderHedef = parseFloat(document.getElementById('butceHedefGider')?.value)||0;
  const not  = document.getElementById('butceNot')?.value.trim()||'';

  if (!yil || !ay) return alert('Yıl ve ay seçin.');
  if (!gelirHedef && !giderHedef) return alert('En az bir hedef girin.');

  const arr = getButceler().filter(b => !(b.yil === yil && b.ay === ay));
  arr.push({ yil, ay, gelirHedef, giderHedef, not });
  saveButceler(arr);
  alert(`✅ ${yil} ${['','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'][ay]} bütçesi kaydedildi.`);
  document.getElementById('butceHedefGelir').value = '';
  document.getElementById('butceHedefGider').value = '';
  document.getElementById('butceNot').value = '';
  renderButceSapma();
};

function renderButceSapma() {
  const div = document.getElementById('butceSapmaDiv');
  if (!div) return;

  const yil = document.getElementById('butceYil')?.value || new Date().getFullYear().toString();
  const butceler = getButceler().filter(b => b.yil === yil);
  if (!butceler.length) {
    div.innerHTML = '<div style="color:#9ca3af;padding:20px;text-align:center">Bu yıl için bütçe hedefi girilmemiş</div>';
    return;
  }

  const ayIsim = ['','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
  const rows = butceler.sort((a,b) => a.ay - b.ay).map(b => {
    const ayStr = yil + '-' + String(b.ay).padStart(2,'0');
    const gercekGelir = getGelirler().filter(g => g.tarih && g.tarih.startsWith(ayStr)).reduce((s,g) => s+(parseFloat(g.tutar)||0), 0);
    const gercekGider = getGiderler().filter(g => g.tarih && g.tarih.startsWith(ayStr)).reduce((s,g) => s+(parseFloat(g.tutar)||0), 0);

    const gelirSapma  = gercekGelir - b.gelirHedef;
    const giderSapma  = gercekGider - b.giderHedef;
    const gelirPct    = b.gelirHedef > 0 ? (gercekGelir / b.gelirHedef * 100) : null;
    const giderPct    = b.giderHedef > 0 ? (gercekGider / b.giderHedef * 100) : null;

    const progBar = (pct, isCost) => {
      if (pct === null) return '—';
      const w = Math.min(pct, 150);
      const good = isCost ? pct <= 100 : pct >= 100;
      const color = good ? '#16a34a' : pct > (isCost ? 110 : 90) ? '#d97706' : '#dc2626';
      return `<div style="background:#f3f4f6;border-radius:4px;height:16px;width:100px;overflow:hidden;display:inline-block;vertical-align:middle">
        <div style="height:100%;width:${w}%;background:${color};border-radius:4px"></div>
      </div> <span style="font-size:11px;color:${color};font-weight:700">${pct.toFixed(0)}%</span>`;
    };

    return `<tr>
      <td><b>${ayIsim[b.ay]}</b></td>
      <td style="text-align:right">${fmtPara(b.gelirHedef)}</td>
      <td style="text-align:right;color:#1a56db;font-weight:700">${fmtPara(gercekGelir)}</td>
      <td>${progBar(gelirPct, false)}</td>
      <td style="text-align:right;color:${gelirSapma >= 0 ? '#16a34a' : '#dc2626'};font-weight:700">${gelirSapma >= 0 ? '+':''} ${fmtPara(gelirSapma)}</td>
      <td style="text-align:right">${fmtPara(b.giderHedef)}</td>
      <td style="text-align:right;color:#dc2626;font-weight:700">${fmtPara(gercekGider)}</td>
      <td>${progBar(giderPct, true)}</td>
    </tr>`;
  }).join('');

  div.innerHTML = `<table class="tbl" style="font-size:12px">
    <thead>
      <tr style="background:#eef3ff">
        <th>Ay</th>
        <th style="text-align:right">Hedef Gelir</th>
        <th style="text-align:right">Gerçekleşen</th>
        <th>Gerçekleşme</th>
        <th style="text-align:right">Sapma</th>
        <th style="text-align:right">Hedef Gider</th>
        <th style="text-align:right">Gerçekleşen</th>
        <th>Bütçe Kullanımı</th>
      </tr>
    </thead>
    <tbody>${rows}</tbody>
  </table>`;
}

// ─────────────────────────────────────────
// Excel Export (Dashboard)
// ─────────────────────────────────────────
window.finDashboardExcel = function() {
  const yil  = document.getElementById('dashYil')?.value  || new Date().getFullYear().toString();
  const ayIsim = ['','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];

  const rows = ['\uFEFFDönem,Gelir (₺),Gider (₺),Net Kâr (₺),Kâr Marjı %,Maliyet Oranı %'];
  for (let ay = 1; ay <= 12; ay++) {
    const ayStr = yil + '-' + String(ay).padStart(2,'0');
    const gelir = getGelirler().filter(g => g.tarih && g.tarih.startsWith(ayStr)).reduce((s,g) => s+(parseFloat(g.tutar)||0), 0);
    const gider = getGiderler().filter(g => g.tarih && g.tarih.startsWith(ayStr)).reduce((s,g) => s+(parseFloat(g.tutar)||0), 0);
    const net   = gelir - gider;
    const marj  = gelir > 0 ? (net/gelir*100).toFixed(1) : '';
    const malOran = gelir > 0 ? (gider/gelir*100).toFixed(1) : '';
    rows.push(`"${ayIsim[ay]} ${yil}",${gelir.toFixed(2)},${gider.toFixed(2)},${net.toFixed(2)},${marj},${malOran}`);
  }

  const csv  = rows.join('\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url;
  a.download = `UYSA_Finans_Dashboard_${yil}.csv`;
  a.click();
  URL.revokeObjectURL(url);
};

// ─────────────────────────────────────────
// refreshFinStats Override - dashboard da güncelle
// ─────────────────────────────────────────
const _origRefreshFinStats = window.refreshFinStats;
// refreshFinStats'ı hook'la
document.addEventListener('DOMContentLoaded', function() {
  // Finans modülüne geçince dashboard render et
  const finBtn = document.querySelector('[data-module="finans"]');
  if (finBtn) {
    finBtn.addEventListener('click', function() {
      setTimeout(function() {
        initYilDropdowns();
        const dashPane = document.getElementById('finPane-dashboard');
        if (dashPane && dashPane.classList.contains('fin-active')) {
          renderFinDashboard();
        }
      }, 200);
    });
  }
});

// İlk render - DOMContentLoaded sonrası
window.addEventListener('load', function() {
  initYilDropdowns();
  // Eğer aktif tab dashboard ise render et
  setTimeout(function() {
    const dp = document.getElementById('finPane-dashboard');
    if (dp && dp.classList.contains('fin-active')) {
      renderFinDashboard();
    }
  }, 800);
});

})(); // IIFE end
