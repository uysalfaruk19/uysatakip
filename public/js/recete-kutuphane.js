// ═══════════════════════════════════════════════════════════════
// REÇETE & MALİYET MODÜLÜ — Reçete Kütüphanesi
// ═══════════════════════════════════════════════════════════════

(function(){
  'use strict';
  var rc = window._rc;
  var _currentRecipe = null; // Currently editing recipe name

  // ── Yemek listesi render ─────────────────────────────────────
  window.rcRenderYemekList = function(){
    var div = document.getElementById('rcYemekListDiv');
    if(!div) return;
    var recipes = rc.getRecipes();
    var names = rc.getAllDishNames();
    var filter = (document.getElementById('rcYemekAra')?.value||'').toLowerCase();
    if(filter) names = names.filter(function(n){ return n.toLowerCase().indexOf(filter)>=0; });

    if(!names.length){ div.innerHTML='<div style="color:#94a3b8;padding:10px">Henüz yemek yok.</div>'; return; }

    div.innerHTML = names.map(function(name){
      var hasRecipe = recipes[name] && recipes[name].length > 0;
      var bg = (name===_currentRecipe) ? '#dbeafe' : (hasRecipe ? '#f0fdf4' : '#fff');
      var badge = hasRecipe ? '<span style="color:#16a34a;font-size:10px">&#10003; reçete</span>' : '<span style="color:#d97706;font-size:10px">reçete yok</span>';
      return '<div onclick="rcOpenRecipe(\''+name.replace(/'/g,"\\'")+'\')" style="padding:8px 10px;border-bottom:1px solid #f1f5f9;cursor:pointer;background:'+bg+';border-radius:6px;margin-bottom:2px;display:flex;justify-content:space-between;align-items:center" onmouseover="this.style.background=\'#eff6ff\'" onmouseout="this.style.background=\''+bg+'\'">'
        +'<span style="font-weight:600">'+name+'</span>'+badge+'</div>';
    }).join('');
  };

  window.rcFilterYemekList = function(){ window.rcRenderYemekList(); };

  // ── Yeni yemek ekle ──────────────────────────────────────────
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

  // ── Reçete aç ────────────────────────────────────────────────
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

    // Kişi sayısını 1'e sıfırla (kişi başı gramaj modu)
    var kisiInp = document.getElementById('rcKisiSayisi');
    if(kisiInp) kisiInp.value = '1';
    var ters = document.getElementById('rcTersHesap');
    if(ters) ters.checked = false;

    // Mevcut reçeteyi yükle
    var recipes = rc.getRecipes();
    var ings = recipes[name] || [];
    var prices = rc.getPrices();

    ings.forEach(function(ing){
      _addRow(ing.name, ing.gram, prices[ing.name]||0);
    });

    if(!ings.length){
      // Boş reçete - 3 boş satır ekle
      _addRow('','',0);
      _addRow('','',0);
      _addRow('','',0);
    }

    window.rcRecalc();
    window.rcRenderYemekList();
  };

  // ── Malzeme satırı ekle ──────────────────────────────────────
  function _addRow(name, gram, price){
    var tbody = document.getElementById('rcIngredientTbody');
    if(!tbody) return;
    var tr = document.createElement('tr');
    tr.style.borderBottom = '1px solid #f1f5f9';

    var ters = document.getElementById('rcTersHesap');
    var isTers = ters && ters.checked;

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

  // ── Malzeme adı girilince fiyat otomatik doldur ──────────────
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

  // ── Ters hesaplama: toplam KG girilince gramaj hesapla ───────
  window.rcOnTotalKgInput = function(inp){
    var ters = document.getElementById('rcTersHesap');
    if(!ters || !ters.checked) return; // Sadece ters modda çalışsın
    var kisi = parseInt(document.getElementById('rcKisiSayisi')?.value) || 1;
    var totalKg = parseFloat(inp.value) || 0;
    var gramPerPerson = kisi > 0 ? (totalKg * 1000 / kisi) : 0;
    var tr = inp.closest('tr');
    var gramInp = tr.querySelector('.rc-gram-inp');
    if(gramInp) gramInp.value = gramPerPerson.toFixed(1);
    window.rcRecalc();
  };

  // ── Hesaplama (recalc) ───────────────────────────────────────
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
        // Ters mod: kg input'tan oku, gramı hesapla
        kg = parseFloat(kgInp?.value) || 0;
        // gramInp güncelleme rcOnTotalKgInput'ta yapılıyor
      } else {
        // Normal mod: gramdan kg hesapla
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

  // ── Reçete kaydet ────────────────────────────────────────────
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

    // Fiyatları da güncelle
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

  // ── Reçete sil ───────────────────────────────────────────────
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

  // ── Malzeme datalist (autocomplete) ──────────────────────────
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

  // Init datalist on first load
  var _origRender = window.rcRenderYemekList;
  window.rcRenderYemekList = function(){
    _ensureDatalist();
    _origRender();
  };

})();
