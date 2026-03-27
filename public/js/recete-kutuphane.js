// ═══════════════════════════════════════════════════════════════
// REÇETE & MALİYET MODÜLÜ — Reçete Kütüphanesi (Kategorili)
// ═══════════════════════════════════════════════════════════════

(function(){
  'use strict';
  var rc = window._rc;
  var _currentRecipe = null;

  // Kategori açık/kapalı durumu
  var _openCats = {};

  // ── Kategori etiketleri ────────────────────────────────────
  var CAT_LABELS = {
    soups: '🥣 Çorbalar',
    meat: '🥩 Kırmızı Et',
    chicken: '🍗 Beyaz Et',
    legume: '🫘 Bakliyat',
    kebab: '🍖 Köfte',
    vegetable: '🥦 Sebze',
    sides: '🍚 Yardımcı Yemek',
    salatabar: '🥗 Salatabar',
    diger: '📋 Diğer'
  };

  // ── Yemekleri kategorilere ayır ─────────────────────────────
  function _categorize(){
    var cat = rc.getCatalog();
    var recipes = rc.getRecipes();
    var groups = {};

    // Tüm kategorileri başlat
    Object.keys(CAT_LABELS).forEach(function(k){ groups[k] = []; });

    var assigned = new Set();

    // Çorbalar
    if(cat.soups) cat.soups.forEach(function(n){
      if(n){ groups.soups.push(n); assigned.add(n); }
    });

    // Ana yemekler — alt kategoriler
    if(cat.mainsByType){
      ['meat','chicken','legume','kebab','vegetable'].forEach(function(key){
        if(cat.mainsByType[key]) cat.mainsByType[key].forEach(function(n){
          if(n){ groups[key].push(n); assigned.add(n); }
        });
      });
      // Bilinmeyen alt kategori varsa
      Object.keys(cat.mainsByType).forEach(function(key){
        if(!groups[key]) groups[key] = [];
        if(['meat','chicken','legume','kebab','vegetable'].indexOf(key) < 0){
          cat.mainsByType[key].forEach(function(n){
            if(n && !assigned.has(n)){ groups.diger.push(n); assigned.add(n); }
          });
        }
      });
    }

    // Yardımcı yemekler
    if(cat.sidesByGroup) Object.values(cat.sidesByGroup).forEach(function(arr){
      arr.forEach(function(n){
        if(n){ groups.sides.push(n); assigned.add(n); }
      });
    });

    // Salatabar (catalog'da varsa)
    if(cat.salads) cat.salads.forEach(function(n){
      if(n){ groups.salatabar.push(n); assigned.add(n); }
    });

    // Recipes'da olan ama catalog'da olmayan yemekler
    Object.keys(recipes).forEach(function(name){
      if(!assigned.has(name)){
        groups.diger.push(name);
        assigned.add(name);
      }
    });

    // Her grubu sırala
    Object.keys(groups).forEach(function(k){
      groups[k].sort(function(a,b){ return a.localeCompare(b,'tr'); });
    });

    return groups;
  }

  // ── Yemek listesi render (kategorili) ──────────────────────
  window.rcRenderYemekList = function(){
    var div = document.getElementById('rcYemekListDiv');
    if(!div) return;
    var recipes = rc.getRecipes();
    var groups = _categorize();
    var filter = (document.getElementById('rcYemekAra')?.value||'').toLowerCase();

    var html = '';
    var catOrder = ['soups','meat','chicken','legume','kebab','vegetable','sides','salatabar','diger'];

    catOrder.forEach(function(catKey){
      var items = groups[catKey] || [];
      if(filter){
        items = items.filter(function(n){ return n.toLowerCase().indexOf(filter)>=0; });
      }
      if(!items.length) return;

      var isOpen = filter ? true : (_openCats[catKey] !== false); // default open when filtering
      var label = CAT_LABELS[catKey] || catKey;
      var count = items.length;
      var recipeCount = items.filter(function(n){ return recipes[n] && recipes[n].length>0; }).length;

      html += '<div style="margin-bottom:4px">';
      html += '<div onclick="rcToggleCat(\''+catKey+'\')" style="padding:8px 10px;background:linear-gradient(135deg,#f1f5f9,#e2e8f0);border-radius:8px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;font-weight:700;font-size:13px;color:#334155;user-select:none">';
      html += '<span>'+(isOpen?'▼':'▶')+'  '+label+'</span>';
      html += '<span style="font-size:10px;color:#64748b;font-weight:500">'+recipeCount+'/'+count+' reçete</span>';
      html += '</div>';

      if(isOpen){
        html += '<div style="padding-left:6px">';
        items.forEach(function(name){
          var hasRecipe = recipes[name] && recipes[name].length > 0;
          var bg = (name===_currentRecipe) ? '#dbeafe' : (hasRecipe ? '#f0fdf4' : '#fff');
          var badge = hasRecipe
            ? '<span style="color:#16a34a;font-size:10px">&#10003; reçete</span>'
            : '<span style="color:#d97706;font-size:10px">reçete yok</span>';
          html += '<div onclick="rcOpenRecipe(\''+name.replace(/'/g,"\\'")+'\')" style="padding:7px 10px;border-bottom:1px solid #f1f5f9;cursor:pointer;background:'+bg+';border-radius:5px;margin:2px 0;display:flex;justify-content:space-between;align-items:center;font-size:12px" onmouseover="this.style.background=\'#eff6ff\'" onmouseout="this.style.background=\''+bg+'\'">';
          html += '<span style="font-weight:600">'+name+'</span>'+badge+'</div>';
        });
        html += '</div>';
      }
      html += '</div>';
    });

    if(!html) html = '<div style="color:#94a3b8;padding:10px">Sonuç bulunamadı.</div>';
    div.innerHTML = html;
  };

  // ── Kategori aç/kapa toggle ────────────────────────────────
  window.rcToggleCat = function(catKey){
    _openCats[catKey] = _openCats[catKey] === false ? true : false;
    window.rcRenderYemekList();
  };

  window.rcFilterYemekList = function(){ window.rcRenderYemekList(); };

  // ── Yeni yemek ekle ────────────────────────────────────────
  window.rcYeniYemek = function(){
    var name = prompt('Yeni yemek adı:');
    if(!name || !name.trim()) return;
    name = name.trim();
    var recipes = rc.getRecipes();
    if(!recipes[name]) recipes[name] = [];
    rc.setRecipes(recipes);
    window.rcRenderYemekList();
    window.rcOpenRecipe(name);
  };

  // ── Reçete aç ──────────────────────────────────────────────
  window.rcOpenRecipe = function(name){
    _currentRecipe = name;
    var placeholder = document.getElementById('rcEditorPlaceholder');
    var editor = document.getElementById('rcEditorDiv');
    var title = document.getElementById('rcEditorTitle');
    if(placeholder) placeholder.style.display='none';
    if(editor) editor.style.display='';
    if(title) title.textContent = name + ' Reçetesi';

    var tbody = document.getElementById('rcIngredientTbody');
    if(tbody) tbody.innerHTML='';

    var kisiInp = document.getElementById('rcKisiSayisi');
    if(kisiInp) kisiInp.value = '1';
    var ters = document.getElementById('rcTersHesap');
    if(ters) ters.checked = false;

    var recipes = rc.getRecipes();
    var ings = recipes[name] || [];
    var prices = rc.getPrices();

    ings.forEach(function(ing){
      _addRow(ing.name, ing.gram, prices[ing.name]||0);
    });

    if(!ings.length){
      _addRow('','',0);
      _addRow('','',0);
      _addRow('','',0);
    }

    window.rcRecalc();
    window.rcRenderYemekList();
  };

  // ── Malzeme satırı ekle ────────────────────────────────────
  function _addRow(name, gram, price){
    var tbody = document.getElementById('rcIngredientTbody');
    if(!tbody) return;
    var tr = document.createElement('tr');
    tr.style.borderBottom = '1px solid #f1f5f9';

    tr.innerHTML =
      '<td style="padding:6px"><input type="text" value="'+(name||'')+'" placeholder="Malzeme adı" style="width:100%;padding:6px;border:1px solid #d1d5db;border-radius:6px;font-size:12px" oninput="rcOnMalzemeInput(this)" list="rcMalzemeDatalist"/></td>'
     +'<td style="padding:6px"><input type="number" value="'+(gram||'')+'" min="0" step="0.1" placeholder="g/kişi" style="width:100%;padding:6px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;text-align:right" oninput="rcRecalc()" class="rc-gram-inp"/></td>'
     +'<td style="padding:6px"><input type="number" value="" min="0" step="0.001" placeholder="kg" style="width:100%;padding:6px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;text-align:right;background:#f8fafc" oninput="rcOnTotalKgInput(this)" class="rc-kg-inp"/></td>'
     +'<td style="padding:6px"><input type="number" value="'+(price||'')+'" min="0" step="0.01" placeholder="₺/kg" style="width:100%;padding:6px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;text-align:right" oninput="rcRecalc()" class="rc-price-inp"/></td>'
     +'<td style="padding:6px;text-align:right;font-weight:700;font-size:12px;color:#dc2626" class="rc-cost-cell">0</td>'
     +'<td style="padding:6px;text-align:center"><button onclick="this.closest(\'tr\').remove();rcRecalc()" style="background:#fef2f2;color:#dc2626;border:none;border-radius:6px;padding:4px 8px;cursor:pointer;font-size:11px">X</button></td>';

    tbody.appendChild(tr);
  }

  window.rcAddIngredientRow = function(){ _addRow('','',0); };

  // ── Malzeme adı girilince fiyat otomatik doldur ────────────
  window.rcOnMalzemeInput = function(inp){
    var name = (inp.value||'').trim();
    if(!name) return;
    var prices = rc.getPrices();
    var tr = inp.closest('tr');
    var priceInp = tr.querySelector('.rc-price-inp');
    if(priceInp && prices[name] && !priceInp.value){
      priceInp.value = prices[name];
    }
    window.rcRecalc();
  };

  // ── Ters hesaplama ─────────────────────────────────────────
  window.rcOnTotalKgInput = function(inp){
    var ters = document.getElementById('rcTersHesap');
    if(!ters || !ters.checked) return;
    var kisi = parseInt(document.getElementById('rcKisiSayisi')?.value) || 1;
    var totalKg = parseFloat(inp.value) || 0;
    var gramPerPerson = kisi > 0 ? (totalKg * 1000 / kisi) : 0;
    var tr = inp.closest('tr');
    var gramInp = tr.querySelector('.rc-gram-inp');
    if(gramInp) gramInp.value = gramPerPerson.toFixed(1);
    window.rcRecalc();
  };

  // ── Hesaplama ──────────────────────────────────────────────
  window.rcRecalc = function(){
    var tbody = document.getElementById('rcIngredientTbody');
    if(!tbody) return;
    var kisi = parseInt(document.getElementById('rcKisiSayisi')?.value) || 1;
    var ters = document.getElementById('rcTersHesap');
    var isTers = ters && ters.checked;

    var totalGram = 0, totalKg = 0, totalCost = 0;

    tbody.querySelectorAll('tr').forEach(function(tr){
      var gramInp = tr.querySelector('.rc-gram-inp');
      var kgInp = tr.querySelector('.rc-kg-inp');
      var priceInp = tr.querySelector('.rc-price-inp');
      var costCell = tr.querySelector('.rc-cost-cell');

      var gram = parseFloat(gramInp?.value) || 0;
      var price = parseFloat(priceInp?.value) || 0;
      var kg;

      if(isTers){
        kg = parseFloat(kgInp?.value) || 0;
      } else {
        kg = (gram * kisi) / 1000;
        if(kgInp) kgInp.value = kg > 0 ? kg.toFixed(3) : '';
      }

      var cost = kg * price;
      if(costCell) costCell.textContent = rc.fmt(cost);

      totalGram += gram;
      totalKg += kg;
      totalCost += cost;
    });

    var el = function(id,v){ var e=document.getElementById(id); if(e) e.textContent=v; };
    el('rcTotalGram', rc.fmt(totalGram,1) + ' g');
    el('rcTotalKg', rc.fmt(totalKg,3) + ' kg');
    el('rcTotalMaliyet', rc.fmtTL(totalCost));
    el('rcKisiBasiMaliyet', rc.fmtTL(kisi > 0 ? totalCost / kisi : 0));
  };

  // ── Reçete kaydet ──────────────────────────────────────────
  window.rcSaveRecipe = function(){
    if(!_currentRecipe){ alert('Önce bir yemek seçin.'); return; }
    var tbody = document.getElementById('rcIngredientTbody');
    if(!tbody) return;
    var list = [];
    tbody.querySelectorAll('tr').forEach(function(tr){
      var nameInp = tr.querySelector('input[type="text"]');
      var gramInp = tr.querySelector('.rc-gram-inp');
      var name = (nameInp?.value||'').trim();
      var gram = parseFloat(gramInp?.value) || 0;
      if(name) list.push({name:name, gram:gram});
    });

    var recipes = rc.getRecipes();
    recipes[_currentRecipe] = list;
    rc.setRecipes(recipes);

    var prices = rc.getPrices();
    tbody.querySelectorAll('tr').forEach(function(tr){
      var nameInp = tr.querySelector('input[type="text"]');
      var priceInp = tr.querySelector('.rc-price-inp');
      var name = (nameInp?.value||'').trim();
      var price = parseFloat(priceInp?.value) || 0;
      if(name && price > 0) prices[name] = price;
    });
    try{ localStorage.setItem('uysa_prices_tl_per_kg_v1', JSON.stringify(prices)); }catch(e){}

    alert(_currentRecipe + ' reçetesi kaydedildi! (' + list.length + ' malzeme)');
    window.rcRenderYemekList();
  };

  // ── Reçete sil ─────────────────────────────────────────────
  window.rcSilRecipe = function(){
    if(!_currentRecipe) return;
    if(!confirm(_currentRecipe + ' reçetesini silmek istediğinize emin misiniz?')) return;
    var recipes = rc.getRecipes();
    delete recipes[_currentRecipe];
    rc.setRecipes(recipes);
    _currentRecipe = null;
    document.getElementById('rcEditorDiv').style.display='none';
    document.getElementById('rcEditorPlaceholder').style.display='';
    window.rcRenderYemekList();
  };

  // ── Excel ile reçete yükleme ───────────────────────────────
  // Format beklentisi:
  //   A sütunu: Yemek Adı
  //   B sütunu: Malzeme Adı
  //   C sütunu: Gramaj (g/kişi)
  //   D sütunu (opsiyonel): Fiyat (₺/kg)
  //
  // Veya tek yemek formatı:
  //   A sütunu: Malzeme Adı
  //   B sütunu: Gramaj (g/kişi)
  //   C sütunu (opsiyonel): Fiyat (₺/kg)
  window.rcImportExcel = function(input){
    var file = input.files[0];
    if(!file) return;
    input.value = '';

    if(typeof XLSX === 'undefined'){
      alert('Excel kütüphanesi yüklenemedi. Sayfayı yenileyip tekrar deneyin.');
      return;
    }

    var reader = new FileReader();
    reader.onload = function(e){
      try {
        var wb = XLSX.read(e.target.result, {type:'array'});
        var ws = wb.Sheets[wb.SheetNames[0]];
        var rows = XLSX.utils.sheet_to_json(ws, {header:1, defval:''});

        if(!rows.length){ alert('Excel dosyası boş.'); return; }

        // Başlık satırını kontrol et
        var headerRow = rows[0];
        var hasHeader = false;
        if(headerRow && headerRow.length > 0){
          var h0 = String(headerRow[0]).toLowerCase().trim();
          if(h0.indexOf('yemek')>=0 || h0.indexOf('malzeme')>=0 || h0.indexOf('ad')>=0 || h0===''){
            hasHeader = true;
          }
        }
        var dataRows = hasHeader ? rows.slice(1) : rows;
        dataRows = dataRows.filter(function(r){ return r.some(function(c){ return c!=='' && c!=null; }); });

        if(!dataRows.length){ alert('Veri satırı bulunamadı.'); return; }

        // Format tespiti: 4+ sütun = çok yemekli, 2-3 sütun = tek yemekli
        var maxCols = 0;
        dataRows.forEach(function(r){ if(r.length > maxCols) maxCols = r.length; });

        var recipes = rc.getRecipes();
        var prices = rc.getPrices();
        var importedCount = 0;
        var importedDishes = [];

        if(maxCols >= 4){
          // Çok yemekli format: Yemek | Malzeme | Gram | Fiyat
          var currentDish = '';
          dataRows.forEach(function(r){
            var dish = String(r[0]||'').trim();
            var malzeme = String(r[1]||'').trim();
            var gram = parseFloat(r[2]) || 0;
            var fiyat = parseFloat(r[3]) || 0;

            if(dish) currentDish = dish;
            if(!currentDish || !malzeme) return;

            if(!recipes[currentDish]){
              recipes[currentDish] = [];
              importedDishes.push(currentDish);
            }
            // Aynı malzeme varsa güncelle, yoksa ekle
            var existing = recipes[currentDish].find(function(i){ return i.name === malzeme; });
            if(existing){
              existing.gram = gram;
            } else {
              recipes[currentDish].push({name: malzeme, gram: gram});
            }
            if(fiyat > 0) prices[malzeme] = fiyat;
            importedCount++;
          });
        } else {
          // Tek yemekli format: Malzeme | Gram | Fiyat
          var dishName = prompt('Bu reçetenin yemek adını girin:');
          if(!dishName || !dishName.trim()) return;
          dishName = dishName.trim();

          if(!recipes[dishName]) recipes[dishName] = [];
          importedDishes.push(dishName);

          dataRows.forEach(function(r){
            var malzeme = String(r[0]||'').trim();
            var gram = parseFloat(r[1]) || 0;
            var fiyat = parseFloat(r[2]) || 0;

            if(!malzeme) return;
            var existing = recipes[dishName].find(function(i){ return i.name === malzeme; });
            if(existing){
              existing.gram = gram;
            } else {
              recipes[dishName].push({name: malzeme, gram: gram});
            }
            if(fiyat > 0) prices[malzeme] = fiyat;
            importedCount++;
          });
        }

        rc.setRecipes(recipes);
        try{ localStorage.setItem('uysa_prices_tl_per_kg_v1', JSON.stringify(prices)); }catch(e){}

        alert('Excel yüklendi!\n' + importedDishes.length + ' yemek, ' + importedCount + ' malzeme satırı aktarıldı.\n\nYemekler: ' + importedDishes.join(', '));
        window.rcRenderYemekList();

        // İlk yüklenen yemeği aç
        if(importedDishes.length > 0) window.rcOpenRecipe(importedDishes[0]);

      } catch(err) {
        console.error('[Recete] Excel import error:', err);
        alert('Excel okunamadı: ' + err.message);
      }
    };
    reader.readAsArrayBuffer(file);
  };

  // ── Malzeme datalist (autocomplete) ────────────────────────
  function _ensureDatalist(){
    if(document.getElementById('rcMalzemeDatalist')) return;
    var dl = document.createElement('datalist');
    dl.id = 'rcMalzemeDatalist';
    var prices = rc.getPrices();
    Object.keys(prices).sort().forEach(function(k){
      var opt = document.createElement('option');
      opt.value = k;
      dl.appendChild(opt);
    });
    document.body.appendChild(dl);
  }

  var _origRender = window.rcRenderYemekList;
  window.rcRenderYemekList = function(){
    _ensureDatalist();
    _origRender();
  };

})();
