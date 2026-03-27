// ═══════════════════════════════════════════════════════════════
// REÇETE & MALİYET MODÜLÜ — Maliyet Analizi & Reçete Geçmişi
// ═══════════════════════════════════════════════════════════════

(function(){
  'use strict';
  var rc = window._rc;

  // ── Analiz tab init ──────────────────────────────────────────
  window.rcInitAnaliz = function(){
    var now = new Date();
    var bas = document.getElementById('rcAnalizBas');
    var bit = document.getElementById('rcAnalizBit');
    if(bas && !bas.value) bas.value = now.getFullYear()+'-'+(now.getMonth()<9?'0':'')+(now.getMonth()+1)+'-01';
    if(bit && !bit.value) bit.value = now.toISOString().slice(0,10);
    window.rcRenderAnaliz();
  };

  // ── Maliyet analizi render ───────────────────────────────────
  window.rcRenderAnaliz = function(){
    var bas = document.getElementById('rcAnalizBas')?.value || '';
    var bit = document.getElementById('rcAnalizBit')?.value || '';
    var kpiDiv = document.getElementById('rcAnalizKpiDiv');
    var detayDiv = document.getElementById('rcAnalizDetayDiv');
    if(!kpiDiv || !detayDiv) return;

    var history = rc.getHistory();
    if(bas) history = history.filter(function(h){ return h.tarih >= bas; });
    if(bit) history = history.filter(function(h){ return h.tarih <= bit; });

    var prices = rc.getPrices();

    // Toplam maliyet hesapla
    var totalCost = 0, totalKisi = 0, dishCount = 0;
    var byDish = {};   // yemek bazlı maliyet
    var byMalzeme = {}; // malzeme bazlı toplam

    history.forEach(function(h){
      dishCount++;
      totalKisi += (h.kisi || 0);
      var dishCost = 0;
      (h.malzemeler||[]).forEach(function(m){
        var price = prices[m.name] || 0;
        var cost = (m.totalKg||0) * price;
        dishCost += cost;
        totalCost += cost;

        if(!byMalzeme[m.name]) byMalzeme[m.name] = {kg:0, cost:0, count:0};
        byMalzeme[m.name].kg += (m.totalKg||0);
        byMalzeme[m.name].cost += cost;
        byMalzeme[m.name].count++;
      });

      if(!byDish[h.yemek]) byDish[h.yemek] = {cost:0, count:0, kisi:0};
      byDish[h.yemek].cost += dishCost;
      byDish[h.yemek].count++;
      byDish[h.yemek].kisi += (h.kisi||0);
    });

    var avgKisiBasiTotal = totalKisi > 0 ? totalCost / totalKisi : 0;
    var uniqueDays = new Set(history.map(function(h){ return h.tarih; })).size;

    // KPI kartları
    kpiDiv.innerHTML = [
      {lbl:'Toplam Üretim Maliyeti', val:rc.fmtTL(totalCost), color:'#dc2626'},
      {lbl:'Toplam Kişi', val:rc.fmt(totalKisi,0), color:'#1e40af'},
      {lbl:'Ortalama Kişi Başı', val:rc.fmtTL(avgKisiBasiTotal), color:'#166534'},
      {lbl:'Üretim Günü', val:uniqueDays+' gün', color:'#7c3aed'},
      {lbl:'Yemek Üretimi', val:dishCount+' kalem', color:'#ea580c'}
    ].map(function(k){
      return '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;text-align:center">'
        +'<div style="font-size:11px;color:#64748b;font-weight:700">'+k.lbl+'</div>'
        +'<div style="font-size:22px;font-weight:900;color:'+k.color+'">'+k.val+'</div></div>';
    }).join('');

    // Yemek bazlı tablo
    var dishKeys = Object.keys(byDish).sort(function(a,b){ return byDish[b].cost - byDish[a].cost; });
    var html = '<div class="panel" style="margin-bottom:14px"><h3 style="margin-bottom:10px">Yemek Bazlı Maliyet</h3>';
    html += '<table style="width:100%;border-collapse:collapse;font-size:13px">';
    html += '<tr style="background:#f1f5f9"><th style="padding:6px;text-align:left">Yemek</th><th style="padding:6px;text-align:right">Üretim</th><th style="padding:6px;text-align:right">Kişi</th><th style="padding:6px;text-align:right">Toplam Maliyet</th><th style="padding:6px;text-align:right">Kişi Başı</th></tr>';
    dishKeys.forEach(function(d){
      var v = byDish[d];
      var kisiBasi = v.kisi > 0 ? v.cost / v.kisi : 0;
      html += '<tr style="border-bottom:1px solid #f1f5f9">';
      html += '<td style="padding:6px;font-weight:600">'+d+'</td>';
      html += '<td style="padding:6px;text-align:right">'+v.count+'x</td>';
      html += '<td style="padding:6px;text-align:right">'+rc.fmt(v.kisi,0)+'</td>';
      html += '<td style="padding:6px;text-align:right;font-weight:700;color:#dc2626">'+rc.fmtTL(v.cost)+'</td>';
      html += '<td style="padding:6px;text-align:right;color:#166534">'+rc.fmtTL(kisiBasi)+'</td>';
      html += '</tr>';
    });
    html += '</table></div>';

    // Malzeme bazlı tablo
    var malKeys = Object.keys(byMalzeme).sort(function(a,b){ return byMalzeme[b].cost - byMalzeme[a].cost; });
    html += '<div class="panel"><h3 style="margin-bottom:10px">Malzeme Bazlı Kullanım</h3>';
    html += '<table style="width:100%;border-collapse:collapse;font-size:13px">';
    html += '<tr style="background:#f1f5f9"><th style="padding:6px;text-align:left">Malzeme</th><th style="padding:6px;text-align:right">Toplam KG</th><th style="padding:6px;text-align:right">Birim Fiyat</th><th style="padding:6px;text-align:right">Toplam Maliyet</th><th style="padding:6px;text-align:right">Kullanım</th></tr>';
    malKeys.forEach(function(m){
      var v = byMalzeme[m];
      var unitPrice = prices[m] || 0;
      html += '<tr style="border-bottom:1px solid #f1f5f9">';
      html += '<td style="padding:6px;font-weight:600">'+m+'</td>';
      html += '<td style="padding:6px;text-align:right">'+rc.fmt(v.kg,2)+' kg</td>';
      html += '<td style="padding:6px;text-align:right">'+rc.fmtTL(unitPrice)+'</td>';
      html += '<td style="padding:6px;text-align:right;font-weight:700;color:#dc2626">'+rc.fmtTL(v.cost)+'</td>';
      html += '<td style="padding:6px;text-align:right;color:#64748b">'+v.count+'x</td>';
      html += '</tr>';
    });
    html += '</table></div>';

    detayDiv.innerHTML = html;
  };

  // ══════════════════════════════════════════════════════════════
  // REÇETE GEÇMİŞİ & ORTALAMA
  // ══════════════════════════════════════════════════════════════

  window.rcInitGecmis = function(){
    var sel = document.getElementById('rcGecmisYemek');
    if(!sel) return;
    var recipes = rc.getRecipes();
    var names = Object.keys(recipes).sort(function(a,b){ return a.localeCompare(b,'tr'); });
    var current = sel.value;
    sel.innerHTML = '<option value="">-- Yemek Seçin --</option>';
    names.forEach(function(n){
      sel.innerHTML += '<option value="'+n+'"'+(n===current?' selected':'')+'>'+n+'</option>';
    });
    if(current) window.rcRenderGecmis();
  };

  // ── Reçete geçmişi render ────────────────────────────────────
  window.rcRenderGecmis = function(){
    var yemek = document.getElementById('rcGecmisYemek')?.value;
    var div = document.getElementById('rcGecmisDiv');
    if(!div) return;
    if(!yemek){ div.innerHTML='<div style="color:#94a3b8;text-align:center;padding:40px">Bir yemek seçin.</div>'; return; }

    var history = rc.getHistory().filter(function(h){ return h.yemek === yemek; });
    if(!history.length){
      div.innerHTML='<div style="color:#94a3b8;text-align:center;padding:40px">Bu yemek için üretim geçmişi bulunamadı.</div>';
      return;
    }

    // Mevcut standart reçete
    var recipes = rc.getRecipes();
    var stdRecipe = recipes[yemek] || [];

    var html = '<div class="panel" style="margin-bottom:14px">';
    html += '<h3 style="margin-bottom:10px">'+yemek+' — '+history.length+' Üretim Kaydı</h3>';

    // Her üretim kaydı
    history.sort(function(a,b){ return (b.tarih||'').localeCompare(a.tarih||''); });
    html += '<table style="width:100%;border-collapse:collapse;font-size:12px">';
    html += '<tr style="background:#f1f5f9"><th style="padding:6px;text-align:left">Tarih</th><th style="padding:6px;text-align:right">Kişi</th>';

    // Tüm malzeme isimlerini topla
    var allMats = new Set();
    history.forEach(function(h){ (h.malzemeler||[]).forEach(function(m){ allMats.add(m.name); }); });
    var matList = Array.from(allMats).sort();
    matList.forEach(function(m){ html += '<th style="padding:6px;text-align:right;font-size:10px">'+m+' (g/kişi)</th>'; });
    html += '</tr>';

    history.forEach(function(h){
      html += '<tr style="border-bottom:1px solid #f1f5f9">';
      html += '<td style="padding:6px">'+h.tarih+'</td>';
      html += '<td style="padding:6px;text-align:right">'+h.kisi+'</td>';
      matList.forEach(function(matName){
        var found = (h.malzemeler||[]).find(function(m){ return m.name===matName; });
        var gram = found ? found.gram : '';
        html += '<td style="padding:6px;text-align:right">'+(gram ? rc.fmt(gram,1) : '-')+'</td>';
      });
      html += '</tr>';
    });

    // Ortalama satırı
    html += '<tr style="background:#fefce8;font-weight:800"><td style="padding:6px">ORTALAMA</td><td style="padding:6px;text-align:right">';
    var avgKisi = history.reduce(function(s,h){ return s+h.kisi; },0) / history.length;
    html += rc.fmt(avgKisi,0);
    html += '</td>';
    matList.forEach(function(matName){
      var vals = history.map(function(h){
        var f = (h.malzemeler||[]).find(function(m){ return m.name===matName; });
        return f ? f.gram : 0;
      }).filter(function(v){ return v > 0; });
      var avg = vals.length > 0 ? vals.reduce(function(s,v){ return s+v; },0) / vals.length : 0;
      html += '<td style="padding:6px;text-align:right;color:#b45309">'+(avg>0?rc.fmt(avg,1):'-')+'</td>';
    });
    html += '</tr>';

    // Mevcut standart
    if(stdRecipe.length){
      html += '<tr style="background:#ecfdf5;font-weight:600"><td style="padding:6px;color:#166534">STANDART REÇETE</td><td style="padding:6px"></td>';
      matList.forEach(function(matName){
        var f = stdRecipe.find(function(i){ return i.name===matName; });
        html += '<td style="padding:6px;text-align:right;color:#166534">'+(f?rc.fmt(f.gram,1):'-')+'</td>';
      });
      html += '</tr>';
    }

    html += '</table></div>';

    // Sapma analizi
    if(history.length >= 2 && stdRecipe.length){
      html += '<div class="panel"><h3 style="margin-bottom:10px">Sapma Analizi (Standart vs Gerçekleşen)</h3>';
      html += '<table style="width:100%;border-collapse:collapse;font-size:13px">';
      html += '<tr style="background:#f1f5f9"><th style="padding:6px;text-align:left">Malzeme</th><th style="padding:6px;text-align:right">Standart (g)</th><th style="padding:6px;text-align:right">Ortalama (g)</th><th style="padding:6px;text-align:right">Sapma</th><th style="padding:6px;text-align:right">Sapma %</th></tr>';
      matList.forEach(function(matName){
        var std = stdRecipe.find(function(i){ return i.name===matName; });
        var stdGram = std ? std.gram : 0;
        var vals = history.map(function(h){
          var f = (h.malzemeler||[]).find(function(m){ return m.name===matName; });
          return f ? f.gram : 0;
        }).filter(function(v){ return v>0; });
        var avg = vals.length ? vals.reduce(function(s,v){ return s+v; },0)/vals.length : 0;
        var sapma = avg - stdGram;
        var sapmaPct = stdGram > 0 ? (sapma/stdGram*100) : 0;
        var color = Math.abs(sapmaPct) > 15 ? '#dc2626' : (Math.abs(sapmaPct) > 5 ? '#d97706' : '#166534');
        html += '<tr style="border-bottom:1px solid #f1f5f9">';
        html += '<td style="padding:6px;font-weight:600">'+matName+'</td>';
        html += '<td style="padding:6px;text-align:right">'+(stdGram>0?rc.fmt(stdGram,1):'-')+'</td>';
        html += '<td style="padding:6px;text-align:right">'+(avg>0?rc.fmt(avg,1):'-')+'</td>';
        html += '<td style="padding:6px;text-align:right;color:'+color+'">'+(sapma>0?'+':'')+rc.fmt(sapma,1)+'</td>';
        html += '<td style="padding:6px;text-align:right;font-weight:700;color:'+color+'">'+(sapmaPct>0?'+':'')+rc.fmt(sapmaPct,1)+'%</td>';
        html += '</tr>';
      });
      html += '</table></div>';
    }

    div.innerHTML = html;
  };

  // ── Ortalama reçete oluştur ──────────────────────────────────
  window.rcOrtalamaAl = function(){
    var yemek = document.getElementById('rcGecmisYemek')?.value;
    if(!yemek){ alert('Önce bir yemek seçin.'); return; }

    var history = rc.getHistory().filter(function(h){ return h.yemek === yemek; });
    if(history.length < 2){ alert('Ortalama için en az 2 üretim kaydı gerekli. Mevcut: '+history.length); return; }

    // Tüm malzemeleri topla ve ortalamasını al
    var matTotals = {};
    history.forEach(function(h){
      (h.malzemeler||[]).forEach(function(m){
        if(!matTotals[m.name]) matTotals[m.name] = {sum:0, count:0};
        if(m.gram > 0){
          matTotals[m.name].sum += m.gram;
          matTotals[m.name].count++;
        }
      });
    });

    var newRecipe = Object.keys(matTotals).map(function(name){
      var t = matTotals[name];
      return { name: name, gram: Math.round(t.sum / t.count * 10) / 10 };
    }).filter(function(i){ return i.gram > 0; });

    if(!newRecipe.length){ alert('Hesaplanacak veri bulunamadı.'); return; }

    if(!confirm(yemek+' için '+history.length+' kayıttan ortalama reçete oluşturulacak.\nMevcut reçete güncellenecek. Devam?')) return;

    var recipes = rc.getRecipes();
    recipes[yemek] = newRecipe;
    rc.setRecipes(recipes);

    alert('Ortalama reçete oluşturuldu! '+newRecipe.length+' malzeme.');
    window.rcRenderGecmis();
  };

})();
