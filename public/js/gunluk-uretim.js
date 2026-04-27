/* ═══════════════════════════════════════════════════════════════════════
   UYSA v78 PATCH — Günlük Üretim Tablosu tam yenileme
   • CRM'den otomatik kişi+fiyat çek
   • Tarih değişince o güne ait kayıt yükle
   • Anlık hesaplama (kişi × fiyat = günlük toplam)
   • Ay başından toplamlar
   • Kaydet + Aylık rapor güncelle
   ═══════════════════════════════════════════════════════════════════════ */
(function(){
  'use strict';

  /* ── yardımcılar ──────────────────────────────────────────────────── */
  var _ls = {
    get: function(k,d){ try{ var v=localStorage.getItem(k); return v===null?d:JSON.parse(v); }catch(e){ return d; } },
    set: function(k,v){ try{ localStorage.setItem(k,JSON.stringify(v)); }catch(e){} }
  };
  function _today(){
    var d=new Date();
    return d.getFullYear()+'-'+(''+(d.getMonth()+1)).padStart(2,'0')+'-'+(''+d.getDate()).padStart(2,'0');
  }
  function _fmt(n){ return Number(n||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function _toast(msg){
    if(typeof window.toast==='function'){ window.toast(msg); return; }
    var t=document.createElement('div');
    t.textContent=msg;
    t.style.cssText='position:fixed;bottom:24px;right:24px;background:#1e293b;color:#fff;padding:12px 20px;border-radius:10px;font-size:13px;z-index:99999;max-width:360px;box-shadow:0 4px 20px rgba(0,0,0,.35)';
    document.body.appendChild(t);
    setTimeout(function(){ t.remove(); },3000);
  }
  function _getCusts(){
    try{
      var raw=localStorage.getItem('uysa_customers_v1');
      var pasif=_ls.get('uysa_pasif_musteriler',[]);
      if(!raw) return [];
      var obj=JSON.parse(raw);
      return (obj.customers||[]).filter(function(x){ return x&&x!=='GENEL'&&!pasif.includes(x); });
    }catch(e){ return []; }
  }
  function _getGunluk(){ return _ls.get('uysa_gunluk_uretim',[]); }
  function _saveGunluk(arr){ _ls.set('uysa_gunluk_uretim',arr); }
  function _getGelirler(){ return _ls.get('uysa_gelirler',[]); }
  function _saveGelirler(arr){ _ls.set('uysa_gelirler',arr); }

  /* ── Öğün sabitleri ──────────────────────────────────────────────── */
  var _ogunler = ['ogle','aksam','kumanya'];
  var _ogunLabels = {ogle:'Öğle', aksam:'Akşam', kumanya:'Kumanya'};

  /* ── Müşteri bazlı öğün aç/kapa durumu ─────────────────────────── */
  if(!window._gsOgunAcik) window._gsOgunAcik = {};

  /* ── Öğün toggle: müşteri satırını aç/kapa ─────────────────────── */
  window.gsOgunToggle = function(cust, idx){
    window._gsOgunAcik[cust] = !window._gsOgunAcik[cust];
    // Mevcut inputlardan değerleri kaydet, tabloyu yeniden render et
    _gsPreserveAndRerender();
  };

  /* ── Mevcut input değerlerini topla, tabloyu yeniden render et ── */
  function _gsPreserveAndRerender(){
    var tableDiv = document.getElementById('gsTableDiv');
    if(!tableDiv || !tableDiv.dataset.custs) { gsRenderTableV76(); return; }
    var custs = [];
    try{ custs=JSON.parse(tableDiv.dataset.custs||'[]'); }catch(e){}
    // Mevcut input değerlerini topla
    var preserved = {};
    custs.forEach(function(cust, i){
      preserved[cust] = {};
      _ogunler.forEach(function(og){
        var kEl = document.querySelector('.gsv76-kisi-'+og+'[data-idx="'+i+'"]');
        var fEl = document.querySelector('.gsv76-fiyat-'+og+'[data-idx="'+i+'"]');
        if(kEl || fEl){
          preserved[cust][og] = {
            kisi: kEl ? (parseInt(kEl.value)||0) : 0,
            fiyat: fEl ? (parseFloat(fEl.value)||0) : 0
          };
        }
      });
    });
    window._gsPreserved = preserved;
    gsRenderTableV76();
    window._gsPreserved = null;
  }

  /* ── Anlık güncelleme: müşteri idx'i için tüm öğünleri hesapla ──── */
  window.gsCalcRow = function(idx){
    var tableDiv = document.getElementById('gsTableDiv');
    var custs = [];
    try{ custs=JSON.parse(tableDiv?.dataset.custs||'[]'); }catch(e){}
    var cust = custs[idx]||'';
    var isOpen = window._gsOgunAcik[cust];

    var topNet = 0;
    if(isOpen){
      // 3 öğün modu: her öğünü ayrı hesapla
      _ogunler.forEach(function(og){
        var kEl = document.querySelector('.gsv76-kisi-'+og+'[data-idx="'+idx+'"]');
        var fEl = document.querySelector('.gsv76-fiyat-'+og+'[data-idx="'+idx+'"]');
        var tEl = document.querySelector('.gsv76-ogun-top-'+og+'[data-idx="'+idx+'"]');
        if(!kEl||!fEl) return;
        var k = parseFloat(kEl.value)||0;
        var f = parseFloat(fEl.value)||0;
        var net = k>0&&f>0 ? k*f : 0;
        topNet += net;
        if(tEl){
          tEl.textContent = net>0 ? _fmt(net)+' ₺' : '—';
          tEl.style.color = net>0 ? '#166534' : '#94a3b8';
        }
      });
    } else {
      // Tek satır modu: öğle inputları = ana kişi/fiyat
      var kEl = document.querySelector('.gsv76-kisi-ogle[data-idx="'+idx+'"]');
      var fEl = document.querySelector('.gsv76-fiyat-ogle[data-idx="'+idx+'"]');
      if(kEl&&fEl){
        var k = parseFloat(kEl.value)||0;
        var f = parseFloat(fEl.value)||0;
        topNet = k>0&&f>0 ? k*f : 0;
      }
    }

    // Müşteri toplam
    var tEl = document.querySelector('.gsv76-gunluk[data-idx="'+idx+'"]');
    if(tEl){
      var kdvPct = window._gsKdvPct || 0;
      if(topNet>0){
        if(kdvPct>0){
          var kdvTutar = topNet * kdvPct / 100;
          var kdvDahil = topNet + kdvTutar;
          tEl.innerHTML = '<span style="color:#166534;font-weight:700">'+_fmt(topNet)+' ₺</span>'
            +'<br><span style="color:#7c3aed;font-size:10px">+%'+kdvPct+' KDV: '+_fmt(kdvTutar)+' ₺</span>'
            +'<br><span style="color:#4a148c;font-size:10px;font-weight:800">Toplam: '+_fmt(kdvDahil)+' ₺</span>';
        } else {
          tEl.textContent = _fmt(topNet)+' ₺';
          tEl.style.color = '#166534';
        }
      } else {
        tEl.textContent = '—';
        tEl.style.color = '#94a3b8';
      }
    }
    // Toplam kişi
    var topKisi = 0;
    if(isOpen){
      _ogunler.forEach(function(og){
        var kEl = document.querySelector('.gsv76-kisi-'+og+'[data-idx="'+idx+'"]');
        topKisi += parseFloat(kEl?.value)||0;
      });
    } else {
      var kEl = document.querySelector('.gsv76-kisi-ogle[data-idx="'+idx+'"]');
      topKisi = parseFloat(kEl?.value)||0;
    }
    var topKisiEl = document.querySelector('.gsv76-topkisi[data-idx="'+idx+'"]');
    if(topKisiEl) topKisiEl.textContent = topKisi>0 ? topKisi : '—';
    _updateGunlukOzet();
  };

  /* ── Günlük özet banner ─────────────────────────────────────────── */
  function _updateGunlukOzet(){
    var ozetDiv    = document.getElementById('gsGunOzet');
    var ozetIcerik = document.getElementById('gsGunOzetIcerik');
    var tableDiv   = document.getElementById('gsTableDiv');
    if(!ozetDiv||!ozetIcerik||!tableDiv) return;

    var tarih = tableDiv.dataset.tarih||_today();
    var custs = [];
    try{ custs=JSON.parse(tableDiv.dataset.custs||'[]'); }catch(e){}

    var totalKisi=0, totalTutar=0;
    var satirlar = custs.map(function(cust,i){
      var custTutar=0, custKisi=0;
      _ogunler.forEach(function(og){
        var k=parseFloat(document.querySelector('.gsv76-kisi-'+og+'[data-idx="'+i+'"]')?.value)||0;
        var f=parseFloat(document.querySelector('.gsv76-fiyat-'+og+'[data-idx="'+i+'"]')?.value)||0;
        custKisi+=k; custTutar+=k*f;
      });
      totalKisi+=custKisi; totalTutar+=custTutar;
      return custKisi>0
        ? '<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #d1fae5">'
          +'<span><b>'+cust+'</b> — '+custKisi+' kişi</span>'
          +'<span style="font-weight:800;color:#166534">'+_fmt(custTutar)+' ₺</span></div>'
        : '';
    }).filter(Boolean).join('');

    if(!satirlar){ ozetDiv.style.display='none'; return; }
    ozetDiv.style.display='';
    ozetIcerik.innerHTML = satirlar
      +'<div style="display:flex;justify-content:space-between;padding:8px 0;font-weight:900;font-size:14px;margin-top:4px">'
      +'<span>📅 '+tarih+' Toplam: '+totalKisi+' kişi</span>'
      +'<span style="color:#166534">💰 '+_fmt(totalTutar)+' ₺</span></div>';
  }

  /* ── ANA TABLO RENDER ──────────────────────────────────────────── */
  window.gsRenderTableV76 = function(){
    var tableDiv  = document.getElementById('gsTableDiv');
    var durum     = document.getElementById('gs-tarih-durum');
    if(!tableDiv) return;

    var tarih = document.getElementById('gs-tarih')?.value||_today();
    var custs = _getCusts();

    if(!custs.length){
      tableDiv.innerHTML='<div style="color:var(--muted);padding:10px">⚠️ Henüz müşteri eklenmemiş. Satış&CRM bölümünden müşteri ekleyin.</div>';
      if(durum) durum.textContent='';
      return;
    }

    var buAy = tarih.slice(0,7);
    var gunlukArr = _getGunluk();
    var bugunKayitlar = {};
    gunlukArr.filter(function(u){ return u.tarih===tarih; }).forEach(function(u){
      bugunKayitlar[u.musteri] = u;
    });

    var kayitliSayi = Object.keys(bugunKayitlar).filter(function(k){ return bugunKayitlar[k].kisi>0; }).length;
    if(durum){
      if(kayitliSayi>0)
        durum.innerHTML='<span style="color:#16a34a;font-weight:700">✓ '+kayitliSayi+' müşteri kaydı mevcut</span>';
      else
        durum.innerHTML='<span style="color:#94a3b8">Yeni kayıt</span>';
    }

    var _tarihObj = new Date(tarih);
    var _haftaGun = _tarihObj.getDay();
    var _isWeekend = (_haftaGun===0||_haftaGun===6);

    // Preserved data from toggle
    var preserved = window._gsPreserved || null;

    var rows = '';
    custs.forEach(function(cust, i){
      var crm      = _ls.get('uysa_crm_'+cust, {});
      var crmKisi  = crm.kisi || 0;
      var crmFiyat = crm.fiyat || 0;
      var bugun    = bugunKayitlar[cust];
      var isOpen   = !!window._gsOgunAcik[cust];

      // Önceki gün verisi
      var onceki = null;
      if(!bugun) {
        var filterFn = _isWeekend
          ? function(u){
              if(u.musteri!==cust||!u.tarih||u.tarih>=tarih) return false;
              var _d=new Date(u.tarih), _g=_d.getDay();
              return (_g===0||_g===6)&&u.kisi>0;
            }
          : function(u){
              if(u.musteri!==cust||!u.tarih||u.tarih>=tarih) return false;
              var _d=new Date(u.tarih), _g=_d.getDay();
              return _g!==0&&_g!==6&&u.kisi>0;
            };
        var _sorted = gunlukArr.filter(filterFn).sort(function(a,b){return b.tarih.localeCompare(a.tarih);});
        if(_sorted.length>0) onceki = _sorted[0];
      }

      // Her öğün için değerleri hesapla
      var ogunVals = {};
      var topKisi = 0, topTutar = 0;
      _ogunler.forEach(function(og){
        var kisi = 0, fiyat = crmFiyat;
        // Önce preserved data'dan bak (toggle sırasında input değerlerini koru)
        if(preserved && preserved[cust] && preserved[cust][og]){
          kisi = preserved[cust][og].kisi||0;
          fiyat = preserved[cust][og].fiyat||crmFiyat;
        } else if(bugun && bugun[og]){
          kisi = bugun[og].kisi||0;
          fiyat = bugun[og].fiyat||crmFiyat;
        } else if(bugun){
          if(og==='ogle'){ kisi = bugun.kisi||0; fiyat = bugun.fiyat||crmFiyat; }
        } else if(onceki){
          if(onceki[og]){
            kisi = onceki[og].kisi||0;
            fiyat = onceki[og].fiyat||crmFiyat;
          } else if(og==='ogle'){
            kisi = onceki.kisi||0;
            fiyat = onceki.fiyat||crmFiyat;
          }
        } else {
          if(og==='ogle'){ kisi = crmKisi; fiyat = crmFiyat; }
        }
        ogunVals[og] = {kisi:kisi, fiyat:fiyat};
        topKisi += kisi;
        topTutar += kisi * fiyat;
      });

      var kaydedildi = !!bugun;
      var otoDoldu = !bugun && !!onceki;

      // Ay başından toplam
      var ayOnceki = gunlukArr.filter(function(u){
        return u.musteri===cust && (u.tarih||'').startsWith(buAy) && u.tarih!==tarih;
      });
      var ayTopKisi  = ayOnceki.reduce(function(s,u){ return s+(u.kisi||0); }, 0);
      var ayTopTutar = ayOnceki.reduce(function(s,u){ return s+(u.tutar||0); }, 0);

      var rowBg = kaydedildi ? '#f0fdf4' : (otoDoldu ? '#fffbeb' : (i%2===0?'#fff':'#f8fafc'));
      var statusLabel = kaydedildi ? '<span style="color:#16a34a;font-size:10px">✓ kaydedildi</span>'
        : (otoDoldu ? '<span style="color:#d97706;font-size:10px">↻ '+(_isWeekend?'önceki h.sonu':'önceki gün')+'</span>' : '');
      var alSatTag = crm.alSat ? ' <span style="background:#fbbf24;color:#78350f;padding:1px 5px;border-radius:8px;font-size:9px;font-weight:700">Al/Sat</span>' : '';
      var alSatInfo = crm.alSat && crm.alisFiyat ? '<br><span style="color:#d97706;font-size:10px">Alış: '+_fmt(crm.alisFiyat)+' ₺'+(crm.tedarikci?' · '+crm.tedarikci:'')+'</span>' : '';

      // Öğün durumunu göster: aksam veya kumanya'da kişi varsa otomatik aç
      var hasMultiOgun = (ogunVals.aksam.kisi > 0 || ogunVals.kumanya.kisi > 0);
      if(hasMultiOgun && !isOpen) {
        window._gsOgunAcik[cust] = true;
        isOpen = true;
      }

      var ogunColors = {ogle:'#1e40af', aksam:'#7c3aed', kumanya:'#b45309'};
      var ogunBgs = {ogle:'#eff6ff', aksam:'#f5f3ff', kumanya:'#fffbeb'};

      if(isOpen){
        // ═══ AÇIK MOD: 3 öğün sub-satırları ═══
        var rowCount = 3;
        _ogunler.forEach(function(og, oi){
          var isFirst = (oi===0);
          var isLast = (oi===2);
          var v = ogunVals[og];
          var ogunTutar = v.kisi * v.fiyat;

          rows += '<tr style="background:'+rowBg+'">';

          if(isFirst){
            rows += '<td rowspan="'+rowCount+'" style="padding:8px 10px;font-weight:700;color:#1e293b;border-bottom:2px solid #c7d2fe;border-right:1px solid #e2e8f0;min-width:140px;vertical-align:top">'
              +'<div style="cursor:pointer" onclick="gsMusteriDetay(\''+cust.replace(/'/g,"\\'")+'\')">'
              +'<span style="color:#2563eb;text-decoration:underline;text-decoration-style:dotted">'+cust+'</span>'+alSatTag
              +(statusLabel?'<br>'+statusLabel:'')
              +alSatInfo
              +'<br><span style="color:#94a3b8;font-size:10px">CRM: '+(crmKisi||'—')+' kişi / '+(crmFiyat?_fmt(crmFiyat)+' ₺':'—')+'</span>'
              +'</div>'
              +'<button onclick="gsOgunToggle(\''+cust.replace(/'/g,"\\'")+'\','+i+')" style="margin-top:4px;padding:2px 8px;font-size:10px;background:#e0e7ff;color:#4338ca;border:1px solid #c7d2fe;border-radius:6px;cursor:pointer;font-weight:600" title="Tek satıra daralt">▲ Tek Satır</button>'
              +'</td>';
          }

          // Öğün etiketi
          rows += '<td style="padding:4px 6px;border-bottom:1px solid '+(isLast?'#c7d2fe':'#f1f5f9')+';border-right:1px solid #e2e8f0;min-width:70px;text-align:center">'
            +'<span style="font-size:10px;font-weight:700;color:'+ogunColors[og]+';background:'+ogunBgs[og]+';padding:2px 8px;border-radius:10px">'+_ogunLabels[og]+'</span>'
            +'</td>';

          // Kişi input
          rows += '<td style="padding:3px 4px;border-bottom:1px solid '+(isLast?'#c7d2fe':'#f1f5f9')+';border-right:1px solid #e2e8f0;min-width:80px">'
            +'<input type="number" min="0" step="1"'
            +' class="gsv76-kisi-'+og+'"'
            +' data-idx="'+i+'" data-musteri="'+cust+'" data-ogun="'+og+'"'
            +' value="'+(v.kisi||'')+'"'
            +' placeholder="0"'
            +' oninput="gsCalcRow('+i+')"'
            +' style="width:100%;padding:5px;border:1px solid '+(kaydedildi?'#86efac':'#e2e8f0')+';border-radius:6px;text-align:center;font-size:13px;font-weight:700;background:'+(kaydedildi?'#f0fdf4':'#fff')+'"/>'
            +'</td>';

          // Fiyat input
          rows += '<td style="padding:3px 4px;border-bottom:1px solid '+(isLast?'#c7d2fe':'#f1f5f9')+';border-right:1px solid #e2e8f0;min-width:80px">'
            +'<input type="number" min="0" step="0.01"'
            +' class="gsv76-fiyat-'+og+'"'
            +' data-idx="'+i+'" data-musteri="'+cust+'" data-ogun="'+og+'"'
            +' value="'+(v.fiyat||'')+'"'
            +' placeholder="₺"'
            +' oninput="gsCalcRow('+i+')"'
            +' style="width:100%;padding:5px;border:1px solid '+(kaydedildi?'#86efac':'#e2e8f0')+';border-radius:6px;text-align:center;font-size:12px;background:'+(kaydedildi?'#f0fdf4':'#fff')+'"/>'
            +'</td>';

          // Öğün toplam
          rows += '<td style="padding:4px 8px;text-align:right;border-bottom:1px solid '+(isLast?'#c7d2fe':'#f1f5f9')+';border-right:1px solid #e2e8f0;font-size:12px">'
            +'<span class="gsv76-ogun-top-'+og+'" data-idx="'+i+'" style="color:'+(ogunTutar>0?'#166534':'#94a3b8')+'">'+(ogunTutar>0?_fmt(ogunTutar)+' ₺':'—')+'</span>'
            +'</td>';

          // Günlük toplam + Ay başından: sadece ilk satırda (rowspan)
          if(isFirst){
            var kdvPct = window._gsKdvPct||0;
            var topHtml = '';
            if(topTutar>0 && kdvPct>0){
              var _kdvT=topTutar*kdvPct/100;
              topHtml = '<span class="gsv76-gunluk" data-idx="'+i+'" style="display:block">'
                +'<span style="color:#166534;font-weight:700">'+_fmt(topTutar)+' ₺</span>'
                +'<br><span style="color:#7c3aed;font-size:10px">+%'+kdvPct+' KDV: '+_fmt(_kdvT)+' ₺</span>'
                +'<br><span style="color:#4a148c;font-size:10px;font-weight:800">Toplam: '+_fmt(topTutar+_kdvT)+' ₺</span>'
                +'</span>';
            } else {
              topHtml = '<span class="gsv76-gunluk" data-idx="'+i+'" style="color:'+(topTutar?'#166534':'#94a3b8')+';font-weight:800">'+(topTutar?_fmt(topTutar)+' ₺':'—')+'</span>';
            }
            topHtml += '<br><span class="gsv76-topkisi" data-idx="'+i+'" style="font-size:11px;color:#2563eb;font-weight:700">'+(topKisi||'—')+'</span><span style="font-size:10px;color:#94a3b8"> kişi</span>';

            rows += '<td rowspan="'+rowCount+'" style="padding:6px 10px;text-align:right;border-bottom:2px solid #c7d2fe;border-right:1px solid #e2e8f0;min-width:100px;vertical-align:middle">'
              +topHtml+'</td>';

            rows += '<td rowspan="'+rowCount+'" style="padding:6px 10px;text-align:center;border-bottom:2px solid #c7d2fe;min-width:80px;vertical-align:middle">'
              +'<span style="font-weight:700;color:'+(ayTopKisi?'#2563eb':'#94a3b8')+'">'+(ayTopKisi||'—')+'</span>'
              +(ayTopKisi?'<br><span style="font-size:10px;color:#64748b">'+_fmt(ayTopTutar)+' ₺</span>':'')
              +'</td>';
          }
          rows += '</tr>';
        });
      } else {
        // ═══ KAPALI MOD: Tek satır (kişi + fiyat → öğle olarak kaydedilir) ═══
        var v = ogunVals.ogle;
        var satTutar = topTutar; // topTutar zaten tüm öğünlerin toplamı

        rows += '<tr style="background:'+rowBg+'">';

        // Müşteri kolonu
        rows += '<td style="padding:8px 10px;font-weight:700;color:#1e293b;border-bottom:2px solid #c7d2fe;border-right:1px solid #e2e8f0;min-width:140px;vertical-align:top">'
          +'<div style="cursor:pointer" onclick="gsMusteriDetay(\''+cust.replace(/'/g,"\\'")+'\')">'
          +'<span style="color:#2563eb;text-decoration:underline;text-decoration-style:dotted">'+cust+'</span>'+alSatTag
          +(statusLabel?'<br>'+statusLabel:'')
          +alSatInfo
          +'<br><span style="color:#94a3b8;font-size:10px">CRM: '+(crmKisi||'—')+' kişi / '+(crmFiyat?_fmt(crmFiyat)+' ₺':'—')+'</span>'
          +'</div>'
          +'<button onclick="gsOgunToggle(\''+cust.replace(/'/g,"\\'")+'\','+i+')" style="margin-top:4px;padding:2px 8px;font-size:10px;background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe;border-radius:6px;cursor:pointer;font-weight:600" title="3 öğün (öğle/akşam/kumanya) gir">▼ 3 Öğün</button>'
          +'</td>';

        // Öğün etiketi — boş (tek satır, öğün ayrımı yok)
        rows += '<td style="padding:4px 6px;border-bottom:2px solid #c7d2fe;border-right:1px solid #e2e8f0;min-width:70px;text-align:center">'
          +'<span style="font-size:10px;font-weight:600;color:#64748b">Genel</span>'
          +'</td>';

        // Kişi input (class: gsv76-kisi-ogle — tek satırda öğle inputu kullanılır)
        rows += '<td style="padding:3px 4px;border-bottom:2px solid #c7d2fe;border-right:1px solid #e2e8f0;min-width:80px">'
          +'<input type="number" min="0" step="1"'
          +' class="gsv76-kisi-ogle"'
          +' data-idx="'+i+'" data-musteri="'+cust+'" data-ogun="ogle"'
          +' value="'+(v.kisi||'')+'"'
          +' placeholder="0"'
          +' oninput="gsCalcRow('+i+')"'
          +' style="width:100%;padding:5px;border:1px solid '+(kaydedildi?'#86efac':'#e2e8f0')+';border-radius:6px;text-align:center;font-size:13px;font-weight:700;background:'+(kaydedildi?'#f0fdf4':'#fff')+'"/>'
          +'</td>';

        // Fiyat input
        rows += '<td style="padding:3px 4px;border-bottom:2px solid #c7d2fe;border-right:1px solid #e2e8f0;min-width:80px">'
          +'<input type="number" min="0" step="0.01"'
          +' class="gsv76-fiyat-ogle"'
          +' data-idx="'+i+'" data-musteri="'+cust+'" data-ogun="ogle"'
          +' value="'+(v.fiyat||'')+'"'
          +' placeholder="₺"'
          +' oninput="gsCalcRow('+i+')"'
          +' style="width:100%;padding:5px;border:1px solid '+(kaydedildi?'#86efac':'#e2e8f0')+';border-radius:6px;text-align:center;font-size:12px;background:'+(kaydedildi?'#f0fdf4':'#fff')+'"/>'
          +'</td>';

        // Tutar (günlük toplam yerine öğün toplam hücresinde göster)
        var kdvPct = window._gsKdvPct||0;
        var tutarHtml = '';
        if(satTutar>0 && kdvPct>0){
          var _kdvT=satTutar*kdvPct/100;
          tutarHtml = '<span style="color:#166534;font-weight:700">'+_fmt(satTutar)+' ₺</span>'
            +'<br><span style="color:#7c3aed;font-size:10px">+%'+kdvPct+': '+_fmt(_kdvT)+' ₺</span>';
        } else {
          tutarHtml = '<span style="color:'+(satTutar>0?'#166534':'#94a3b8')+';font-weight:700">'+(satTutar>0?_fmt(satTutar)+' ₺':'—')+'</span>';
        }
        rows += '<td style="padding:4px 8px;text-align:right;border-bottom:2px solid #c7d2fe;border-right:1px solid #e2e8f0;font-size:12px">'
          +tutarHtml+'</td>';

        // Günlük toplam
        rows += '<td style="padding:6px 10px;text-align:right;border-bottom:2px solid #c7d2fe;border-right:1px solid #e2e8f0;min-width:100px;vertical-align:middle">'
          +'<span class="gsv76-gunluk" data-idx="'+i+'" style="color:'+(satTutar?'#166534':'#94a3b8')+';font-weight:800">'+(satTutar?_fmt(satTutar)+' ₺':'—')+'</span>'
          +'<br><span class="gsv76-topkisi" data-idx="'+i+'" style="font-size:11px;color:#2563eb;font-weight:700">'+(v.kisi||'—')+'</span><span style="font-size:10px;color:#94a3b8"> kişi</span>'
          +'</td>';

        // Ay başından
        rows += '<td style="padding:6px 10px;text-align:center;border-bottom:2px solid #c7d2fe;min-width:80px;vertical-align:middle">'
          +'<span style="font-weight:700;color:'+(ayTopKisi?'#2563eb':'#94a3b8')+'">'+(ayTopKisi||'—')+'</span>'
          +(ayTopKisi?'<br><span style="font-size:10px;color:#64748b">'+_fmt(ayTopTutar)+' ₺</span>':'')
          +'</td>';

        rows += '</tr>';
      }
    });

    var html = '<div style="overflow-x:auto">'
      +'<table style="width:100%;font-size:13px;border-collapse:collapse;border-radius:12px;overflow:hidden;box-shadow:0 1px 8px rgba(0,0,0,.08)">'
      +'<thead>'
      +'<tr style="background:#eef3ff">'
      +'<th style="padding:10px 12px;text-align:left;border-bottom:2px solid #c7d2fe;border-right:1px solid #c7d2fe">Müşteri</th>'
      +'<th style="padding:10px 12px;text-align:center;border-bottom:2px solid #c7d2fe;border-right:1px solid #c7d2fe">Öğün</th>'
      +'<th style="padding:10px 12px;text-align:center;border-bottom:2px solid #c7d2fe;border-right:1px solid #c7d2fe">Kişi Sayısı</th>'
      +'<th style="padding:10px 12px;text-align:center;border-bottom:2px solid #c7d2fe;border-right:1px solid #c7d2fe">Fiyat (₺/kişi)</th>'
      +'<th style="padding:10px 12px;text-align:right;border-bottom:2px solid #c7d2fe;border-right:1px solid #c7d2fe">Tutar</th>'
      +'<th style="padding:10px 12px;text-align:right;border-bottom:2px solid #c7d2fe;border-right:1px solid #c7d2fe">Günlük Toplam</th>'
      +'<th style="padding:10px 12px;text-align:center;border-bottom:2px solid #c7d2fe">Ay Başından</th>'
      +'</tr>'
      +'</thead>'
      +'<tbody>'+rows+'</tbody>'
      +'</table>'
      +'</div>';

    tableDiv.innerHTML = html;
    tableDiv.dataset.custs  = JSON.stringify(custs);
    tableDiv.dataset.tarih  = tarih;

    // Özet ve aylık rapor güncelle
    _updateGunlukOzet();
    if(typeof window.v75RenderAylikRapor==='function') setTimeout(window.v75RenderAylikRapor, 200);
  };

  /* ── Müşteri Detay Modal (tarih aralığı) ───────────────────────── */
  window.gsMusteriDetay = function(musteriAdi){
    var gunlukArr = _getGunluk();
    var musteriKayitlar = gunlukArr.filter(function(u){ return u.musteri===musteriAdi && u.kisi>0; })
      .sort(function(a,b){ return b.tarih.localeCompare(a.tarih); });

    // Tarih aralığı: son 90 gün varsayılan
    var bugun = _today();
    var d90 = new Date(); d90.setDate(d90.getDate()-90);
    var baslangic = d90.toISOString().slice(0,10);

    var modal = document.createElement('div');
    modal.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:10000;display:flex;align-items:flex-start;justify-content:center;padding:24px;overflow:auto';
    modal.onclick=function(e){ if(e.target===modal) modal.remove(); };

    function renderDetay(bas, bit){
      var filtrelenmis = musteriKayitlar.filter(function(u){ return u.tarih>=bas && u.tarih<=bit; });
      var topKisi = filtrelenmis.reduce(function(s,u){ return s+(u.kisi||0); },0);
      var topTutar = filtrelenmis.reduce(function(s,u){ return s+(u.tutar||0); },0);
      var gunSayisi = filtrelenmis.length;

      var satirlar = filtrelenmis.map(function(u, ri){
        var d=new Date(u.tarih); var gunAdi=['Paz','Pzt','Sal','Çar','Per','Cum','Cmt'][d.getDay()];
        var haftaSonu=(d.getDay()===0||d.getDay()===6);
        return '<tr style="background:'+(haftaSonu?'#fef3c7':'#fff')+'" data-tarih="'+u.tarih+'">'
          +'<td style="padding:8px 12px;border-bottom:1px solid #e2e8f0;font-weight:600">'+u.tarih+' <span style="color:#64748b;font-size:11px">('+gunAdi+')</span></td>'
          +'<td style="padding:8px 12px;border-bottom:1px solid #e2e8f0;text-align:center">'
          +'<input type="number" class="gsMd-kisi" data-tarih="'+u.tarih+'" value="'+u.kisi+'" min="0" style="width:70px;text-align:center;font-weight:700;color:#2563eb;border:1px solid #e2e8f0;border-radius:6px;padding:4px 6px;font-size:13px" oninput="gsMdCalcRow(this)">'
          +'</td>'
          +'<td style="padding:8px 12px;border-bottom:1px solid #e2e8f0;text-align:right">'+_fmt(u.fiyat||0)+' ₺</td>'
          +'<td style="padding:8px 12px;border-bottom:1px solid #e2e8f0;text-align:right;font-weight:700;color:#166534" class="gsMd-tutar" data-tarih="'+u.tarih+'">'+_fmt(u.tutar||0)+' ₺</td>'
          +'</tr>';
      }).join('');

      if(!satirlar) satirlar='<tr><td colspan="4" style="padding:20px;text-align:center;color:#94a3b8">Bu tarih aralığında kayıt yok</td></tr>';

      return '<div style="background:#f0f9ff;border-radius:12px;padding:16px;margin-bottom:16px;display:grid;grid-template-columns:repeat(3,1fr);gap:12px">'
        +'<div style="text-align:center"><div style="font-size:11px;color:#64748b">Toplam Gün</div><div style="font-size:22px;font-weight:800;color:#2563eb">'+gunSayisi+'</div></div>'
        +'<div style="text-align:center"><div style="font-size:11px;color:#64748b">Toplam Kişi</div><div style="font-size:22px;font-weight:800;color:#7c3aed">'+topKisi.toLocaleString('tr-TR')+'</div></div>'
        +'<div style="text-align:center"><div style="font-size:11px;color:#64748b">Toplam Tutar</div><div style="font-size:22px;font-weight:800;color:#166534">'+_fmt(topTutar)+' ₺</div></div>'
        +'</div>'
        +'<div style="overflow-x:auto;max-height:400px;overflow-y:auto">'
        +'<table style="width:100%;font-size:13px;border-collapse:collapse">'
        +'<thead><tr style="background:#eef3ff;position:sticky;top:0">'
        +'<th style="padding:8px 12px;text-align:left;border-bottom:2px solid #c7d2fe">Tarih</th>'
        +'<th style="padding:8px 12px;text-align:center;border-bottom:2px solid #c7d2fe">Kişi</th>'
        +'<th style="padding:8px 12px;text-align:right;border-bottom:2px solid #c7d2fe">Fiyat</th>'
        +'<th style="padding:8px 12px;text-align:right;border-bottom:2px solid #c7d2fe">Tutar</th>'
        +'</tr></thead>'
        +'<tbody>'+satirlar+'</tbody>'
        +'</table></div>';
    }

    var content = document.createElement('div');
    content.style.cssText='background:#fff;border-radius:16px;max-width:700px;width:100%;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.25);margin-top:40px';
    content.innerHTML='<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">'
      +'<h2 style="margin:0;font-size:18px;color:#1e293b">📊 '+musteriAdi+' — Yemek Sayıları</h2>'
      +'<button onclick="this.closest(\'div[style*=fixed]\').remove()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#64748b">✕</button>'
      +'</div>'
      +'<div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap">'
      +'<label style="font-size:12px;color:#64748b">Başlangıç:</label>'
      +'<input type="date" id="gsMdBas" value="'+baslangic+'" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px"/>'
      +'<label style="font-size:12px;color:#64748b">Bitiş:</label>'
      +'<input type="date" id="gsMdBit" value="'+bugun+'" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px"/>'
      +'<button class="btn primary" style="padding:6px 14px;font-size:12px" id="gsMdFiltrele">Filtrele</button>'
      +'<button style="padding:6px 12px;font-size:11px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:8px;cursor:pointer" onclick="document.getElementById(\'gsMdBas\').value=new Date(Date.now()-7*86400000).toISOString().slice(0,10);document.getElementById(\'gsMdFiltrele\').click()">Son 7 gün</button>'
      +'<button style="padding:6px 12px;font-size:11px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:8px;cursor:pointer" onclick="document.getElementById(\'gsMdBas\').value=new Date().toISOString().slice(0,7)+\'-01\';document.getElementById(\'gsMdFiltrele\').click()">Bu ay</button>'
      +'<button style="padding:6px 12px;font-size:11px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:8px;cursor:pointer" onclick="var d=new Date();d.setMonth(d.getMonth()-1);document.getElementById(\'gsMdBas\').value=d.toISOString().slice(0,7)+\'-01\';var l=new Date(d.getFullYear(),d.getMonth()+1,0);document.getElementById(\'gsMdBit\').value=l.toISOString().slice(0,10);document.getElementById(\'gsMdFiltrele\').click()">Geçen ay</button>'
      +'</div>'
      +'<div id="gsMdIcerik">'+renderDetay(baslangic, bugun)+'</div>'
      +'<div style="display:flex;gap:8px;margin-top:12px;justify-content:flex-end;flex-wrap:wrap">'
      +'<button class="btn" style="font-size:12px;background:#047857;color:#fff;padding:6px 14px" onclick="gsExcelExportMusteri(\''+musteriAdi.replace(/'/g,"\\'")+'\', document.getElementById(\'gsMdBas\').value, document.getElementById(\'gsMdBit\').value)">📥 Excel İndir</button>'
      +'<button class="btn primary" style="font-size:12px;padding:6px 14px" id="gsMdKaydet">💾 Değişiklikleri Kaydet</button>'
      +'</div>';

    modal.appendChild(content);
    document.body.appendChild(modal);

    document.getElementById('gsMdFiltrele').onclick=function(){
      var b=document.getElementById('gsMdBas').value;
      var bi=document.getElementById('gsMdBit').value;
      document.getElementById('gsMdIcerik').innerHTML=renderDetay(b,bi);
    };

    // Detay modalda kişi sayısı değiştirildiğinde satır toplam güncelle
    window.gsMdCalcRow = function(inputEl){
      var tarih = inputEl.getAttribute('data-tarih');
      var kisi = parseInt(inputEl.value)||0;
      var kayit = musteriKayitlar.find(function(u){ return u.tarih===tarih; });
      if(kayit){
        var tutar = kisi * (kayit.fiyat||0);
        var tutarEl = modal.querySelector('.gsMd-tutar[data-tarih="'+tarih+'"]');
        if(tutarEl) tutarEl.textContent = _fmt(tutar)+' ₺';
      }
    };

    // Kaydet butonu: Değişen kişi sayılarını kaydet
    var kaydetBtn = document.getElementById('gsMdKaydet');
    if(kaydetBtn){
      kaydetBtn.onclick = function(){
        var gunlukArr = _getGunluk();
        var gelirArr = _getGelirler();
        var degisen = 0;
        var kisiInputs = modal.querySelectorAll('.gsMd-kisi');
        kisiInputs.forEach(function(inp){
          var tarih = inp.getAttribute('data-tarih');
          var yeniKisi = parseInt(inp.value)||0;
          // Eski kaydı bul
          var idx = -1;
          for(var i=0;i<gunlukArr.length;i++){
            if(gunlukArr[i].musteri===musteriAdi && gunlukArr[i].tarih===tarih){ idx=i; break; }
          }
          if(idx===-1) return;
          var eski = gunlukArr[idx];
          if(eski.kisi===yeniKisi) return;

          // Güncelle
          var yeniFiyat = eski.fiyat||0;
          var yeniTutar = yeniKisi * yeniFiyat;
          gunlukArr[idx] = {musteri:musteriAdi, tarih:tarih, kisi:yeniKisi, fiyat:yeniFiyat, tutar:yeniTutar, not:eski.not||''};

          // Gelir kaydını da güncelle
          for(var gi=0;gi<gelirArr.length;gi++){
            if(gelirArr[gi].musteri===musteriAdi && gelirArr[gi].tarih===tarih && gelirArr[gi].aciklama && gelirArr[gi].aciklama.startsWith('Günlük üretim')){
              gelirArr[gi].tutar = yeniTutar;
              gelirArr[gi].kisi = yeniKisi;
              gelirArr[gi].aciklama = 'Günlük üretim — '+yeniKisi+' kişi × '+yeniFiyat+' ₺';
              break;
            }
          }
          degisen++;
        });

        if(degisen===0){
          _toast('Değişiklik yok.');
          return;
        }

        _saveGunluk(gunlukArr);
        _saveGelirler(gelirArr);
        if(typeof window.renderGelirler==='function') try{ window.renderGelirler(); }catch(e){}
        if(typeof window.refreshFinStats==='function') try{ window.refreshFinStats(); }catch(e){}
        if(typeof window.gsRenderTableV76==='function') try{ window.gsRenderTableV76(); }catch(e){}

        // musteriKayitlar'ı da güncelle
        musteriKayitlar = _getGunluk().filter(function(u){ return u.musteri===musteriAdi && u.kisi>0; })
          .sort(function(a,b){ return b.tarih.localeCompare(a.tarih); });

        _toast('✅ '+degisen+' kayıt güncellendi — '+musteriAdi);

        // Tabloyu güncelle
        var b=document.getElementById('gsMdBas').value;
        var bi=document.getElementById('gsMdBit').value;
        document.getElementById('gsMdIcerik').innerHTML=renderDetay(b,bi);
      };
    }
  };

  /* ── Kaydet butonu override ─────────────────────────────────────── */
  function _bindKaydetBtn(){
    var btn = document.getElementById('gsKaydetBtn');
    if(!btn) return;
    // Clone trick - eski listener temizle
    var fresh = btn.cloneNode(true);
    btn.parentNode.replaceChild(fresh, btn);

    fresh.addEventListener('click', function(){
      var tableDiv = document.getElementById('gsTableDiv');
      if(!tableDiv||!tableDiv.dataset.custs){
        alert('Önce "Tabloyu Yükle" butonuna basın.');
        return;
      }
      var tarih = tableDiv.dataset.tarih||_today();
      var custs = [];
      try{ custs=JSON.parse(tableDiv.dataset.custs||'[]'); }catch(e){}

      var gunlukArr = _getGunluk().filter(function(u){ return u.tarih!==tarih; });
      var gelirArr  = _getGelirler().filter(function(g){
        return !(g.tarih===tarih && g.aciklama && g.aciklama.startsWith('Günlük üretim'));
      });

      // Al/Sat müşterileri için gider dizisi
      var giderArr = [];
      try{ giderArr = JSON.parse(localStorage.getItem('uysa_giderler')||'[]'); }catch(e){ giderArr=[]; }
      // Bugünkü otomatik Al/Sat giderlerini temizle
      giderArr = giderArr.filter(function(g){
        return !(g.tarih===tarih && g.aciklama && g.aciklama.startsWith('Al/Sat alış —'));
      });

      // Al/Sat borçlarını bu tarih için temizle (tekrar oluşturulacak)
      if(typeof window._addBorc==='function'){
        try{
          var borcArr = JSON.parse(localStorage.getItem('uysa_borclar')||'[]');
          borcArr = borcArr.filter(function(b){ return !(b.tarih===tarih && b.aciklama && b.aciklama.startsWith('Al/Sat alış —')); });
          localStorage.setItem('uysa_borclar', JSON.stringify(borcArr));
        }catch(e){}
      }

      var count=0, totalTutar=0, alSatCount=0;
      custs.forEach(function(cust,i){
        // 3 öğünden kişi/fiyat oku
        var topKisi=0, topTutar=0;
        var ogunData = {};
        ['ogle','aksam','kumanya'].forEach(function(og){
          var kEl = document.querySelector('.gsv76-kisi-'+og+'[data-idx="'+i+'"]');
          var fEl = document.querySelector('.gsv76-fiyat-'+og+'[data-idx="'+i+'"]');
          var k = parseInt(kEl?.value)||0;
          var f = parseFloat(fEl?.value)||0;
          ogunData[og] = {kisi:k, fiyat:f};
          topKisi += k;
          topTutar += k*f;
        });
        if(topKisi<=0) return;
        // Ana fiyat = öğle fiyatı (geriye uyumluluk)
        var anaFiyat = ogunData.ogle.fiyat || ogunData.aksam.fiyat || ogunData.kumanya.fiyat || 0;
        gunlukArr.push({musteri:cust, tarih:tarih, kisi:topKisi, fiyat:anaFiyat, tutar:topTutar, not:'',
          ogle:ogunData.ogle, aksam:ogunData.aksam, kumanya:ogunData.kumanya});
        gelirArr.push({musteri:cust, tarih:tarih, tutar:topTutar, kisi:topKisi, fatura:'',
          aciklama:'Günlük üretim — '+topKisi+' kişi (öğle:'+ogunData.ogle.kisi+' akşam:'+ogunData.aksam.kisi+' kumanya:'+ogunData.kumanya.kisi+')'});
        count++;
        totalTutar+=topTutar;

        // Al/Sat müşterisi ise otomatik gider + borç oluştur
        var crmData = _ls.get('uysa_crm_'+cust, {});
        if(crmData.alSat && crmData.alisFiyat>0){
          var alisTutar = topKisi * crmData.alisFiyat;
          var alisAciklama = 'Al/Sat alış — '+cust+' — '+topKisi+' kişi × '+crmData.alisFiyat+' ₺';
          giderArr.push({
            kat:'uretim-diger-gida',
            tarih:tarih,
            tutar:alisTutar,
            aciklama:alisAciklama,
            belge:'',
            tedarikci: crmData.tedarikci||'',
            aitlik:'musteri',
            musteriler:[cust]
          });
          // Borçlara da ekle
          if(typeof window._addBorc==='function'){
            window._addBorc({tedarikci:crmData.tedarikci||cust, tarih:tarih, tutar:alisTutar, belge:'', aciklama:alisAciklama});
          }
          alSatCount++;
        }
      });

      if(count===0){
        alert('Kayıt yapılacak kişi sayısı girilmemiş.');
        return;
      }

      _saveGunluk(gunlukArr);
      _saveGelirler(gelirArr);
      // Al/Sat giderlerini kaydet
      if(alSatCount>0){
        localStorage.setItem('uysa_giderler', JSON.stringify(giderArr));
      }

      // Diğer modülleri güncelle
      if(typeof window.renderGelirler==='function') try{ window.renderGelirler(); }catch(e){}
      if(typeof window.renderGiderler==='function') try{ window.renderGiderler(); }catch(e){}
      if(typeof window.refreshFinStats==='function') try{ window.refreshFinStats(); }catch(e){}
      if(typeof window.renderBorclar==='function') try{ window.renderBorclar(); }catch(e){}

      // Tabloyu yenile (kayıtlı işaretleri göster)
      window.gsRenderTableV76();

      // Aylık rapor güncelle
      if(typeof window.v75RenderAylikRapor==='function') setTimeout(window.v75RenderAylikRapor, 200);
      if(typeof window.v75RenderMusteriListesi==='function') setTimeout(window.v75RenderMusteriListesi, 300);

      var msg = '✅ '+count+' müşteri kaydedildi — Toplam: '+_fmt(totalTutar)+' ₺  Gelire işlendi.';
      if(alSatCount>0) msg += '\n🔄 '+alSatCount+' Al/Sat müşterisi gidere eklendi.';
      _toast(msg);
    });
  }

  /* ═══════════════════════════════════════════════════════════════════
     HAFTALIK MİKTAR KOPYALAMA
     ═══════════════════════════════════════════════════════════════════ */
  function _getMonday(d){
    var dt = new Date(d);
    // Eğer string ise UTC olarak parse eder — lokal saate çevir
    if(typeof d === 'string') { var p=d.split('-'); dt=new Date(parseInt(p[0]),parseInt(p[1])-1,parseInt(p[2])); }
    var day = dt.getDay();
    var diff = dt.getDate() - day + (day===0 ? -6 : 1);
    dt.setDate(diff);
    return dt;
  }
  function _fmtD(d){
    var y = d.getFullYear();
    var m = String(d.getMonth()+1).padStart(2,'0');
    var dd = String(d.getDate()).padStart(2,'0');
    return y+'-'+m+'-'+dd;
  }
  function _fmtDTR(d){
    var p = _fmtD(d).split('-');
    return p[2]+'/'+p[1]+'/'+p[0];
  }

  // Hafta state'ini window'da tut (DOM'a bağımlı olma)
  window._gsHKaynak = null; // kaynak Pzt tarihi string: "2026-04-06"
  window._gsHHedef = null;  // hedef Pzt tarihi string

  window.gsHaftalikKopyaAc = function(){
    var tableDiv = document.getElementById('gsTableDiv');
    if(!tableDiv) return;

    // İlk açılışta veya state yoksa: bugünkü haftayı kullan
    if(!window._gsHKaynak){
      var tarihEl = document.getElementById('gs-tarih');
      var bugun = tarihEl && tarihEl.value ? tarihEl.value : _fmtD(new Date());
      var pzt = _getMonday(bugun);
      window._gsHKaynak = _fmtD(pzt);
    }
    if(!window._gsHHedef){
      var kPzt = _getMonday(window._gsHKaynak);
      var hPzt = new Date(kPzt); hPzt.setDate(kPzt.getDate()+7);
      window._gsHHedef = _fmtD(hPzt);
    }

    var kaynakPzt = _getMonday(window._gsHKaynak);
    var kaynakPaz = new Date(kaynakPzt); kaynakPaz.setDate(kaynakPzt.getDate()+6);
    var hedefPzt = _getMonday(window._gsHHedef);
    var hedefPaz = new Date(hedefPzt); hedefPaz.setDate(hedefPzt.getDate()+6);

    var custs = _getCusts();
    var gunlukArr = _getGunluk();
    var gunAdlari = ['Pzt','Sal','Çar','Per','Cum','Cmt','Paz'];

    // Kaynak haftadaki verileri topla — öğün bazlı
    // kaynakData[cust][d] = {kisi, fiyat, ogle:{kisi,fiyat}, aksam:{kisi,fiyat}, kumanya:{kisi,fiyat}}
    var kaynakData = {};
    custs.forEach(function(cust){
      kaynakData[cust] = {};
      for(var d=0; d<7; d++){
        var dt = new Date(kaynakPzt);
        dt.setDate(kaynakPzt.getDate()+d);
        var tarihStr = _fmtD(dt);
        var kayit = null;
        gunlukArr.forEach(function(u){
          if(u.musteri===cust && u.tarih===tarihStr) kayit = u;
        });
        if(kayit){
          kaynakData[cust][d] = {
            kisi:kayit.kisi||0, fiyat:kayit.fiyat||0,
            ogle: kayit.ogle || {kisi:kayit.kisi||0, fiyat:kayit.fiyat||0},
            aksam: kayit.aksam || {kisi:0, fiyat:kayit.fiyat||0},
            kumanya: kayit.kumanya || {kisi:0, fiyat:kayit.fiyat||0}
          };
        } else {
          var cf = _ls.get('uysa_crm_'+cust,{}).fiyat||0;
          kaynakData[cust][d] = {kisi:0, fiyat:cf, ogle:{kisi:0,fiyat:cf}, aksam:{kisi:0,fiyat:cf}, kumanya:{kisi:0,fiyat:cf}};
        }
      }
    });

    // Tarih formatlama: 06/04
    function _shortD(d){ return String(d.getDate()).padStart(2,'0')+'/'+String(d.getMonth()+1).padStart(2,'0'); }

    // Modal HTML oluştur
    var html = '<div style="background:#fff;border:3px solid #7c3aed;border-radius:14px;padding:20px;margin-bottom:14px;position:relative">';
    html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">';
    html += '<h3 style="margin:0;color:#5b21b6;font-size:18px">Haftalik Miktar Kopyalama</h3>';
    html += '<div style="display:flex;gap:8px">';
    html += '<button class="btn" onclick="gsHaftalikKopyaUygula()" style="background:#7c3aed;color:#fff;font-weight:700;padding:8px 20px;font-size:13px">Uygula</button>';
    html += '<button class="btn" onclick="gsRenderTableV76()" style="padding:8px 16px;font-size:13px">X Iptal</button>';
    html += '</div></div>';

    // Kaynak ve Hedef hafta — ok butonlarıyla kolay navigasyon
    html += '<div style="display:flex;gap:16px;align-items:center;margin-bottom:16px;flex-wrap:wrap">';

    // Kaynak Hafta
    html += '<div style="background:#f0f5ff;border:2px solid #c7d2fe;border-radius:10px;padding:10px 14px;display:flex;align-items:center;gap:8px">';
    html += '<span style="font-weight:700;font-size:12px;color:#374151;white-space:nowrap">Kaynak:</span>';
    html += '<button onclick="gsHaftalikNav(\'kaynak\',-1)" style="padding:4px 10px;font-size:14px;font-weight:800;background:#e0e7ff;color:#4338ca;border:1px solid #c7d2fe;border-radius:6px;cursor:pointer">&lt;</button>';
    html += '<span style="font-weight:800;font-size:13px;color:#1e40af;min-width:130px;text-align:center">'+_shortD(kaynakPzt)+' — '+_shortD(kaynakPaz)+'</span>';
    html += '<button onclick="gsHaftalikNav(\'kaynak\',1)" style="padding:4px 10px;font-size:14px;font-weight:800;background:#e0e7ff;color:#4338ca;border:1px solid #c7d2fe;border-radius:6px;cursor:pointer">&gt;</button>';
    html += '<input type="hidden" id="gsKaynakBas" value="'+_fmtD(kaynakPzt)+'"/>';
    html += '<input type="hidden" id="gsKaynakBit" value="'+_fmtD(kaynakPaz)+'"/>';
    html += '</div>';

    html += '<div style="font-size:20px;color:#7c3aed;font-weight:900">&rarr;</div>';

    // Hedef Hafta
    html += '<div style="background:#f5f3ff;border:2px solid #a78bfa;border-radius:10px;padding:10px 14px;display:flex;align-items:center;gap:8px">';
    html += '<span style="font-weight:700;font-size:12px;color:#374151;white-space:nowrap">Hedef:</span>';
    html += '<button onclick="gsHaftalikNav(\'hedef\',-1)" style="padding:4px 10px;font-size:14px;font-weight:800;background:#ede9fe;color:#7c3aed;border:1px solid #a78bfa;border-radius:6px;cursor:pointer">&lt;</button>';
    html += '<span style="font-weight:800;font-size:13px;color:#7c3aed;min-width:130px;text-align:center">'+_shortD(hedefPzt)+' — '+_shortD(hedefPaz)+'</span>';
    html += '<button onclick="gsHaftalikNav(\'hedef\',1)" style="padding:4px 10px;font-size:14px;font-weight:800;background:#ede9fe;color:#7c3aed;border:1px solid #a78bfa;border-radius:6px;cursor:pointer">&gt;</button>';
    html += '<input type="hidden" id="gsHedefBas" value="'+_fmtD(hedefPzt)+'"/>';
    html += '<input type="hidden" id="gsHedefBit" value="'+_fmtD(hedefPaz)+'"/>';
    html += '</div>';

    html += '</div>';

    var ogunColors = {ogle:'#1e40af', aksam:'#7c3aed', kumanya:'#b45309'};
    var ogunBgs = {ogle:'#eff6ff', aksam:'#f5f3ff', kumanya:'#fffbeb'};

    // Tablo: Müşteri / Öğün / Fiyat / Pzt-Paz / Hafta Top.
    html += '<div style="overflow-x:auto">';
    html += '<table style="width:100%;border-collapse:collapse;font-size:12px">';
    html += '<thead><tr style="background:#f5f3ff">';
    html += '<th style="padding:8px;text-align:left;border-bottom:2px solid #e9e5ff;min-width:120px">Musteri</th>';
    html += '<th style="padding:8px;text-align:center;border-bottom:2px solid #e9e5ff;min-width:60px">Öğün</th>';
    html += '<th style="padding:8px;text-align:center;border-bottom:2px solid #e9e5ff;width:70px">Fiyat</th>';
    for(var d=0; d<7; d++){
      var gunTarih = new Date(kaynakPzt); gunTarih.setDate(kaynakPzt.getDate()+d);
      var isWknd = (d>=5);
      html += '<th style="padding:8px;text-align:center;border-bottom:2px solid #e9e5ff;min-width:55px;'+(isWknd?'background:#fef3c7':'')+'">';
      html += '<div style="font-size:11px;color:'+(isWknd?'#d97706':'#7c3aed')+'">'+gunAdlari[d]+'</div>';
      html += '<div style="font-size:10px;color:#94a3b8">'+gunTarih.getDate()+'/'+(gunTarih.getMonth()+1)+'</div>';
      html += '</th>';
    }
    html += '<th style="padding:8px;text-align:center;border-bottom:2px solid #e9e5ff;width:70px;color:#166534;font-weight:800">Hafta Top.</th>';
    html += '</tr></thead><tbody>';

    custs.forEach(function(cust, ci){
      var crm = _ls.get('uysa_crm_'+cust, {});
      var isOpen = !!window._gsOgunAcik[cust];
      var rowBg = ci%2===0?'#fff':'#faf9ff';

      if(isOpen){
        // ═══ 3 ÖĞÜN AÇIK: her öğün için ayrı satır ═══
        _ogunler.forEach(function(og, oi){
          var isFirst = (oi===0);
          var isLast = (oi===2);
          var haftalikOgunTop = 0;

          html += '<tr style="border-bottom:1px solid '+(isLast?'#c7d2fe':'#f1f5f9')+';background:'+rowBg+'">';

          // Müşteri kolonu — ilk satırda rowspan=3
          if(isFirst){
            html += '<td rowspan="3" style="padding:6px 8px;font-weight:600;color:#1e293b;border-bottom:2px solid #c7d2fe;border-right:1px solid #e9e5ff;vertical-align:top">'
              +cust;
            if(crm.alSat) html += ' <span style="background:#dc2626;color:#fff;font-size:9px;padding:1px 4px;border-radius:3px">Al/Sat</span>';
            html += '<br><button onclick="gsOgunToggle(\''+cust.replace(/'/g,"\\'")+'\','+ci+');gsHaftalikKopyaAc()" style="margin-top:3px;padding:2px 6px;font-size:9px;background:#e0e7ff;color:#4338ca;border:1px solid #c7d2fe;border-radius:5px;cursor:pointer;font-weight:600">▲ Tek Satır</button>';
            html += '</td>';
          }

          // Öğün etiketi
          html += '<td style="padding:4px 6px;text-align:center;border-right:1px solid #e9e5ff">'
            +'<span style="font-size:10px;font-weight:700;color:'+ogunColors[og]+';background:'+ogunBgs[og]+';padding:2px 6px;border-radius:8px">'+_ogunLabels[og]+'</span>'
            +'</td>';

          // Fiyat
          var ogunFiyat = kaynakData[cust][0][og]?.fiyat || crm.fiyat || 0;
          html += '<td style="padding:4px;text-align:center;border-right:1px solid #e9e5ff">'
            +'<input type="number" step="0.01" class="gsHK-fiyat-'+og+'" data-cust="'+ci+'" data-ogun="'+og+'" value="'+ogunFiyat+'" style="width:55px;padding:3px;border:1px solid #e2e8f0;border-radius:5px;text-align:center;font-size:11px"/>'
            +'</td>';

          // Pzt-Paz kişi inputları
          for(var dd=0; dd<7; dd++){
            var ogunKisi = kaynakData[cust][dd][og]?.kisi || 0;
            haftalikOgunTop += ogunKisi;
            var wkBg = dd>=5 ? 'background:#fffbeb;' : '';
            html += '<td style="padding:3px;text-align:center;'+wkBg+'">'
              +'<input type="number" min="0" class="gsHK-kisi-'+og+'" data-cust="'+ci+'" data-day="'+dd+'" data-ogun="'+og+'" value="'+(ogunKisi||'')+'" placeholder="0" style="width:48px;padding:3px;border:1px solid #e2e8f0;border-radius:5px;text-align:center;font-size:11px;font-weight:700" oninput="gsHKCalcTop('+ci+')"/>'
              +'</td>';
          }

          // Öğün haftalık toplam
          html += '<td class="gsHK-ogun-top-'+og+'" data-cust="'+ci+'" style="padding:4px;text-align:center;font-weight:700;color:'+ogunColors[og]+';font-size:11px">'+(haftalikOgunTop||'—')+'</td>';
          html += '</tr>';
        });

        // Toplam satırı (müşteri toplam)
        var custHaftalikTop = 0;
        for(var dd=0; dd<7; dd++){
          _ogunler.forEach(function(og){
            custHaftalikTop += kaynakData[cust][dd][og]?.kisi || 0;
          });
        }
        html += '<tr style="background:#eef3ff;border-bottom:2px solid #c7d2fe">';
        html += '<td colspan="3" style="padding:4px 8px;text-align:right;font-weight:700;font-size:11px;color:#374151">'+cust+' Toplam</td>';
        for(var dd=0; dd<7; dd++){
          var gunTop = 0;
          _ogunler.forEach(function(og){ gunTop += kaynakData[cust][dd][og]?.kisi || 0; });
          html += '<td style="padding:4px;text-align:center;font-weight:800;color:#166534;font-size:11px">'+(gunTop||'—')+'</td>';
        }
        html += '<td class="gsHK-top" data-cust="'+ci+'" style="padding:4px;text-align:center;font-weight:800;color:#166534;font-size:13px">'+custHaftalikTop+'</td>';
        html += '</tr>';

      } else {
        // ═══ TEK SATIR: eski davranış ═══
        var haftalikTop = 0;
        html += '<tr style="border-bottom:1px solid #f1f5f9;background:'+rowBg+'">';
        html += '<td style="padding:6px 8px;font-weight:600;color:#1e293b;border-right:1px solid #e9e5ff">'+cust;
        if(crm.alSat) html += ' <span style="background:#dc2626;color:#fff;font-size:9px;padding:1px 4px;border-radius:3px">Al/Sat</span>';
        html += '<br><button onclick="gsOgunToggle(\''+cust.replace(/'/g,"\\'")+'\','+ci+');gsHaftalikKopyaAc()" style="margin-top:3px;padding:2px 6px;font-size:9px;background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe;border-radius:5px;cursor:pointer;font-weight:600">▼ 3 Öğün</button>';
        html += '</td>';

        // Öğün: Genel
        html += '<td style="padding:4px 6px;text-align:center;border-right:1px solid #e9e5ff"><span style="font-size:10px;font-weight:600;color:#64748b">Genel</span></td>';

        // Fiyat
        html += '<td style="padding:4px;text-align:center;border-right:1px solid #e9e5ff"><input type="number" step="0.01" class="gsHK-fiyat" data-cust="'+ci+'" value="'+(kaynakData[cust][0].fiyat||crm.fiyat||0)+'" style="width:55px;padding:3px;border:1px solid #e2e8f0;border-radius:5px;text-align:center;font-size:11px"/></td>';

        for(var dd=0; dd<7; dd++){
          var val = kaynakData[cust][dd].kisi || 0;
          haftalikTop += val;
          var wkBg = dd>=5 ? 'background:#fffbeb;' : '';
          html += '<td style="padding:3px;text-align:center;'+wkBg+'"><input type="number" min="0" class="gsHK-kisi" data-cust="'+ci+'" data-day="'+dd+'" value="'+(val||'')+'" placeholder="0" style="width:48px;padding:3px;border:1px solid #e2e8f0;border-radius:5px;text-align:center;font-size:12px;font-weight:700" oninput="gsHKCalcTop('+ci+')"/></td>';
        }
        html += '<td class="gsHK-top" data-cust="'+ci+'" style="padding:6px;text-align:center;font-weight:800;color:#166534;font-size:13px">'+(haftalikTop||'—')+'</td>';
        html += '</tr>';
      }
    });

    html += '</tbody></table></div>';
    html += '</div>';

    tableDiv.innerHTML = html;
  };

  // Hafta navigasyonu: < > ok butonlarıyla hafta ileri/geri
  window.gsHaftalikNav = function(tip, yon){
    if(tip==='kaynak'){
      var pzt = _getMonday(window._gsHKaynak);
      pzt.setDate(pzt.getDate() + (yon * 7));
      window._gsHKaynak = _fmtD(pzt);
    } else {
      var pzt = _getMonday(window._gsHHedef);
      pzt.setDate(pzt.getDate() + (yon * 7));
      window._gsHHedef = _fmtD(pzt);
    }
    gsHaftalikKopyaAc();
  };

  // Haftalık toplam hesapla (tek satır + öğünlü)
  window.gsHKCalcTop = function(ci){
    var top = 0;
    // Tek satır inputları
    var inputs = document.querySelectorAll('.gsHK-kisi[data-cust="'+ci+'"]');
    inputs.forEach(function(inp){ top += parseInt(inp.value)||0; });
    // Öğün inputları
    _ogunler.forEach(function(og){
      var ogunInputs = document.querySelectorAll('.gsHK-kisi-'+og+'[data-cust="'+ci+'"]');
      var ogunTop = 0;
      ogunInputs.forEach(function(inp){ ogunTop += parseInt(inp.value)||0; top += parseInt(inp.value)||0; });
      // Öğün toplam hücresini güncelle
      var ogunTopEl = document.querySelector('.gsHK-ogun-top-'+og+'[data-cust="'+ci+'"]');
      if(ogunTopEl) ogunTopEl.textContent = ogunTop||'—';
    });
    var td = document.querySelector('.gsHK-top[data-cust="'+ci+'"]');
    if(td) td.textContent = top||'—';
  };

  // Uygula: hedef haftaya kaynak değerlerini yaz (öğün destekli)
  window.gsHaftalikKopyaUygula = function(){
    if(!window._gsHHedef) return;
    var hedefPzt = _getMonday(window._gsHHedef);

    var custs = _getCusts();
    var gunlukArr = _getGunluk();
    var gelirArr = _getGelirler();
    var kayitSayisi = 0;

    custs.forEach(function(cust, ci){
      var isOpen = !!window._gsOgunAcik[cust];

      for(var d=0; d<7; d++){
        var dt = new Date(hedefPzt);
        dt.setDate(hedefPzt.getDate()+d);
        var tarih = _fmtD(dt);

        var topKisi = 0, topTutar = 0;
        var ogunData = {};

        if(isOpen){
          // Öğün bazlı inputlardan oku
          _ogunler.forEach(function(og){
            var kEl = document.querySelector('.gsHK-kisi-'+og+'[data-cust="'+ci+'"][data-day="'+d+'"]');
            var fEl = document.querySelector('.gsHK-fiyat-'+og+'[data-cust="'+ci+'"]');
            var k = parseInt(kEl?.value)||0;
            var f = parseFloat(fEl?.value)||0;
            ogunData[og] = {kisi:k, fiyat:f};
            topKisi += k;
            topTutar += k * f;
          });
        } else {
          // Tek satır inputlardan oku
          var kisiEl = document.querySelector('.gsHK-kisi[data-cust="'+ci+'"][data-day="'+d+'"]');
          var fiyatEl = document.querySelector('.gsHK-fiyat[data-cust="'+ci+'"]');
          var kisi = parseInt(kisiEl?.value)||0;
          var fiyat = parseFloat(fiyatEl?.value)||0;
          topKisi = kisi;
          topTutar = kisi * fiyat;
          ogunData.ogle = {kisi:kisi, fiyat:fiyat};
          ogunData.aksam = {kisi:0, fiyat:fiyat};
          ogunData.kumanya = {kisi:0, fiyat:fiyat};
        }

        if(topKisi <= 0) continue;

        var anaFiyat = ogunData.ogle.fiyat || ogunData.aksam.fiyat || ogunData.kumanya.fiyat || 0;

        // Mevcut kaydı sil
        gunlukArr = gunlukArr.filter(function(u){
          return !(u.musteri===cust && u.tarih===tarih);
        });
        gelirArr = gelirArr.filter(function(g){
          return !(g.tarih===tarih && g.musteri===cust && g.aciklama && g.aciklama.startsWith('Günlük üretim'));
        });

        // Yeni kayıt ekle (öğün verisiyle birlikte)
        var rec = {musteri:cust, tarih:tarih, kisi:topKisi, fiyat:anaFiyat, tutar:topTutar, not:'haftalik-kopya',
          ogle:ogunData.ogle, aksam:ogunData.aksam, kumanya:ogunData.kumanya};
        gunlukArr.push(rec);

        var aciklama = isOpen
          ? 'Günlük üretim — '+topKisi+' kişi (öğle:'+ogunData.ogle.kisi+' akşam:'+ogunData.aksam.kisi+' kumanya:'+ogunData.kumanya.kisi+')'
          : 'Günlük üretim — '+topKisi+' kişi × '+anaFiyat+' ₺';
        gelirArr.push({musteri:cust, tarih:tarih, tutar:topTutar, kisi:topKisi, fatura:'', aciklama:aciklama});
        kayitSayisi++;
      }
    });

    _saveGunluk(gunlukArr);
    _saveGelirler(gelirArr);

    if(typeof window.renderGelirler==='function') try{ window.renderGelirler(); }catch(e){}
    if(typeof window.refreshFinStats==='function') try{ window.refreshFinStats(); }catch(e){}
    if(typeof window.v75RenderAylikRapor==='function') setTimeout(window.v75RenderAylikRapor, 200);

    _toast('Haftalik kopyalama tamamlandi! '+kayitSayisi+' kayit hedef haftaya yazildi.');
    // Tabloyu yenile
    setTimeout(function(){ gsRenderTableV76(); }, 500);
  };

  /* ═══════════════════════════════════════════════════════════════════
     GEÇEN AYDAN KOPYALA
     ═══════════════════════════════════════════════════════════════════ */
  window.gsGecenAydanKopyala = function(){
    var tarihEl = document.getElementById('gs-tarih');
    var bugun = tarihEl && tarihEl.value ? tarihEl.value : _fmtD(new Date());
    var buAy = bugun.slice(0,7); // "2026-04"

    // Geçen ayı hesapla
    var parts = buAy.split('-');
    var yil = parseInt(parts[0]);
    var ay = parseInt(parts[1]);
    var gecenAy, gecenYil;
    if(ay === 1){ gecenAy = 12; gecenYil = yil - 1; }
    else { gecenAy = ay - 1; gecenYil = yil; }
    var gecenAyStr = gecenYil + '-' + String(gecenAy).padStart(2,'0');

    var ayAdlari = ['','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran',
                    'Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];

    var custs = _getCusts();
    var gunlukArr = _getGunluk();

    // Geçen aydaki kayıtları topla
    var gecenAyKayitlar = gunlukArr.filter(function(u){
      return (u.tarih||'').startsWith(gecenAyStr) && u.kisi > 0;
    });

    if(!gecenAyKayitlar.length){
      alert(ayAdlari[gecenAy]+' '+gecenYil+' ayında kayıt bulunamadı.');
      return;
    }

    // Geçen ayın gün -> bu ayın gün eşleştirmesi
    // Geçen ayın kaç günü var
    var gecenAyGunSayisi = new Date(gecenYil, gecenAy, 0).getDate();
    var buAyGunSayisi = new Date(yil, ay, 0).getDate();

    // Onay modalı
    var modal = document.createElement('div');
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:10000;display:flex;align-items:center;justify-content:center;padding:24px';
    modal.onclick = function(e){ if(e.target===modal) modal.remove(); };

    // Geçen ayın gün bazlı özeti
    var gunOzet = {};
    gecenAyKayitlar.forEach(function(u){
      var gun = parseInt(u.tarih.split('-')[2]);
      if(!gunOzet[gun]) gunOzet[gun] = {kisi:0, musteriler:[]};
      gunOzet[gun].kisi += u.kisi||0;
      if(gunOzet[gun].musteriler.indexOf(u.musteri)===-1) gunOzet[gun].musteriler.push(u.musteri);
    });

    var topKisi = gecenAyKayitlar.reduce(function(s,u){ return s+(u.kisi||0); },0);
    var topGun = Object.keys(gunOzet).length;
    var topMusteri = new Set(gecenAyKayitlar.map(function(u){ return u.musteri; })).size;

    // Bu ayda zaten kayıt olan günler
    var buAyKayitlar = gunlukArr.filter(function(u){
      return (u.tarih||'').startsWith(buAy) && u.kisi > 0;
    });
    var buAyGunler = {};
    buAyKayitlar.forEach(function(u){ buAyGunler[u.tarih] = true; });

    // Kopyalanacak gün sayısını hesapla (bu ayda karşılığı olan ve henüz kaydı olmayan)
    var kopyalanacak = 0;
    Object.keys(gunOzet).forEach(function(gun){
      var g = parseInt(gun);
      if(g <= buAyGunSayisi){
        var hedefTarih = buAy + '-' + String(g).padStart(2,'0');
        if(!buAyGunler[hedefTarih]) kopyalanacak++;
      }
    });

    var content = document.createElement('div');
    content.style.cssText = 'background:#fff;border-radius:16px;max-width:500px;width:100%;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.25)';
    content.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">'
      +'<h2 style="margin:0;font-size:18px;color:#0d9488">📋 Geçen Aydan Kopyala</h2>'
      +'<button onclick="this.closest(\'div[style*=fixed]\').remove()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#64748b">✕</button>'
      +'</div>'
      +'<div style="background:#f0fdfa;border:1.5px solid #99f6e4;border-radius:10px;padding:14px;margin-bottom:14px">'
      +'<div style="font-weight:700;font-size:14px;color:#0d9488;margin-bottom:8px">'+ayAdlari[gecenAy]+' '+gecenYil+' → '+ayAdlari[ay]+' '+yil+'</div>'
      +'<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;font-size:13px">'
      +'<div><span style="color:#64748b">Kayıtlı Gün:</span> <b>'+topGun+'</b></div>'
      +'<div><span style="color:#64748b">Müşteri:</span> <b>'+topMusteri+'</b></div>'
      +'<div><span style="color:#64748b">Toplam Kişi:</span> <b>'+topKisi.toLocaleString('tr-TR')+'</b></div>'
      +'</div>'
      +'</div>'
      +'<div style="font-size:12px;color:#64748b;margin-bottom:8px">'
      +'• Geçen ayın her gününe ait veriler, bu ayın aynı gününe kopyalanır (1→1, 2→2, ...)<br>'
      +'• Bu ayda zaten kaydı olan günler <b>atlanır</b> (üzerine yazılmaz)<br>'
      +'• Bu ayda karşılığı olmayan günler (ör. 31. gün) atlanır<br>'
      +'• Aktif müşteri listesinde olmayan firmalar boş bırakılır'
      +'</div>'
      +'<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:10px;margin-bottom:14px;font-size:13px;font-weight:600;color:#92400e">'
      +'Kopyalanacak: <b>'+kopyalanacak+'</b> gün (mevcut kayıtlı günler atlanır)'
      +'</div>'
      +'<div style="display:flex;gap:8px;justify-content:flex-end">'
      +'<button onclick="this.closest(\'div[style*=fixed]\').remove()" class="btn" style="padding:8px 20px;font-size:13px">İptal</button>'
      +'<button id="gsGecenAyOnaylaBtn" class="btn" style="padding:8px 20px;font-size:13px;background:#0d9488;color:#fff;font-weight:700">Kopyala</button>'
      +'</div>';

    modal.appendChild(content);
    document.body.appendChild(modal);

    document.getElementById('gsGecenAyOnaylaBtn').onclick = function(){
      _gsGecenAyUygula(gecenAyStr, buAy, buAyGunSayisi, custs);
      modal.remove();
    };
  };

  function _gsGecenAyUygula(gecenAyStr, buAy, buAyGunSayisi, custs){
    var gunlukArr = _getGunluk();
    var gelirArr = _getGelirler();
    var gecenKayitlar = gunlukArr.filter(function(u){
      return (u.tarih||'').startsWith(gecenAyStr) && u.kisi > 0;
    });

    // Bu ayda mevcut kayıtları tespit et (üzerine yazma)
    var buAyMevcut = {};
    gunlukArr.forEach(function(u){
      if((u.tarih||'').startsWith(buAy) && u.kisi > 0){
        buAyMevcut[u.musteri+'_'+u.tarih] = true;
      }
    });

    var kayitSayisi = 0;
    gecenKayitlar.forEach(function(u){
      // Aktif müşteri mi kontrol et
      if(custs.indexOf(u.musteri) === -1) return;

      var gun = parseInt(u.tarih.split('-')[2]);
      if(gun > buAyGunSayisi) return; // Bu ayda o gün yok

      var hedefTarih = buAy + '-' + String(gun).padStart(2,'0');
      var key = u.musteri + '_' + hedefTarih;
      if(buAyMevcut[key]) return; // Zaten kaydı var

      // Yeni kayıt oluştur
      var rec = {
        musteri: u.musteri,
        tarih: hedefTarih,
        kisi: u.kisi,
        fiyat: u.fiyat || 0,
        tutar: u.tutar || (u.kisi * (u.fiyat||0)),
        not: 'gecen-ay-kopya'
      };
      // Öğün verilerini kopyala
      if(u.ogle)    rec.ogle    = {kisi:u.ogle.kisi||0, fiyat:u.ogle.fiyat||u.fiyat||0};
      if(u.aksam)   rec.aksam   = {kisi:u.aksam.kisi||0, fiyat:u.aksam.fiyat||u.fiyat||0};
      if(u.kumanya) rec.kumanya = {kisi:u.kumanya.kisi||0, fiyat:u.kumanya.fiyat||u.fiyat||0};
      if(!rec.ogle && !rec.aksam && !rec.kumanya){
        rec.ogle = {kisi:u.kisi, fiyat:u.fiyat||0};
        rec.aksam = {kisi:0, fiyat:u.fiyat||0};
        rec.kumanya = {kisi:0, fiyat:u.fiyat||0};
      }

      gunlukArr.push(rec);
      gelirArr.push({
        musteri: u.musteri, tarih: hedefTarih,
        tutar: rec.tutar, kisi: rec.kisi, fatura: '',
        aciklama: 'Günlük üretim — '+rec.kisi+' kişi × '+(rec.fiyat||0)+' ₺ (geçen aydan)'
      });
      buAyMevcut[key] = true; // Aynı gün+müşteriyi tekrar ekleme
      kayitSayisi++;
    });

    if(kayitSayisi === 0){
      _toast('Kopyalanacak yeni kayıt bulunamadı (tüm günler zaten dolu).');
      return;
    }

    _saveGunluk(gunlukArr);
    _saveGelirler(gelirArr);
    if(typeof window.renderGelirler==='function') try{ window.renderGelirler(); }catch(e){}
    if(typeof window.refreshFinStats==='function') try{ window.refreshFinStats(); }catch(e){}
    if(typeof window.v75RenderAylikRapor==='function') setTimeout(window.v75RenderAylikRapor, 200);
    gsRenderTableV76();

    _toast('Geçen aydan '+kayitSayisi+' kayıt bu aya kopyalandı!');
  }

  /* ── gsRenderTable override (eski çağrıları da yakala) ─────────── */
  var _origGsRenderTable = window.gsRenderTable;
  window.gsRenderTable = function(){
    window.gsRenderTableV76();
  };
  window.renderGunlukSayilar = function(){ window.gsRenderTableV76(); };

  /* switchFinTab sayi hook — artık ana fonksiyonda birleştirildi */

  /* ── Gün sonu otomatik kayıt ──────────────────────────────────── */
  function _autoSaveGunSonu(){
    var tarih = _today();
    var gunlukArr = _getGunluk();
    var bugunKayitVar = gunlukArr.some(function(u){ return u.tarih===tarih && u.kisi>0; });
    if(bugunKayitVar) return; // Bugün zaten kaydedilmiş

    var custs = _getCusts();
    if(!custs.length) return;

    var yeniKayitlar = [];
    custs.forEach(function(cust){
      // CRM'den kişi ve fiyat al
      var crmData = _ls.get('uysa_crm_'+cust, {});
      var crmKisi = parseInt(crmData.kisi)||0;
      var crmFiyat = parseFloat(crmData.fiyat)||0;

      // Önceki günden otomatik değer bul
      var d = new Date(tarih);
      var isWeekend = d.getDay()===0 || d.getDay()===6;
      var oncekiArr;
      if(isWeekend){
        oncekiArr = gunlukArr.filter(function(u){
          var _d=new Date(u.tarih), _g=_d.getDay();
          return u.musteri===cust && (_g===0||_g===6) && u.kisi>0 && u.tarih<tarih;
        }).sort(function(a,b){ return b.tarih.localeCompare(a.tarih); });
      } else {
        oncekiArr = gunlukArr.filter(function(u){
          var _d=new Date(u.tarih), _g=_d.getDay();
          return u.musteri===cust && _g!==0 && _g!==6 && u.kisi>0 && u.tarih<tarih;
        }).sort(function(a,b){ return b.tarih.localeCompare(a.tarih); });
      }

      var prev = (oncekiArr.length>0) ? oncekiArr[0] : null;
      var kisi = prev ? prev.kisi : crmKisi;
      var fiyat = prev ? (prev.fiyat||crmFiyat) : crmFiyat;
      if(kisi<=0) return;

      // Öğün verilerini önceki kayıttan kopyala
      var rec = {musteri:cust, tarih:tarih, kisi:kisi, fiyat:fiyat, tutar:kisi*fiyat, not:'otomatik'};
      if(prev && prev.ogle) rec.ogle = {kisi:prev.ogle.kisi||0, fiyat:prev.ogle.fiyat||fiyat};
      if(prev && prev.aksam) rec.aksam = {kisi:prev.aksam.kisi||0, fiyat:prev.aksam.fiyat||fiyat};
      if(prev && prev.kumanya) rec.kumanya = {kisi:prev.kumanya.kisi||0, fiyat:prev.kumanya.fiyat||fiyat};
      // Öğün yoksa eski formatı koru (sadece öğle)
      if(!rec.ogle && !rec.aksam && !rec.kumanya){
        rec.ogle = {kisi:kisi, fiyat:fiyat};
        rec.aksam = {kisi:0, fiyat:fiyat};
        rec.kumanya = {kisi:0, fiyat:fiyat};
      }
      yeniKayitlar.push(rec);
    });

    if(!yeniKayitlar.length) return;

    // Kaydet
    var gunlukFull = _getGunluk().filter(function(u){ return u.tarih!==tarih; });
    gunlukFull = gunlukFull.concat(yeniKayitlar);
    _saveGunluk(gunlukFull);

    // Gelire de ekle
    var gelirArr = _getGelirler().filter(function(g){
      return !(g.tarih===tarih && g.aciklama && g.aciklama.startsWith('Günlük üretim'));
    });
    // Al/Sat müşterileri için otomatik gider
    var giderArr = [];
    try{ giderArr = JSON.parse(localStorage.getItem('uysa_giderler')||'[]'); }catch(e){ giderArr=[]; }
    giderArr = giderArr.filter(function(g){
      return !(g.tarih===tarih && g.aciklama && g.aciklama.startsWith('Al/Sat alış —'));
    });
    var alSatCount = 0;

    // Al/Sat borçlarını bu tarih için temizle
    if(typeof window._addBorc==='function'){
      try{
        var borcArr = JSON.parse(localStorage.getItem('uysa_borclar')||'[]');
        borcArr = borcArr.filter(function(b){ return !(b.tarih===tarih && b.aciklama && b.aciklama.startsWith('Al/Sat alış —')); });
        localStorage.setItem('uysa_borclar', JSON.stringify(borcArr));
      }catch(e){}
    }

    yeniKayitlar.forEach(function(r){
      gelirArr.push({musteri:r.musteri, tarih:r.tarih, tutar:r.tutar, kisi:r.kisi, fatura:'',
        aciklama:'Günlük üretim — '+r.kisi+' kişi × '+r.fiyat+' ₺ (otomatik)'});
      // Al/Sat otomatik gider + borç
      var crmData = _ls.get('uysa_crm_'+r.musteri, {});
      if(crmData.alSat && crmData.alisFiyat>0){
        var alisAciklama = 'Al/Sat alış — '+r.musteri+' — '+r.kisi+' kişi × '+crmData.alisFiyat+' ₺';
        giderArr.push({
          kat:'uretim-diger-gida', tarih:tarih, tutar:r.kisi*crmData.alisFiyat,
          aciklama:alisAciklama,
          belge:'', tedarikci:crmData.tedarikci||'', aitlik:'musteri', musteriler:[r.musteri]
        });
        if(typeof window._addBorc==='function'){
          window._addBorc({tedarikci:crmData.tedarikci||r.musteri, tarih:tarih, tutar:r.kisi*crmData.alisFiyat, belge:'', aciklama:alisAciklama});
        }
        alSatCount++;
      }
    });
    _saveGelirler(gelirArr);
    if(alSatCount>0) localStorage.setItem('uysa_giderler', JSON.stringify(giderArr));

    console.log('✅ Gün sonu otomatik kayıt: '+yeniKayitlar.length+' müşteri, '+alSatCount+' Al/Sat gider, tarih: '+tarih);
    if(typeof window.renderGelirler==='function') try{ window.renderGelirler(); }catch(e){}
    if(typeof window.renderGiderler==='function') try{ window.renderGiderler(); }catch(e){}
    if(typeof window.refreshFinStats==='function') try{ window.refreshFinStats(); }catch(e){}
  }

  // Her gün saat 12:00'de otomatik kayıt kontrolü
  function _scheduleAutoSave(){
    var now = new Date();
    var hedef = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 12, 0, 0);
    if(now >= hedef) hedef.setDate(hedef.getDate()+1);
    var ms = hedef.getTime() - now.getTime();
    setTimeout(function(){
      _autoSaveGunSonu();
      // Ertesi gün için tekrar kur
      setInterval(_autoSaveGunSonu, 24*60*60*1000);
    }, ms);

    // Sayfa açıldığında: bugünün ve dünün kaydı yoksa otomatik oluştur
    var _autoFillDay = function(targetDate){
      var tarih = targetDate.getFullYear()+'-'+String(targetDate.getMonth()+1).padStart(2,'0')+'-'+String(targetDate.getDate()).padStart(2,'0');
      var dow = targetDate.getDay();
      if(dow===0 || dow===6) return; // Hafta sonu atla
      var gunlukArr = _getGunluk();
      if(gunlukArr.some(function(u){ return u.tarih===tarih && u.kisi>0; })) return; // Zaten var
      var custs = _getCusts();
      var yeniKayitlar = [];
      custs.forEach(function(cust){
        var crmData = _ls.get('uysa_crm_'+cust, {});
        var oncekiArr = gunlukArr.filter(function(u){
          var _d=new Date(u.tarih), _g=_d.getDay();
          return u.musteri===cust && _g!==0 && _g!==6 && u.kisi>0 && u.tarih<tarih;
        }).sort(function(a,b){ return b.tarih.localeCompare(a.tarih); });
        var kisi = (oncekiArr.length>0) ? oncekiArr[0].kisi : (parseInt(crmData.kisi)||0);
        var fiyat = (oncekiArr.length>0) ? oncekiArr[0].fiyat : (parseFloat(crmData.fiyat)||0);
        if(kisi<=0) return;
        yeniKayitlar.push({musteri:cust, tarih:tarih, kisi:kisi, fiyat:fiyat, tutar:kisi*fiyat, not:'otomatik'});
      });
      if(!yeniKayitlar.length) return;
      var full = _getGunluk().filter(function(u){ return u.tarih!==tarih; }).concat(yeniKayitlar);
      _saveGunluk(full);
      var gel = _getGelirler().filter(function(g){ return !(g.tarih===tarih && g.aciklama && g.aciklama.startsWith('Günlük üretim')); });
      yeniKayitlar.forEach(function(r){ gel.push({musteri:r.musteri,tarih:r.tarih,tutar:r.tutar,kisi:r.kisi,fatura:'',aciklama:'Günlük üretim — '+r.kisi+' kişi × '+r.fiyat+' ₺ (otomatik)'}); });
      _saveGelirler(gel);
      // Al/Sat gider + borç sync
      var giderArr=[]; try{ giderArr=JSON.parse(localStorage.getItem('uysa_giderler')||'[]'); }catch(e){}
      giderArr=giderArr.filter(function(g){ return !(g.tarih===tarih && g.aciklama && g.aciklama.startsWith('Al/Sat alış —')); });
      // Al/Sat borçlarını bu tarih için temizle
      if(typeof window._addBorc==='function'){
        try{
          var borcArr=JSON.parse(localStorage.getItem('uysa_borclar')||'[]');
          borcArr=borcArr.filter(function(b){ return !(b.tarih===tarih && b.aciklama && b.aciklama.startsWith('Al/Sat alış —')); });
          localStorage.setItem('uysa_borclar', JSON.stringify(borcArr));
        }catch(e){}
      }
      var alSatCount=0;
      yeniKayitlar.forEach(function(r){
        var cd=_ls.get('uysa_crm_'+r.musteri,{});
        if(cd.alSat && cd.alisFiyat>0){
          var alisAciklama='Al/Sat alış — '+r.musteri+' — '+r.kisi+' kişi × '+cd.alisFiyat+' ₺';
          giderArr.push({kat:'uretim-diger-gida',tarih:tarih,tutar:r.kisi*cd.alisFiyat,aciklama:alisAciklama,belge:'',tedarikci:cd.tedarikci||'',aitlik:'musteri',musteriler:[r.musteri]});
          if(typeof window._addBorc==='function'){
            window._addBorc({tedarikci:cd.tedarikci||r.musteri, tarih:tarih, tutar:r.kisi*cd.alisFiyat, belge:'', aciklama:alisAciklama});
          }
          alSatCount++;
        }
      });
      if(alSatCount>0) localStorage.setItem('uysa_giderler',JSON.stringify(giderArr));
      console.log('✅ Otomatik kayıt ('+tarih+'): '+yeniKayitlar.length+' müşteri, '+alSatCount+' Al/Sat');
    };

    // Dün
    var dun = new Date(); dun.setDate(dun.getDate()-1);
    _autoFillDay(dun);
    // Bugün
    _autoFillDay(new Date());

    // Pasif müşteri temizliği (bir kerelik)
    try{
      var _pasif = JSON.parse(localStorage.getItem('uysa_pasif_musteriler')||'[]');
      if(_pasif.indexOf('SANBARELS')<0){
        _pasif.push('SANBARELS');
        localStorage.setItem('uysa_pasif_musteriler', JSON.stringify(_pasif));
      }
    }catch(e){}

    // Dashboard yenile
    setTimeout(function(){
      if(typeof window.renderAnasayfa==='function') try{ window.renderAnasayfa(); }catch(e){}
      if(typeof window.renderGelirler==='function') try{ window.renderGelirler(); }catch(e){}
    }, 500);
  }

  /* ── Excel Export ─────────────────────────────────────────────── */
  window.gsExcelExport = function(){
    var gunlukArr = _getGunluk();
    if(!gunlukArr.length){
      alert('Dışa aktarılacak veri yok.');
      return;
    }

    // Tarih aralığı seçim modalı
    var modal = document.createElement('div');
    modal.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10000;display:flex;align-items:center;justify-content:center';
    modal.onclick=function(e){ if(e.target===modal) modal.remove(); };

    var bugun = _today();
    var d30 = new Date(); d30.setDate(d30.getDate()-30);
    var baslangic = d30.toISOString().slice(0,10);

    var box = document.createElement('div');
    box.style.cssText='background:#fff;border-radius:16px;padding:24px;max-width:400px;width:90%;box-shadow:0 12px 40px rgba(0,0,0,.2)';
    box.innerHTML='<h3 style="margin:0 0 16px">📥 Excel İndir</h3>'
      +'<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">'
      +'<div><label style="font-size:12px;color:#64748b">Başlangıç</label><input type="date" id="gsExBas" value="'+baslangic+'" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box"></div>'
      +'<div><label style="font-size:12px;color:#64748b">Bitiş</label><input type="date" id="gsExBit" value="'+bugun+'" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box"></div>'
      +'</div>'
      +'<div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">'
      +'<button class="btn" style="font-size:11px;padding:4px 10px" onclick="document.getElementById(\'gsExBas\').value=new Date(Date.now()-7*86400000).toISOString().slice(0,10)">Son 7 gün</button>'
      +'<button class="btn" style="font-size:11px;padding:4px 10px" onclick="document.getElementById(\'gsExBas\').value=new Date().toISOString().slice(0,7)+\'-01\'">Bu ay</button>'
      +'<button class="btn" style="font-size:11px;padding:4px 10px" onclick="var d=new Date();d.setMonth(d.getMonth()-1);document.getElementById(\'gsExBas\').value=d.toISOString().slice(0,7)+\'-01\';var l=new Date(d.getFullYear(),d.getMonth()+1,0);document.getElementById(\'gsExBit\').value=l.toISOString().slice(0,10)">Geçen ay</button>'
      +'</div>'
      +'<div style="display:flex;gap:8px;justify-content:flex-end">'
      +'<button class="btn" onclick="this.closest(\'div[style*=fixed]\').remove()">İptal</button>'
      +'<button class="btn primary" id="gsExIndir" style="background:#047857">📥 İndir</button>'
      +'</div>';
    modal.appendChild(box);
    document.body.appendChild(modal);

    document.getElementById('gsExIndir').onclick=function(){
      var bas = document.getElementById('gsExBas').value;
      var bit = document.getElementById('gsExBit').value;
      var filtered = gunlukArr.filter(function(u){ return u.tarih>=bas && u.tarih<=bit && u.kisi>0; })
        .sort(function(a,b){ return a.tarih.localeCompare(b.tarih) || a.musteri.localeCompare(b.musteri); });

      if(!filtered.length){ alert('Seçili tarih aralığında kayıt yok.'); return; }

      // CSV oluştur (Excel uyumlu, UTF-8 BOM + ; separator)
      var bom = '\uFEFF';
      var rows = [['Tarih','Gün','Müşteri','Kişi Sayısı','Birim Fiyat (₺)','Toplam (₺)','Not'].join(';')];
      var gunAdlari = ['Pazar','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi'];
      filtered.forEach(function(u){
        var d = new Date(u.tarih);
        rows.push([
          u.tarih,
          gunAdlari[d.getDay()],
          '"'+u.musteri+'"',
          u.kisi,
          (u.fiyat||0).toFixed(2).replace('.',','),
          (u.tutar||0).toFixed(2).replace('.',','),
          u.not||''
        ].join(';'));
      });

      // Toplam satır
      var topKisi = filtered.reduce(function(s,u){ return s+(u.kisi||0); },0);
      var topTutar = filtered.reduce(function(s,u){ return s+(u.tutar||0); },0);
      rows.push(['','','TOPLAM',topKisi,'',topTutar.toFixed(2).replace('.',','),''].join(';'));

      var blob = new Blob([bom + rows.join('\n')], {type:'text/csv;charset=utf-8'});
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = 'UYSA_Uretim_'+bas+'_'+bit+'.csv';
      a.click();
      URL.revokeObjectURL(url);
      modal.remove();
      _toast('📥 Excel dosyası indirildi: '+filtered.length+' kayıt');
    };
  };

  /* ── Excel export: Müşteri detay modalından ────────────────────── */
  window.gsExcelExportMusteri = function(musteriAdi, bas, bit){
    var gunlukArr = _getGunluk();
    var filtered = gunlukArr.filter(function(u){ return u.musteri===musteriAdi && u.tarih>=bas && u.tarih<=bit && u.kisi>0; })
      .sort(function(a,b){ return a.tarih.localeCompare(b.tarih); });
    if(!filtered.length){ alert('Kayıt yok.'); return; }

    var bom = '\uFEFF';
    var rows = [['Tarih','Gün','Kişi Sayısı','Birim Fiyat (₺)','Toplam (₺)'].join(';')];
    var gunAdlari = ['Pazar','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi'];
    filtered.forEach(function(u){
      var d = new Date(u.tarih);
      rows.push([u.tarih, gunAdlari[d.getDay()], u.kisi, (u.fiyat||0).toFixed(2).replace('.',','), (u.tutar||0).toFixed(2).replace('.',',')].join(';'));
    });
    var topKisi = filtered.reduce(function(s,u){ return s+(u.kisi||0); },0);
    var topTutar = filtered.reduce(function(s,u){ return s+(u.tutar||0); },0);
    rows.push(['','TOPLAM',topKisi,'',topTutar.toFixed(2).replace('.',',')].join(';'));

    var blob = new Blob([bom + rows.join('\n')], {type:'text/csv;charset=utf-8'});
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'UYSA_'+musteriAdi.replace(/[^a-zA-Z0-9ğüşıöçĞÜŞİÖÇ]/g,'_')+'_'+bas+'_'+bit+'.csv';
    a.click();
    URL.revokeObjectURL(url);
    _toast('📥 '+musteriAdi+' Excel indirildi');
  };

  /* ── INIT ───────────────────────────────────────────────────────── */
  function _initV76(){
    console.log('✅ UYSA v78 patch loading...');
    _bindKaydetBtn();

    // Tarih input değişimini dinle
    var tarihEl = document.getElementById('gs-tarih');
    if(tarihEl){
      tarihEl.addEventListener('change', function(){
        window.gsRenderTableV76();
      });
      // Başlangıç değeri yoksa bugünü koy
      if(!tarihEl.value) tarihEl.value=_today();
    }

    // Eğer finans sayi pane aktif açıksa render et
    var sanyiPane = document.getElementById('finPane-sayi');
    if(sanyiPane && sanyiPane.style.display!=='none'){
      setTimeout(window.gsRenderTableV76, 200);
    }

    // Gün sonu otomatik kayıt zamanlayıcısı
    _scheduleAutoSave();

    console.log('✅ UYSA v78 patch loaded (auto-save + excel export aktif)');
  }

  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded', _initV76);
  } else {
    _initV76();
  }

})();
