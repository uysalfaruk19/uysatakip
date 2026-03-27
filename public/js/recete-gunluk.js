// ═══════════════════════════════════════════════════════════════
// REÇETE & MALİYET MODÜLÜ — Günlük Üretim Girişi
// ═══════════════════════════════════════════════════════════════

(function(){
  'use strict';
  var rc = window._rc;

  // ── Müşterilerden kişi sayısı çek ────────────────────────────
  window.rcCekKisiSayisi = function(){
    var tarih = document.getElementById('rcGunTarih')?.value;
    if(!tarih){ alert('Önce tarih seçin.'); return; }
    var gunluk = rc.getGunluk();
    var total = gunluk.filter(function(g){ return g.tarih === tarih; })
                      .reduce(function(s,g){ return s + (parseInt(g.kisi)||0); }, 0);
    if(total > 0){
      document.getElementById('rcGunKisi').value = total;
      document.getElementById('rcOtomatikKisi').textContent = total + ' kişi';
      window.rcRecalcGunluk();
    } else {
      alert('Bu tarih için günlük üretim kaydı bulunamadı. Manuel girin veya önce Günlük Sayılar modülünden kayıt ekleyin.');
    }
  };

  // ── Günün menüsünü yükle ─────────────────────────────────────
  window.rcLoadGunlukMenu = function(){
    var tarih = document.getElementById('rcGunTarih')?.value;
    var div = document.getElementById('rcGunMenuDiv');
    if(!tarih || !div) return;

    // Otomatik kişi sayısını da göster
    var gunluk = rc.getGunluk();
    var total = gunluk.filter(function(g){ return g.tarih === tarih; })
                      .reduce(function(s,g){ return s + (parseInt(g.kisi)||0); }, 0);
    var otEl = document.getElementById('rcOtomatikKisi');
    if(otEl) otEl.textContent = total > 0 ? (total + ' kişi') : '—';

    // Menüden yemekleri çek
    var dishes = rc.getDayMenu(tarih);
    if(!dishes || !dishes.length){
      div.innerHTML = '<div class="panel" style="text-align:center;color:#d97706;padding:30px">'
        +'<b>Bu tarih için menü bulunamadı.</b><br>Menü modülünden önce menüyü girmeniz gerekiyor.'
        +'<br><br>Veya manuel yemek ekleyebilirsiniz:'
        +'<br><button class="btn" onclick="rcManuelYemekEkle()" style="margin-top:10px">+ Manuel Yemek Ekle</button>'
        +'</div>';
      return;
    }

    var recipes = rc.getRecipes();
    var prices = rc.getPrices();
    var kisi = parseInt(document.getElementById('rcGunKisi')?.value) || 100;

    var html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">'
      +'<h3 style="margin:0">'+_formatDate(tarih)+' Menüsü — '+dishes.length+' kalem</h3>'
      +'<button class="btn" onclick="rcManuelYemekEkle()" style="font-size:12px">+ Manuel Yemek Ekle</button>'
      +'</div>';

    dishes.forEach(function(dish, idx){
      var recipe = recipes[dish] || [];
      var hasRecipe = recipe.length > 0;
      var totalCost = 0;

      html += '<div class="panel" style="margin-bottom:10px;border:'+(hasRecipe?'2px solid #bbf7d0':'2px dashed #fde68a')+'">';
      html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">';
      html += '<h4 style="margin:0;font-size:14px">'+(hasRecipe?'&#9989;':'&#9888;&#65039;')+' '+dish+'</h4>';
      if(!hasRecipe){
        html += '<button class="btn" onclick="rcQuickRecipe(\''+dish.replace(/'/g,"\\'")+'\')" style="font-size:11px">Reçete Oluştur</button>';
      }
      html += '</div>';

      if(hasRecipe){
        html += '<table style="width:100%;border-collapse:collapse;font-size:12px">';
        html += '<tr style="background:#f1f5f9"><th style="padding:4px 6px;text-align:left">Malzeme</th><th style="padding:4px 6px;text-align:right">g/kişi</th><th style="padding:4px 6px;text-align:right">Toplam KG</th><th style="padding:4px 6px;text-align:right">Birim ₺</th><th style="padding:4px 6px;text-align:right">Maliyet</th></tr>';
        recipe.forEach(function(ing){
          var kg = (ing.gram * kisi) / 1000;
          var price = prices[ing.name] || 0;
          var cost = kg * price;
          totalCost += cost;
          html += '<tr style="border-bottom:1px solid #f1f5f9">';
          html += '<td style="padding:4px 6px">'+ing.name+'</td>';
          html += '<td style="padding:4px 6px;text-align:right">'+rc.fmt(ing.gram,1)+'</td>';
          html += '<td style="padding:4px 6px;text-align:right">'+rc.fmt(kg,3)+'</td>';
          html += '<td style="padding:4px 6px;text-align:right">'+(price>0?rc.fmtTL(price):'<span style="color:#d97706">?</span>')+'</td>';
          html += '<td style="padding:4px 6px;text-align:right;font-weight:700;color:#dc2626">'+rc.fmtTL(cost)+'</td>';
          html += '</tr>';
        });
        html += '<tr style="background:#ecfdf5;font-weight:800"><td colspan="4" style="padding:6px">Toplam</td><td style="padding:6px;text-align:right;color:#166534">'+rc.fmtTL(totalCost)+'</td></tr>';
        html += '<tr style="background:#f0fdf4"><td colspan="4" style="padding:6px;font-size:11px;color:#64748b">Kişi başı maliyet</td><td style="padding:6px;text-align:right;font-weight:700;color:#166534;font-size:11px">'+rc.fmtTL(totalCost/kisi)+'</td></tr>';
        html += '</table>';
      } else {
        html += '<div style="color:#94a3b8;font-size:12px;padding:8px">Bu yemek için reçete tanımlanmamış.</div>';
      }
      html += '</div>';
    });

    // Genel toplam
    var grandTotal = 0;
    dishes.forEach(function(dish){
      var recipe = recipes[dish] || [];
      recipe.forEach(function(ing){
        var kg = (ing.gram * kisi) / 1000;
        grandTotal += kg * (prices[ing.name]||0);
      });
    });

    html += '<div class="panel" style="background:linear-gradient(135deg,#eff6ff,#dbeafe);border:2px solid #93c5fd">';
    html += '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;text-align:center">';
    html += '<div><div style="font-size:11px;color:#64748b">Toplam Üretim Maliyeti</div><div style="font-size:22px;font-weight:900;color:#dc2626">'+rc.fmtTL(grandTotal)+'</div></div>';
    html += '<div><div style="font-size:11px;color:#64748b">Kişi Başı Maliyet</div><div style="font-size:22px;font-weight:900;color:#166534">'+rc.fmtTL(kisi>0?grandTotal/kisi:0)+'</div></div>';
    html += '<div><div style="font-size:11px;color:#64748b">Toplam Kişi</div><div style="font-size:22px;font-weight:900;color:#1e40af">'+kisi+'</div></div>';
    html += '</div></div>';

    div.innerHTML = html;
  };

  window.rcRecalcGunluk = function(){ window.rcLoadGunlukMenu(); };

  // ── Manuel yemek ekle ────────────────────────────────────────
  window.rcManuelYemekEkle = function(){
    var name = prompt('Yemek adı:');
    if(!name || !name.trim()) return;
    // Eğer reçetesi yoksa oluştur
    var recipes = rc.getRecipes();
    if(!recipes[name.trim()]) recipes[name.trim()] = [];
    rc.setRecipes(recipes);
    // Menüye eklenemez ama reçete kütüphanesine eklendi
    alert(name.trim() + ' eklendi. Reçete Kütüphanesi sekmesinden reçetesini tanımlayabilirsiniz.');
    window.rcLoadGunlukMenu();
  };

  // ── Hızlı reçete oluştur ─────────────────────────────────────
  window.rcQuickRecipe = function(dishName){
    window.switchReceteTab('kutuphane');
    setTimeout(function(){ window.rcOpenRecipe(dishName); }, 200);
  };

  // ── Günlük üretimi kaydet ────────────────────────────────────
  window.rcSaveGunlukUretim = function(){
    var tarih = document.getElementById('rcGunTarih')?.value;
    var kisi = parseInt(document.getElementById('rcGunKisi')?.value) || 0;
    if(!tarih){ alert('Tarih seçin.'); return; }
    if(kisi <= 0){ alert('Kişi sayısı girin.'); return; }

    var dishes = rc.getDayMenu(tarih) || [];
    if(!dishes.length){ alert('Bu tarih için menü bulunamadı.'); return; }

    var recipes = rc.getRecipes();
    var prices = rc.getPrices();
    var history = rc.getHistory();

    // Üretim gider kayıtları
    var uretimArr = rc.getUretimGider();
    var addedCount = 0;

    dishes.forEach(function(dish){
      var recipe = recipes[dish];
      if(!recipe || !recipe.length) return;

      // Reçete geçmişine kaydet
      history.push({
        tarih: tarih,
        yemek: dish,
        kisi: kisi,
        malzemeler: recipe.map(function(ing){
          return { name: ing.name, gram: ing.gram, totalKg: (ing.gram*kisi)/1000 };
        }),
        kaydedilme: new Date().toISOString()
      });

      // Üretim gider kayıtlarına ekle
      recipe.forEach(function(ing){
        var kg = (ing.gram * kisi) / 1000;
        var price = prices[ing.name] || 0;
        uretimArr.push({
          tarih: tarih,
          proje: '',
          malzeme: ing.name,
          kg: kg,
          fiyat: price,
          toplam: kg * price,
          kaynak: 'recete:' + dish
        });
        addedCount++;
      });
    });

    rc.setHistory(history);
    rc.setUretimGider(uretimArr);

    alert('Günlük üretim kaydedildi!\n' + dishes.length + ' yemek, ' + addedCount + ' malzeme satırı eklendi.\nTarih: ' + tarih + ' | Kişi: ' + kisi);
    window.rcLoadGunlukMenu();
  };

  // ── Tarih formatlama ─────────────────────────────────────────
  function _formatDate(str){
    if(!str) return '';
    var p = str.split('-');
    var gunler = ['Pazar','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi'];
    var d = new Date(parseInt(p[0]), parseInt(p[1])-1, parseInt(p[2]));
    return p[2]+'.'+p[1]+'.'+p[0]+' '+gunler[d.getDay()];
  }

})();
