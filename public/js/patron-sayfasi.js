// ═══════════════════════════════════════════════════════════════
// PATRON SAYFASI — Yönetim Dashboard
// Dönemsel K/Z, birim maliyet, müşteri bazlı gelir, tedarikçi analizi
// ═══════════════════════════════════════════════════════════════

(function(){
  'use strict';

  var _ls = {
    get: function(k,d){ try{ var v=localStorage.getItem(k); return v?JSON.parse(v):d; }catch(e){ return d; } }
  };

  var _charts = {};

  function fmt(n, dec){
    dec = dec !== undefined ? dec : 2;
    return (Number(n)||0).toLocaleString('tr-TR',{minimumFractionDigits:dec,maximumFractionDigits:dec});
  }
  function fmtTL(n){ return fmt(n) + ' \u20BA'; }

  // Dönem hesaplama
  function getDonemRange(){
    var sel = document.getElementById('patronDonem');
    var donem = sel ? sel.value : 'bu_ay';
    var now = new Date();
    var y = now.getFullYear(), m = now.getMonth();
    var start, end;

    switch(donem){
      case 'bu_ay':
        start = new Date(y,m,1); end = new Date(y,m+1,0); break;
      case 'gecen_ay':
        start = new Date(y,m-1,1); end = new Date(y,m,0); break;
      case 'son3ay':
        start = new Date(y,m-2,1); end = new Date(y,m+1,0); break;
      case 'son6ay':
        start = new Date(y,m-5,1); end = new Date(y,m+1,0); break;
      case 'bu_yil':
        start = new Date(y,0,1); end = new Date(y,11,31); break;
      default: // tumu
        start = new Date(2020,0,1); end = new Date(y+1,0,1); break;
    }

    var startStr = start.toISOString().slice(0,10);
    var endStr = end.toISOString().slice(0,10);
    return { start: startStr, end: endStr, label: donem };
  }

  function filterByDate(arr, field, range){
    return arr.filter(function(x){
      var d = x[field]||'';
      return d >= range.start && d <= range.end;
    });
  }

  // Ana render fonksiyonu
  window.renderPatronSayfasi = function(){
    var range = getDonemRange();

    // Veri yükleme
    var gelirler = filterByDate(_ls.get('uysa_gelirler',[]), 'tarih', range);
    var giderler = filterByDate(_ls.get('uysa_giderler',[]), 'tarih', range);
    var personeller = _ls.get('uysa_personeller',[]);
    var gunluk = filterByDate(_ls.get('uysa_gunluk_uretim',[]), 'tarih', range);

    // Toplamlar
    var topGelir = gelirler.reduce(function(s,g){ return s + (parseFloat(g.tutar)||0); },0);
    var topGider = giderler.reduce(function(s,g){ return s + (parseFloat(g.tutar)||0); },0);
    var topKisi = gunluk.reduce(function(s,g){ return s + (parseInt(g.kisi)||0); },0);
    var topPersonelMaas = personeller.reduce(function(s,p){
      return s + (parseFloat(p.maas)||0) + (parseFloat(p.sgk)||0);
    },0);
    var netKar = topGelir - topGider - topPersonelMaas;
    var karMarji = topGelir > 0 ? (netKar / topGelir * 100) : 0;
    var birimGida = topKisi > 0 ? topGider / topKisi : 0;
    var birimGelir = topKisi > 0 ? topGelir / topKisi : 0;

    // KPI kartları
    var kpiDiv = document.getElementById('patronKpiDiv');
    if(kpiDiv){
      kpiDiv.innerHTML = ''
        + _kpiCard('TOPLAM GELİR', fmtTL(topGelir), '#16a34a', '📈')
        + _kpiCard('TOPLAM GİDER', fmtTL(topGider + topPersonelMaas), '#dc2626', '📉')
        + _kpiCard('NET KÂR', fmtTL(netKar), netKar>=0?'#16a34a':'#dc2626', netKar>=0?'💰':'⚠️')
        + _kpiCard('KÂR MARJİ', fmt(karMarji,1)+'%', karMarji>=10?'#16a34a':karMarji>=0?'#f59e0b':'#dc2626', '📊')
        + _kpiCard('KİŞİ BAŞI GELİR', fmtTL(birimGelir), '#1e40af', '👤');
    }

    // Müşteri bazlı gelir
    _renderMusteriGelir(gelirler);

    // Birim maliyet analizi
    _renderBirimMaliyet(topGelir, topGider, topPersonelMaas, topKisi, gelirler, giderler);

    // Dönemsel K/Z
    _renderDonemselKZ(range);

    // Tedarikçi harcamaları
    _renderTedarikciHarcama(giderler);

    // Grafikler
    _renderTrendChart(range);
    _renderGiderPie(giderler, topPersonelMaas);
  };

  function _kpiCard(label, value, color, icon){
    return '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;text-align:center;border-top:4px solid '+color+'">'
      +'<div style="font-size:24px;margin-bottom:4px">'+icon+'</div>'
      +'<div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:0.5px">'+label+'</div>'
      +'<div style="font-size:22px;font-weight:900;color:'+color+';margin-top:4px">'+value+'</div>'
      +'</div>';
  }

  function _renderMusteriGelir(gelirler){
    var div = document.getElementById('patronMusteriDiv');
    if(!div) return;
    var byMusteri = {};
    gelirler.forEach(function(g){
      var m = g.musteri||g.aciklama||'Diğer';
      if(!byMusteri[m]) byMusteri[m] = { tutar:0, kisi:0, count:0 };
      byMusteri[m].tutar += (parseFloat(g.tutar)||0);
      byMusteri[m].kisi += (parseInt(g.kisi)||0);
      byMusteri[m].count++;
    });
    var arr = Object.entries(byMusteri).sort(function(a,b){ return b[1].tutar - a[1].tutar; });
    var topGelir = arr.reduce(function(s,e){ return s+e[1].tutar; },0);

    if(!arr.length){ div.innerHTML='<div style="color:var(--muted);text-align:center;padding:20px">Dönemde gelir kaydı yok.</div>'; return; }

    div.innerHTML = '<table class="monthTable" style="width:100%"><thead><tr><th>Müşteri</th><th>Gelir</th><th>Kişi</th><th>Oran</th><th>İşlem</th></tr></thead><tbody>'
      + arr.map(function(e){
        var pct = topGelir>0 ? (e[1].tutar/topGelir*100) : 0;
        return '<tr><td><b>'+e[0]+'</b> <span style="color:var(--muted);font-size:10px">('+e[1].count+' kayıt)</span></td>'
          +'<td style="font-weight:700;color:#16a34a">'+fmtTL(e[1].tutar)+'</td>'
          +'<td>'+fmt(e[1].kisi,0)+'</td>'
          +'<td><div style="width:80px;height:8px;background:#e5e7eb;border-radius:4px;display:inline-block;vertical-align:middle">'
          +'<div style="width:'+Math.min(100,pct)+'%;height:100%;background:#16a34a;border-radius:4px"></div></div> '+fmt(pct,1)+'%</td>'
          +'<td style="font-size:11px">'+fmtTL(e[1].kisi>0?e[1].tutar/e[1].kisi:0)+'/kişi</td></tr>';
      }).join('')
      + '</tbody></table>';
  }

  function _renderBirimMaliyet(topGelir, topGider, topPersonel, topKisi, gelirler, giderler){
    var div = document.getElementById('patronBirimDiv');
    if(!div) return;
    var birimGida = topKisi>0 ? topGider/topKisi : 0;
    var birimPersonel = topKisi>0 ? topPersonel/topKisi : 0;
    var birimGelir = topKisi>0 ? topGelir/topKisi : 0;
    var birimKar = birimGelir - birimGida - birimPersonel;

    div.innerHTML = '<table class="monthTable" style="width:100%"><tbody>'
      +'<tr style="background:#f0fdf4"><td style="font-weight:700">Kişi Başı Gelir</td><td style="font-weight:900;color:#16a34a;text-align:right;font-size:18px">'+fmtTL(birimGelir)+'</td></tr>'
      +'<tr style="background:#fef2f2"><td style="font-weight:700">Kişi Başı Gıda Maliyeti</td><td style="font-weight:900;color:#dc2626;text-align:right;font-size:18px">'+fmtTL(birimGida)+'</td></tr>'
      +'<tr style="background:#faf5ff"><td style="font-weight:700">Kişi Başı Personel Maliyeti</td><td style="font-weight:900;color:#7c3aed;text-align:right;font-size:18px">'+fmtTL(birimPersonel)+'</td></tr>'
      +'<tr style="background:'+(birimKar>=0?'#ecfdf5':'#fef2f2')+'"><td style="font-weight:700;font-size:15px">Kişi Başı Net Kâr</td><td style="font-weight:900;color:'+(birimKar>=0?'#16a34a':'#dc2626')+';text-align:right;font-size:22px">'+fmtTL(birimKar)+'</td></tr>'
      +'<tr><td colspan="2" style="text-align:center;padding-top:12px">'
      +'<div style="font-size:11px;color:var(--muted)">Toplam kişi: <b>'+fmt(topKisi,0)+'</b></div>'
      +'</td></tr>'
      +'</tbody></table>';
  }

  function _renderDonemselKZ(range){
    var div = document.getElementById('patronKZDiv');
    if(!div) return;
    var allGelir = _ls.get('uysa_gelirler',[]);
    var allGider = _ls.get('uysa_giderler',[]);
    var personeller = _ls.get('uysa_personeller',[]);
    var topPersonel = personeller.reduce(function(s,p){ return s+(parseFloat(p.maas)||0)+(parseFloat(p.sgk)||0); },0);

    // Son 6 ay dönemsel
    var aylar = [];
    var now = new Date();
    for(var i=5; i>=0; i--){
      var d = new Date(now.getFullYear(), now.getMonth()-i, 1);
      var ym = d.toISOString().slice(0,7);
      var ayAdi = d.toLocaleDateString('tr-TR',{month:'long',year:'numeric'});
      var gelir = allGelir.filter(function(g){ return (g.tarih||'').startsWith(ym); }).reduce(function(s,g){ return s+(parseFloat(g.tutar)||0); },0);
      var gider = allGider.filter(function(g){ return (g.tarih||'').startsWith(ym); }).reduce(function(s,g){ return s+(parseFloat(g.tutar)||0); },0);
      var kar = gelir - gider - topPersonel;
      aylar.push({ ay:ayAdi, gelir:gelir, gider:gider+topPersonel, kar:kar });
    }

    div.innerHTML = '<table class="monthTable" style="width:100%"><thead><tr><th>Dönem</th><th>Gelir</th><th>Toplam Gider</th><th>Net K/Z</th></tr></thead><tbody>'
      + aylar.map(function(a){
        return '<tr><td><b>'+a.ay+'</b></td>'
          +'<td style="color:#16a34a">'+fmtTL(a.gelir)+'</td>'
          +'<td style="color:#dc2626">'+fmtTL(a.gider)+'</td>'
          +'<td style="font-weight:900;color:'+(a.kar>=0?'#16a34a':'#dc2626')+'">'+fmtTL(a.kar)+'</td></tr>';
      }).join('')
      + '</tbody></table>';
  }

  function _renderTedarikciHarcama(giderler){
    var div = document.getElementById('patronTedarikciDiv');
    if(!div) return;
    var byTed = {};
    giderler.forEach(function(g){
      var t = g.tedarikci||g.firma||g.aciklama||'Diğer';
      if(!byTed[t]) byTed[t] = 0;
      byTed[t] += (parseFloat(g.tutar)||0);
    });
    var arr = Object.entries(byTed).sort(function(a,b){ return b[1]-a[1]; });
    var top = arr.reduce(function(s,e){ return s+e[1]; },0);

    if(!arr.length){ div.innerHTML='<div style="color:var(--muted);text-align:center;padding:20px">Dönemde gider kaydı yok.</div>'; return; }

    div.innerHTML = '<table class="monthTable" style="width:100%"><thead><tr><th>Tedarikçi</th><th>Harcama</th><th>Oran</th></tr></thead><tbody>'
      + arr.map(function(e){
        var pct = top>0 ? (e[1]/top*100) : 0;
        return '<tr><td><b>'+e[0]+'</b></td>'
          +'<td style="font-weight:700;color:#dc2626">'+fmtTL(e[1])+'</td>'
          +'<td><div style="width:80px;height:8px;background:#e5e7eb;border-radius:4px;display:inline-block;vertical-align:middle">'
          +'<div style="width:'+Math.min(100,pct)+'%;height:100%;background:#dc2626;border-radius:4px"></div></div> '+fmt(pct,1)+'%</td></tr>';
      }).join('')
      + '</tbody></table>';
  }

  function _renderTrendChart(range){
    var canvas = document.getElementById('patronTrendChart');
    if(!canvas || typeof Chart==='undefined') return;

    if(_charts.trend){ _charts.trend.destroy(); }

    var allGelir = _ls.get('uysa_gelirler',[]);
    var allGider = _ls.get('uysa_giderler',[]);

    var aylar = [], gelirData = [], giderData = [], karData = [];
    var now = new Date();
    for(var i=5; i>=0; i--){
      var d = new Date(now.getFullYear(), now.getMonth()-i, 1);
      var ym = d.toISOString().slice(0,7);
      aylar.push(d.toLocaleDateString('tr-TR',{month:'short'}));
      var g = allGelir.filter(function(x){ return (x.tarih||'').startsWith(ym); }).reduce(function(s,x){ return s+(parseFloat(x.tutar)||0); },0);
      var e = allGider.filter(function(x){ return (x.tarih||'').startsWith(ym); }).reduce(function(s,x){ return s+(parseFloat(x.tutar)||0); },0);
      gelirData.push(g);
      giderData.push(e);
      karData.push(g-e);
    }

    _charts.trend = new Chart(canvas.getContext('2d'), {
      type: 'bar',
      data: {
        labels: aylar,
        datasets: [
          { label: 'Gelir', data: gelirData, backgroundColor: 'rgba(22,163,74,0.7)', borderRadius: 4 },
          { label: 'Gider', data: giderData, backgroundColor: 'rgba(220,38,38,0.7)', borderRadius: 4 },
          { label: 'Net K/Z', data: karData, type: 'line', borderColor: '#1e40af', backgroundColor: 'rgba(30,64,175,0.1)', fill: true, tension: 0.4, pointRadius: 4 }
        ]
      },
      options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' } },
        scales: { y: { beginAtZero: true, ticks: { callback: function(v){ return (v/1000).toFixed(0)+'K'; } } } }
      }
    });
  }

  function _renderGiderPie(giderler, topPersonel){
    var canvas = document.getElementById('patronGiderPie');
    if(!canvas || typeof Chart==='undefined') return;

    if(_charts.pie){ _charts.pie.destroy(); }

    // Kategorize
    var cats = { 'Gıda/Hammadde':0, 'Personel':topPersonel, 'Nakliye':0, 'Kira/Sabit':0, 'Diğer':0 };
    giderler.forEach(function(g){
      var kat = (g.kategori||g.aciklama||'').toLowerCase();
      var tutar = parseFloat(g.tutar)||0;
      if(kat.includes('gıda')||kat.includes('et ')||kat.includes('sebze')||kat.includes('malzeme')) cats['Gıda/Hammadde']+=tutar;
      else if(kat.includes('nakliye')||kat.includes('yakıt')||kat.includes('taşıma')) cats['Nakliye']+=tutar;
      else if(kat.includes('kira')||kat.includes('sabit')) cats['Kira/Sabit']+=tutar;
      else cats['Diğer']+=tutar;
    });

    var labels = [], data = [], colors = ['#16a34a','#7c3aed','#f59e0b','#64748b','#dc2626'];
    Object.entries(cats).forEach(function(e,i){
      if(e[1]>0){ labels.push(e[0]); data.push(e[1]); }
    });

    _charts.pie = new Chart(canvas.getContext('2d'), {
      type: 'doughnut',
      data: {
        labels: labels,
        datasets: [{ data: data, backgroundColor: colors.slice(0,labels.length), borderWidth: 2, borderColor: '#fff' }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { position: 'bottom', labels: { font: { size: 11 } } },
          tooltip: { callbacks: { label: function(ctx){ return ctx.label+': '+fmtTL(ctx.parsed); } } }
        }
      }
    });
  }

  // Yazdır
  window.patronSayfasiYazdir = function(){
    var kpiHtml = document.getElementById('patronKpiDiv')?.innerHTML||'';
    var musteriHtml = document.getElementById('patronMusteriDiv')?.innerHTML||'';
    var birimHtml = document.getElementById('patronBirimDiv')?.innerHTML||'';
    var kzHtml = document.getElementById('patronKZDiv')?.innerHTML||'';
    var tedHtml = document.getElementById('patronTedarikciDiv')?.innerHTML||'';

    var w = window.open('','_blank','width=900,height=700');
    w.document.write('<!DOCTYPE html><html><head><title>Patron Sayfası</title>'
      +'<style>body{font-family:Arial,sans-serif;padding:20px;font-size:12px;color:#333}'
      +'h2{color:#1e1b4b;border-bottom:3px solid #7c3aed;padding-bottom:8px}'
      +'h3{color:#312e81;margin-top:20px}'
      +'table{width:100%;border-collapse:collapse;margin:8px 0}th,td{padding:6px 8px;border:1px solid #e5e7eb;text-align:left}'
      +'th{background:#f8fafc;font-weight:700}.kpi-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin:16px 0}'
      +'@media print{body{padding:0}}</style></head><body>'
      +'<h2>UYSA ERP — Patron Sayfası</h2>'
      +'<div class="kpi-grid">'+kpiHtml+'</div>'
      +'<h3>Müşteri Bazlı Gelir</h3>'+musteriHtml
      +'<h3>Birim Maliyet Analizi</h3>'+birimHtml
      +'<h3>Dönemsel Kâr / Zarar</h3>'+kzHtml
      +'<h3>Tedarikçi Harcamaları</h3>'+tedHtml
      +'</body></html>');
    w.document.close();
    setTimeout(function(){ w.print(); },500);
  };

})();
