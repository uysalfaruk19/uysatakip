// ═══════════════════════════════════════════════════════════════
// REÇETE & MALİYET MODÜLÜ — Core Utilities
// ═══════════════════════════════════════════════════════════════

(function(){
  'use strict';

  // ── Storage helpers ──────────────────────────────────────────
  const _ls = {
    get(k, def){ try{ const v=localStorage.getItem(k); return v?JSON.parse(v):def; }catch{ return def; } },
    set(k, v){ try{ localStorage.setItem(k, JSON.stringify(v)); }catch(e){ console.warn('[Recete] storage err',e); } }
  };

  // Storage keys
  const RECIPES_KEY  = 'uysa_recipes_v2';
  const PRICES_KEY   = 'uysa_prices_tl_per_kg_v1';
  const HISTORY_KEY  = 'uysa_recipe_history_v1';
  const MENU_KEY     = 'uysa_menu_grid_v2_dates';
  const GUNLUK_KEY   = 'uysa_gunluk_uretim';
  const URETIM_KEY   = 'uysa_uretim_gider';
  const CATALOG_KEY  = 'uysa_catalog_v1';

  // ── Data access functions ────────────────────────────────────
  window._rc = window._rc || {};

  _rc.getRecipes  = function(){ return _ls.get(RECIPES_KEY, {}); };
  _rc.setRecipes  = function(d){ _ls.set(RECIPES_KEY, d); };
  _rc.getPrices   = function(){ return _ls.get(PRICES_KEY, {}); };
  _rc.getHistory  = function(){ return _ls.get(HISTORY_KEY, []); };
  _rc.setHistory  = function(d){ _ls.set(HISTORY_KEY, d); };
  _rc.getGunluk   = function(){ return _ls.get(GUNLUK_KEY, []); };
  _rc.getUretimGider = function(){ return _ls.get(URETIM_KEY, []); };
  _rc.setUretimGider = function(d){ _ls.set(URETIM_KEY, d); };
  _rc.getCatalog  = function(){ return _ls.get(CATALOG_KEY, {}); };

  // ── Format helpers ───────────────────────────────────────────
  _rc.fmt = function(n, dec){
    dec = dec !== undefined ? dec : 2;
    return (Number(n)||0).toLocaleString('tr-TR',{minimumFractionDigits:dec,maximumFractionDigits:dec});
  };
  _rc.fmtTL = function(n){ return _rc.fmt(n) + ' \u20BA'; };

  // ── Get all known dish names ─────────────────────────────────
  _rc.getAllDishNames = function(){
    const set = new Set();
    // From recipes
    Object.keys(_rc.getRecipes()).forEach(function(k){ set.add(k); });
    // From catalog
    var cat = _rc.getCatalog();
    if(cat.soups) cat.soups.forEach(function(x){ set.add(x); });
    if(cat.mainsByType) Object.values(cat.mainsByType).forEach(function(arr){ arr.forEach(function(x){ set.add(x); }); });
    if(cat.sidesByGroup) Object.values(cat.sidesByGroup).forEach(function(arr){ arr.forEach(function(x){ set.add(x); }); });
    return Array.from(set).filter(Boolean).sort(function(a,b){ return a.localeCompare(b,'tr'); });
  };

  // ── Get day menu from menu store ─────────────────────────────
  _rc.getDayMenu = function(dateStr){
    // dateStr = "YYYY-MM-DD"
    var store;
    try{ store = JSON.parse(localStorage.getItem(MENU_KEY)||'{}'); }catch{ return null; }
    var parts = dateStr.split('-');
    var y = parseInt(parts[0]), m1 = parseInt(parts[1]), d = parseInt(parts[2]);
    var dt = new Date(y, m1-1, d);
    var dow = (dt.getDay()+6)%7; // 0=Mon

    // Find week index
    function mondays(yr, m0){
      var arr=[], first=new Date(yr,m0,1);
      var shift=(first.getDay()+6)%7;
      var mon=new Date(first); mon.setDate(1-shift);
      for(var i=0;i<6;i++){
        var m=new Date(mon); m.setDate(mon.getDate()+7*i);
        if(m.getMonth()<=m0 || (m.getMonth()===m0+1 && m.getDate()<=7)) arr.push(m);
        if(arr.length>0 && m.getMonth()>m0) break;
      }
      return arr;
    }
    var mons = mondays(y, m1-1);
    var wi = -1;
    for(var i=0;i<mons.length;i++){
      var end = new Date(mons[i]); end.setDate(mons[i].getDate()+6);
      if(dt>=mons[i] && dt<=end){ wi=i+1; break; }
    }
    if(wi<0) return null;

    // Try each customer then GENEL
    var customers = Object.keys(store).filter(function(k){ return k.startsWith(y+'-'); }).map(function(k){ return k.split('::')[1]||'GENEL'; });
    customers = Array.from(new Set(customers));
    if(customers.indexOf('GENEL')<0) customers.push('GENEL');

    var dishes = [];
    for(var ci=0;ci<customers.length;ci++){
      var yk = y+'-'+(m1<10?'0':'')+m1+'::'+customers[ci];
      var w = store[yk]&&store[yk].weeks&&store[yk].weeks['W'+wi];
      if(!w) continue;
      var items = [
        w.soups&&w.soups[dow], w.soups2&&w.soups2[dow],
        w.mains&&w.mains[dow], w.mains2&&w.mains2[dow],
        w.sides&&w.sides[dow], w.sides2&&w.sides2[dow],
        w.salads&&w.salads[dow],
        w.dessertFruitName&&w.dessertFruitName[dow],
        w.ayran&&w.ayran[dow]
      ];
      items.forEach(function(v){ if(v && v.trim()) dishes.push(v.trim()); });
    }
    return Array.from(new Set(dishes));
  };

  // ── Tab switching ────────────────────────────────────────────
  window.switchReceteTab = function(tab){
    document.querySelectorAll('.rc-tab').forEach(function(t){ t.style.display='none'; });
    var el = document.getElementById('rc-'+tab);
    if(el) el.style.display='';
    // Button active styles
    ['kutuphane','gunluk','analiz','gecmis'].forEach(function(t){
      var btn = document.getElementById('rcTab'+t.charAt(0).toUpperCase()+t.slice(1));
      if(btn) btn.style.background = (t===tab) ? '#0b3aa6' : '';
      if(btn) btn.style.color = (t===tab) ? '#fff' : '';
    });
    // Tab init callbacks
    if(tab==='kutuphane' && typeof window.rcRenderYemekList==='function') window.rcRenderYemekList();
    if(tab==='gunluk') { var inp=document.getElementById('rcGunTarih'); if(inp&&!inp.value) inp.value=new Date().toISOString().slice(0,10); if(typeof window.rcLoadGunlukMenu==='function') window.rcLoadGunlukMenu(); }
    if(tab==='analiz' && typeof window.rcInitAnaliz==='function') window.rcInitAnaliz();
    if(tab==='gecmis' && typeof window.rcInitGecmis==='function') window.rcInitGecmis();
  };

})();
