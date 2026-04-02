// ═══════════════════════════════════════════════════════════════
// REÇETE & MALİYET MODÜLÜ — Günlük Üretim (Tüketim Bazlı)
// ═══════════════════════════════════════════════════════════════

(function(){
  'use strict';
  var rc = window._rc;

  // ── localStorage helper ─────────────────────────────────────
  var _ls2 = {
    get: function(k,d){ try{ var v=localStorage.getItem(k); return v?JSON.parse(v):d; }catch(e){ return d; } },
    set: function(k,v){ try{ localStorage.setItem(k,JSON.stringify(v)); }catch(e){} }
  };

  // ── Yemek adı normalleştirme (trim + Turkish-aware lookup) ──
  function _findTuketimKey(dishName){
    var st = window._rcGunState;
    if(!dishName) return null;
    // Direkt eşleşme
    if(st.tuketimData[dishName] && st.tuketimData[dishName].length > 0) return dishName;
    // Trim dene
    var trimmed = dishName.trim();
    if(st.tuketimData[trimmed] && st.tuketimData[trimmed].length > 0) return trimmed;
    // Case-insensitive + Turkish locale
    var lower = trimmed.toLocaleLowerCase('tr');
    var keys = Object.keys(st.tuketimData);
    for(var i=0; i<keys.length; i++){
      if(keys[i].toLocaleLowerCase('tr') === lower && st.tuketimData[keys[i]].length > 0) return keys[i];
    }
    return null;
  }

  // ── Modül State ─────────────────────────────────────────────
  window._rcGunState = {
    selectedDish: null,
    activeTab: 'tuketim',       // 'tuketim' veya 'recete'
    tuketimData: {},             // { yemekAdi: [{name:'Tavuk', kg:12.5}, ...] }
    dishes: [],                  // o günün yemek listesi
    aralikMode: false
  };

  // ── En iyi fiyat: fatura → prices → 0 ──────────────────────
  function _getBestPrice(malzemeAdi){
    if(!malzemeAdi) return 0;
    var best = 0;
    // 1) Faturalardan en yüksek birimFiyat
    try {
      var faturalar = _ls2.get('uysa_faturalar',[]);
      var lower = malzemeAdi.toLowerCase().trim();
      faturalar.forEach(function(f){
        if(f && f.urun && f.urun.toLowerCase().trim() === lower){
          var p = parseFloat(f.birimFiyat)||0;
          if(p > best) best = p;
        }
      });
    } catch(e){}
    if(best > 0) return best;
    // 2) Fiyat tablosundan
    var prices = rc.getPrices();
    if(prices[malzemeAdi]) return parseFloat(prices[malzemeAdi])||0;
    // 3) 0
    return 0;
  }

  // ── Tarih formatlama ────────────────────────────────────────
  function _formatDate(str){
    if(!str) return '';
    var p = str.split('-');
    var gunler = ['Pazar','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi'];
    var d = new Date(parseInt(p[0]), parseInt(p[1])-1, parseInt(p[2]));
    return p[2]+'.'+p[1]+'.'+p[0]+' '+gunler[d.getDay()];
  }

  // ── Seçili müşteri listesi ──────────────────────────────────
  function _getSelectedCustomers(){
    var cbs = document.querySelectorAll('#rcGunCustList input[type=checkbox]');
    var sel = [];
    cbs.forEach(function(cb){ if(cb.checked) sel.push(cb.value); });
    return sel;
  }

  // ── Seçili müşterilerden otomatik kişi hesapla ──────────────
  function _calcAutoKisi(){
    var sel = _getSelectedCustomers();
    var total = 0;
    sel.forEach(function(c){
      var crm = _ls2.get('uysa_crm_'+c, {});
      total += parseInt(crm.kisi)||0;
    });
    var inp = document.getElementById('rcGunKisi');
    var lbl = document.getElementById('rcOtomatikKisi');
    if(total > 0){
      if(inp) inp.value = total;
      if(lbl) lbl.textContent = total + ' kişi';
    } else {
      if(lbl) lbl.textContent = '—';
    }
  }

  // ── Müşteri checkbox listesi render ─────────────────────────
  window.rcRenderGunCustList = function(){
    var div = document.getElementById('rcGunCustList');
    if(!div) return;
    var custRaw = _ls2.get('uysa_customers_v1', {});
    var custs = (custRaw.customers||[]).filter(function(c){ return c && c!=='GENEL'; });
    if(!custs.length){
      div.innerHTML = '<span style="color:#94a3b8;font-size:12px">Müşteri bulunamadı. CRM modülünden müşteri ekleyin.</span>';
      return;
    }
    var html = '';
    custs.forEach(function(c){
      var crm = _ls2.get('uysa_crm_'+c, {});
      var kisi = parseInt(crm.kisi)||0;
      html += '<label style="display:inline-flex;align-items:center;gap:4px;padding:5px 10px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;cursor:pointer;user-select:none">'
        +'<input type="checkbox" value="'+c+'" onchange="window._rcCustChanged()" style="margin:0"/>'
        +'<span style="font-weight:600">'+c+'</span>'
        +(kisi>0?'<span style="color:#64748b;font-size:10px">('+kisi+')</span>':'')
        +'</label>';
    });
    div.innerHTML = html;
  };

  // Müşteri checkbox değişince — kişi + menü yeniden yükle
  window._rcCustChanged = function(){
    _calcAutoKisi();
    _loadMenuImpl();
  };

  // Tümünü seç/kaldır
  window.rcGunToggleAllCust = function(){
    var cbs = document.querySelectorAll('#rcGunCustList input[type=checkbox]');
    var allChecked = true;
    cbs.forEach(function(cb){ if(!cb.checked) allChecked = false; });
    cbs.forEach(function(cb){ cb.checked = !allChecked; });
    _calcAutoKisi();
    _loadMenuImpl();
  };

  // Tarih aralığı toggle
  window.rcToggleAralik = function(){
    var cb = document.getElementById('rcGunAralikToggle');
    var wrap = document.getElementById('rcGunAralikWrap');
    var tek = document.getElementById('rcGunTarih');
    if(!cb || !wrap) return;
    window._rcGunState.aralikMode = cb.checked;
    wrap.style.display = cb.checked ? 'inline' : 'none';
    if(tek) tek.style.display = cb.checked ? 'none' : '';
  };

  // ── Recalc (kişi sayısı değişince) ──────────────────────────
  window.rcRecalcGunluk = function(){
    // Maliyet barını güncelle
    if(typeof window.rcUpdateMaliyetBar === 'function') window.rcUpdateMaliyetBar();
    // Sağ paneli güncelle (reçete sekmesinde gram/kişi değişir)
    var st = window._rcGunState;
    if(st.selectedDish && typeof window.rcRenderDishDetail === 'function'){
      window.rcRenderDishDetail(st.selectedDish);
    }
  };

  // ── Sayfa açılınca müşteri listesini render et ──────────────
  // switchReceteTab('gunluk') çağrıldığında rcLoadGunlukMenu çalışır
  // Ama önce müşteri listesini render etmeliyiz
  var _custRendered = false;
  var _origLoad = window.rcLoadGunlukMenu;
  window.rcLoadGunlukMenu = function(){
    if(!_custRendered){
      window.rcRenderGunCustList();
      _custRendered = true;
    }
    // Push 2'de gerçek menü yükleme yazılacak
    _loadMenuImpl();
  };

  // ── Menü yükleme (sol/sağ split) ─────────────────────────────
  function _loadMenuImpl(){
    var tarihEl = document.getElementById('rcGunTarih');
    if(tarihEl && !tarihEl.value) tarihEl.value = new Date().toISOString().slice(0,10);
    var tarih = tarihEl ? tarihEl.value : '';
    var div = document.getElementById('rcGunMenuDiv');
    if(!div || !tarih) return;

    // Mevcut kayıt varsa tüketim verisini yükle
    _loadExistingTuketim(tarih);

    // Hafta indeksi hesapla (recete-core.js ile aynı mantık)
    function _calcWeekIdx(dateStr){
      var p = dateStr.split('-');
      var y = parseInt(p[0]), m1 = parseInt(p[1]), d = parseInt(p[2]);
      var dt2 = new Date(y, m1-1, d);
      // Pazartesi bazlı hafta başlangıçları
      var first = new Date(y, m1-1, 1);
      var shift = (first.getDay()+6)%7;
      var mon = new Date(first); mon.setDate(1-shift);
      for(var i=0;i<6;i++){
        var wStart = new Date(mon); wStart.setDate(mon.getDate()+7*i);
        var wEnd = new Date(wStart); wEnd.setDate(wStart.getDate()+6);
        if(dt2>=wStart && dt2<=wEnd) return i+1;
      }
      return 1;
    }

    // Seçili müşterilerden menü yemeklerini topla (müşteri bazlı)
    var sel = _getSelectedCustomers();
    var dishes = [];
    var dishCustomerMap = {}; // {yemekAdı: [müşteri1, müşteri2]}
    var menuGrid = _ls2.get('uysa_menu_grid_v2_dates',{});
    var dt = new Date(tarih+'T00:00:00');
    var dow = (dt.getDay()+6)%7;
    var wIdx = 'W'+_calcWeekIdx(tarih);
    // Key format: "YYYY-M::CUST" veya "YYYY-MM::CUST" (her ikisini dene)
    var yy = dt.getFullYear();
    var mm = dt.getMonth()+1;
    var ymPadded = yy+'-'+(mm<10?'0':'')+mm;   // 2026-03
    var ymNoPad  = yy+'-'+mm;                    // 2026-3

    var custList = sel.length > 0 ? sel : ['GENEL'];
    custList.forEach(function(cust){
      var grid = menuGrid[ymPadded+'::'+cust] || menuGrid[ymNoPad+'::'+cust];
      if(!grid || !grid.weeks || !grid.weeks[wIdx]) return;
      var w = grid.weeks[wIdx];
      ['soups','soups2','mains','mains2','sides','sides2','salads'].forEach(function(cat){
        var arr = w[cat]; if(!arr) return;
        var v = arr[dow]; if(!v || !v.trim()) return;
        v = v.trim();
        if(dishes.indexOf(v)<0) dishes.push(v);
        if(!dishCustomerMap[v]) dishCustomerMap[v] = [];
        if(dishCustomerMap[v].indexOf(cust)<0) dishCustomerMap[v].push(cust);
      });
      // dessert/ayran
      ['dessertFruitName','ayran'].forEach(function(cat){
        var arr = w[cat]; if(!arr) return;
        var v = arr[dow]; if(!v || !v.trim()) return;
        v = v.trim();
        if(dishes.indexOf(v)<0) dishes.push(v);
        if(!dishCustomerMap[v]) dishCustomerMap[v] = [];
        if(dishCustomerMap[v].indexOf(cust)<0) dishCustomerMap[v].push(cust);
      });
    });
    // Hafta sonu ise en yakın Cuma'nın menüsünü öner
    var isWeekend = (dow === 5 || dow === 6); // 5=Cmt, 6=Paz
    if(!dishes.length && isWeekend){
      // Cuma'yı dene (dow=4)
      custList.forEach(function(cust){
        var grid = menuGrid[ymPadded+'::'+cust] || menuGrid[ymNoPad+'::'+cust];
        if(!grid || !grid.weeks || !grid.weeks[wIdx]) return;
        var w = grid.weeks[wIdx];
        ['soups','soups2','mains','mains2','sides','sides2','salads','dessertFruitName','ayran'].forEach(function(cat){
          var arr = w[cat]; if(!arr) return;
          var v = arr[4]; // Cuma=4
          if(!v || !v.trim()) return;
          v = v.trim();
          if(dishes.indexOf(v)<0) dishes.push(v);
          if(!dishCustomerMap[v]) dishCustomerMap[v] = [];
          if(dishCustomerMap[v].indexOf(cust)<0) dishCustomerMap[v].push(cust);
        });
      });
    }
    // Fallback: GENEL
    if(!dishes.length && sel.length > 0){
      var fallback = rc.getDayMenu(tarih);
      if(fallback) dishes = fallback;
    }
    var st = window._rcGunState;
    st.dishes = dishes;

    // Tüketim verisinde olup menüde olmayan yemekleri de listeye ekle
    // Normalized comparison ile duplicate önle (casing/trim farkları)
    Object.keys(st.tuketimData).forEach(function(d){
      if(!st.tuketimData[d] || !st.tuketimData[d].length) return;
      var dLower = d.toLocaleLowerCase('tr');
      var found = st.dishes.some(function(existing){
        return existing.toLocaleLowerCase('tr') === dLower;
      });
      if(!found) st.dishes.push(d);
    });

    // Menü bulunamasa bile sol/sağ paneli göster (manuel yemek eklenebilsin)

    // Sol-sağ split render
    var html = '<div style="display:flex;gap:12px;min-height:400px">';
    // SOL — Yemek listesi
    html += '<div style="flex:0 0 220px;border-right:1px solid #e2e8f0;padding-right:12px">';
    html += '<h4 style="margin:0 0 8px;font-size:13px;color:#1e40af">'+_formatDate(tarih)+' ('+(dishes.length||0)+' kalem)</h4>';
    if(isWeekend && dishes.length){
      html += '<div style="color:#1e40af;font-size:11px;padding:8px;background:#eff6ff;border-radius:6px;margin-bottom:8px">Hafta sonu - Cuma menusu gosteriliyor.</div>';
    }
    if(!dishes.length){
      html += '<div style="color:#d97706;font-size:11px;padding:10px;background:#fffbeb;border-radius:6px;margin-bottom:8px">'+(isWeekend?'Hafta sonu menüsü yok. ':'')+'Manuel yemek ekleyebilirsiniz.</div>';
    }
    dishes.forEach(function(d){
      var hasTuk = !!_findTuketimKey(d);
      var sel2 = (st.selectedDish === d);
      var custInfo = dishCustomerMap[d] ? dishCustomerMap[d].join(', ') : '';
      html += '<div onclick="rcSelectDish(\''+d.replace(/'/g,"\\'")+'\')" style="padding:8px 10px;margin-bottom:4px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;'
        +(sel2?'background:#1e40af;color:#fff;':'background:#f8fafc;border:1px solid #e2e8f0;')
        +'">'+(hasTuk?'<span style="color:'+(sel2?'#bbf7d0':'#16a34a')+'">&#10003;</span> ':'')+d
        +(custInfo?'<div style="font-size:9px;font-weight:400;color:'+(sel2?'#93c5fd':'#94a3b8')+';margin-top:2px">'+custInfo+'</div>':'')
        +'</div>';
    });
    html += '<button class="btn" onclick="rcManuelYemekEkle()" style="width:100%;margin-top:8px;font-size:11px">+ Manuel Yemek Ekle</button>';
    html += '</div>';
    // SAĞ — Detay paneli
    html += '<div id="rcGunDetailPanel" style="flex:1;min-width:0">';
    if(st.selectedDish){
      html += _renderDishDetail(st.selectedDish);
    } else {
      html += '<div style="color:#94a3b8;text-align:center;padding:60px">Soldaki listeden bir yemek seçin.</div>';
    }
    html += '</div></div>';
    div.innerHTML = html;
    _updateMaliyetBar();
  }

  // ── Yemek seç ──────────────────────────────────────────────
  window.rcSelectDish = function(name){
    window._rcGunState.selectedDish = name;
    _loadMenuImpl();
  };

  // ── Manuel yemek ekle ──────────────────────────────────────
  window.rcManuelYemekEkle = function(){
    var name = prompt('Yemek adi:');
    if(!name || !name.trim()) return;
    name = name.trim();
    var st = window._rcGunState;
    if(st.dishes.indexOf(name)<0) st.dishes.push(name);
    st.selectedDish = name;
    _loadMenuImpl();
  };

  // ── Sağ panel — Tüketim / Reçete detay ─────────────────────
  function _renderDishDetail(dishName){
    var st = window._rcGunState;
    var tab = st.activeTab || 'tuketim';
    var kisi = parseInt(document.getElementById('rcGunKisi')?.value)||100;
    // Normalized key lookup — handles trim/casing/Turkish char differences
    var tukKey = _findTuketimKey(dishName);
    var rows = tukKey ? st.tuketimData[tukKey] : [];

    var h = '<div style="margin-bottom:10px;display:flex;gap:4px">';
    h += '<button class="btn" onclick="rcSwitchDishTab(\'tuketim\')" style="font-size:12px;padding:6px 14px;'+(tab==='tuketim'?'background:#1e40af;color:#fff':'background:#f1f5f9')+'">Tuketim</button>';
    h += '<button class="btn" onclick="rcSwitchDishTab(\'recete\')" style="font-size:12px;padding:6px 14px;'+(tab==='recete'?'background:#7c3aed;color:#fff':'background:#f1f5f9')+'">Recete (g/kisi)</button>';
    h += '</div>';
    h += '<h4 style="margin:0 0 10px;font-size:15px">'+dishName+'</h4>';

    if(tab === 'tuketim'){
      // Giriş formu
      h += '<div style="display:flex;gap:6px;margin-bottom:10px">';
      h += '<input id="rcTukMalzeme" placeholder="Malzeme adi" style="flex:1;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:12px"/>';
      h += '<input id="rcTukKg" type="number" step="0.1" min="0" placeholder="KG" style="width:80px;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:12px"/>';
      h += '<button class="btn" onclick="rcAddTuketimRow(\''+dishName.replace(/'/g,"\\'")+'\')" style="font-size:12px;padding:6px 12px;background:#16a34a;color:#fff">+ Ekle</button>';
      h += '</div>';
      // Tablo
      if(rows.length > 0){
        h += '<table style="width:100%;border-collapse:collapse;font-size:12px">';
        h += '<tr style="background:#f1f5f9"><th style="padding:4px 6px;text-align:left">Malzeme</th><th style="padding:4px 6px;text-align:right">KG</th><th style="padding:4px 6px;width:30px"></th></tr>';
        rows.forEach(function(r, i){
          h += '<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:4px 6px">'+r.name+'</td>';
          h += '<td style="padding:4px 6px;text-align:right;font-weight:700">'+rc.fmt(r.kg,2)+'</td>';
          h += '<td style="padding:4px 6px;text-align:center"><button onclick="rcDelTuketimRow(\''+dishName.replace(/'/g,"\\'")+'\','+i+')" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:14px">&#10005;</button></td></tr>';
        });
        h += '</table>';
      } else {
        h += '<div style="color:#94a3b8;font-size:12px;padding:20px;text-align:center">Henuz tuketim girisi yapilmadi.</div>';
      }
    } else {
      // Reçete sekmesi — gram/kişi hesabı
      if(rows.length > 0){
        var topMaliyet = 0;
        h += '<table style="width:100%;border-collapse:collapse;font-size:12px">';
        h += '<tr style="background:#f5f3ff"><th style="padding:4px 6px;text-align:left">Malzeme</th><th style="padding:4px 6px;text-align:right">Toplam KG</th><th style="padding:4px 6px;text-align:right">g/kisi</th><th style="padding:4px 6px;text-align:right">Birim TL</th><th style="padding:4px 6px;text-align:right">Maliyet</th></tr>';
        rows.forEach(function(r){
          var gpk = kisi > 0 ? (r.kg * 1000 / kisi) : 0;
          var price = _getBestPrice(r.name);
          var cost = r.kg * price;
          topMaliyet += cost;
          h += '<tr style="border-bottom:1px solid #f1f5f9">';
          h += '<td style="padding:4px 6px">'+r.name+'</td>';
          h += '<td style="padding:4px 6px;text-align:right">'+rc.fmt(r.kg,2)+'</td>';
          h += '<td style="padding:4px 6px;text-align:right;font-weight:700;color:#7c3aed">'+rc.fmt(gpk,1)+'</td>';
          h += '<td style="padding:4px 6px;text-align:right">'+(price>0?rc.fmtTL(price):'<span style="color:#d97706">?</span>')+'</td>';
          h += '<td style="padding:4px 6px;text-align:right;font-weight:700;color:#dc2626">'+rc.fmtTL(cost)+'</td>';
          h += '</tr>';
        });
        h += '<tr style="background:#ecfdf5;font-weight:800"><td colspan="4" style="padding:6px">Toplam</td><td style="padding:6px;text-align:right;color:#166534">'+rc.fmtTL(topMaliyet)+'</td></tr>';
        if(kisi>0) h += '<tr style="background:#f0fdf4"><td colspan="4" style="padding:6px;font-size:11px;color:#64748b">Kisi basi</td><td style="padding:6px;text-align:right;font-weight:700;color:#166534;font-size:11px">'+rc.fmtTL(topMaliyet/kisi)+'</td></tr>';
        h += '</table>';
      } else {
        h += '<div style="color:#94a3b8;font-size:12px;padding:20px;text-align:center">Tuketim verisi girilmeden recete hesabi yapilamaz.</div>';
      }
    }
    return h;
  }

  window.rcSwitchDishTab = function(tab){
    window._rcGunState.activeTab = tab;
    var panel = document.getElementById('rcGunDetailPanel');
    if(panel && window._rcGunState.selectedDish){
      panel.innerHTML = _renderDishDetail(window._rcGunState.selectedDish);
    }
  };

  // ── Tüketim satır ekle/sil ─────────────────────────────────
  window.rcAddTuketimRow = function(dishName){
    var mEl = document.getElementById('rcTukMalzeme');
    var kEl = document.getElementById('rcTukKg');
    if(!mEl || !kEl) return;
    var name = mEl.value.trim();
    var kg = parseFloat(kEl.value)||0;
    if(!name){ alert('Malzeme adi girin.'); return; }
    if(kg <= 0){ alert('KG girin.'); return; }
    var st = window._rcGunState;
    // Mevcut key'i bul (casing/trim farkı olabilir)
    var tukKey = _findTuketimKey(dishName) || dishName;
    if(!st.tuketimData[tukKey]) st.tuketimData[tukKey] = [];
    st.tuketimData[tukKey].push({name:name, kg:kg});
    mEl.value = ''; kEl.value = '';
    _loadMenuImpl();
  };

  window.rcDelTuketimRow = function(dishName, idx){
    var st = window._rcGunState;
    var tukKey = _findTuketimKey(dishName) || dishName;
    if(st.tuketimData[tukKey]) st.tuketimData[tukKey].splice(idx,1);
    _loadMenuImpl();
  };

  // ── Maliyet bar güncelle ────────────────────────────────────
  function _updateMaliyetBar(){
    var bar = document.getElementById('rcGunMaliyetBar');
    if(!bar) return;
    var st = window._rcGunState;
    var kisi = parseInt(document.getElementById('rcGunKisi')?.value)||0;
    var topMaliyet = 0;
    Object.keys(st.tuketimData).forEach(function(dish){
      (st.tuketimData[dish]||[]).forEach(function(r){
        topMaliyet += r.kg * _getBestPrice(r.name);
      });
    });
    if(topMaliyet > 0){
      bar.style.display = 'flex';
      bar.innerHTML = '<div>Gunluk Maliyet: <b>'+rc.fmtTL(topMaliyet)+'</b></div>'
        +'<div>Kisi Basi: <b>'+(kisi>0?rc.fmtTL(topMaliyet/kisi):'—')+'</b></div>'
        +'<div>Toplam Kisi: <b>'+kisi+'</b></div>';
    } else {
      bar.style.display = 'none';
    }
  }
  window.rcUpdateMaliyetBar = _updateMaliyetBar;

  // ── Mevcut kayıtları yükle ──────────────────────────────────
  function _loadExistingTuketim(tarih){
    var st = window._rcGunState;
    // Her tarih değişiminde tuketimData'yı sıfırla ve yeniden yükle
    if(st._loadedTarih === tarih) return; // zaten yüklü
    st._loadedTarih = tarih;
    st.tuketimData = {}; // ÖNCEKİ VERİYİ TEMİZLE
    st.selectedDish = null; // Tarih değişince seçimi sıfırla

    var gunlukArr = _ls2.get('uysa_gunluk_uretim',[]);
    // Aynı tarihli kayıtları tarihe göre filtrele, en son kaydedileni kullan
    var matchingRecords = [];
    for(var i=0; i<gunlukArr.length; i++){
      var g = gunlukArr[i];
      if(g.tarih === tarih && g.tuketim && typeof g.tuketim === 'object' && !Array.isArray(g.tuketim)){
        matchingRecords.push(g);
      }
    }
    if(!matchingRecords.length){
      console.log('[Günlük] '+tarih+': tuketim kaydı bulunamadı (toplam '+gunlukArr.length+' kayıt, '+gunlukArr.filter(function(x){return x.tarih===tarih}).length+' aynı tarih)');
      return;
    }
    // En son kaydedileni kullan (kaydedilme timestamp'ına göre veya dizideki son)
    var latest = matchingRecords[matchingRecords.length - 1];
    Object.keys(latest.tuketim).forEach(function(k){
      var arr = latest.tuketim[k];
      if(Array.isArray(arr) && arr.length > 0){
        st.tuketimData[k] = arr.map(function(item){
          return {name: item.name||'', kg: parseFloat(item.kg)||0};
        });
      }
    });
    console.log('[Günlük] '+tarih+': tuketim yüklendi — '+Object.keys(st.tuketimData).length+' yemek:', Object.keys(st.tuketimData));
    // Kişi sayısını da yükle (kayıttaki değeri input'a yaz)
    if(latest.kisi > 0){
      var inp = document.getElementById('rcGunKisi');
      if(inp) inp.value = latest.kisi;
    }
  }

  // ── Kaydet — uysa_gunluk_uretim + depo stok düş ────────────
  window.rcSaveGunlukUretim = function(){
    var tarih = document.getElementById('rcGunTarih')?.value;
    var kisi = parseInt(document.getElementById('rcGunKisi')?.value)||0;
    if(!tarih){ alert('Tarih secin.'); return; }
    if(kisi<=0){ alert('Kisi sayisi girin.'); return; }
    var st = window._rcGunState;
    if(!Object.keys(st.tuketimData).length){ alert('Tuketim verisi girin.'); return; }

    // Konsolide malzeme
    var konsolide = {};
    Object.keys(st.tuketimData).forEach(function(dish){
      (st.tuketimData[dish]||[]).forEach(function(r){
        if(!konsolide[r.name]) konsolide[r.name] = 0;
        konsolide[r.name] += r.kg;
      });
    });

    // Maliyet hesapla
    var topMaliyet = 0;
    Object.keys(konsolide).forEach(function(m){
      topMaliyet += konsolide[m] * _getBestPrice(m);
    });

    // uysa_gunluk_uretim'e kaydet
    var sel = _getSelectedCustomers();
    var gunlukArr = _ls2.get('uysa_gunluk_uretim',[]);
    gunlukArr.push({
      tarih: tarih,
      kisi: kisi,
      musteriler: sel,
      tuketim: JSON.parse(JSON.stringify(st.tuketimData)),
      toplamMaliyet: topMaliyet,
      kisiBasi: kisi>0 ? topMaliyet/kisi : 0,
      kaydedilme: new Date().toISOString()
    });
    _ls2.set('uysa_gunluk_uretim', gunlukArr);

    // Depo stok düş
    var aktifDepo = _ls2.get('uysa_aktif_depo','');
    var dusCount = 0, eksikler = [];
    if(aktifDepo){
      var depoStok = _ls2.get('uysa_stok_'+aktifDepo,{});
      var hareketler = _ls2.get('uysa_stok_hareketler',[]);
      Object.keys(konsolide).forEach(function(mal){
        var kg = konsolide[mal];
        if(depoStok[mal]){
          depoStok[mal].miktar = Math.max(0,(depoStok[mal].miktar||0)-kg);
          dusCount++;
        } else {
          eksikler.push(mal);
        }
        hareketler.push({
          tip:'uretim', malzeme:mal, miktar:kg, depo:aktifDepo,
          aciklama:'Tuketim: '+tarih+' ('+kisi+' kisi)',
          tarih:tarih, saat:new Date().toLocaleTimeString('tr-TR',{hour:'2-digit',minute:'2-digit'}),
          id:Date.now()+Math.random()
        });
      });
      _ls2.set('uysa_stok_'+aktifDepo, depoStok);
      _ls2.set('uysa_stok_hareketler', hareketler);
    }

    var msg = 'Kaydedildi!\n\nTarih: '+tarih+'\nKisi: '+kisi+'\nMaliyet: '+rc.fmtTL(topMaliyet);
    if(aktifDepo) msg += '\nStok dusulen: '+dusCount+' kalem ('+aktifDepo+')';
    if(eksikler.length) msg += '\n\nDepoda bulunamayan: '+eksikler.join(', ');
    alert(msg);
  };

  // ── Üretim fişi oluştur ────────────────────────────────────
  window.rcUretimFisiOlustur = function(){
    var tarih = document.getElementById('rcGunTarih')?.value;
    var kisi = parseInt(document.getElementById('rcGunKisi')?.value)||0;
    if(!tarih){ alert('Tarih secin.'); return; }
    var st = window._rcGunState;
    var konsolide = {};
    Object.keys(st.tuketimData).forEach(function(dish){
      (st.tuketimData[dish]||[]).forEach(function(r){
        if(!konsolide[r.name]) konsolide[r.name] = {kg:0, yemekler:[]};
        konsolide[r.name].kg += r.kg;
        if(konsolide[r.name].yemekler.indexOf(dish)<0) konsolide[r.name].yemekler.push(dish);
      });
    });
    if(!Object.keys(konsolide).length){ alert('Tuketim verisi girin.'); return; }

    var div = document.getElementById('rcGunMenuDiv');
    var topMaliyet = 0;
    var html = '<div class="panel" style="border:3px solid #1e40af;background:linear-gradient(135deg,#eff6ff,#dbeafe)">';
    html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">';
    html += '<h3 style="margin:0;color:#1e40af">Uretim Fisi - '+_formatDate(tarih)+'</h3>';
    html += '<span style="font-size:12px;color:#64748b">'+kisi+' kisi</span></div>';
    html += '<table style="width:100%;border-collapse:collapse;font-size:12px;background:#fff;border-radius:8px;overflow:hidden">';
    html += '<thead><tr style="background:#1e40af;color:#fff"><th style="padding:6px;text-align:left">Malzeme</th><th style="padding:6px;text-align:right">KG</th><th style="padding:6px;text-align:right">Birim TL</th><th style="padding:6px;text-align:right">Maliyet</th><th style="padding:6px;text-align:left">Yemekler</th></tr></thead><tbody>';
    Object.keys(konsolide).forEach(function(mal){
      var v = konsolide[mal];
      var price = _getBestPrice(mal);
      var cost = v.kg * price;
      topMaliyet += cost;
      html += '<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:4px 6px;font-weight:700">'+mal+'</td>';
      html += '<td style="padding:4px 6px;text-align:right">'+rc.fmt(v.kg,2)+'</td>';
      html += '<td style="padding:4px 6px;text-align:right">'+(price>0?rc.fmtTL(price):'?')+'</td>';
      html += '<td style="padding:4px 6px;text-align:right;font-weight:700;color:#dc2626">'+rc.fmtTL(cost)+'</td>';
      html += '<td style="padding:4px 6px;font-size:11px;color:#64748b">'+v.yemekler.join(', ')+'</td></tr>';
    });
    html += '</tbody></table>';
    html += '<div style="margin-top:10px;text-align:right;font-size:16px;font-weight:900;color:#1e40af">Toplam: '+rc.fmtTL(topMaliyet)+'</div>';
    html += '<div style="display:flex;gap:8px;margin-top:12px">';
    html += '<button class="btn" onclick="rcLoadGunlukMenu()" style="padding:8px 16px">Geri</button>';
    html += '<button class="btn" onclick="rcUretimFisiYazdir()" style="padding:8px 16px">Yazdir</button>';
    html += '</div></div>';
    div.innerHTML = html;
  };

  // ── Yazdır ──────────────────────────────────────────────────
  window.rcUretimFisiYazdir = function(){
    var content = document.getElementById('rcGunMenuDiv')?.innerHTML||'';
    var w = window.open('','_blank','width=900,height=700');
    w.document.write('<!DOCTYPE html><html><head><title>Uretim Fisi</title><style>body{font-family:Arial,sans-serif;padding:20px;font-size:12px}table{width:100%;border-collapse:collapse}th,td{padding:4px 6px;border:1px solid #ccc}.btn{display:none}@media print{.btn{display:none}}</style></head><body>'+content+'</body></html>');
    w.document.close();
    setTimeout(function(){ w.print(); },500);
  };

  // ── Hızlı reçete oluştur ───────────────────────────────────
  window.rcQuickRecipe = function(dishName){
    window.switchReceteTab('kutuphane');
    setTimeout(function(){ window.rcOpenRecipe(dishName); },200);
  };

})();
