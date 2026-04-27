/* ── UYSA v80 — CRM Müşteri Detay Sidebar ──────────────────────────────── */
(function(){
  'use strict';

  /* ── Yardımcılar ──────────────────────────────────────────────── */
  var _ls = {
    get: function(k,d){ try{ var v=localStorage.getItem(k); return v!==null?JSON.parse(v):d; }catch(e){ return d; } }
  };
  function _fmt(n){ return Number(n||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function _today(){ var d=new Date(); return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }
  function _thisMonth(){ return _today().slice(0,7); }

  var _currentSidebarCust = null;

  /* ── Sidebar aç ───────────────────────────────────────────────── */
  window.crmOpenDetailSidebar = function(cust){
    if(!cust) return;
    _currentSidebarCust = cust;

    var sidebar   = document.getElementById('crmDetailSidebar');
    var backdrop  = document.getElementById('crmSidebarBackdrop');
    var title     = document.getElementById('crmSidebarTitle');
    var sub       = document.getElementById('crmSidebarSub');
    var body      = document.getElementById('crmSidebarBody');
    if(!sidebar) return;

    /* Header */
    title.textContent = cust;

    /* CRM verisi */
    var crm = _ls.get('uysa_crm_'+cust, {});
    var kisi   = crm.kisi   || 0;
    var fiyat  = crm.fiyat  || 0;
    var buAy   = _thisMonth();
    var gunluk = _ls.get('uysa_gunluk_uretim', []);
    var ayKayit= gunluk.filter(function(u){ return u.musteri===cust && (u.tarih||'').startsWith(buAy); });
    var ayKisi = ayKayit.reduce(function(s,u){ return s+(u.kisi||0); },0);
    var ayCiro = ayKayit.reduce(function(s,u){ return s+(u.tutar||0); },0);
    var ayGun  = ayKayit.length;

    /* Tüm zamanlar toplamı */
    var gelirler = _ls.get('uysa_gelirler',[]).filter(function(g){ return g.musteri===cust; });
    var toplamCiro = gelirler.reduce(function(s,g){ return s+(g.tutar||0); },0);

    /* Sub başlık */
    sub.textContent = (crm.ilgili||'') + (crm.telefon ? '  ·  '+crm.telefon : '');

    /* Konfigürasyon */
    var VARDIYA = {tek:'Tek Vardiya',cift:'Çift Vardiya',uc:'Üç Vardiya'};
    var AYRAN   = {menuye_gore:'Menüye Göre',donusumlu:'Dönüşümlü',yogurt_sabit:'Yoğurt Sabit',ayran_sabit:'Ayran Sabit'};
    var EKMEK   = {roll:'Roll Ekmek',somun:'Somun Ekmeği',ikisi:'Roll + Somun'};
    var ODEME   = {cek:'Çek',havale:'Havale/EFT',acik_hesap:'Açık Hesap',kredi_karti:'Kredi Kartı'};

    function badge(txt, color){
      return '<span style="background:'+(color||'#dbeafe')+';color:#1e40af;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:700;display:inline-block;margin:2px">'
        +txt+'</span>';
    }

    /* ── Son 6 ay mini bar chart ─────────────────────────────────── */
    var months = [];
    var d = new Date();
    for(var m=5; m>=0; m--){
      var dt = new Date(d.getFullYear(), d.getMonth()-m, 1);
      var key = dt.getFullYear()+'-'+String(dt.getMonth()+1).padStart(2,'0');
      var kayit = gunluk.filter(function(u){ return u.musteri===cust && (u.tarih||'').startsWith(key); });
      var ciro = kayit.reduce(function(s,u){ return s+(u.tutar||0); },0);
      var pKisi = kayit.reduce(function(s,u){ return s+(u.kisi||0); },0);
      var ayAdi = ['Oca','Şub','Mar','Nis','May','Haz','Tem','Ağu','Eyl','Eki','Kas','Ara'][dt.getMonth()];
      months.push({key:key, label:ayAdi, ciro:ciro, kisi:pKisi, gun:kayit.length});
    }
    var maxCiro = Math.max.apply(null, months.map(function(m){ return m.ciro||0; })) || 1;
    var barChart = '<div style="display:flex;gap:6px;align-items:flex-end;height:80px;margin-top:6px">';
    months.forEach(function(m){
      var h = Math.round((m.ciro/maxCiro)*68)+2;
      var isCurrentMonth = m.key === buAy;
      barChart += '<div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:3px">'
        +'<div style="width:100%;background:'+(isCurrentMonth?'#2563eb':'#bfdbfe')+';height:'+h+'px;border-radius:4px 4px 0 0;'
        +'transition:height .3s" title="'+m.label+': '+_fmt(m.ciro)+' ₺"></div>'
        +'<div style="font-size:9px;color:'+(isCurrentMonth?'#2563eb':'#94a3b8')+';font-weight:'+(isCurrentMonth?'800':'400')+'">'+m.label+'</div>'
        +'</div>';
    });
    barChart += '</div>';

    /* ── Son 5 üretim kaydı ─────────────────────────────────────── */
    var sonKayitlar = gunluk
      .filter(function(u){ return u.musteri===cust; })
      .sort(function(a,b){ return (b.tarih||'').localeCompare(a.tarih||''); })
      .slice(0,5);

    var kayitRows = sonKayitlar.length
      ? sonKayitlar.map(function(u){
          return '<tr>'
            +'<td style="padding:5px 8px;font-size:12px;color:#374151">'+u.tarih+'</td>'
            +'<td style="padding:5px 8px;text-align:right;font-size:12px;font-weight:700;color:#1d4ed8">'+(u.kisi||0)+' kişi</td>'
            +'<td style="padding:5px 8px;text-align:right;font-size:12px;font-weight:700;color:#16a34a">'+_fmt(u.tutar||0)+' ₺</td>'
            +'</tr>';
        }).join('')
      : '<tr><td colspan="3" style="padding:10px;text-align:center;color:#94a3b8;font-size:12px">Henüz üretim kaydı yok</td></tr>';

    /* ── Sidebar içeriği ─────────────────────────────────────────── */
    body.innerHTML = [

      /* 1. Kişi / Fiyat / Ciro kartları */
      '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:14px">',
        _card('👤','KİŞİ/GÜN', kisi||'—', '#eff6ff','#1d4ed8'),
        _card('💰','BİRİM FİYAT', fiyat ? fiyat.toFixed(2)+' ₺' : '—', '#f0fdf4','#16a34a'),
        _card('📊','TAHMİNLİ/AY', kisi&&fiyat ? _fmt(kisi*fiyat*22)+' ₺' : '—', '#fef9c3','#d97706'),
      '</div>',

      /* 2. Bu ay özet */
      '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;margin-bottom:14px">',
        '<div style="font-weight:800;color:#1e3a8a;margin-bottom:10px;font-size:13px">📅 Bu Ay ('+(buAy)+')</div>',
        '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;text-align:center">',
          _statMini(ayGun+' gün','Servis'),
          _statMini(ayKisi+' kişi','Toplam Kişi'),
          _statMini(_fmt(ayCiro)+' ₺','Ciro','#16a34a'),
        '</div>',
        '<div style="margin-top:12px"><div style="font-size:11px;color:#64748b;font-weight:700;margin-bottom:4px">Son 6 Ay Ciro Trendi</div>'+barChart+'</div>',
      '</div>',

      /* 3. İletişim & Servis Konfigürasyonu */
      '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;margin-bottom:14px">',
        '<div style="font-weight:800;color:#1e3a8a;margin-bottom:8px;font-size:13px">🔧 Servis Konfigürasyonu</div>',
        '<div style="display:flex;flex-wrap:wrap;gap:4px">',
          (crm.vardiya  ? badge('🕐 '+(VARDIYA[crm.vardiya]||crm.vardiya)) : ''),
          (crm.ekmek    ? badge('🍞 '+(EKMEK[crm.ekmek]||crm.ekmek),'#dcfce7') : ''),
          (crm.ayran    ? badge('🥛 '+(AYRAN[crm.ayran]||crm.ayran),'#f0f9ff') : ''),
          (crm.anaYemek ? badge('🍽️ Ana: '+crm.anaYemek+' çeşit','#fef9c3') : ''),
          (crm.corba    ? badge('🥣 Çorba: '+crm.corba+' çeşit','#fef9c3') : ''),
          (crm.salata   ? badge('🥗 Salata: '+crm.salata+' çeşit','#fef9c3') : ''),
          (crm.odemeDurum ? badge('💳 '+(ODEME[crm.odemeDurum]||crm.odemeDurum),'#f3e8ff') : ''),
          (!crm.vardiya && !crm.ekmek && !crm.ayran && !crm.anaYemek ?
            '<span style="color:#94a3b8;font-size:12px">Henüz konfigürasyon girilmemiş</span>' : ''),
        '</div>',
        (crm.ilgili||crm.telefon ? [
          '<div style="border-top:1px solid #f1f5f9;margin-top:10px;padding-top:10px">',
            (crm.ilgili  ? '<div style="font-size:12px;color:#374151;margin-bottom:3px">👤 İlgili: <b>'+crm.ilgili+'</b></div>' : ''),
            (crm.telefon ? '<div style="font-size:12px;color:#374151">📞 '+crm.telefon+'</div>' : ''),
          '</div>'
        ].join('') : ''),
      '</div>',

      /* 4. Son üretim kayıtları */
      '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;margin-bottom:14px">',
        '<div style="font-weight:800;color:#1e3a8a;margin-bottom:8px;font-size:13px">📋 Son Üretim Kayıtları</div>',
        '<table style="width:100%;border-collapse:collapse">',
          '<thead><tr style="background:#f8fafc">',
            '<th style="padding:5px 8px;text-align:left;font-size:11px;color:#64748b;font-weight:700;border-bottom:2px solid #e2e8f0">Tarih</th>',
            '<th style="padding:5px 8px;text-align:right;font-size:11px;color:#64748b;font-weight:700;border-bottom:2px solid #e2e8f0">Kişi</th>',
            '<th style="padding:5px 8px;text-align:right;font-size:11px;color:#64748b;font-weight:700;border-bottom:2px solid #e2e8f0">Ciro</th>',
          '</tr></thead>',
          '<tbody>'+kayitRows+'</tbody>',
        '</table>',
      '</div>',

      /* 5. Toplam tahsilat */
      (toplamCiro > 0 ?
        '<div style="background:linear-gradient(135deg,#eff6ff,#e0f2fe);border:1px solid #bfdbfe;border-radius:12px;padding:12px;text-align:center">'
          +'<div style="font-size:11px;color:#64748b;font-weight:700">TÜM ZAMANLAR TOPLAM CİRO</div>'
          +'<div style="font-size:26px;font-weight:900;color:#1d4ed8;margin:4px 0">'+_fmt(toplamCiro)+' ₺</div>'
          +'<div style="font-size:11px;color:#64748b">'+gelirler.length+' gelir kaydı</div>'
        +'</div>'
      : ''),

      /* 6. Notlar */
      ((crm.diet||crm.diff) ?
        '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;margin-top:14px">'
          +'<div style="font-weight:800;color:#1e3a8a;margin-bottom:8px;font-size:13px">📝 Notlar</div>'
          +(crm.diet ? '<div style="font-size:12px;color:#374151;margin-bottom:6px"><b>Diyet:</b> '+crm.diet+'</div>' : '')
          +(crm.diff ? '<div style="font-size:12px;color:#374151"><b>Fark Notu:</b> '+crm.diff+'</div>' : '')
        +'</div>'
      : ''),

    ].join('');

    /* Sidebar göster */
    sidebar.style.display = 'flex';
    backdrop.style.display = 'block';
    /* Animasyon: translate geri al */
    requestAnimationFrame(function(){
      requestAnimationFrame(function(){
        sidebar.style.transform = 'translateX(0)';
      });
    });

    /* ESC listener */
    document._crmSidebarEsc = function(e){ if(e.key==='Escape') crmCloseSidebar(); };
    document.addEventListener('keydown', document._crmSidebarEsc);
  };

  /* ── Kart yardımcıları ────────────────────────────────────────── */
  function _card(icon, label, val, bg, color){
    return '<div style="background:'+(bg||'#eff6ff')+';border-radius:10px;padding:10px;text-align:center">'
      +'<div style="font-size:18px">'+icon+'</div>'
      +'<div style="font-size:10px;color:#64748b;font-weight:700;margin:2px 0">'+label+'</div>'
      +'<div style="font-size:16px;font-weight:900;color:'+(color||'#1d4ed8')+'">'+val+'</div>'
      +'</div>';
  }
  function _statMini(val, label, color){
    return '<div>'
      +'<div style="font-size:15px;font-weight:900;color:'+(color||'#1d4ed8')+'">'+val+'</div>'
      +'<div style="font-size:10px;color:#94a3b8;font-weight:600">'+label+'</div>'
      +'</div>';
  }

  /* ── Sidebar kapat ────────────────────────────────────────────── */
  window.crmCloseSidebar = function(){
    var sidebar  = document.getElementById('crmDetailSidebar');
    var backdrop = document.getElementById('crmSidebarBackdrop');
    if(sidebar)  { sidebar.style.transform = 'translateX(100%)'; }
    if(backdrop) { backdrop.style.display = 'none'; }
    setTimeout(function(){
      if(sidebar) sidebar.style.display = 'none';
    }, 320);
    if(document._crmSidebarEsc) document.removeEventListener('keydown', document._crmSidebarEsc);
    _currentSidebarCust = null;
  };

  /* ── Sidebar'dan CRM formu aç ─────────────────────────────────── */
  window.crmOpenEditFromSidebar = function(){
    var cust = _currentSidebarCust;
    if(!cust) return;
    crmCloseSidebar();
    /* CRM formunda müşteriyi seç */
    setTimeout(function(){
      var sel = document.getElementById('crmCustomerSel');
      if(sel){ sel.value = cust; if(typeof crmLoadData==='function') crmLoadData(); }
      /* Scroll CRM formuna */
      var panel = document.querySelector('.mod-panel.active #mod-satis') ||
                  document.getElementById('mod-satis');
      if(panel) panel.scrollIntoView({behavior:'smooth', block:'start'});
    }, 350);
  };

  /* ══════════════════════════════════════════════════════════════════
     v75RenderMusteriListesi OVERRIDE
     Her müşteri satırına sidebar açma click eventi ekle
  ══════════════════════════════════════════════════════════════════ */
  var _origV75Render = window.v75RenderMusteriListesi;
  window.v75RenderMusteriListesi = function(){
    /* Orijinali çalıştır */
    if(_origV75Render) _origV75Render.call(this);

    /* Tüm müşteri ismi span'larına sidebar trigger ekle */
    setTimeout(function(){
      var div = document.getElementById('v75MusteriListeDiv');
      if(!div) return;

      /* Mevcut onclick'li span'ları bul — müşteri adını tablo TD'lerden çek */
      var rows = div.querySelectorAll('tr');
      rows.forEach(function(row){
        /* İlk td = müşteri adı */
        var td = row.querySelector('td:first-child');
        if(!td) return;
        var nameSpan = td.querySelector('span[style*="cursor:pointer"]');
        var custName = nameSpan ? nameSpan.textContent.trim() : '';

        /* Tüm satırı tıklanabilir yap */
        if(custName && custName !== '✓ bugün'){
          row.style.cursor = 'pointer';
          row.style.transition = 'background .15s';

          /* Hover efekti */
          row.addEventListener('mouseenter', function(){
            this.style.background = '#eff6ff';
          });
          row.addEventListener('mouseleave', function(){ this.style.background = ''; });

          /* Tıkla → Sidebar */
          row.addEventListener('click', function(e){
            /* Eğer ✏️ Yeniden Adlandır butonuna tıkladıysa sidebar açma */
            if(e.target.closest('button') || e.target.closest('a')) return;
            crmOpenDetailSidebar(custName);
          });

          /* Mevcut nameSpan onclick'ini koru ama sidebar da aç */
          if(nameSpan){
            var oldOnclick = nameSpan.getAttribute('onclick');
            nameSpan.removeAttribute('onclick');
            nameSpan.addEventListener('click', function(e){
              e.stopPropagation();
              /* Önce CRM seçici güncelle (orijinal davranış) */
              var s = document.getElementById('crmCustomerSel');
              if(s){ s.value = custName; if(typeof crmLoadData==='function') crmLoadData(); }
              /* Sonra sidebar aç */
              crmOpenDetailSidebar(custName);
            });
          }
        }
      });

      /* Tablo header'ına açıklama ekle */
      var thead = div.querySelector('thead tr');
      if(thead && !thead.querySelector('.v80-hint')){
        var hintTh = document.createElement('th');
        hintTh.className = 'v80-hint';
        hintTh.colSpan = '99';
        hintTh.style.cssText = 'padding:4px 10px;font-size:10px;color:#94a3b8;font-weight:400;text-align:right;background:#f8fafc;border-bottom:1px solid #e2e8f0';
        hintTh.textContent = '👆 Satıra tıkla → Detay';
        thead.parentNode.insertBefore(document.createElement('tr'), thead);
        var hintRow = thead.previousSibling;
        hintRow.appendChild(hintTh);
      }

    }, 80);
  };

  /* ── İlk yüklemede müşteri listesini render et ─────────────────── */
  window.addEventListener('load', function(){
    setTimeout(function(){
      try{
        if(typeof v75RenderMusteriListesi==='function') v75RenderMusteriListesi();
      }catch(e){}
    }, 600);
  });

  /* CRM sekmesine her geçişte override'ı yeniden uygula */
  var crmNavBtn = document.querySelector('[data-module="satis"]');
  if(crmNavBtn){
    crmNavBtn.addEventListener('click', function(){
      setTimeout(function(){
        if(typeof v75RenderMusteriListesi==='function') v75RenderMusteriListesi();
      }, 300);
    });
  }

  console.log('[UYSA v80] CRM Detay Sidebar yüklendi.');
})();
