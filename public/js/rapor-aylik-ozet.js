// ═══════════════════════════════════════════════════════════════
// AYLIK ÖZET RAPOR — Tedarikçi, Müşteri, Personel, Birim Maliyet
// ═══════════════════════════════════════════════════════════════

(function(){
  'use strict';

  var _ls = {
    get: function(k,d){ try{ var v=localStorage.getItem(k); return v?JSON.parse(v):d; }catch(e){ return d; } }
  };
  var fmt = function(n,dec){
    dec = dec!==undefined ? dec : 2;
    return (Number(n)||0).toLocaleString('tr-TR',{minimumFractionDigits:dec,maximumFractionDigits:dec});
  };

  // ── Ay seçici doldur ───────────────────────────────────────
  function _fillAySecici(){
    var sel = document.getElementById('aoRaporAy');
    if(!sel || sel.options.length > 1) return;
    var now = new Date();
    for(var i=0; i<12; i++){
      var d = new Date(now.getFullYear(), now.getMonth()-i, 1);
      var val = d.getFullYear()+'-'+(d.getMonth()<9?'0':'')+(d.getMonth()+1);
      var months = ['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
      var label = months[d.getMonth()] + ' ' + d.getFullYear();
      var opt = document.createElement('option');
      opt.value = val;
      opt.textContent = label;
      sel.appendChild(opt);
    }
  }

  // Sayfa yüklenince
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', _fillAySecici);
  else setTimeout(_fillAySecici, 500);

  // ── Ana rapor oluştur ──────────────────────────────────────
  window.renderAylikOzetRapor = function(){
    var div = document.getElementById('aoRaporDiv');
    if(!div) return;

    var ay = document.getElementById('aoRaporAy')?.value;
    if(!ay){
      var now = new Date();
      ay = now.getFullYear()+'-'+(now.getMonth()<9?'0':'')+(now.getMonth()+1);
    }

    // ── Veri çek ──
    var gelirler = _ls.get('uysa_gelirler',[]).filter(function(g){ return (g.tarih||'').startsWith(ay); });
    var giderler = _ls.get('uysa_giderler',[]).filter(function(g){ return (g.tarih||'').startsWith(ay); });
    var gunluk   = _ls.get('uysa_gunluk_uretim',[]).filter(function(g){ return (g.tarih||'').startsWith(ay); });
    var personeller = _ls.get('uysa_personeller',[]).filter(function(p){ return !p.pasif; });

    // ══════════════════════════════════════════════════════════
    // 1. TOPLAM HARCAMALAR (Tedarikçi/Firma Bazlı)
    // ══════════════════════════════════════════════════════════
    var harcFirma = {};
    giderler.forEach(function(g){
      var firma = (g.tedarikci||g.firma||'Diğer').trim() || 'Diğer';
      harcFirma[firma] = (harcFirma[firma]||0) + (parseFloat(g.tutar)||0);
    });
    var harcRows = Object.keys(harcFirma).map(function(f){ return {firma:f, tutar:harcFirma[f]}; });
    harcRows.sort(function(a,b){ return b.tutar-a.tutar; });
    var genelHarcToplam = harcRows.reduce(function(s,r){ return s+r.tutar; }, 0);

    // ══════════════════════════════════════════════════════════
    // 2. TOPLAM SATIŞLAR (Müşteri Bazlı)
    // ══════════════════════════════════════════════════════════
    var satisMusteri = {};
    var kisiMusteri = {};

    // Önce günlük üretimden müşteri bazlı kişi sayısı
    gunluk.forEach(function(g){
      var m = (g.musteri||'GENEL').trim();
      kisiMusteri[m] = (kisiMusteri[m]||0) + (parseInt(g.kisi)||0);
    });

    // Gelirlerden müşteri bazlı tutar
    gelirler.forEach(function(g){
      var m = (g.musteri||'GENEL').trim();
      satisMusteri[m] = (satisMusteri[m]||0) + (parseFloat(g.tutar)||0);
      // Gelirde kişi bilgisi varsa ve günlükte yoksa ekle
      if((parseInt(g.kisi)||0) > 0 && !kisiMusteri[m]){
        kisiMusteri[m] = (kisiMusteri[m]||0) + (parseInt(g.kisi)||0);
      }
    });

    // Eğer günlükte olmayan müşteri varsa gelirden al
    Object.keys(satisMusteri).forEach(function(m){
      if(!kisiMusteri[m]) kisiMusteri[m] = 0;
    });
    Object.keys(kisiMusteri).forEach(function(m){
      if(!satisMusteri[m]) satisMusteri[m] = 0;
    });

    var allMusteriler = Object.keys(Object.assign({}, satisMusteri, kisiMusteri));
    var satisRows = allMusteriler.map(function(m){
      return {musteri:m, kisi:kisiMusteri[m]||0, tutar:satisMusteri[m]||0};
    });
    satisRows.sort(function(a,b){ return b.tutar-a.tutar; });
    var genelSatisToplam = satisRows.reduce(function(s,r){ return s+r.tutar; }, 0);
    var genelKisiToplam  = satisRows.reduce(function(s,r){ return s+r.kisi; }, 0);

    // ══════════════════════════════════════════════════════════
    // 3. PERSONEL MAAŞLARI
    // ══════════════════════════════════════════════════════════
    var perRows = personeller.map(function(p){
      return {
        isim: (p.ad||'')+' '+(p.soyad||''),
        maas: parseFloat(p.maas)||0,
        sgk: parseFloat(p.sgk)||0,
        biriken: parseFloat(p.birikenHak) || ((parseFloat(p.maas)||0)*1.3/12)
      };
    });
    perRows.sort(function(a,b){ return b.maas-a.maas; });
    var topMaas = perRows.reduce(function(s,r){ return s+r.maas; }, 0);
    var topSgk  = perRows.reduce(function(s,r){ return s+r.sgk; }, 0);
    var sgkDahilToplam = topMaas + topSgk;

    // ══════════════════════════════════════════════════════════
    // 4. ÖZET HESAPLAMA
    // ══════════════════════════════════════════════════════════
    var birimGida    = genelKisiToplam > 0 ? genelHarcToplam / genelKisiToplam : 0;
    var birimPersonel= genelKisiToplam > 0 ? sgkDahilToplam / genelKisiToplam : 0;
    var birimToplam  = birimGida + birimPersonel;
    var karZarar     = genelSatisToplam - genelHarcToplam - sgkDahilToplam;

    // ══════════════════════════════════════════════════════════
    // HTML RENDER
    // ══════════════════════════════════════════════════════════
    var html = '';

    // Ay başlığı
    var months = ['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
    var ayParts = ay.split('-');
    var ayLabel = months[parseInt(ayParts[1])-1] + ' ' + ayParts[0];

    html += '<div style="text-align:center;margin-bottom:16px"><h2 style="margin:0;color:#0f766e">UYSA — '+ayLabel+' Aylık Özet Rapor</h2></div>';

    // ── 4 tablo grid ──
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">';

    // ▸ TABLO 1: Toplam Harcamalar
    html += '<div style="border:1.5px solid #cbd5e1;border-radius:10px;overflow:hidden">';
    html += '<div style="background:#1e293b;color:#fff;padding:8px 12px;font-weight:800;font-size:13px">Toplam Harcamalar (Tedarikçi Bazlı)</div>';
    html += '<table style="width:100%;border-collapse:collapse;font-size:12px">';
    html += '<thead><tr style="background:#f1f5f9"><th style="padding:6px 8px;text-align:left">Firma</th><th style="padding:6px 8px;text-align:right">Toplam Tutar</th><th style="padding:6px 8px;text-align:right">Oran (%)</th></tr></thead><tbody>';
    harcRows.forEach(function(r){
      var pct = genelHarcToplam > 0 ? (r.tutar/genelHarcToplam*100) : 0;
      html += '<tr><td style="padding:5px 8px;border-bottom:1px solid #f1f5f9">'+r.firma+'</td>';
      html += '<td style="padding:5px 8px;text-align:right;border-bottom:1px solid #f1f5f9;font-weight:600">₺'+fmt(r.tutar)+'</td>';
      html += '<td style="padding:5px 8px;text-align:right;border-bottom:1px solid #f1f5f9;color:#64748b">%'+fmt(pct,1)+'</td></tr>';
    });
    html += '</tbody><tfoot><tr style="background:#0f766e;color:#fff;font-weight:800">';
    html += '<td style="padding:8px">GENEL TOPLAM</td><td style="padding:8px;text-align:right">₺'+fmt(genelHarcToplam)+'</td><td style="padding:8px;text-align:right">%100</td>';
    html += '</tr></tfoot></table></div>';

    // ▸ TABLO 2: Toplam Satışlar
    html += '<div style="border:1.5px solid #cbd5e1;border-radius:10px;overflow:hidden">';
    html += '<div style="background:#1e293b;color:#fff;padding:8px 12px;font-weight:800;font-size:13px">Toplam Satışlar (Müşteri Bazlı)</div>';
    html += '<table style="width:100%;border-collapse:collapse;font-size:12px">';
    html += '<thead><tr style="background:#f1f5f9"><th style="padding:6px 8px;text-align:left">Firma</th><th style="padding:6px 8px;text-align:right">Yemek Sayısı</th><th style="padding:6px 8px;text-align:right">Toplam Tutar</th><th style="padding:6px 8px;text-align:right">Oran (%)</th></tr></thead><tbody>';
    satisRows.forEach(function(r){
      var pct = genelSatisToplam > 0 ? (r.tutar/genelSatisToplam*100) : 0;
      html += '<tr><td style="padding:5px 8px;border-bottom:1px solid #f1f5f9">'+r.musteri+'</td>';
      html += '<td style="padding:5px 8px;text-align:right;border-bottom:1px solid #f1f5f9">'+fmt(r.kisi,0)+'</td>';
      html += '<td style="padding:5px 8px;text-align:right;border-bottom:1px solid #f1f5f9;font-weight:600">₺'+fmt(r.tutar)+'</td>';
      html += '<td style="padding:5px 8px;text-align:right;border-bottom:1px solid #f1f5f9;color:#64748b">%'+fmt(pct,1)+'</td></tr>';
    });
    html += '</tbody><tfoot><tr style="background:#0f766e;color:#fff;font-weight:800">';
    html += '<td style="padding:8px">GENEL TOPLAM</td><td style="padding:8px;text-align:right">'+fmt(genelKisiToplam,0)+'</td><td style="padding:8px;text-align:right">₺'+fmt(genelSatisToplam)+'</td><td style="padding:8px;text-align:right">%100</td>';
    html += '</tr></tfoot></table></div>';

    // ▸ TABLO 3: Personel Maaşları
    html += '<div style="border:1.5px solid #cbd5e1;border-radius:10px;overflow:hidden">';
    html += '<div style="background:#1e293b;color:#fff;padding:8px 12px;font-weight:800;font-size:13px">Personel Maaşları</div>';
    html += '<table style="width:100%;border-collapse:collapse;font-size:12px">';
    html += '<thead><tr style="background:#f1f5f9"><th style="padding:6px 8px;text-align:left">İsim</th><th style="padding:6px 8px;text-align:right">Maaş</th><th style="padding:6px 8px;text-align:right">SGK</th></tr></thead><tbody>';
    perRows.forEach(function(r){
      html += '<tr><td style="padding:5px 8px;border-bottom:1px solid #f1f5f9">'+r.isim+'</td>';
      html += '<td style="padding:5px 8px;text-align:right;border-bottom:1px solid #f1f5f9;font-weight:600">₺'+fmt(r.maas)+'</td>';
      html += '<td style="padding:5px 8px;text-align:right;border-bottom:1px solid #f1f5f9">₺'+fmt(r.sgk)+'</td></tr>';
    });
    html += '</tbody><tfoot>';
    html += '<tr style="background:#f1f5f9;font-weight:700"><td style="padding:6px 8px">TOPLAM</td><td style="padding:6px 8px;text-align:right">₺'+fmt(topMaas)+'</td><td style="padding:6px 8px;text-align:right">₺'+fmt(topSgk)+'</td></tr>';
    html += '<tr style="background:#0f766e;color:#fff;font-weight:800"><td style="padding:8px">SGK DAHİL TOPLAM</td><td colspan="2" style="padding:8px;text-align:right;font-size:14px">₺'+fmt(sgkDahilToplam)+'</td></tr>';
    html += '</tfoot></table></div>';

    // ▸ TABLO 4: Özet (Birim Maliyet & Kar/Zarar)
    html += '<div style="border:1.5px solid #cbd5e1;border-radius:10px;overflow:hidden">';
    html += '<div style="background:#1e293b;color:#fff;padding:8px 12px;font-weight:800;font-size:13px">Özet — Birim Maliyet & Kar/Zarar</div>';
    html += '<table style="width:100%;border-collapse:collapse;font-size:13px">';
    html += '<tbody>';

    var ozetRows = [
      {label:'Toplam Satış',         val:'₺'+fmt(genelSatisToplam), color:'#166534', bg:'#f0fdf4'},
      {label:'Toplam Harcama (Gıda)', val:'₺'+fmt(genelHarcToplam), color:'#b91c1c', bg:'#fef2f2'},
      {label:'Toplam Personel (SGK Dahil)', val:'₺'+fmt(sgkDahilToplam), color:'#b45309', bg:'#fffbeb'},
      {label:'Toplam Yemek Sayısı',   val:fmt(genelKisiToplam,0)+' kişi', color:'#1d4ed8', bg:'#eff6ff'},
      {label:'', val:'', color:'', bg:'#fff'}
    ];
    ozetRows.forEach(function(r){
      if(!r.label){ html+='<tr><td colspan="2" style="padding:2px;border-bottom:2px solid #e2e8f0"></td></tr>'; return; }
      html += '<tr style="background:'+r.bg+'">';
      html += '<td style="padding:10px 12px;font-weight:700;border-bottom:1px solid #f1f5f9">'+r.label+'</td>';
      html += '<td style="padding:10px 12px;text-align:right;font-weight:800;font-size:14px;color:'+r.color+';border-bottom:1px solid #f1f5f9">'+r.val+'</td></tr>';
    });

    // Birim maliyetler
    html += '<tr style="background:#f8fafc"><td style="padding:8px 12px;font-weight:700">Gıda Maliyeti (kişi başı)</td><td style="padding:8px 12px;text-align:right;font-weight:800;font-size:15px;color:#dc2626">₺'+fmt(birimGida)+'</td></tr>';
    html += '<tr style="background:#f8fafc"><td style="padding:8px 12px;font-weight:700">Personel Maliyeti (kişi başı)</td><td style="padding:8px 12px;text-align:right;font-weight:800;font-size:15px;color:#b45309">₺'+fmt(birimPersonel)+'</td></tr>';
    html += '<tr style="background:#e2e8f0"><td style="padding:10px 12px;font-weight:900">TOPLAM BİRİM MALİYET</td><td style="padding:10px 12px;text-align:right;font-weight:900;font-size:16px;color:#1e293b">₺'+fmt(birimToplam)+'</td></tr>';

    // Kar/Zarar
    var karColor = karZarar >= 0 ? '#166534' : '#b91c1c';
    var karBg = karZarar >= 0 ? '#dcfce7' : '#fecaca';
    var karLabel = karZarar >= 0 ? 'KAR' : 'ZARAR';
    html += '<tr style="background:'+karBg+'"><td style="padding:12px;font-weight:900;font-size:14px">'+karLabel+'</td><td style="padding:12px;text-align:right;font-weight:900;font-size:20px;color:'+karColor+'">₺'+fmt(Math.abs(karZarar))+'</td></tr>';

    html += '</tbody></table></div>';

    html += '</div>'; // grid end

    div.innerHTML = html;

    // Rapor verisini sakla (PDF için)
    window._aoRaporData = {
      ayLabel: ayLabel,
      harcRows: harcRows,
      genelHarcToplam: genelHarcToplam,
      satisRows: satisRows,
      genelSatisToplam: genelSatisToplam,
      genelKisiToplam: genelKisiToplam,
      perRows: perRows,
      topMaas: topMaas,
      topSgk: topSgk,
      sgkDahilToplam: sgkDahilToplam,
      birimGida: birimGida,
      birimPersonel: birimPersonel,
      birimToplam: birimToplam,
      karZarar: karZarar
    };
  };

  // ── PDF İndir ──────────────────────────────────────────────
  window.aylikOzetPdf = function(){
    var d = window._aoRaporData;
    if(!d){ alert('Önce rapor oluşturun.'); return; }

    var html = '<!DOCTYPE html><html><head><meta charset="utf-8"/><title>UYSA — '+d.ayLabel+' Özet Rapor</title>';
    html += '<style>body{font-family:Arial,sans-serif;margin:20px;font-size:12px;color:#1e293b}';
    html += 'h1{text-align:center;color:#0f766e;margin-bottom:6px;font-size:18px}';
    html += 'h3{margin:16px 0 6px;font-size:13px;color:#334155}';
    html += 'table{width:100%;border-collapse:collapse;margin-bottom:12px}';
    html += 'th,td{border:1px solid #cbd5e1;padding:5px 8px;font-size:11px}';
    html += 'th{background:#f1f5f9;font-weight:700;text-align:left}';
    html += '.r{text-align:right} .b{font-weight:700} .total{background:#e2e8f0;font-weight:800}';
    html += '.green{color:#166534} .red{color:#b91c1c} .orange{color:#b45309}';
    html += '.big{font-size:16px;font-weight:900} .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}';
    html += '@media print{body{margin:10px} .no-print{display:none}}';
    html += '</style></head><body>';

    html += '<button class="no-print" onclick="window.print()" style="position:fixed;top:10px;right:10px;padding:8px 16px;background:#0f766e;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:700">Yazdır / PDF</button>';

    html += '<h1>UYSA — '+d.ayLabel+' Aylık Özet Rapor</h1>';
    html += '<div class="grid">';

    // Tablo 1: Harcamalar
    html += '<div><h3>Toplam Harcamalar</h3><table>';
    html += '<tr><th>Firma</th><th class="r">Toplam Tutar</th><th class="r">Oran (%)</th></tr>';
    d.harcRows.forEach(function(r){
      var pct = d.genelHarcToplam>0 ? (r.tutar/d.genelHarcToplam*100) : 0;
      html += '<tr><td>'+r.firma+'</td><td class="r b">₺'+fmt(r.tutar)+'</td><td class="r">%'+fmt(pct,1)+'</td></tr>';
    });
    html += '<tr class="total"><td>GENEL TOPLAM</td><td class="r">₺'+fmt(d.genelHarcToplam)+'</td><td class="r">%100</td></tr>';
    html += '</table></div>';

    // Tablo 2: Satışlar
    html += '<div><h3>Toplam Satışlar</h3><table>';
    html += '<tr><th>Firma</th><th class="r">Yemek Sayısı</th><th class="r">Toplam Tutar</th><th class="r">Oran (%)</th></tr>';
    d.satisRows.forEach(function(r){
      var pct = d.genelSatisToplam>0 ? (r.tutar/d.genelSatisToplam*100) : 0;
      html += '<tr><td>'+r.musteri+'</td><td class="r">'+fmt(r.kisi,0)+'</td><td class="r b">₺'+fmt(r.tutar)+'</td><td class="r">%'+fmt(pct,1)+'</td></tr>';
    });
    html += '<tr class="total"><td>GENEL TOPLAM</td><td class="r">'+fmt(d.genelKisiToplam,0)+'</td><td class="r">₺'+fmt(d.genelSatisToplam)+'</td><td class="r">%100</td></tr>';
    html += '</table></div>';

    // Tablo 3: Personel
    html += '<div><h3>Personel Maaşları</h3><table>';
    html += '<tr><th>İsim</th><th class="r">Maaş</th><th class="r">SGK</th></tr>';
    d.perRows.forEach(function(r){
      html += '<tr><td>'+r.isim+'</td><td class="r b">₺'+fmt(r.maas)+'</td><td class="r">₺'+fmt(r.sgk)+'</td></tr>';
    });
    html += '<tr style="background:#f1f5f9" class="b"><td>TOPLAM</td><td class="r">₺'+fmt(d.topMaas)+'</td><td class="r">₺'+fmt(d.topSgk)+'</td></tr>';
    html += '<tr class="total"><td>SGK DAHİL TOPLAM</td><td colspan="2" class="r">₺'+fmt(d.sgkDahilToplam)+'</td></tr>';
    html += '</table></div>';

    // Tablo 4: Özet
    html += '<div><h3>Özet</h3><table>';
    html += '<tr><td class="b">Gıda Maliyeti (kişi başı)</td><td class="r b red">₺'+fmt(d.birimGida)+'</td></tr>';
    html += '<tr><td class="b">Personel Maliyeti (kişi başı)</td><td class="r b orange">₺'+fmt(d.birimPersonel)+'</td></tr>';
    html += '<tr class="total"><td>TOPLAM BİRİM MALİYET</td><td class="r big">₺'+fmt(d.birimToplam)+'</td></tr>';
    var karLabel = d.karZarar>=0 ? 'KAR' : 'ZARAR';
    var karClass = d.karZarar>=0 ? 'green' : 'red';
    html += '<tr style="background:'+(d.karZarar>=0?'#dcfce7':'#fecaca')+'"><td class="b" style="font-size:14px">'+karLabel+'</td><td class="r big '+karClass+'">₺'+fmt(Math.abs(d.karZarar))+'</td></tr>';
    html += '</table></div>';

    html += '</div>'; // grid end
    html += '</body></html>';

    var w = window.open('','_blank');
    w.document.write(html);
    w.document.close();
  };

})();
