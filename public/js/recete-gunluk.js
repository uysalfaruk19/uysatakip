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

    // Ortalama porsiyon katsayısı hesapla (tüm aktif müşterilerin ağırlıklı ortalaması)
    var avgKatsayi = _hesaplaOrtKatsayi();

    var html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">'
      +'<h3 style="margin:0">'+_formatDate(tarih)+' Menüsü — '+dishes.length+' kalem</h3>'
      +'<div style="display:flex;gap:8px;align-items:center">'
      +'<span style="font-size:11px;color:#7c3aed;font-weight:700">⚖️ Ort. Katsayı: ×'+avgKatsayi.toFixed(2)+'</span>'
      +'<button class="btn" onclick="rcManuelYemekEkle()" style="font-size:12px">+ Manuel Yemek Ekle</button>'
      +'</div></div>';

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

  // ── Üretim Fişi Oluştur (Hammadde Gereksinim Tablosu) ────────
  window.rcUretimFisiOlustur = function(){
    var tarih = document.getElementById('rcGunTarih')?.value;
    var kisi = parseInt(document.getElementById('rcGunKisi')?.value) || 0;
    if(!tarih){ alert('Tarih seçin.'); return; }
    if(kisi <= 0){ alert('Kişi sayısı girin.'); return; }

    var dishes = rc.getDayMenu(tarih) || [];
    if(!dishes.length){ alert('Bu tarih için menü bulunamadı.'); return; }

    var recipes = rc.getRecipes();
    var prices = rc.getPrices();
    var porsiyonKatsayi = _hesaplaOrtKatsayi();
    var musteriPorsiyon = _getMusteriPorsiyonDetay();

    // Hammadde gereksinim tablosu — tüm reçetelerden konsolide et
    var ihtiyac = {};
    var yemekDetay = [];

    dishes.forEach(function(dish){
      var recipe = recipes[dish];
      if(!recipe || !recipe.length) return;
      var yemekMaliyet = 0;
      recipe.forEach(function(ing){
        var kg = (ing.gram * kisi * porsiyonKatsayi) / 1000;
        var price = prices[ing.name] || 0;
        var cost = kg * price;
        yemekMaliyet += cost;
        if(!ihtiyac[ing.name]) ihtiyac[ing.name] = { kg:0, maliyet:0, yemekler:[] };
        ihtiyac[ing.name].kg += kg;
        ihtiyac[ing.name].maliyet += cost;
        if(ihtiyac[ing.name].yemekler.indexOf(dish)<0) ihtiyac[ing.name].yemekler.push(dish);
      });
      yemekDetay.push({ dish:dish, maliyet:yemekMaliyet, receteVar:true });
    });

    // Aktif depo stok kontrolü
    var _ls = { get:function(k,d){ try{var v=localStorage.getItem(k);return v?JSON.parse(v):d;}catch(e){return d;}} };
    var aktifDepo = _ls.get('uysa_aktif_depo','');
    var depoStok = aktifDepo ? _ls.get('uysa_stok_'+aktifDepo,{}) : {};

    // Stok karşılaştırma
    var eksikler = [], yeterliler = [];
    var topMaliyet = 0;
    Object.entries(ihtiyac).forEach(function(e){
      var mal = e[0], v = e[1];
      var mevcut = depoStok[mal] ? (depoStok[mal].miktar||0) : 0;
      var fark = mevcut - v.kg;
      topMaliyet += v.maliyet;
      var item = { mal:mal, gerekli:v.kg, mevcut:mevcut, fark:fark, maliyet:v.maliyet, yemekler:v.yemekler };
      if(fark < 0) eksikler.push(item);
      else yeterliler.push(item);
    });

    // Üretim fişi HTML göster
    var div = document.getElementById('rcGunMenuDiv');
    var html = '<div class="panel" style="border:3px solid #1e40af;background:linear-gradient(135deg,#eff6ff,#dbeafe)">';
    html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">';
    html += '<h3 style="margin:0;color:#1e40af">📋 Üretim Fişi — '+_formatDate(tarih)+'</h3>';
    html += '<div style="font-size:12px;color:#64748b">Fiş No: UF-'+tarih.replace(/-/g,'')+' | '+kisi+' kişi | Katsayı: ×'+porsiyonKatsayi.toFixed(2)+'</div>';
    html += '</div>';

    // Müşteri porsiyon detay
    if(musteriPorsiyon.length>0){
      html += '<div style="background:#faf5ff;border:1px solid #d8b4fe;border-radius:8px;padding:10px;margin-bottom:12px;font-size:12px">';
      html += '<b style="color:#7c3aed">⚖️ Müşteri Porsiyon Katsayıları:</b> ';
      html += musteriPorsiyon.map(function(mp){ return mp.musteri+' ('+mp.kisi+' kişi × '+mp.katsayi.toFixed(2)+')'; }).join(' · ');
      html += ' → <b>Ağırlıklı ort: ×'+porsiyonKatsayi.toFixed(2)+'</b></div>';
    }

    // Özet kartlar
    html += '<div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-bottom:14px">';
    html += '<div style="text-align:center;padding:10px;background:#fff;border-radius:8px;border:1px solid #e5e7eb"><div style="font-size:11px;color:#64748b">Toplam Yemek</div><div style="font-size:20px;font-weight:900;color:#1e40af">'+dishes.length+'</div></div>';
    html += '<div style="text-align:center;padding:10px;background:#fff;border-radius:8px;border:1px solid #e5e7eb"><div style="font-size:11px;color:#64748b">Malzeme Çeşidi</div><div style="font-size:20px;font-weight:900;color:#7c3aed">'+Object.keys(ihtiyac).length+'</div></div>';
    html += '<div style="text-align:center;padding:10px;background:#fff;border-radius:8px;border:1px solid #e5e7eb"><div style="font-size:11px;color:#64748b">Toplam Maliyet</div><div style="font-size:20px;font-weight:900;color:#dc2626">'+rc.fmtTL(topMaliyet)+'</div></div>';
    html += '<div style="text-align:center;padding:10px;background:'+(eksikler.length>0?'#fef2f2':'#f0fdf4')+';border-radius:8px;border:1px solid '+(eksikler.length>0?'#fca5a5':'#86efac')+'"><div style="font-size:11px;color:#64748b">Stok Durumu</div><div style="font-size:20px;font-weight:900;color:'+(eksikler.length>0?'#dc2626':'#16a34a')+'">'+(eksikler.length>0?eksikler.length+' eksik':'✅ Tamam')+'</div></div>';
    html += '</div>';

    // Hammadde Gereksinim Tablosu
    html += '<h4 style="margin:8px 0 4px;color:#1e40af">📦 Hammadde Gereksinim Tablosu</h4>';
    html += '<table style="width:100%;border-collapse:collapse;font-size:12px;background:#fff;border-radius:8px;overflow:hidden">';
    html += '<thead><tr style="background:#1e40af;color:#fff"><th style="padding:6px;text-align:left">Malzeme</th><th style="padding:6px;text-align:right">Gerekli (kg)</th><th style="padding:6px;text-align:right">Depoda</th><th style="padding:6px;text-align:right">Fark</th><th style="padding:6px;text-align:right">Maliyet</th><th style="padding:6px;text-align:left">Kullanıldığı Yemekler</th></tr></thead>';
    html += '<tbody>';

    // Önce eksikler
    var allItems = eksikler.concat(yeterliler);
    allItems.forEach(function(item){
      var bg = item.fark<0 ? '#fef2f2' : '';
      html += '<tr style="border-bottom:1px solid #f1f5f9;background:'+bg+'">';
      html += '<td style="padding:4px 6px;font-weight:700">'+item.mal+'</td>';
      html += '<td style="padding:4px 6px;text-align:right">'+rc.fmt(item.gerekli,2)+'</td>';
      html += '<td style="padding:4px 6px;text-align:right;color:'+(aktifDepo?'inherit':'#d97706')+'">'+( aktifDepo ? rc.fmt(item.mevcut,2) : '—' )+'</td>';
      html += '<td style="padding:4px 6px;text-align:right;font-weight:700;color:'+(item.fark<0?'#dc2626':'#16a34a')+'">'+( aktifDepo ? (item.fark>=0?'+':'')+rc.fmt(item.fark,2) : '—' )+'</td>';
      html += '<td style="padding:4px 6px;text-align:right">'+rc.fmtTL(item.maliyet)+'</td>';
      html += '<td style="padding:4px 6px;font-size:11px;color:#64748b">'+item.yemekler.join(', ')+'</td>';
      html += '</tr>';
    });
    html += '</tbody></table>';

    if(!aktifDepo){
      html += '<div style="margin-top:8px;padding:8px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:12px;color:#92400e">⚠️ Aktif depo seçili değil. Stok kontrolü yapılamadı. Depo modülünden aktif depo seçin.</div>';
    }

    if(eksikler.length > 0 && aktifDepo){
      html += '<div style="margin-top:8px;padding:8px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;font-size:12px;color:#991b1b">🔴 <b>'+eksikler.length+' malzeme depoda yetersiz!</b> Üretimi onaylarsanız stok eksi bakiyeye düşecektir. Önce tedarik önerilir.</div>';
    }

    // Butonlar
    html += '<div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">';
    html += '<button class="btn success" onclick="rcSaveGunlukUretim()" style="font-size:14px;padding:10px 24px">✅ Üretimi Onayla & Stoktan Düş</button>';
    html += '<button class="btn" onclick="rcLoadGunlukMenu()" style="font-size:14px;padding:10px 24px">← Menüye Dön</button>';
    html += '<button class="btn" onclick="rcUretimFisiYazdir()" style="font-size:14px;padding:10px 24px">🖨️ Yazdır</button>';
    html += '</div>';
    html += '</div>';

    div.innerHTML = html;
  };

  // ── Üretim Fişi Yazdır ──────────────────────────────────────
  window.rcUretimFisiYazdir = function(){
    var content = document.getElementById('rcGunMenuDiv')?.innerHTML || '';
    var w = window.open('','_blank','width=900,height=700');
    w.document.write('<!DOCTYPE html><html><head><title>Üretim Fişi</title><style>body{font-family:Arial,sans-serif;padding:20px;font-size:12px}table{width:100%;border-collapse:collapse}th,td{padding:4px 6px;border:1px solid #ccc;text-align:left}th{background:#1e40af;color:#fff}.btn{display:none}@media print{.btn{display:none}}</style></head><body>'+content+'</body></html>');
    w.document.close();
    setTimeout(function(){ w.print(); }, 500);
  };

  // ── Günlük üretimi kaydet (stoktan düş) ─────────────────────
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

    // Stok düşme için konsolide hammadde
    var konsolide = {};

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
        // Konsolide
        if(!konsolide[ing.name]) konsolide[ing.name] = 0;
        konsolide[ing.name] += kg;
      });
    });

    rc.setHistory(history);
    rc.setUretimGider(uretimArr);

    // Aktif depodan stok düş
    var _ls2 = {
      get:function(k,d){ try{var v=localStorage.getItem(k);return v?JSON.parse(v):d;}catch(e){return d;} },
      set:function(k,v){ try{localStorage.setItem(k,JSON.stringify(v));}catch(e){} }
    };
    var aktifDepo = _ls2.get('uysa_aktif_depo','');
    var dusCount = 0;
    if(aktifDepo){
      var depoStok = _ls2.get('uysa_stok_'+aktifDepo,{});
      Object.entries(konsolide).forEach(function(e){
        var mal = e[0], kg = e[1];
        if(depoStok[mal]){
          depoStok[mal].miktar = Math.max(0, (depoStok[mal].miktar||0) - kg);
          dusCount++;
        }
      });
      _ls2.set('uysa_stok_'+aktifDepo, depoStok);

      // Hareket logları
      var hareketler = _ls2.get('uysa_stok_hareketler',[]);
      Object.entries(konsolide).forEach(function(e){
        hareketler.push({
          tip: 'uretim',
          malzeme: e[0],
          miktar: e[1],
          depo: aktifDepo,
          aciklama: 'Üretim fişi: '+tarih+' ('+kisi+' kişi)',
          tarih: tarih,
          saat: new Date().toLocaleTimeString('tr-TR',{hour:'2-digit',minute:'2-digit'}),
          id: Date.now()+Math.random()
        });
      });
      _ls2.set('uysa_stok_hareketler', hareketler);
    }

    // Üretim fişi geçmişine kaydet
    var uretimFisleri = _ls2.get('uysa_uretim_fisleri',[]);
    uretimFisleri.push({
      fisNo: 'UF-'+tarih.replace(/-/g,''),
      tarih: tarih,
      kisi: kisi,
      yemekler: dishes,
      malzemeler: konsolide,
      toplamMaliyet: Object.entries(konsolide).reduce(function(s,e){ return s + e[1]*(prices[e[0]]||0); },0),
      depo: aktifDepo||'—',
      kaydedilme: new Date().toISOString()
    });
    _ls2.set('uysa_uretim_fisleri', uretimFisleri);

    var msg = '✅ Üretim fişi kaydedildi!\n\n'
      + '📋 Fiş No: UF-'+tarih.replace(/-/g,'')+'\n'
      + '📅 Tarih: '+tarih+'\n'
      + '👥 Kişi: '+kisi+'\n'
      + '🍽️ '+dishes.length+' yemek, '+addedCount+' malzeme satırı\n';
    if(aktifDepo) msg += '📦 '+dusCount+' kalem stoktan düşüldü ('+aktifDepo+')';
    else msg += '⚠️ Aktif depo seçili değil, stok düşülmedi.';

    alert(msg);
    window.rcLoadGunlukMenu();
  };

  // ── Porsiyon katsayısı hesapla ─────────────────────────────────
  function _hesaplaOrtKatsayi(){
    // Tüm müşterilerin CRM verisinden porsiyon katsayısını al, kişi sayısıyla ağırlıklı ortalama
    try {
      var _ls2 = { get:function(k,d){ try{var v=localStorage.getItem(k);return v?JSON.parse(v):d;}catch(e){return d;}} };
      var custRaw = _ls2.get('uysa_customers_v1',{});
      var custs = (custRaw.customers||[]).filter(function(c){ return c && c!=='GENEL'; });
      var topKisi=0, topAgirlik=0;
      custs.forEach(function(c){
        var crm = _ls2.get('uysa_crm_'+c,{});
        var kisi = parseInt(crm.kisi)||0;
        var katsayi = parseFloat(crm.porsiyonKatsayi)||1.0;
        if(kisi>0){ topKisi+=kisi; topAgirlik+=kisi*katsayi; }
      });
      return topKisi>0 ? topAgirlik/topKisi : 1.0;
    } catch(e){ return 1.0; }
  }

  // Müşteri bazlı porsiyon detayları
  function _getMusteriPorsiyonDetay(){
    try {
      var _ls2 = { get:function(k,d){ try{var v=localStorage.getItem(k);return v?JSON.parse(v):d;}catch(e){return d;}} };
      var custRaw = _ls2.get('uysa_customers_v1',{});
      var custs = (custRaw.customers||[]).filter(function(c){ return c && c!=='GENEL'; });
      var result = [];
      custs.forEach(function(c){
        var crm = _ls2.get('uysa_crm_'+c,{});
        var kisi = parseInt(crm.kisi)||0;
        var katsayi = parseFloat(crm.porsiyonKatsayi)||1.0;
        var tip = crm.porsiyonTip||'standart';
        if(kisi>0) result.push({ musteri:c, kisi:kisi, katsayi:katsayi, tip:tip });
      });
      return result;
    } catch(e){ return []; }
  }

  // ── Tarih formatlama ─────────────────────────────────────────
  function _formatDate(str){
    if(!str) return '';
    var p = str.split('-');
    var gunler = ['Pazar','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi'];
    var d = new Date(parseInt(p[0]), parseInt(p[1])-1, parseInt(p[2]));
    return p[2]+'.'+p[1]+'.'+p[0]+' '+gunler[d.getDay()];
  }

})();
