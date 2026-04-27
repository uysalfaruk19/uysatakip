/* ═══════════════════════════════════════════════════════════════════════
   UYSA v78 PATCH  —  Navigation fix, CRM rename modal, customer list,
                       sticky summary, document folder save, finance monthly report
   ═══════════════════════════════════════════════════════════════════════ */
(function(){
  'use strict';

  var _ls = {
    get: function(k, d){ try{ var v=localStorage.getItem(k); return v===null?d:JSON.parse(v); }catch(e){ return d; } },
    set: function(k, v){ try{ localStorage.setItem(k, JSON.stringify(v)); }catch(e){} }
  };

  function _today(){
    var d=new Date();
    return d.getFullYear()+'-'+(''+(d.getMonth()+1)).padStart(2,'0')+'-'+(''+d.getDate()).padStart(2,'0');
  }

  function _fmt(n){
    return Number(n||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});
  }

  function _toast(msg){
    if(typeof window.toast === 'function'){ window.toast(msg); return; }
    var t=document.createElement('div');
    t.textContent=msg;
    t.style.cssText='position:fixed;bottom:24px;right:24px;background:#1e293b;color:#fff;padding:12px 20px;border-radius:10px;font-size:13px;z-index:99999;max-width:360px;box-shadow:0 4px 20px rgba(0,0,0,.35)';
    document.body.appendChild(t);
    setTimeout(function(){ t.remove(); }, 3000);
  }

  function _getCustomers(){
    try{
      var raw=localStorage.getItem('uysa_customers_v1');
      var pasif=_ls.get('uysa_pasif_musteriler',[]);
      if(!raw) return [];
      var obj=JSON.parse(raw);
      var all=(obj.customers||[]).filter(function(x){ return x&&x!=='GENEL'; });
      var aktif=all.filter(function(c){ return !pasif.includes(c); });
      return aktif;
    }catch(e){ return []; }
  }

  function _getGunlukSayilar(){
    return _ls.get('uysa_gunluk_uretim',[]);
  }

  /* ────────────────────────────────────────────────────────────────────
     1. NAV LISTENER FIX  — mevcut listener'lar üstüne tek temiz handler
     ──────────────────────────────────────────────────────────────────── */
  function _initNav(){
    var navBtns = document.querySelectorAll('.mod-nav-item');
    var panels  = document.querySelectorAll('.mod-panel');
    if(!navBtns.length) return;

    navBtns.forEach(function(btn){
      // Eski listener'ları sil - clone trick
      var fresh = btn.cloneNode(true);
      btn.parentNode.replaceChild(fresh, btn);
    });

    // Yeni temiz listener
    document.querySelectorAll('.mod-nav-item').forEach(function(btn){
      btn.addEventListener('click', function(){
        var target = btn.dataset.module;
        document.querySelectorAll('.mod-nav-item').forEach(function(b){ b.classList.remove('active'); });
        document.querySelectorAll('.mod-panel').forEach(function(p){ p.classList.remove('active'); });
        btn.classList.add('active');
        var panel = document.getElementById('mod-'+target);
        if(panel) panel.classList.add('active');

        // Module-specific actions
        if(target==='anasayfa' && typeof window.renderAnasayfa==='function') { try{ setTimeout(window.renderAnasayfa, 100); }catch(e){} }
        if(target==='finans' && typeof window.refreshFinStats==='function') { try{ window.refreshFinStats(); }catch(e){} }
        if(target==='stok' && typeof window.refreshStok==='function') { try{ window.refreshStok(); }catch(e){} }
        if(target==='raporlama' && typeof window.refreshRaporlama==='function') { try{ window.refreshRaporlama(); }catch(e){} }
        if(target==='ik' && typeof window.refreshIkSelectors==='function') { try{ window.refreshIkSelectors(); }catch(e){} }
        if(target==='satis'){
          if(typeof window.syncCrmCustomers==='function') try{ window.syncCrmCustomers(); }catch(e){}
          if(typeof window.syncAllCustomerDropdowns==='function') try{ window.syncAllCustomerDropdowns(); }catch(e){}
          setTimeout(function(){ v75RenderMusteriListesi(); }, 150);
        }
        if(target==='satinalma' && typeof window.refreshAnalizFirma==='function') { try{ window.refreshAnalizFirma(); }catch(e){} }
        if(target==='dokuman') { setTimeout(function(){ if(typeof window.renderDokumanlar==='function') window.renderDokumanlar(); }, 100); }
      });
    });
    console.log('✅ v75 nav initialized');
  }

  /* ────────────────────────────────────────────────────────────────────
     2. CRM MÜŞTERİ LISTESI
     ──────────────────────────────────────────────────────────────────── */
  window.v75RenderMusteriListesi = function(){
    var div = document.getElementById('v75MusteriListeDiv');
    if(!div) return;
    var custs = _getCustomers();
    if(!custs.length){
      div.innerHTML='<div style="color:var(--muted);padding:10px">Henüz müşteri eklenmemiş.</div>';
      return;
    }

    var buAy = _today().slice(0,7); // YYYY-MM
    var gunlukArr = _getGunlukSayilar();

    var rows = custs.map(function(cust, idx){
      var crm = _ls.get('uysa_crm_'+cust, {});
      var defaultKisi = crm.kisi || 0;
      var defaultFiyat = crm.fiyat || 0;

      // Bu ay toplam
      var ayKayitlar = gunlukArr.filter(function(u){ return u.musteri===cust && (u.tarih||'').startsWith(buAy); });
      var ayToplamKisi = ayKayitlar.reduce(function(s,u){ return s+(u.kisi||0); }, 0);
      var ayGun = ayKayitlar.length;
      var ayToplamTutar = ayKayitlar.reduce(function(s,u){ return s+(u.tutar||0); }, 0);

      // Bugün var mı?
      var bugun = _today();
      var bugunKayit = gunlukArr.find(function(u){ return u.musteri===cust && u.tarih===bugun; });

      return '<tr style="background:'+(idx%2===0?'#fff':'#f8fafc')+'">'
        +'<td style="padding:8px 10px;font-weight:700;color:#1e293b;border-bottom:1px solid #e2e8f0">'
        +'<span style="cursor:pointer;color:#2563eb;text-decoration:underline" onclick="(function(){'
        +'var s=document.getElementById(\'crmCustomerSel\');if(s){s.value=\''+cust+'\';if(typeof crmLoadData===\'function\')crmLoadData();}'
        +'var btn=document.querySelector(\'[data-module=crmModule]\');'
        +'})()">'
        +cust+'</span>'
        +(bugunKayit?'<span style="color:#16a34a;font-size:10px;margin-left:4px">✓ bugün</span>':'')
        +'</td>'
        +'<td style="padding:8px 10px;text-align:center;border-bottom:1px solid #e2e8f0;color:#64748b">'+(defaultKisi||'-')+'</td>'
        +'<td style="padding:8px 10px;text-align:center;border-bottom:1px solid #e2e8f0;color:#64748b">'+(defaultFiyat?_fmt(defaultFiyat)+' ₺':'-')+'</td>'
        +'<td style="padding:8px 10px;text-align:center;border-bottom:1px solid #e2e8f0">'+(ayToplamKisi||'-')+'</td>'
        +'<td style="padding:8px 10px;text-align:center;border-bottom:1px solid #e2e8f0">'+(ayGun||'-')+'</td>'
        +'<td style="padding:8px 10px;text-align:right;font-weight:700;color:#166534;border-bottom:1px solid #e2e8f0">'+(ayToplamTutar?_fmt(ayToplamTutar)+' ₺':'-')+'</td>'
        +'<td style="padding:8px 10px;text-align:center;border-bottom:1px solid #e2e8f0">'
        +'<button onclick="v75OpenRenameModal(\''+cust.replace(/'/g,"\\'")+'\')" style="border:none;background:#eff6ff;color:#2563eb;padding:4px 8px;border-radius:6px;cursor:pointer;font-size:11px">✏️ Yeniden Adlandır</button>'
        +'</td>'
        +'</tr>';
    }).join('');

    div.innerHTML = '<table style="width:100%;border-collapse:collapse;font-size:12px">'
      +'<thead><tr style="background:#eef3ff">'
      +'<th style="padding:8px 10px;text-align:left;border-bottom:2px solid #c7d2fe">Müşteri</th>'
      +'<th style="padding:8px 10px;text-align:center;border-bottom:2px solid #c7d2fe">Varsayılan Kişi</th>'
      +'<th style="padding:8px 10px;text-align:center;border-bottom:2px solid #c7d2fe">Birim Fiyat</th>'
      +'<th style="padding:8px 10px;text-align:center;border-bottom:2px solid #c7d2fe">Bu Ay Kişi</th>'
      +'<th style="padding:8px 10px;text-align:center;border-bottom:2px solid #c7d2fe">Gün</th>'
      +'<th style="padding:8px 10px;text-align:right;border-bottom:2px solid #c7d2fe">Bu Ay Toplam</th>'
      +'<th style="padding:8px 10px;text-align:center;border-bottom:2px solid #c7d2fe">İşlem</th>'
      +'</tr></thead>'
      +'<tbody>'+rows+'</tbody>'
      +'</table>';
  };

  /* ────────────────────────────────────────────────────────────────────
     3. CRM RENAME MODAL
     ──────────────────────────────────────────────────────────────────── */
  function _buildRenameModal(){
    if(document.getElementById('v75RenameModal')) return;
    var m = document.createElement('div');
    m.id = 'v75RenameModal';
    m.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99999;align-items:center;justify-content:center;';
    m.innerHTML = '<div style="background:#fff;border-radius:16px;padding:28px 32px;width:380px;max-width:95vw;box-shadow:0 8px 40px rgba(0,0,0,.25)">'
      +'<div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap">'
      +'<div id="offlineQueueCard" style="flex:1;background:#fef3c7;border:1px solid #f59e0b;border-radius:10px;padding:10px;min-width:150px;display:none">'
      +'<div style="font-size:10px;color:#92400e;font-weight:700;margin-bottom:3px">⚠️ OFFLİNE KUYRUK</div>'
      +'<div id="offlineQueueCount" style="font-size:24px;font-weight:800;color:#b45309">0</div>'
      +'<div style="font-size:10px;color:#78350f">bekleyen kayıt (sunucuya gönderilmedi)</div>'
      +'</div>'
      +'<div style="flex:2;background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:10px;min-width:200px">'
      +'<div style="font-size:10px;color:#166534;font-weight:700;margin-bottom:6px">💾 TAM YEDEKLEME</div>'
      +'<div style="display:flex;gap:6px">'
      +'<button onclick="window._uysaExportAll()" style="flex:1;padding:7px;background:#16a34a;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600">📤 JSON İndir</button>'
      +'<label style="flex:1;padding:7px;background:#0ea5e9;color:#fff;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;text-align:center;display:flex;align-items:center;justify-content:center">'
      +'📥 Geri Yükle'
      +'<input type="file" accept=".json" onchange="window._uysaImportAll(this.files[0])" style="display:none">'
      +'</label>'
      +'</div>'
      +'</div>'
      +'</div>'
      +'<h3 style="margin:0 0 16px;color:#1e293b">✏️ Müşteri Adını Değiştir</h3>'
      +'<div style="margin-bottom:12px"><label style="font-size:13px;color:#64748b">Mevcut Ad</label>'
      +'<div id="v75RenameCurrentName" style="font-weight:700;color:#1e293b;font-size:15px;padding:6px 0"></div></div>'
      +'<div style="margin-bottom:16px"><label style="font-size:13px;color:#64748b">Yeni Ad</label>'
      +'<input id="v75RenameInput" style="width:100%;padding:10px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:14px;margin-top:4px" placeholder="Yeni müşteri adını girin..."/>'
      +'<div id="v75RenameError" style="color:#dc2626;font-size:12px;margin-top:4px;min-height:16px"></div></div>'
      +'<div style="display:flex;gap:10px;justify-content:flex-end">'
      +'<button onclick="v75CloseRenameModal()" style="padding:9px 18px;border:1.5px solid #cbd5e1;background:#fff;border-radius:8px;cursor:pointer;font-size:13px">İptal</button>'
      +'<button onclick="v75DoRename()" style="padding:9px 18px;background:#2563eb;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:700">✅ Kaydet</button>'
      +'</div></div>';
    document.body.appendChild(m);

    // Enter/Escape
    document.getElementById('v75RenameInput').addEventListener('keydown', function(e){
      if(e.key==='Enter') v75DoRename();
      if(e.key==='Escape') v75CloseRenameModal();
    });
  }

  window.v75OpenRenameModal = function(currentName){
    _buildRenameModal();
    var modal = document.getElementById('v75RenameModal');
    document.getElementById('v75RenameCurrentName').textContent = currentName;
    var inp = document.getElementById('v75RenameInput');
    inp.value = currentName;
    document.getElementById('v75RenameError').textContent = '';
    modal.style.display = 'flex';
    modal._current = currentName;
    setTimeout(function(){ inp.select(); inp.focus(); }, 50);
  };

  window.v75CloseRenameModal = function(){
    var m = document.getElementById('v75RenameModal');
    if(m) m.style.display = 'none';
  };

  window.v75DoRename = function(){
    var modal = document.getElementById('v75RenameModal');
    var current = modal ? modal._current : '';
    var newName = (document.getElementById('v75RenameInput')?.value||'').trim();
    var errDiv  = document.getElementById('v75RenameError');

    if(!newName){ errDiv.textContent='Ad boş bırakılamaz.'; return; }
    if(newName===current){ errDiv.textContent='Ad değişmedi.'; return; }
    if(newName==='GENEL'){ errDiv.textContent='GENEL adı kullanılamaz.'; return; }

    // Duplicate check
    var cs = (function(){
      try{ var r=localStorage.getItem('uysa_customers_v1'); return r?JSON.parse(r):{customers:[]}; }catch(e){ return {customers:[]}; }
    })();
    if((cs.customers||[]).includes(newName)){ errDiv.textContent='Bu isim zaten kayıtlı: '+newName; return; }

    // 1. CustomerStore
    var idx = (cs.customers||[]).indexOf(current);
    if(idx>=0){ cs.customers[idx]=newName; cs.customers.sort(function(a,b){ return a.localeCompare(b,'tr'); }); }
    localStorage.setItem('uysa_customers_v1', JSON.stringify(cs));

    // 2. Menu store keys
    ['uysa_menu_v1','uysa_menu_grid_v2_dates'].forEach(function(sk){
      try{
        var raw=localStorage.getItem(sk); if(!raw) return;
        var store=JSON.parse(raw); var ns={};
        var esc=current.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
        Object.keys(store).forEach(function(k){
          var nk=k.replace(new RegExp('::'+esc+'(::.*)?$'), function(m){ return m.replace('::'+current,'::'+newName); });
          ns[nk]=store[k];
        });
        localStorage.setItem(sk, JSON.stringify(ns));
      }catch(e){}
    });

    // 3. CRM data
    try{
      var crmData=_ls.get('uysa_crm_'+current,null);
      if(crmData){ _ls.set('uysa_crm_'+newName,crmData); localStorage.removeItem('uysa_crm_'+current); }
    }catch(e){}

    // 4. Pasif list
    try{
      var p=_ls.get('uysa_pasif_musteriler',[]);
      var pi=p.indexOf(current); if(pi>=0){ p[pi]=newName; _ls.set('uysa_pasif_musteriler',p); }
    }catch(e){}

    // 5. Gelir / Günlük üretim records
    try{
      var gelirler=_ls.get('uysa_gelirler',[]);
      _ls.set('uysa_gelirler', gelirler.map(function(g){ return g.musteri===current?Object.assign({},g,{musteri:newName}):g; }));
      var gunluk=_ls.get('uysa_gunluk_uretim',[]);
      _ls.set('uysa_gunluk_uretim', gunluk.map(function(u){ return u.musteri===current?Object.assign({},u,{musteri:newName}):u; }));
    }catch(e){}

    // 6. All dropdowns
    document.querySelectorAll('select').forEach(function(sel){
      Array.from(sel.options).forEach(function(opt){
        if(opt.value===current){ opt.value=newName; opt.textContent=opt.textContent.replace(current,newName); }
      });
    });

    // 7. CRM selector set new value
    var crmSel=document.getElementById('crmCustomerSel');
    if(crmSel && crmSel.value===current){
      crmSel.value=newName;
      if(typeof window.crmLoadData==='function') try{ window.crmLoadData(); }catch(e){}
    }

    // 8. Sync all
    if(typeof window.syncAllCustomerDropdowns==='function') try{ window.syncAllCustomerDropdowns(); }catch(e){}

    v75CloseRenameModal();
    v75RenderMusteriListesi();
    _toast('✅ Müşteri adı değiştirildi: '+current+' → '+newName);
  };

  // Override original rename to use our modal
  window.crmMusteriYendenAdlandir = function(){
    var crmSel=document.getElementById('crmCustomerSel');
    var current=crmSel?.value;
    if(!current){ _toast('Önce bir müşteri seçin.'); return; }
    if(current==='GENEL'){ _toast('GENEL adı değiştirilemez.'); return; }
    window.v75OpenRenameModal(current);
  };

  /* ────────────────────────────────────────────────────────────────────
     4. FİNANS — AYLIK CARİ RAPOR
     ──────────────────────────────────────────────────────────────────── */
  window.v75RenderAylikRapor = function(){
    var div = document.getElementById('v75AylikRaporDiv');
    if(!div) return;

    var tarihEl = document.getElementById('gs-tarih');
    var tarih   = tarihEl ? tarihEl.value : _today();
    if(!tarih) tarih = _today();
    var buAy = tarih.slice(0,7); // YYYY-MM

    var custs    = _getCustomers();
    var gunlukArr = _getGunlukSayilar();

    // Bu aya ait kayıtlar
    var ayKayitlar = gunlukArr.filter(function(u){ return (u.tarih||'').startsWith(buAy) && u.musteri && u.musteri!=='GENEL'; });

    if(!ayKayitlar.length){
      div.innerHTML = '<div style="color:var(--muted);padding:12px">📭 '
        +buAy+' ayına ait henüz kayıt bulunmuyor. Tabloyu yükleyip kaydedin.</div>';
      return;
    }

    // Müşteri bazlı grupla
    var musteriMap = {};
    ayKayitlar.forEach(function(u){
      if(!musteriMap[u.musteri]) musteriMap[u.musteri] = [];
      musteriMap[u.musteri].push(u);
    });

    // Ay adı
    var ayAdlari = ['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran',
                    'Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
    var parts = buAy.split('-');
    var ayAdi = ayAdlari[parseInt(parts[1],10)-1]+' '+parts[0];

    // Özet kartlar
    var toplamMusteriler = Object.keys(musteriMap).length;
    var toplamGunSayisi  = new Set(ayKayitlar.map(function(u){ return u.tarih; })).size;
    var toplamKisi       = ayKayitlar.reduce(function(s,u){ return s+(u.kisi||0); }, 0);
    var toplamTutar      = ayKayitlar.reduce(function(s,u){ return s+(u.tutar||0); }, 0);

    var cardsHtml = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:14px">'
      +'<div style="background:#eff6ff;border-radius:10px;padding:12px;text-align:center">'
      +'<div style="font-size:22px">🏢</div><div style="font-weight:800;font-size:18px;color:#2563eb">'+toplamMusteriler+'</div><div style="font-size:11px;color:#64748b">Müşteri</div></div>'
      +'<div style="background:#f0fdf4;border-radius:10px;padding:12px;text-align:center">'
      +'<div style="font-size:22px">📅</div><div style="font-weight:800;font-size:18px;color:#16a34a">'+toplamGunSayisi+'</div><div style="font-size:11px;color:#64748b">Kayıtlı Gün</div></div>'
      +'<div style="background:#fef9c3;border-radius:10px;padding:12px;text-align:center">'
      +'<div style="font-size:22px">👥</div><div style="font-weight:800;font-size:18px;color:#ca8a04">'+toplamKisi.toLocaleString('tr-TR')+'</div><div style="font-size:11px;color:#64748b">Toplam Kişi</div></div>'
      +'<div style="background:#fdf4ff;border-radius:10px;padding:12px;text-align:center">'
      +'<div style="font-size:22px">💰</div><div style="font-weight:800;font-size:16px;color:#7c3aed">'+_fmt(toplamTutar)+' ₺</div><div style="font-size:11px;color:#64748b">Aylık Toplam</div></div>'
      +'</div>';

    // Müşteri tabloları
    var tableRows = '';
    Object.keys(musteriMap).sort(function(a,b){ return a.localeCompare(b,'tr'); }).forEach(function(cust){
      var kayitlar = musteriMap[cust].sort(function(a,b){ return (a.tarih||'').localeCompare(b.tarih||''); });
      var altTopKisi  = kayitlar.reduce(function(s,u){ return s+(u.kisi||0); }, 0);
      var altTopTutar = kayitlar.reduce(function(s,u){ return s+(u.tutar||0); }, 0);

      kayitlar.forEach(function(u, i){
        var isFirst = i===0;
        tableRows += '<tr style="background:'+(isFirst?'#eff6ff':'#fff')+'">'
          +'<td style="padding:8px 10px;font-weight:'+(isFirst?'700':'400')+';color:'+(isFirst?'#1e40af':'#475569')+';border-bottom:1px solid #e2e8f0">'+(isFirst?cust:'')+'</td>'
          +'<td style="padding:8px 10px;text-align:center;border-bottom:1px solid #e2e8f0;color:#64748b">'+u.tarih+'</td>'
          +'<td style="padding:8px 10px;text-align:center;border-bottom:1px solid #e2e8f0;font-weight:700">'+u.kisi+'</td>'
          +'<td style="padding:8px 10px;text-align:right;border-bottom:1px solid #e2e8f0;color:#64748b">'+_fmt(u.fiyat||0)+' ₺</td>'
          +'<td style="padding:8px 10px;text-align:right;border-bottom:1px solid #e2e8f0;font-weight:700;color:#166534">'+_fmt(u.tutar||0)+' ₺</td>'
          +'</tr>';
      });

      // Alt toplam
      tableRows += '<tr style="background:#f0fdf4">'
        +'<td colspan="2" style="padding:7px 10px;font-weight:800;color:#166534;font-size:12px;border-bottom:2px solid #bbf7d0">📊 '+cust+' Alt Toplam</td>'
        +'<td style="padding:7px 10px;text-align:center;font-weight:800;color:#166534;border-bottom:2px solid #bbf7d0">'+altTopKisi+'</td>'
        +'<td style="border-bottom:2px solid #bbf7d0"></td>'
        +'<td style="padding:7px 10px;text-align:right;font-weight:800;color:#166534;border-bottom:2px solid #bbf7d0">'+_fmt(altTopTutar)+' ₺</td>'
        +'</tr>';
    });

    // Genel toplam
    tableRows += '<tr style="background:#1e293b">'
      +'<td colspan="2" style="padding:10px;font-weight:900;color:#fff;font-size:13px">🏆 '+ayAdi+' — GENEL TOPLAM ('+toplamMusteriler+' müşteri, '+toplamGunSayisi+' gün)</td>'
      +'<td style="padding:10px;text-align:center;font-weight:900;color:#fbbf24;font-size:13px">'+toplamKisi.toLocaleString('tr-TR')+' kişi</td>'
      +'<td style="padding:10px"></td>'
      +'<td style="padding:10px;text-align:right;font-weight:900;color:#34d399;font-size:14px">'+_fmt(toplamTutar)+' ₺</td>'
      +'</tr>';

    var tableHtml = '<div style="overflow-x:auto"><table style="width:100%;font-size:13px;border-collapse:collapse;border-radius:10px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,.08)">'
      +'<thead><tr style="background:#eef3ff">'
      +'<th style="padding:10px;text-align:left;border-bottom:2px solid #c7d2fe">Müşteri</th>'
      +'<th style="padding:10px;text-align:center;border-bottom:2px solid #c7d2fe">Tarih</th>'
      +'<th style="padding:10px;text-align:center;border-bottom:2px solid #c7d2fe">Kişi Sayısı</th>'
      +'<th style="padding:10px;text-align:right;border-bottom:2px solid #c7d2fe">Fiyat (₺/kişi)</th>'
      +'<th style="padding:10px;text-align:right;border-bottom:2px solid #c7d2fe">Gün Toplamı (₺)</th>'
      +'</tr></thead>'
      +'<tbody>'+tableRows+'</tbody>'
      +'</table></div>';

    div.innerHTML = cardsHtml + tableHtml;
  };

  // gsRenderTable çağrıldığında aylık raporu da güncelle
  var _origGsRender = window.gsRenderTable;
  window.gsRenderTable = function(){
    if(typeof _origGsRender === 'function') _origGsRender.apply(this, arguments);
    setTimeout(v75RenderAylikRapor, 200);
  };

  /* ────────────────────────────────────────────────────────────────────
     5. DOKÜMAN KAYDETME — FILE SYSTEM ACCESS API ile klasöre kaydet
     ──────────────────────────────────────────────────────────────────── */
  var _dokFolder = null; // FileSystemDirectoryHandle

  window.v75SelectDokFolder = function(){
    if(!('showDirectoryPicker' in window)){
      alert('Klasöre kaydetme özelliği yalnızca Chrome veya Edge tarayıcısında çalışır.\nŞu an dosyalar sadece tarayıcı hafızasına kaydedilecek.');
      return;
    }
    window.showDirectoryPicker({mode:'readwrite'}).then(function(handle){
      _dokFolder = handle;
      var statusEl = document.getElementById('dokKlasorStatus');
      if(statusEl) statusEl.textContent = '📁 Klasör: '+handle.name;
      _toast('✅ Klasör seçildi: '+handle.name);
    }).catch(function(e){
      if(e.name!=='AbortError') console.warn('Klasör seçimi iptal:', e);
    });
  };

  async function _saveDokToFolder(fileName, dataUrl){
    if(!_dokFolder) return false;
    try{
      // dataUrl → Blob
      var arr = dataUrl.split(',');
      var mime = arr[0].match(/:(.*?);/)[1];
      var bstr = atob(arr[1]);
      var n = bstr.length;
      var u8 = new Uint8Array(n);
      while(n--) u8[n] = bstr.charCodeAt(n);
      var blob = new Blob([u8], {type:mime});

      var today = _today().replace(/-/g,'');
      var safeFileName = today+'_'+fileName.replace(/[/\\:*?"<>|]/g,'_');
      var fh = await _dokFolder.getFileHandle(safeFileName, {create:true});
      var writable = await fh.createWritable();
      await writable.write(blob);
      await writable.close();
      return safeFileName;
    }catch(e){
      console.error('Klasöre kaydetme hatası:', e);
      return false;
    }
  }

  // dokKaydetBtn listener'ını override et
  document.addEventListener('DOMContentLoaded', function(){
    // Klasör seç butonuna listener
    var folderBtn = document.getElementById('dokKlasorSecBtn');
    if(folderBtn) folderBtn.addEventListener('click', window.v75SelectDokFolder);

    // Kaydet butonunu override (clone trick)
    var saveBtn = document.getElementById('dokKaydetBtn');
    if(saveBtn){
      var freshSaveBtn = saveBtn.cloneNode(true);
      saveBtn.parentNode.replaceChild(freshSaveBtn, saveBtn);

      freshSaveBtn.addEventListener('click', async function(){
        var adi      = (document.getElementById('dok-adi')?.value||'').trim();
        var kategori = document.getElementById('dok-kategori')?.value||'diger';
        var tarih    = document.getElementById('dok-tarih')?.value||_today();
        var not      = document.getElementById('dok-not')?.value||'';
        var statusDiv= document.getElementById('dokKaydetStatus');

        if(!adi){ alert('Doküman adı gerekli.'); return; }

        var dosyaInput = document.getElementById('dok-dosya');
        var dokümanlar = _ls.get('uysa_dokumanlar',[]);

        function _afterSave(dosyaAdi, dosyaData){
          var dok = {adi:adi, kategori:kategori, tarih:tarih, not:not, dosyaAdi:dosyaAdi, dosyaData:dosyaData};
          dokümanlar.push(dok);
          _ls.set('uysa_dokumanlar', dokümanlar);
          if(typeof window.renderDokumanlar==='function') window.renderDokumanlar();
          if(dosyaInput) dosyaInput.value='';
          var adiEl=document.getElementById('dok-adi'); if(adiEl) adiEl.value='';
          var notEl=document.getElementById('dok-not'); if(notEl) notEl.value='';
          if(typeof window.autoSaveToFolder==='function') try{ window.autoSaveToFolder(); }catch(e){}
        }

        if(dosyaInput && dosyaInput.files && dosyaInput.files.length>0){
          var file = dosyaInput.files[0];
          if(file.size > 10*1024*1024){ alert('Dosya 10 MB sınırını aşıyor.'); return; }
          if(statusDiv) statusDiv.textContent = 'Dosya okunuyor...';

          var reader = new FileReader();
          reader.onload = async function(e){
            var dataUrl = e.target.result;
            var savedName = dosyaInput.files[0].name;

            if(_dokFolder){
              if(statusDiv) statusDiv.textContent = 'Klasöre kaydediliyor...';
              var result = await _saveDokToFolder(file.name, dataUrl);
              if(result){
                if(statusDiv) statusDiv.textContent = '✅ Klasöre kaydedildi: '+result;
                _toast('📁 Dosya klasöre kaydedildi: '+result);
              } else {
                if(statusDiv) statusDiv.textContent = '⚠️ Klasöre kaydedilemedi. Tarayıcıda saklandı.';
              }
            } else {
              if(statusDiv) statusDiv.textContent = '✅ Tarayıcıya kaydedildi: '+file.name;
            }
            _afterSave(savedName, dataUrl);
          };
          reader.onerror = function(){ alert('Dosya okunamadı.'); if(statusDiv) statusDiv.textContent=''; };
          reader.readAsDataURL(file);
        } else {
          // Dosyasız kayıt
          if(statusDiv) statusDiv.textContent = '✅ Kaydedildi (dosyasız).';
          _afterSave('', '');
          _toast('✅ Doküman arşivlendi: '+adi);
        }
      });
    }
  });

  /* switchFinTab sayi hook — artık ana fonksiyonda birleştirildi */

  /* ────────────────────────────────────────────────────────────────────
     INIT
     ──────────────────────────────────────────────────────────────────── */
  function _v75Init(){
    console.log('✅ UYSA v78 patch loading...');
    _initNav();
    _buildRenameModal();

    // Eğer satis modülü açıksa listele
    var satisPnl = document.getElementById('mod-satis');
    if(satisPnl && satisPnl.classList.contains('active')){
      setTimeout(v75RenderMusteriListesi, 200);
    }

    // satis module event
    var satisBtn = document.querySelector('[data-module="satis"]');
    if(satisBtn){
      satisBtn.addEventListener('click', function(){
        setTimeout(v75RenderMusteriListesi, 200);
      });
    }

    // Finance sayi tab button
    var sayiBtn = document.querySelector('[onclick*="sayi"]');
    if(sayiBtn){
      sayiBtn.addEventListener('click', function(){
        setTimeout(v75RenderAylikRapor, 300);
      });
    }

    // Klasör seç butonu
    var kbtn = document.getElementById('dokKlasorSecBtn');
    if(kbtn && !kbtn._v75bound){
      kbtn._v75bound = true;
      kbtn.addEventListener('click', window.v75SelectDokFolder);
    }

    console.log('✅ UYSA v78 patch loaded.');
  }

  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded', _v75Init);
  } else {
    _v75Init();
  }

})();
