// =================================================================
// RECETE & MALIYET MODULU - Gunluk Uretim (Tuketim Bazli) v2
// =================================================================

(function(){
  'use strict';
  var rc = window._rc;

  // -- localStorage helper
  var _ls2 = {
    get: function(k,d){ try{ var v=localStorage.getItem(k); return v?JSON.parse(v):d; }catch(e){ return d; } },
    set: function(k,v){ try{ localStorage.setItem(k,JSON.stringify(v)); }catch(e){} }
  };

  // -- Yemek adi normallestirme (trim + Turkish-aware lookup)
  function _findTuketimKey(dishName){
    var st = window._rcGunState;
    if(!dishName) return null;
    if(st.tuketimData[dishName] && st.tuketimData[dishName].length > 0) return dishName;
    var trimmed = dishName.trim();
    if(st.tuketimData[trimmed] && st.tuketimData[trimmed].length > 0) return trimmed;
    var lower = trimmed.toLocaleLowerCase('tr');
    var keys = Object.keys(st.tuketimData);
    for(var i=0; i<keys.length; i++){
      if(keys[i].toLocaleLowerCase('tr') === lower && st.tuketimData[keys[i]].length > 0) return keys[i];
    }
    return null;
  }

  // -- Modul State
  window._rcGunState = {
    selectedDish: null,
    activeTab: 'tuketim',
    tuketimData: {},
    dishes: [],
    aralikMode: false,
    viewMode: 'edit' // 'edit' veya 'dashboard'
  };

  // -- En iyi fiyat: fatura -> prices -> 0
  function _getBestPrice(malzemeAdi){
    if(!malzemeAdi) return 0;
    var best = 0;
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
    var prices = rc.getPrices();
    if(prices[malzemeAdi]) return parseFloat(prices[malzemeAdi])||0;
    return 0;
  }

  // -- Tarih formatlama
  function _formatDate(str){
    if(!str) return '';
    var p = str.split('-');
    var gunler = ['Pazar','Pazartesi','Sali','Carsamba','Persembe','Cuma','Cumartesi'];
    var d = new Date(parseInt(p[0]), parseInt(p[1])-1, parseInt(p[2]));
    return p[2]+'.'+p[1]+'.'+p[0]+' '+gunler[d.getDay()];
  }
  function _shortDate(str){
    if(!str) return '';
    var p = str.split('-');
    return p[2]+'.'+p[1];
  }

  // -- Secili musteri listesi
  function _getSelectedCustomers(){
    var cbs = document.querySelectorAll('#rcGunCustList input[type=checkbox]');
    var sel = [];
    cbs.forEach(function(cb){ if(cb.checked) sel.push(cb.value); });
    return sel;
  }

  // -- Secili musterilerden otomatik kisi hesapla
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
      if(lbl) lbl.textContent = total + ' kisi';
    } else {
      if(lbl) lbl.textContent = '—';
    }
  }

  // -- Musteri checkbox listesi render
  window.rcRenderGunCustList = function(){
    var div = document.getElementById('rcGunCustList');
    if(!div) return;
    var custRaw = _ls2.get('uysa_customers_v1', {});
    var custs = (custRaw.customers||[]).filter(function(c){ return c && c!=='GENEL'; });
    if(!custs.length){
      div.innerHTML = '<span style="color:#94a3b8;font-size:12px">Musteri bulunamadi.</span>';
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

  window._rcCustChanged = function(){
    _calcAutoKisi();
    _loadMenuImpl();
  };

  window.rcGunToggleAllCust = function(){
    var cbs = document.querySelectorAll('#rcGunCustList input[type=checkbox]');
    var allChecked = true;
    cbs.forEach(function(cb){ if(!cb.checked) allChecked = false; });
    cbs.forEach(function(cb){ cb.checked = !allChecked; });
    _calcAutoKisi();
    _loadMenuImpl();
  };

  window.rcToggleAralik = function(){
    var cb = document.getElementById('rcGunAralikToggle');
    var wrap = document.getElementById('rcGunAralikWrap');
    var tek = document.getElementById('rcGunTarih');
    if(!cb || !wrap) return;
    window._rcGunState.aralikMode = cb.checked;
    wrap.style.display = cb.checked ? 'inline' : 'none';
    if(tek) tek.style.display = cb.checked ? 'none' : '';
  };

  // -- Recalc (kisi sayisi degisince)
  window.rcRecalcGunluk = function(){
    if(typeof window.rcUpdateMaliyetBar === 'function') window.rcUpdateMaliyetBar();
    var st = window._rcGunState;
    if(st.selectedDish){
      var panel = document.getElementById('rcGunDetailPanel');
      if(panel) panel.innerHTML = _renderDishDetail(st.selectedDish);
    }
  };

  // -- Sayfa acilinca musteri listesini render et
  var _custRendered = false;
  window.rcLoadGunlukMenu = function(){
    if(!_custRendered){
      window.rcRenderGunCustList();
      _custRendered = true;
    }
    window._rcGunState.viewMode = 'edit';
    _loadMenuImpl();
  };

  // -- Hafta indeksi hesapla
  function _calcWeekIdx(dateStr){
    var p = dateStr.split('-');
    var y = parseInt(p[0]), m1 = parseInt(p[1]), d = parseInt(p[2]);
    var dt2 = new Date(y, m1-1, d);
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

  // -- Menu yukleme (sol/sag split)
  function _loadMenuImpl(){
    var tarihEl = document.getElementById('rcGunTarih');
    if(tarihEl && !tarihEl.value) tarihEl.value = new Date().toISOString().slice(0,10);
    var tarih = tarihEl ? tarihEl.value : '';
    var div = document.getElementById('rcGunMenuDiv');
    if(!div || !tarih) return;

    _loadExistingTuketim(tarih);

    var sel = _getSelectedCustomers();
    var dishes = [];
    var dishCustomerMap = {};
    var menuGrid = _ls2.get('uysa_menu_grid_v2_dates',{});
    var dt = new Date(tarih+'T00:00:00');
    var dow = (dt.getDay()+6)%7;
    var wIdx = 'W'+_calcWeekIdx(tarih);
    var yy = dt.getFullYear();
    var mm = dt.getMonth()+1;
    var ymPadded = yy+'-'+(mm<10?'0':'')+mm;
    var ymNoPad  = yy+'-'+mm;

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
      ['dessertFruitName','ayran'].forEach(function(cat){
        var arr = w[cat]; if(!arr) return;
        var v = arr[dow]; if(!v || !v.trim()) return;
        v = v.trim();
        if(dishes.indexOf(v)<0) dishes.push(v);
        if(!dishCustomerMap[v]) dishCustomerMap[v] = [];
        if(dishCustomerMap[v].indexOf(cust)<0) dishCustomerMap[v].push(cust);
      });
    });
    var isWeekend = (dow === 5 || dow === 6);
    if(!dishes.length && isWeekend){
      custList.forEach(function(cust){
        var grid = menuGrid[ymPadded+'::'+cust] || menuGrid[ymNoPad+'::'+cust];
        if(!grid || !grid.weeks || !grid.weeks[wIdx]) return;
        var w = grid.weeks[wIdx];
        ['soups','soups2','mains','mains2','sides','sides2','salads','dessertFruitName','ayran'].forEach(function(cat){
          var arr = w[cat]; if(!arr) return;
          var v = arr[4];
          if(!v || !v.trim()) return;
          v = v.trim();
          if(dishes.indexOf(v)<0) dishes.push(v);
          if(!dishCustomerMap[v]) dishCustomerMap[v] = [];
          if(dishCustomerMap[v].indexOf(cust)<0) dishCustomerMap[v].push(cust);
        });
      });
    }
    if(!dishes.length && sel.length > 0){
      var fallback = rc.getDayMenu(tarih);
      if(fallback) dishes = fallback;
    }
    var st = window._rcGunState;
    st.dishes = dishes;

    // Tuketim verisinde olup menude olmayan yemekleri de ekle
    Object.keys(st.tuketimData).forEach(function(d){
      if(!st.tuketimData[d] || !st.tuketimData[d].length) return;
      var dLower = d.toLocaleLowerCase('tr');
      var found = st.dishes.some(function(existing){
        return existing.toLocaleLowerCase('tr') === dLower;
      });
      if(!found) st.dishes.push(d);
    });

    // --- SOL/SAG SPLIT RENDER ---
    var html = '<div style="display:flex;gap:16px;min-height:420px">';

    // SOL -- Yemek listesi
    html += '<div style="flex:0 0 230px;border-right:1px solid #e2e8f0;padding-right:14px">';
    html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">';
    html += '<h4 style="margin:0;font-size:13px;color:#1e40af">'+_formatDate(tarih)+'</h4>';
    html += '<span style="background:#e0e7ff;color:#1e40af;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">'+(dishes.length||0)+' kalem</span>';
    html += '</div>';

    if(isWeekend && dishes.length){
      html += '<div style="color:#1e40af;font-size:11px;padding:6px 8px;background:#eff6ff;border-radius:6px;margin-bottom:8px">Hafta sonu - Cuma menusu</div>';
    }
    if(!dishes.length){
      html += '<div style="color:#d97706;font-size:11px;padding:10px;background:#fffbeb;border-radius:6px;margin-bottom:8px">'+(isWeekend?'Hafta sonu menusu yok. ':'')+'Manuel yemek ekleyebilirsiniz.</div>';
    }

    dishes.forEach(function(d){
      var hasTuk = !!_findTuketimKey(d);
      var sel2 = (st.selectedDish === d);
      var isMenu = !!dishCustomerMap[d];
      var custInfo = dishCustomerMap[d] ? dishCustomerMap[d].join(', ') : '';
      html += '<div style="display:flex;align-items:center;margin-bottom:4px">';
      html += '<div onclick="rcSelectDish(\''+d.replace(/'/g,"\\'")+'\',event)" style="flex:1;padding:8px 10px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;'
        +(sel2?'background:#1e40af;color:#fff;':'background:#f8fafc;border:1px solid #e2e8f0;')
        +'">'+(hasTuk?'<span style="color:'+(sel2?'#bbf7d0':'#16a34a')+'">&#10003;</span> ':'')+d
        +(custInfo?'<div style="font-size:9px;font-weight:400;color:'+(sel2?'#93c5fd':'#94a3b8')+';margin-top:2px">'+custInfo+'</div>':'')
        +'</div>';
      // Silme butonu (sadece menude olmayan / manuel eklenen)
      if(!isMenu){
        html += '<button onclick="rcSilYemek(\''+d.replace(/'/g,"\\'")+'\',event)" title="Sil" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:16px;padding:4px 6px;margin-left:2px;opacity:0.6" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0.6">&#10005;</button>';
      }
      html += '</div>';
    });

    html += '<button class="btn" onclick="rcManuelYemekEkle()" style="width:100%;margin-top:10px;font-size:11px;background:#f8fafc;border:1px dashed #cbd5e1;color:#64748b">+ Manuel Yemek Ekle</button>';
    html += '</div>';

    // SAG -- Detay paneli
    html += '<div id="rcGunDetailPanel" style="flex:1;min-width:0">';
    if(st.selectedDish){
      html += _renderDishDetail(st.selectedDish);
    } else {
      html += '<div style="color:#94a3b8;text-align:center;padding:60px 20px">';
      html += '<div style="font-size:32px;margin-bottom:10px">&#128073;</div>';
      html += '<div style="font-size:13px">Soldaki listeden bir yemek secin</div>';
      html += '</div>';
    }
    html += '</div></div>';
    div.innerHTML = html;
    _updateMaliyetBar();
  }

  // -- Yemek sec
  window.rcSelectDish = function(name, e){
    if(e) e.stopPropagation();
    window._rcGunState.selectedDish = name;
    _loadMenuImpl();
  };

  // -- Yemek sil (sol panelden)
  window.rcSilYemek = function(name, e){
    if(e) e.stopPropagation();
    var st = window._rcGunState;
    // dishes listesinden cikar
    var idx = st.dishes.indexOf(name);
    if(idx >= 0) st.dishes.splice(idx, 1);
    // tuketim verisini de temizle
    var tukKey = _findTuketimKey(name);
    if(tukKey) delete st.tuketimData[tukKey];
    // secili ise sifirla
    if(st.selectedDish === name) st.selectedDish = null;
    _loadMenuImpl();
  };

  // -- Manuel yemek ekle
  window.rcManuelYemekEkle = function(){
    var name = prompt('Yemek adi:');
    if(!name || !name.trim()) return;
    name = name.trim();
    var st = window._rcGunState;
    if(st.dishes.indexOf(name)<0) st.dishes.push(name);
    st.selectedDish = name;
    _loadMenuImpl();
  };

  // -- Sag panel -- Tuketim / Gramaj detay
  function _renderDishDetail(dishName){
    var st = window._rcGunState;
    var tab = st.activeTab || 'tuketim';
    var kisi = parseInt(document.getElementById('rcGunKisi')?.value)||100;
    var tukKey = _findTuketimKey(dishName);
    var rows = tukKey ? st.tuketimData[tukKey] : [];

    var h = '';
    // Baslik
    h += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">';
    h += '<h4 style="margin:0;font-size:16px;color:#1e293b">'+dishName+'</h4>';
    h += '<div style="display:flex;gap:4px">';
    h += '<button class="btn" onclick="rcSwitchDishTab(\'tuketim\')" style="font-size:11px;padding:5px 14px;border-radius:20px;'+(tab==='tuketim'?'background:#1e40af;color:#fff;border:none':'background:#f1f5f9;border:1px solid #e2e8f0;color:#64748b')+'">Tuketim (KG)</button>';
    h += '<button class="btn" onclick="rcSwitchDishTab(\'recete\')" style="font-size:11px;padding:5px 14px;border-radius:20px;'+(tab==='recete'?'background:#7c3aed;color:#fff;border:none':'background:#f1f5f9;border:1px solid #e2e8f0;color:#64748b')+'">Gramaj (g/kisi)</button>';
    h += '</div></div>';

    if(tab === 'tuketim'){
      // Giris formu
      h += '<div style="display:flex;gap:6px;margin-bottom:12px;background:#f8fafc;padding:10px;border-radius:8px;border:1px solid #e2e8f0">';
      h += '<input id="rcTukMalzeme" placeholder="Malzeme adi" style="flex:1;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12px" onkeydown="if(event.key===\'Enter\'){document.getElementById(\'rcTukKg\').focus()}"/>';
      h += '<input id="rcTukKg" type="number" step="0.01" min="0" placeholder="KG" style="width:90px;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;font-weight:700" onkeydown="if(event.key===\'Enter\'){rcAddTuketimRow(\''+dishName.replace(/'/g,"\\'")+'\')}" />';
      h += '<button class="btn" onclick="rcAddTuketimRow(\''+dishName.replace(/'/g,"\\'")+'\',event)" style="font-size:12px;padding:8px 14px;background:#16a34a;color:#fff;border:none;border-radius:6px;font-weight:700">+ Ekle</button>';
      h += '</div>';
      // Tablo
      if(rows.length > 0){
        var topKg = 0;
        h += '<table style="width:100%;border-collapse:collapse;font-size:12px">';
        h += '<tr style="background:#f1f5f9;border-bottom:2px solid #e2e8f0"><th style="padding:8px 10px;text-align:left;font-weight:700;color:#475569">Malzeme</th><th style="padding:8px 10px;text-align:right;font-weight:700;color:#475569">KG</th><th style="padding:8px 10px;text-align:right;font-weight:700;color:#475569">Maliyet</th><th style="padding:8px 10px;width:36px"></th></tr>';
        rows.forEach(function(r, i){
          var price = _getBestPrice(r.name);
          var cost = r.kg * price;
          topKg += r.kg;
          h += '<tr style="border-bottom:1px solid #f1f5f9">';
          h += '<td style="padding:6px 10px;font-weight:500">'+r.name+'</td>';
          h += '<td style="padding:6px 10px;text-align:right;font-weight:700;color:#1e40af">'+rc.fmt(r.kg,2)+' kg</td>';
          h += '<td style="padding:6px 10px;text-align:right;color:#64748b">'+(price>0?rc.fmtTL(cost):'<span style="color:#d97706">?</span>')+'</td>';
          h += '<td style="padding:6px 10px;text-align:center"><button onclick="rcDelTuketimRow(\''+dishName.replace(/'/g,"\\'")+'\','+i+')" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:15px;opacity:0.6" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0.6">&#10005;</button></td>';
          h += '</tr>';
        });
        h += '<tr style="background:#eff6ff;font-weight:800"><td style="padding:8px 10px">Toplam ('+rows.length+' kalem)</td><td style="padding:8px 10px;text-align:right;color:#1e40af">'+rc.fmt(topKg,2)+' kg</td><td colspan="2"></td></tr>';
        h += '</table>';
      } else {
        h += '<div style="color:#94a3b8;font-size:12px;padding:30px;text-align:center;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0">Henuz tuketim girisi yapilmadi.<br/><span style="font-size:11px">Yukaridaki formdan malzeme ekleyin.</span></div>';
      }
    } else {
      // Gramaj sekmesi -- kisi basi gram hesabi
      if(rows.length > 0){
        var topMaliyet = 0;
        h += '<div style="background:#f5f3ff;padding:8px 12px;border-radius:8px;margin-bottom:10px;font-size:12px;color:#7c3aed;font-weight:600">'+kisi+' kisi icin kisi basi gramaj hesabi</div>';
        h += '<table style="width:100%;border-collapse:collapse;font-size:12px">';
        h += '<tr style="background:#f5f3ff;border-bottom:2px solid #e9e5ff"><th style="padding:8px 10px;text-align:left;color:#5b21b6">Malzeme</th><th style="padding:8px 10px;text-align:right;color:#5b21b6">Toplam KG</th><th style="padding:8px 10px;text-align:right;color:#5b21b6;font-weight:800">g/kisi</th><th style="padding:8px 10px;text-align:right;color:#5b21b6">Birim TL/kg</th><th style="padding:8px 10px;text-align:right;color:#5b21b6">Maliyet</th></tr>';
        rows.forEach(function(r){
          var gpk = kisi > 0 ? (r.kg * 1000 / kisi) : 0;
          var price = _getBestPrice(r.name);
          var cost = r.kg * price;
          topMaliyet += cost;
          h += '<tr style="border-bottom:1px solid #f1f5f9">';
          h += '<td style="padding:6px 10px;font-weight:500">'+r.name+'</td>';
          h += '<td style="padding:6px 10px;text-align:right">'+rc.fmt(r.kg,2)+' kg</td>';
          h += '<td style="padding:6px 10px;text-align:right;font-weight:800;color:#7c3aed;font-size:14px">'+rc.fmt(gpk,1)+' g</td>';
          h += '<td style="padding:6px 10px;text-align:right;color:#64748b">'+(price>0?rc.fmtTL(price):'<span style="color:#d97706">?</span>')+'</td>';
          h += '<td style="padding:6px 10px;text-align:right;font-weight:700;color:#dc2626">'+rc.fmtTL(cost)+'</td>';
          h += '</tr>';
        });
        h += '<tr style="background:#ecfdf5;font-weight:800"><td colspan="4" style="padding:8px 10px;color:#166534">Toplam Maliyet</td><td style="padding:8px 10px;text-align:right;color:#166534;font-size:14px">'+rc.fmtTL(topMaliyet)+'</td></tr>';
        if(kisi>0) h += '<tr style="background:#f0fdf4"><td colspan="4" style="padding:6px 10px;font-size:11px;color:#64748b">Kisi basi maliyet</td><td style="padding:6px 10px;text-align:right;font-weight:700;color:#166534">'+rc.fmtTL(topMaliyet/kisi)+'</td></tr>';
        h += '</table>';
      } else {
        h += '<div style="color:#94a3b8;font-size:12px;padding:30px;text-align:center;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0">Tuketim verisi girilmeden gramaj hesabi yapilamaz.</div>';
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

  // -- Tuketim satir ekle/sil
  window.rcAddTuketimRow = function(dishName, e){
    if(e) e.preventDefault();
    var mEl = document.getElementById('rcTukMalzeme');
    var kEl = document.getElementById('rcTukKg');
    if(!mEl || !kEl) return;
    var name = mEl.value.trim();
    var kg = parseFloat(kEl.value)||0;
    if(!name){ alert('Malzeme adi girin.'); return; }
    if(kg <= 0){ alert('KG girin.'); return; }
    var st = window._rcGunState;
    var tukKey = _findTuketimKey(dishName) || dishName;
    if(!st.tuketimData[tukKey]) st.tuketimData[tukKey] = [];
    st.tuketimData[tukKey].push({name:name, kg:kg});
    mEl.value = ''; kEl.value = '';
    _loadMenuImpl();
    // Focus back to malzeme input
    setTimeout(function(){ var m = document.getElementById('rcTukMalzeme'); if(m) m.focus(); }, 100);
  };

  window.rcDelTuketimRow = function(dishName, idx){
    var st = window._rcGunState;
    var tukKey = _findTuketimKey(dishName) || dishName;
    if(st.tuketimData[tukKey]) st.tuketimData[tukKey].splice(idx,1);
    _loadMenuImpl();
  };

  // -- Maliyet bar guncelle
  function _updateMaliyetBar(){
    var bar = document.getElementById('rcGunMaliyetBar');
    if(!bar) return;
    var st = window._rcGunState;
    var kisi = parseInt(document.getElementById('rcGunKisi')?.value)||0;
    var topMaliyet = 0;
    var topKg = 0;
    var kalemSayisi = 0;
    Object.keys(st.tuketimData).forEach(function(dish){
      (st.tuketimData[dish]||[]).forEach(function(r){
        topMaliyet += r.kg * _getBestPrice(r.name);
        topKg += r.kg;
        kalemSayisi++;
      });
    });
    if(topKg > 0){
      bar.style.display = 'flex';
      bar.innerHTML = '<div style="text-align:center"><div style="font-size:10px;opacity:0.8">Gunluk Maliyet</div><div style="font-size:16px;font-weight:800">'+rc.fmtTL(topMaliyet)+'</div></div>'
        +'<div style="text-align:center"><div style="font-size:10px;opacity:0.8">Kisi Basi</div><div style="font-size:16px;font-weight:800">'+(kisi>0?rc.fmtTL(topMaliyet/kisi):'\u2014')+'</div></div>'
        +'<div style="text-align:center"><div style="font-size:10px;opacity:0.8">Toplam</div><div style="font-size:16px;font-weight:800">'+rc.fmt(topKg,1)+' kg</div></div>'
        +'<div style="text-align:center"><div style="font-size:10px;opacity:0.8">Kisi / Kalem</div><div style="font-size:16px;font-weight:800">'+kisi+' / '+kalemSayisi+'</div></div>';
    } else {
      bar.style.display = 'none';
    }
  }
  window.rcUpdateMaliyetBar = _updateMaliyetBar;

  // -- Mevcut kayitlari yukle
  function _loadExistingTuketim(tarih){
    var st = window._rcGunState;
    if(st._loadedTarih === tarih) return;
    st._loadedTarih = tarih;
    st.tuketimData = {};
    st.selectedDish = null;

    var gunlukArr = _ls2.get('uysa_gunluk_uretim',[]);
    var matchingRecords = [];
    for(var i=0; i<gunlukArr.length; i++){
      var g = gunlukArr[i];
      if(g.tarih === tarih && g.tuketim && typeof g.tuketim === 'object' && !Array.isArray(g.tuketim)){
        matchingRecords.push(g);
      }
    }
    if(!matchingRecords.length){
      console.log('[Gunluk] '+tarih+': tuketim kaydi bulunamadi ('+gunlukArr.filter(function(x){return x.tarih===tarih}).length+' ayni tarih)');
      return;
    }
    var latest = matchingRecords[matchingRecords.length - 1];
    Object.keys(latest.tuketim).forEach(function(k){
      var arr = latest.tuketim[k];
      if(Array.isArray(arr) && arr.length > 0){
        st.tuketimData[k] = arr.map(function(item){
          return {name: item.name||'', kg: parseFloat(item.kg)||0};
        });
      }
    });
    console.log('[Gunluk] '+tarih+': tuketim yuklendi - '+Object.keys(st.tuketimData).length+' yemek:', Object.keys(st.tuketimData));
    if(latest.kisi > 0){
      var inp = document.getElementById('rcGunKisi');
      if(inp) inp.value = latest.kisi;
    }
  }

  // -- Kaydet
  window.rcSaveGunlukUretim = function(){
    var tarih = document.getElementById('rcGunTarih')?.value;
    var kisi = parseInt(document.getElementById('rcGunKisi')?.value)||0;
    if(!tarih){ alert('Tarih secin.'); return; }
    if(kisi<=0){ alert('Kisi sayisi girin.'); return; }
    var st = window._rcGunState;
    if(!Object.keys(st.tuketimData).length){ alert('Tuketim verisi girin.'); return; }

    var konsolide = {};
    Object.keys(st.tuketimData).forEach(function(dish){
      (st.tuketimData[dish]||[]).forEach(function(r){
        if(!konsolide[r.name]) konsolide[r.name] = 0;
        konsolide[r.name] += r.kg;
      });
    });

    var topMaliyet = 0;
    Object.keys(konsolide).forEach(function(m){
      topMaliyet += konsolide[m] * _getBestPrice(m);
    });

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

    var msg = 'Kaydedildi!\n\nTarih: '+tarih+'\nKisi: '+kisi+'\nYemek: '+Object.keys(st.tuketimData).length+' kalem\nMaliyet: '+rc.fmtTL(topMaliyet);
    if(aktifDepo) msg += '\nStok dusulen: '+dusCount+' kalem ('+aktifDepo+')';
    if(eksikler.length) msg += '\n\nDepoda bulunamayan: '+eksikler.join(', ');
    alert(msg);

    // Reload to refresh checkmarks
    st._loadedTarih = null;
    _loadMenuImpl();
  };

  // ================================================================
  // GECMIS GUNLER DASHBOARD
  // ================================================================
  window.rcGecmisGunlerDashboard = function(){
    var div = document.getElementById('rcGunMenuDiv');
    if(!div) return;
    window._rcGunState.viewMode = 'dashboard';

    var gunlukArr = _ls2.get('uysa_gunluk_uretim',[]);
    // Tarihe gore grupla, sadece tuketim verisi olanlari al
    var gunMap = {};
    gunlukArr.forEach(function(g){
      if(!g.tarih || !g.tuketim || typeof g.tuketim !== 'object' || Array.isArray(g.tuketim)) return;
      if(!gunMap[g.tarih]) gunMap[g.tarih] = [];
      gunMap[g.tarih].push(g);
    });

    var tarihler = Object.keys(gunMap).sort().reverse();
    if(!tarihler.length){
      div.innerHTML = '<div style="text-align:center;padding:60px;color:#94a3b8"><div style="font-size:32px;margin-bottom:10px">&#128203;</div><div>Henuz kayitli tuketim verisi yok.</div><div style="font-size:12px;margin-top:6px">Menuyu yukleyip tuketim girisi yapin.</div></div>';
      return;
    }

    var html = '<div style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center">';
    html += '<h3 style="margin:0;font-size:16px;color:#1e293b">Gecmis Gunler ('+tarihler.length+' gun)</h3>';
    html += '<button class="btn" onclick="rcLoadGunlukMenu()" style="font-size:11px;padding:6px 14px;background:#f1f5f9;border:1px solid #e2e8f0">&#8592; Bugune Don</button>';
    html += '</div>';

    html += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px">';
    tarihler.forEach(function(tarih){
      var records = gunMap[tarih];
      var latest = records[records.length - 1];
      var yemekSayisi = Object.keys(latest.tuketim).length;
      var topMaliyet = latest.toplamMaliyet || 0;
      var kisi = latest.kisi || 0;

      // Toplam malzeme ve kg hesapla
      var topKg = 0;
      var malzemeSayisi = 0;
      var malzemeSet = {};
      Object.keys(latest.tuketim).forEach(function(dish){
        var arr = latest.tuketim[dish];
        if(Array.isArray(arr)){
          arr.forEach(function(item){
            if(!malzemeSet[item.name]) malzemeSet[item.name] = 0;
            malzemeSet[item.name] += item.kg || 0;
            topKg += item.kg || 0;
          });
        }
      });
      malzemeSayisi = Object.keys(malzemeSet).length;

      html += '<div onclick="rcGecmisGunDetay(\''+tarih+'\')" style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px;cursor:pointer;transition:all 0.15s" onmouseover="this.style.borderColor=\'#1e40af\';this.style.boxShadow=\'0 2px 8px rgba(30,64,175,0.1)\'" onmouseout="this.style.borderColor=\'#e2e8f0\';this.style.boxShadow=\'none\'">';
      html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">';
      html += '<span style="font-weight:700;font-size:14px;color:#1e293b">'+_formatDate(tarih)+'</span>';
      html += '<span style="background:#e0e7ff;color:#1e40af;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">'+kisi+' kisi</span>';
      html += '</div>';
      html += '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:8px">';
      html += '<div style="text-align:center;background:#f8fafc;padding:6px;border-radius:6px"><div style="font-size:10px;color:#94a3b8">Yemek</div><div style="font-weight:700;color:#1e40af">'+yemekSayisi+'</div></div>';
      html += '<div style="text-align:center;background:#f8fafc;padding:6px;border-radius:6px"><div style="font-size:10px;color:#94a3b8">Malzeme</div><div style="font-weight:700;color:#7c3aed">'+malzemeSayisi+'</div></div>';
      html += '<div style="text-align:center;background:#f8fafc;padding:6px;border-radius:6px"><div style="font-size:10px;color:#94a3b8">Toplam</div><div style="font-weight:700;color:#059669">'+rc.fmt(topKg,1)+' kg</div></div>';
      html += '</div>';
      if(topMaliyet > 0){
        html += '<div style="display:flex;justify-content:space-between;font-size:11px;color:#64748b;border-top:1px solid #f1f5f9;padding-top:6px">';
        html += '<span>Maliyet: <b style="color:#dc2626">'+rc.fmtTL(topMaliyet)+'</b></span>';
        html += '<span>Kisi basi: <b style="color:#166534">'+(kisi>0?rc.fmtTL(topMaliyet/kisi):'\u2014')+'</b></span>';
        html += '</div>';
      }
      // Yemek listesi preview
      var yemekler = Object.keys(latest.tuketim).slice(0,4);
      html += '<div style="margin-top:6px;font-size:10px;color:#94a3b8">'+yemekler.join(' \u2022 ')+(Object.keys(latest.tuketim).length>4?' ...':'')+'</div>';
      html += '</div>';
    });
    html += '</div>';
    div.innerHTML = html;
  };

  // -- Gecmis gun detay goruntuleme
  window.rcGecmisGunDetay = function(tarih){
    var div = document.getElementById('rcGunMenuDiv');
    if(!div) return;

    var gunlukArr = _ls2.get('uysa_gunluk_uretim',[]);
    var records = gunlukArr.filter(function(g){
      return g.tarih === tarih && g.tuketim && typeof g.tuketim === 'object' && !Array.isArray(g.tuketim);
    });
    if(!records.length){ alert('Kayit bulunamadi.'); return; }
    var latest = records[records.length - 1];
    var kisi = latest.kisi || 0;

    var html = '';
    html += '<div style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center">';
    html += '<div>';
    html += '<h3 style="margin:0 0 4px;font-size:16px;color:#1e293b">'+_formatDate(tarih)+'</h3>';
    html += '<span style="font-size:12px;color:#64748b">'+kisi+' kisi | Kayit: '+(latest.kaydedilme?new Date(latest.kaydedilme).toLocaleString('tr-TR'):'?')+'</span>';
    html += '</div>';
    html += '<div style="display:flex;gap:6px">';
    html += '<button class="btn" onclick="rcGecmisGunlerDashboard()" style="font-size:11px;padding:6px 14px;background:#f1f5f9;border:1px solid #e2e8f0">&#8592; Geri</button>';
    html += '<button class="btn" onclick="rcGecmisGunDuzenle(\''+tarih+'\')" style="font-size:11px;padding:6px 14px;background:#1e40af;color:#fff">Duzenle</button>';
    html += '</div>';
    html += '</div>';

    // Tab secimi: Tuketim / Gramaj / Uretim Fisi
    var viewTab = window._rcGunState._detayTab || 'tuketim';
    html += '<div style="display:flex;gap:4px;margin-bottom:14px">';
    html += '<button class="btn" onclick="window._rcGunState._detayTab=\'tuketim\';rcGecmisGunDetay(\''+tarih+'\')" style="font-size:11px;padding:6px 16px;border-radius:20px;'+(viewTab==='tuketim'?'background:#1e40af;color:#fff;border:none':'background:#f1f5f9;border:1px solid #e2e8f0;color:#64748b')+'">Tuketim (KG)</button>';
    html += '<button class="btn" onclick="window._rcGunState._detayTab=\'gramaj\';rcGecmisGunDetay(\''+tarih+'\')" style="font-size:11px;padding:6px 16px;border-radius:20px;'+(viewTab==='gramaj'?'background:#7c3aed;color:#fff;border:none':'background:#f1f5f9;border:1px solid #e2e8f0;color:#64748b')+'">Gramaj (g/kisi)</button>';
    html += '<button class="btn" onclick="window._rcGunState._detayTab=\'fis\';rcGecmisGunDetay(\''+tarih+'\')" style="font-size:11px;padding:6px 16px;border-radius:20px;'+(viewTab==='fis'?'background:#059669;color:#fff;border:none':'background:#f1f5f9;border:1px solid #e2e8f0;color:#64748b')+'">Uretim Fisi</button>';
    html += '</div>';

    var yemekler = Object.keys(latest.tuketim);

    if(viewTab === 'tuketim'){
      // Yemek bazli tuketim goruntuleme
      yemekler.forEach(function(dish){
        var items = latest.tuketim[dish];
        if(!Array.isArray(items) || !items.length) return;
        var topKg = 0;
        items.forEach(function(it){ topKg += (it.kg||0); });
        html += '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-bottom:10px">';
        html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">';
        html += '<h4 style="margin:0;font-size:14px;color:#1e293b">'+dish+'</h4>';
        html += '<span style="font-size:12px;font-weight:700;color:#1e40af">'+rc.fmt(topKg,2)+' kg</span>';
        html += '</div>';
        html += '<table style="width:100%;border-collapse:collapse;font-size:12px">';
        html += '<tr style="background:#f8fafc"><th style="padding:4px 8px;text-align:left;color:#64748b;font-weight:600">Malzeme</th><th style="padding:4px 8px;text-align:right;color:#64748b;font-weight:600">KG</th><th style="padding:4px 8px;text-align:right;color:#64748b;font-weight:600">Maliyet</th></tr>';
        items.forEach(function(it){
          var price = _getBestPrice(it.name);
          html += '<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:4px 8px">'+it.name+'</td><td style="padding:4px 8px;text-align:right;font-weight:700">'+rc.fmt(it.kg,2)+'</td><td style="padding:4px 8px;text-align:right;color:#64748b">'+(price>0?rc.fmtTL(it.kg*price):'?')+'</td></tr>';
        });
        html += '</table></div>';
      });
    } else if(viewTab === 'gramaj'){
      // Gramaj goruntuleme (kisi basi)
      yemekler.forEach(function(dish){
        var items = latest.tuketim[dish];
        if(!Array.isArray(items) || !items.length) return;
        html += '<div style="background:#fff;border:1px solid #e9e5ff;border-radius:8px;padding:12px;margin-bottom:10px">';
        html += '<h4 style="margin:0 0 8px;font-size:14px;color:#5b21b6">'+dish+' <span style="font-size:11px;color:#94a3b8;font-weight:400">('+kisi+' kisi)</span></h4>';
        html += '<table style="width:100%;border-collapse:collapse;font-size:12px">';
        html += '<tr style="background:#f5f3ff"><th style="padding:4px 8px;text-align:left;color:#5b21b6">Malzeme</th><th style="padding:4px 8px;text-align:right;color:#5b21b6">KG</th><th style="padding:4px 8px;text-align:right;color:#5b21b6;font-weight:800">g/kisi</th></tr>';
        items.forEach(function(it){
          var gpk = kisi > 0 ? ((it.kg||0) * 1000 / kisi) : 0;
          html += '<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:4px 8px">'+it.name+'</td><td style="padding:4px 8px;text-align:right">'+rc.fmt(it.kg,2)+'</td><td style="padding:4px 8px;text-align:right;font-weight:800;color:#7c3aed;font-size:14px">'+rc.fmt(gpk,1)+' g</td></tr>';
        });
        html += '</table></div>';
      });
    } else {
      // Uretim Fisi - konsolide malzeme listesi
      var konsolide = {};
      yemekler.forEach(function(dish){
        var items = latest.tuketim[dish];
        if(!Array.isArray(items)) return;
        items.forEach(function(it){
          if(!konsolide[it.name]) konsolide[it.name] = {kg:0, yemekler:[]};
          konsolide[it.name].kg += (it.kg||0);
          if(konsolide[it.name].yemekler.indexOf(dish)<0) konsolide[it.name].yemekler.push(dish);
        });
      });
      var topMaliyet = 0;
      html += '<div style="background:linear-gradient(135deg,#eff6ff,#dbeafe);border:2px solid #1e40af;border-radius:10px;padding:16px">';
      html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">';
      html += '<h3 style="margin:0;color:#1e40af">Uretim Fisi</h3>';
      html += '<button class="btn" onclick="rcUretimFisiYazdir()" style="font-size:11px;padding:6px 12px">Yazdir</button>';
      html += '</div>';
      html += '<table style="width:100%;border-collapse:collapse;font-size:12px;background:#fff;border-radius:8px;overflow:hidden">';
      html += '<thead><tr style="background:#1e40af;color:#fff"><th style="padding:8px 10px;text-align:left">Malzeme</th><th style="padding:8px 10px;text-align:right">KG</th><th style="padding:8px 10px;text-align:right">g/kisi</th><th style="padding:8px 10px;text-align:right">Birim TL</th><th style="padding:8px 10px;text-align:right">Maliyet</th><th style="padding:8px 10px;text-align:left">Yemekler</th></tr></thead><tbody>';
      Object.keys(konsolide).forEach(function(mal){
        var v = konsolide[mal];
        var price = _getBestPrice(mal);
        var cost = v.kg * price;
        var gpk = kisi > 0 ? (v.kg * 1000 / kisi) : 0;
        topMaliyet += cost;
        html += '<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:6px 10px;font-weight:600">'+mal+'</td>';
        html += '<td style="padding:6px 10px;text-align:right;font-weight:700">'+rc.fmt(v.kg,2)+'</td>';
        html += '<td style="padding:6px 10px;text-align:right;color:#7c3aed;font-weight:700">'+rc.fmt(gpk,1)+' g</td>';
        html += '<td style="padding:6px 10px;text-align:right">'+(price>0?rc.fmtTL(price):'?')+'</td>';
        html += '<td style="padding:6px 10px;text-align:right;font-weight:700;color:#dc2626">'+rc.fmtTL(cost)+'</td>';
        html += '<td style="padding:6px 10px;font-size:10px;color:#64748b">'+v.yemekler.join(', ')+'</td></tr>';
      });
      html += '</tbody></table>';
      html += '<div style="margin-top:10px;text-align:right;font-size:18px;font-weight:900;color:#1e40af">Toplam: '+rc.fmtTL(topMaliyet)+'</div>';
      if(kisi>0) html += '<div style="text-align:right;font-size:12px;color:#64748b;margin-top:2px">Kisi basi: '+rc.fmtTL(topMaliyet/kisi)+'</div>';
      html += '</div>';
    }

    div.innerHTML = html;
  };

  // -- Gecmis gunu duzenle modu
  window.rcGecmisGunDuzenle = function(tarih){
    var tarihEl = document.getElementById('rcGunTarih');
    if(tarihEl) tarihEl.value = tarih;
    window._rcGunState._loadedTarih = null; // Force reload
    window._rcGunState.viewMode = 'edit';
    _loadMenuImpl();
  };

  // -- Uretim fisi olustur (mevcut edit modundaki veriden)
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
    var html = '<div style="background:linear-gradient(135deg,#eff6ff,#dbeafe);border:2px solid #1e40af;border-radius:10px;padding:16px">';
    html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">';
    html += '<h3 style="margin:0;color:#1e40af">Uretim Fisi - '+_formatDate(tarih)+'</h3>';
    html += '<span style="font-size:12px;color:#64748b">'+kisi+' kisi</span></div>';
    html += '<table style="width:100%;border-collapse:collapse;font-size:12px;background:#fff;border-radius:8px;overflow:hidden">';
    html += '<thead><tr style="background:#1e40af;color:#fff"><th style="padding:8px 10px;text-align:left">Malzeme</th><th style="padding:8px 10px;text-align:right">KG</th><th style="padding:8px 10px;text-align:right">g/kisi</th><th style="padding:8px 10px;text-align:right">Birim TL</th><th style="padding:8px 10px;text-align:right">Maliyet</th><th style="padding:8px 10px;text-align:left">Yemekler</th></tr></thead><tbody>';
    Object.keys(konsolide).forEach(function(mal){
      var v = konsolide[mal];
      var price = _getBestPrice(mal);
      var cost = v.kg * price;
      var gpk = kisi > 0 ? (v.kg * 1000 / kisi) : 0;
      topMaliyet += cost;
      html += '<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:6px 10px;font-weight:600">'+mal+'</td>';
      html += '<td style="padding:6px 10px;text-align:right;font-weight:700">'+rc.fmt(v.kg,2)+'</td>';
      html += '<td style="padding:6px 10px;text-align:right;color:#7c3aed;font-weight:700">'+rc.fmt(gpk,1)+' g</td>';
      html += '<td style="padding:6px 10px;text-align:right">'+(price>0?rc.fmtTL(price):'?')+'</td>';
      html += '<td style="padding:6px 10px;text-align:right;font-weight:700;color:#dc2626">'+rc.fmtTL(cost)+'</td>';
      html += '<td style="padding:6px 10px;font-size:10px;color:#64748b">'+v.yemekler.join(', ')+'</td></tr>';
    });
    html += '</tbody></table>';
    html += '<div style="margin-top:10px;text-align:right;font-size:18px;font-weight:900;color:#1e40af">Toplam: '+rc.fmtTL(topMaliyet)+'</div>';
    if(kisi>0) html += '<div style="text-align:right;font-size:12px;color:#64748b;margin-top:2px">Kisi basi: '+rc.fmtTL(topMaliyet/kisi)+'</div>';
    html += '<div style="display:flex;gap:8px;margin-top:12px">';
    html += '<button class="btn" onclick="rcLoadGunlukMenu()" style="padding:8px 16px">Geri</button>';
    html += '<button class="btn" onclick="rcUretimFisiYazdir()" style="padding:8px 16px">Yazdir</button>';
    html += '</div></div>';
    div.innerHTML = html;
  };

  // -- Yazdir
  window.rcUretimFisiYazdir = function(){
    var content = document.getElementById('rcGunMenuDiv')?.innerHTML||'';
    var w = window.open('','_blank','width=900,height=700');
    w.document.write('<!DOCTYPE html><html><head><title>Uretim Fisi</title><style>body{font-family:Arial,sans-serif;padding:20px;font-size:12px}table{width:100%;border-collapse:collapse}th,td{padding:4px 6px;border:1px solid #ccc}.btn{display:none}@media print{.btn{display:none}}</style></head><body>'+content+'</body></html>');
    w.document.close();
    setTimeout(function(){ w.print(); },500);
  };

  // -- Hizli recete olustur
  window.rcQuickRecipe = function(dishName){
    window.switchReceteTab('kutuphane');
    setTimeout(function(){ window.rcOpenRecipe(dishName); },200);
  };

})();
