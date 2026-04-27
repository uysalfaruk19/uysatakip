/* ══════════════════════════════════════════════════════════════════════
   UYSA v95 PATCH
   Üretim Özeti — TÜM aktif müşteriler gösterilir (kayıtsız olsalar bile)
   Firma filtresi + Toplam Göster + satır düzenleme korundu.
   ══════════════════════════════════════════════════════════════════════ */
(function(){
  'use strict';

  /* ── Yardımcılar ────────────────────────────────────────────────────── */
  var _ls = {
    get: function(k,d){ try{ var v=localStorage.getItem(k); return v===null?d:JSON.parse(v); }catch(e){ return d; } },
    set: function(k,v){ try{ localStorage.setItem(k,JSON.stringify(v)); }catch(e){} }
  };
  function _fmt(n){ return Number(n||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function _toast(msg){
    if(typeof window.toast==='function'){ window.toast(msg); return; }
    var t=document.createElement('div');
    t.textContent=msg;
    t.style.cssText='position:fixed;bottom:24px;right:24px;background:#1e293b;color:#fff;padding:12px 20px;border-radius:10px;font-size:13px;z-index:99999;max-width:360px;box-shadow:0 4px 20px rgba(0,0,0,.35)';
    document.body.appendChild(t);
    setTimeout(function(){ t.remove(); },3500);
  }
  function _today(){
    var d=new Date();
    return d.getFullYear()+'-'+(''+(d.getMonth()+1)).padStart(2,'0')+'-'+(''+d.getDate()).padStart(2,'0');
  }
  /* TÜM aktif müşteriler — pasif hariç, GENEL hariç */
  function _getCustsAll(){
    try{
      var raw=localStorage.getItem('uysa_customers_v1');
      var pasif=_ls.get('uysa_pasif_musteriler',[]);
      if(!raw) return [];
      var obj=JSON.parse(raw);
      return (obj.customers||[]).filter(function(x){ return x&&x!=='GENEL'&&!pasif.includes(x); })
                                .sort(function(a,b){ return a.localeCompare(b,'tr'); });
    }catch(e){ return []; }
  }
  function _getGunluk(){ return _ls.get('uysa_gunluk_uretim',[]); }
  function _saveGunluk(arr){
    _ls.set('uysa_gunluk_uretim',arr);
    try{ if(typeof window.autoSaveToFolder==='function') window.autoSaveToFolder(); }catch(e){}
  }
  function _getGelirler(){ return _ls.get('uysa_gelirler',[]); }
  function _saveGelirler(arr){ _ls.set('uysa_gelirler',arr); }

  /* ── Global durum ───────────────────────────────────────────────────── */
  window._v94FirmaFilter = window._v94FirmaFilter || null;
  window._v94ShowTotal   = window._v94ShowTotal   || false;

  /* ══════════════════════════════════════════════════════════════════════
     ANA RENDER FONKSİYONU
  ══════════════════════════════════════════════════════════════════════ */
  window.v94RenderAylikRapor = function(){
    var div = document.getElementById('v75AylikRaporDiv');
    if(!div) return;

    /* Hangi ay? */
    var ayEl    = document.getElementById('v77AySecici');
    var tarihEl = document.getElementById('gs-tarih');
    var buAy;
    if(ayEl && ayEl.value)           buAy = ayEl.value;
    else if(tarihEl && tarihEl.value) buAy = tarihEl.value.slice(0,7);
    else                              buAy = _today().slice(0,7);

    var ayAdlari=['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran',
                  'Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
    var parts=buAy.split('-');
    var ayAdi=ayAdlari[parseInt(parts[1],10)-1]+' '+parts[0];

    /* ── Veri: TÜM aktif müşteriler + ay kayıtları ──────────────────── */
    var allCusts  = _getCustsAll();           /* registry → tüm aktif */
    var gunlukArr = _getGunluk();
    var ayKayitlar = gunlukArr.filter(function(u){
      return (u.tarih||'').startsWith(buAy) && u.musteri && u.musteri!=='GENEL';
    });

    /* Kayıtlardaki müşteri adlarını da ekle (eski kayıt / ad değişikliği) */
    ayKayitlar.forEach(function(u){
      if(allCusts.indexOf(u.musteri)===-1) allCusts.push(u.musteri);
    });
    allCusts.sort(function(a,b){ return a.localeCompare(b,'tr'); });

    /* Kayıt yoksa uyarı — ama müşteri listesi doluysa yine de göster */
    if(!allCusts.length){
      div.innerHTML='<div style="color:var(--muted);padding:12px">📭 Henüz müşteri eklenmemiş.</div>';
      return;
    }

    /* ── Firma filtresi ─────────────────────────────────────────────── */
    var firmaFilter = window._v94FirmaFilter;
    var showTotal   = window._v94ShowTotal;
    var gunFilter   = window._v94GunFilter || null; // 'YYYY-MM-DD' veya null

    var displayCusts = firmaFilter
      ? allCusts.filter(function(c){ return c===firmaFilter; })
      : allCusts;

    /* Gün filtresi uygulandıysa ayKayitlar'ı daralt */
    var filteredKayitlar = gunFilter
      ? ayKayitlar.filter(function(u){ return u.tarih === gunFilter; })
      : ayKayitlar;

    /* ── Mevcut günler listesi (dropdown için) ───────────────────────── */
    var mevcutGunler = [];
    var gunlerSet = {};
    ayKayitlar.forEach(function(u){
      if(!gunlerSet[u.tarih]){
        gunlerSet[u.tarih] = true;
        mevcutGunler.push(u.tarih);
      }
    });
    mevcutGunler.sort();

    /* ── Firma dropdown + Gün filtresi ───────────────────────────────── */
    var controlHtml =
      '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px;padding:10px 14px;background:#eff6ff;border-radius:10px;border:1.5px solid #c7d2fe">'
      +'<span style="font-size:12px;font-weight:700;color:#2563eb">🏢 Firma:</span>'
      +'<select id="v94FirmaSelect" onchange="window.v94OnFirmaChange(this.value)" '
      +'style="padding:6px 12px;border:1.5px solid #c7d2fe;border-radius:8px;font-size:13px;font-weight:600;color:#1e40af;background:#fff;cursor:pointer">'
      +'<option value="">— Tüm Firmalar —</option>';
    allCusts.forEach(function(m){
      var kayitVar = ayKayitlar.some(function(u){ return u.musteri===m; });
      controlHtml += '<option value="'+m+'"'+(firmaFilter===m?' selected':'')+'>'
        +m+(kayitVar?' ✓':' (kayıt yok)')+'</option>';
    });
    controlHtml += '</select>';

    /* Gün filtresi dropdown */
    controlHtml += '<span style="font-size:12px;font-weight:700;color:#2563eb;margin-left:8px">📅 Gün:</span>'
      +'<select id="v94GunSelect" onchange="window.v94OnGunChange(this.value)" '
      +'style="padding:6px 12px;border:1.5px solid #c7d2fe;border-radius:8px;font-size:13px;font-weight:600;color:#1e40af;background:#fff;cursor:pointer">'
      +'<option value="">— Tüm Günler —</option>';
    var gunAdlari = ['Paz','Pzt','Sal','Çar','Per','Cum','Cmt'];
    mevcutGunler.forEach(function(t){
      var dt = new Date(t+'T00:00:00');
      var gunAdi = gunAdlari[dt.getDay()];
      var parts = t.split('-');
      var label = parts[2]+'.'+parts[1]+' '+gunAdi;
      /* O gün kaç kişi var */
      var gunKisi = ayKayitlar.filter(function(u){ return u.tarih===t; }).reduce(function(s,u){ return s+(u.kisi||0); },0);
      controlHtml += '<option value="'+t+'"'+(gunFilter===t?' selected':'')+'>'
        +label+' ('+gunKisi+' kişi)</option>';
    });
    controlHtml += '</select>';

    if(gunFilter){
      controlHtml += '<button onclick="window.v94OnGunChange(\'\')" style="padding:4px 10px;border:1px solid #dc2626;border-radius:6px;font-size:11px;background:#fef2f2;color:#dc2626;cursor:pointer;font-weight:600">✕ Temizle</button>';
    }

    if(firmaFilter){
      controlHtml +=
        '<button onclick="window.v94ToggleTotal()" style="padding:6px 14px;'
        +'border:1.5px solid '+(showTotal?'#16a34a':'#64748b')+';border-radius:8px;'
        +'font-size:12px;font-weight:700;background:'+(showTotal?'#f0fdf4':'#fff')+';'
        +'color:'+(showTotal?'#16a34a':'#64748b')+';cursor:pointer">'
        +'📊 Toplam Göster</button>';
    }
    controlHtml += '<button onclick="window.v94ExcelIndir()" style="padding:6px 14px;margin-left:auto;'
      +'border:1.5px solid #047857;border-radius:8px;font-size:12px;font-weight:700;'
      +'background:#047857;color:#fff;cursor:pointer">📥 Excel İndir</button>';
    controlHtml += '</div>';

    /* ── Genel istatistikler (seçili firma + güne göre) ────────────── */
    var secilenKayitlar = firmaFilter
      ? filteredKayitlar.filter(function(u){ return u.musteri===firmaFilter; })
      : filteredKayitlar;

    var toplamKisi   = secilenKayitlar.reduce(function(s,u){ return s+(u.kisi||0); },0);
    var toplamTutar  = secilenKayitlar.reduce(function(s,u){ return s+(u.tutar||0); },0);
    var toplamGun    = new Set(secilenKayitlar.map(function(u){ return u.tarih; })).size;
    var toplamMust   = firmaFilter ? 1 : displayCusts.length;

    /* ── Özet kartlar ───────────────────────────────────────────────── */
    var cardsHtml =
      '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:14px">'
      +'<div style="background:#eff6ff;border-radius:10px;padding:12px;text-align:center">'
      +'<div style="font-size:22px">🏢</div><div style="font-weight:800;font-size:18px;color:#2563eb">'+toplamMust+'</div>'
      +'<div style="font-size:11px;color:#64748b">'+(firmaFilter?'Seçili Firma':'Müşteri')+'</div></div>'
      +'<div style="background:#f0fdf4;border-radius:10px;padding:12px;text-align:center">'
      +'<div style="font-size:22px">📅</div><div style="font-weight:800;font-size:18px;color:#16a34a">'+toplamGun+'</div>'
      +'<div style="font-size:11px;color:#64748b">Kayıtlı Gün</div></div>'
      +'<div style="background:#fef9c3;border-radius:10px;padding:12px;text-align:center">'
      +'<div style="font-size:22px">👥</div><div style="font-weight:800;font-size:18px;color:#ca8a04">'+toplamKisi.toLocaleString('tr-TR')+'</div>'
      +'<div style="font-size:11px;color:#64748b">Toplam Kişi</div></div>'
      +'<div style="background:#fdf4ff;border-radius:10px;padding:12px;text-align:center">'
      +'<div style="font-size:22px">💰</div><div style="font-weight:800;font-size:16px;color:#7c3aed">'+_fmt(toplamTutar)+' ₺</div>'
      +'<div style="font-size:11px;color:#64748b">'+(gunFilter?'Günlük Toplam':'Aylık Toplam')+'</div></div>'
      +'</div>';

    /* ── Toplam Göster kartı ────────────────────────────────────────── */
    var totalCardHtml = '';
    if(firmaFilter && showTotal){
      var kdvPct=window._gsKdvPct||0, kdvT=toplamTutar*kdvPct/100, kdvD=toplamTutar+kdvT;
      totalCardHtml =
        '<div style="background:linear-gradient(135deg,#1e40af,#7c3aed);border-radius:14px;'
        +'padding:16px 20px;margin-bottom:14px;color:#fff">'
        +'<div style="font-size:13px;font-weight:700;opacity:.85;margin-bottom:6px">'
        +'📊 '+firmaFilter+' — '+ayAdi+' Teyit Özeti</div>'
        +'<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px">'
        +'<div><div style="font-size:11px;opacity:.7">Kayıtlı Gün</div><div style="font-size:22px;font-weight:900">'+toplamGun+'</div></div>'
        +'<div><div style="font-size:11px;opacity:.7">Toplam Kişi</div><div style="font-size:22px;font-weight:900">'+toplamKisi.toLocaleString('tr-TR')+'</div></div>'
        +'<div><div style="font-size:11px;opacity:.7">Net Tutar</div><div style="font-size:20px;font-weight:900">'+_fmt(toplamTutar)+' ₺</div></div>'
        +(kdvPct>0
          ?'<div><div style="font-size:11px;opacity:.7">KDV ('+kdvPct+'%)</div><div style="font-size:18px;font-weight:900">'+_fmt(kdvT)+' ₺</div></div>'
           +'<div><div style="font-size:11px;opacity:.7">KDV&#39;li Toplam</div><div style="font-size:20px;font-weight:900">'+_fmt(kdvD)+' ₺</div></div>'
          :'')
        +'</div></div>';
    }

    /* ── TABLO SATIRLARI ────────────────────────────────────────────── */
    /* musteriMap: kayıtlı firmalar (filtrelenmiş) */
    var musteriMap = {};
    filteredKayitlar.forEach(function(u){
      if(!musteriMap[u.musteri]) musteriMap[u.musteri]=[];
      musteriMap[u.musteri].push(u);
    });

    var tableRows = '';
    var genelTopKisi=0, genelTopTutar=0;

    displayCusts.forEach(function(cust){
      var kayitlar = (musteriMap[cust]||[]).sort(function(a,b){
        return (a.tarih||'').localeCompare(b.tarih||'');
      });
      var altTopKisi  = kayitlar.reduce(function(s,u){ return s+(u.kisi||0); },0);
      var altTopTutar = kayitlar.reduce(function(s,u){ return s+(u.tutar||0); },0);
      genelTopKisi  += altTopKisi;
      genelTopTutar += altTopTutar;

      if(!kayitlar.length){
        /* ── Kayıt yok satırı ────── */
        tableRows +=
          '<tr style="background:#fffbeb">'
          +'<td style="padding:8px 10px;font-weight:700;color:#92400e;border-bottom:1px solid #fde68a">'
          +cust+'<br><span style="font-size:10px;font-weight:400;color:#b45309">⚠️ '+ayAdi+' ayında kayıt yok</span></td>'
          +'<td colspan="5" style="padding:8px 10px;color:#b45309;font-size:12px;border-bottom:1px solid #fde68a;text-align:center">'
          +'Kayıtlı gün: 0 — Günlük Sayılar tablosundan veri girip Kaydet\'e basın</td>'
          +'</tr>';
        return;
      }

      /* ── Kayıtlı satırlar ────── */
      kayitlar.forEach(function(u,i){
        var isFirst=(i===0);
        var fullArr=_getGunluk();
        var realIdx=-1;
        for(var ri=0;ri<fullArr.length;ri++){
          if(fullArr[ri].musteri===u.musteri&&fullArr[ri].tarih===u.tarih){ realIdx=ri; break; }
        }
        tableRows +=
          '<tr id="v94row-'+realIdx+'" style="background:'+(isFirst?'#eff6ff':'#fff')+'">'
          +'<td style="padding:8px 10px;font-weight:'+(isFirst?'700':'400')+';color:'+(isFirst?'#1e40af':'#475569')+';border-bottom:1px solid #e2e8f0">'
          +(isFirst?cust:'')+'</td>'
          +'<td style="padding:8px 10px;text-align:center;border-bottom:1px solid #e2e8f0;color:#64748b">'+u.tarih+'</td>'
          +'<td style="padding:6px 8px;text-align:center;border-bottom:1px solid #e2e8f0">'
          +'<input type="number" min="0" step="1" id="v94kisi-'+realIdx+'" value="'+(u.kisi||0)+'" '
          +'style="width:72px;padding:5px;border:1.5px solid #c7d2fe;border-radius:7px;text-align:center;font-size:13px;font-weight:700;background:#fff" '
          +'onchange="window.v94SaveRow('+realIdx+')" /></td>'
          +'<td style="padding:6px 8px;text-align:right;border-bottom:1px solid #e2e8f0">'
          +'<input type="number" min="0" step="0.01" id="v94fiyat-'+realIdx+'" value="'+(u.fiyat||0)+'" '
          +'style="width:80px;padding:5px;border:1.5px solid #c7d2fe;border-radius:7px;text-align:right;font-size:13px;background:#fff" '
          +'onchange="window.v94SaveRow('+realIdx+')" /></td>'
          +'<td id="v94tutar-'+realIdx+'" style="padding:8px 10px;text-align:right;border-bottom:1px solid #e2e8f0;font-weight:700;color:#166534">'+_fmt(u.tutar||0)+' ₺</td>'
          +'<td style="padding:4px 8px;text-align:center;border-bottom:1px solid #e2e8f0">'
          +'<button onclick="window.v94SaveRow('+realIdx+')" style="padding:5px 10px;border:none;border-radius:7px;background:#2563eb;color:#fff;font-size:11px;font-weight:700;cursor:pointer">💾</button>'
          +'</td>'
          +'</tr>';
      });

      /* Alt toplam */
      tableRows +=
        '<tr style="background:#f0fdf4">'
        +'<td colspan="2" style="padding:7px 10px;font-weight:800;color:#166534;font-size:12px;border-bottom:2px solid #bbf7d0">📊 '+cust+' Alt Toplam</td>'
        +'<td style="padding:7px 10px;text-align:center;font-weight:800;color:#166534;border-bottom:2px solid #bbf7d0">'+altTopKisi+'</td>'
        +'<td style="border-bottom:2px solid #bbf7d0"></td>'
        +'<td style="padding:7px 10px;text-align:right;font-weight:800;color:#166534;border-bottom:2px solid #bbf7d0">'+_fmt(altTopTutar)+' ₺</td>'
        +'<td style="border-bottom:2px solid #bbf7d0"></td>'
        +'</tr>';
    });

    /* Genel toplam */
    tableRows +=
      '<tr style="background:#1e293b">'
      +'<td colspan="2" style="padding:10px;font-weight:900;color:#fff;font-size:13px">🏆 '+(gunFilter?gunFilter.split('-').reverse().join('.')+' — ':ayAdi+' — ')+(firmaFilter||'GENEL')+' TOPLAM</td>'
      +'<td style="padding:10px;text-align:center;font-weight:900;color:#fbbf24;font-size:13px">'+genelTopKisi.toLocaleString('tr-TR')+' kişi</td>'
      +'<td style="padding:10px"></td>'
      +'<td style="padding:10px;text-align:right;font-weight:900;color:#34d399;font-size:14px">'+_fmt(genelTopTutar)+' ₺</td>'
      +'<td style="padding:10px"></td>'
      +'</tr>';

    var tableHtml =
      '<div style="overflow-x:auto">'
      +'<table style="width:100%;font-size:13px;border-collapse:collapse;border-radius:10px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,.08)">'
      +'<thead><tr style="background:#eef3ff">'
      +'<th style="padding:10px;text-align:left;border-bottom:2px solid #c7d2fe">Müşteri</th>'
      +'<th style="padding:10px;text-align:center;border-bottom:2px solid #c7d2fe">Tarih</th>'
      +'<th style="padding:10px;text-align:center;border-bottom:2px solid #c7d2fe">Kişi Sayısı</th>'
      +'<th style="padding:10px;text-align:right;border-bottom:2px solid #c7d2fe">Fiyat (₺/kişi)</th>'
      +'<th style="padding:10px;text-align:right;border-bottom:2px solid #c7d2fe">Gün Toplamı (₺)</th>'
      +'<th style="padding:10px;text-align:center;border-bottom:2px solid #c7d2fe">Kaydet</th>'
      +'</tr></thead>'
      +'<tbody>'+tableRows+'</tbody>'
      +'</table></div>';

    div.innerHTML = controlHtml + totalCardHtml + cardsHtml + tableHtml;
    console.log('✅ v95 özet render — '+displayCusts.length+' müşteri ('+ayKayitlar.length+' kayıt)');
  };

  /* ── Firma filter change ──────────────────────────────────────────────── */
  window.v94OnFirmaChange = function(val){
    window._v94FirmaFilter = val||null;
    window._v94ShowTotal   = false;
    window.v94RenderAylikRapor();
  };

  /* ── Gün filter change ───────────────────────────────────────────────── */
  window.v94OnGunChange = function(val){
    window._v94GunFilter = val||null;
    window.v94RenderAylikRapor();
  };

  /* ── Toplam toggle ──────────────────────────────────────────────────── */
  window.v94ToggleTotal = function(){
    window._v94ShowTotal = !window._v94ShowTotal;
    window.v94RenderAylikRapor();
  };

  /* ── Satır kaydet ───────────────────────────────────────────────────── */
  window.v94SaveRow = function(realIdx){
    var kisiEl  = document.getElementById('v94kisi-'+realIdx);
    var fiyatEl = document.getElementById('v94fiyat-'+realIdx);
    var tutarEl = document.getElementById('v94tutar-'+realIdx);
    if(!kisiEl||!fiyatEl) return;
    var yeniKisi=parseInt(kisiEl.value)||0;
    var yeniFiyat=parseFloat(fiyatEl.value)||0;
    var yeniTutar=yeniKisi*yeniFiyat;
    var gunlukArr=_getGunluk();
    if(realIdx<0||realIdx>=gunlukArr.length){ _toast('❌ Kayıt bulunamadı.'); return; }
    var eski=gunlukArr[realIdx];
    gunlukArr[realIdx].kisi=yeniKisi;
    gunlukArr[realIdx].fiyat=yeniFiyat;
    gunlukArr[realIdx].tutar=yeniTutar;
    _saveGunluk(gunlukArr);
    var gelirArr=_getGelirler();
    var bulundu=false;
    for(var gi=0;gi<gelirArr.length;gi++){
      var g=gelirArr[gi];
      if(g.musteri===eski.musteri&&g.tarih===eski.tarih&&g.aciklama&&g.aciklama.startsWith('Günlük üretim')){
        gelirArr[gi].tutar=yeniTutar; gelirArr[gi].kisi=yeniKisi;
        gelirArr[gi].aciklama='Günlük üretim — '+yeniKisi+' kişi × '+yeniFiyat+' ₺';
        bulundu=true; break;
      }
    }
    if(!bulundu) gelirArr.push({musteri:eski.musteri,tarih:eski.tarih,tutar:yeniTutar,kisi:yeniKisi,
      fatura:'',aciklama:'Günlük üretim — '+yeniKisi+' kişi × '+yeniFiyat+' ₺'});
    _saveGelirler(gelirArr);
    if(tutarEl) tutarEl.textContent=_fmt(yeniTutar)+' ₺';
    var rowEl=document.getElementById('v94row-'+realIdx);
    if(rowEl) rowEl.style.background='#f0fdf4';
    if(typeof window.renderGelirler==='function') try{ window.renderGelirler(); }catch(e){}
    if(typeof window.refreshFinStats==='function') try{ window.refreshFinStats(); }catch(e){}
    _toast('✅ '+eski.musteri+' ('+eski.tarih+'): '+yeniKisi+' kişi × '+_fmt(yeniFiyat)+' ₺ = '+_fmt(yeniTutar)+' ₺');
    setTimeout(function(){ window.v94RenderAylikRapor(); },800);
  };

  /* ── Excel İndir (Üretim Özeti) ────────────────────────────────────── */
  window.v94ExcelIndir = function(){
    var gunlukArr = _getGunluk();
    var ayEl = document.getElementById('v77AySecici');
    var tarihEl = document.getElementById('gs-tarih');
    var buAy;
    if(ayEl && ayEl.value) buAy = ayEl.value;
    else if(tarihEl && tarihEl.value) buAy = tarihEl.value.slice(0,7);
    else buAy = _today().slice(0,7);

    var firmaFilter = window._v94FirmaFilter || null;
    var gunFilter = window._v94GunFilter || null;

    // Verileri filtrele
    var kayitlar = gunlukArr.filter(function(u){
      return (u.tarih||'').startsWith(buAy) && u.kisi > 0;
    });
    if(firmaFilter) kayitlar = kayitlar.filter(function(u){ return u.musteri===firmaFilter; });
    if(gunFilter) kayitlar = kayitlar.filter(function(u){ return u.tarih===gunFilter; });
    kayitlar.sort(function(a,b){
      var c = (a.musteri||'').localeCompare(b.musteri||'','tr');
      return c !== 0 ? c : (a.tarih||'').localeCompare(b.tarih||'');
    });

    if(!kayitlar.length){
      _toast('İndirilecek kayıt bulunamadı.');
      return;
    }

    // Excel HTML oluştur
    var rows = '<tr><th>Müşteri</th><th>Tarih</th><th>Kişi</th><th>Fiyat (₺)</th><th>Tutar (₺)</th></tr>';
    var topKisi = 0, topTutar = 0;
    kayitlar.forEach(function(u){
      topKisi += u.kisi||0;
      topTutar += u.tutar||0;
      rows += '<tr>'
        +'<td>'+u.musteri+'</td>'
        +'<td>'+u.tarih+'</td>'
        +'<td style="text-align:center">'+(u.kisi||0)+'</td>'
        +'<td style="text-align:right">'+((u.fiyat||0).toFixed(2))+'</td>'
        +'<td style="text-align:right">'+((u.tutar||0).toFixed(2))+'</td>'
        +'</tr>';
    });
    rows += '<tr style="font-weight:bold;background:#f0f0f0">'
      +'<td colspan="2">TOPLAM</td>'
      +'<td style="text-align:center">'+topKisi+'</td>'
      +'<td></td>'
      +'<td style="text-align:right">'+topTutar.toFixed(2)+'</td>'
      +'</tr>';

    var excelHtml = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">'
      +'<head><meta charset="utf-8"/></head><body>'
      +'<table border="1" cellpadding="4" cellspacing="0" style="font-family:Arial;font-size:12px">'
      +rows+'</table></body></html>';

    var blob = new Blob([excelHtml], {type:'application/vnd.ms-excel;charset=utf-8'});
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    var dosyaAdi = 'UYSA_Uretim_'+buAy;
    if(firmaFilter) dosyaAdi += '_'+firmaFilter.replace(/\s+/g,'_');
    if(gunFilter) dosyaAdi += '_'+gunFilter;
    a.href = url;
    a.download = dosyaAdi + '.xls';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
    _toast('📥 Excel indirildi: '+kayitlar.length+' kayıt');
  };

  /* ── Hook v75RenderAylikRapor → v94 ───────────────────────────────── */
  function _hookRender(){
    window.v75RenderAylikRapor = function(){ window.v94RenderAylikRapor(); };
    console.log('✅ v95: v75RenderAylikRapor hooked → v94RenderAylikRapor');
  }

  /* ── INIT ──────────────────────────────────────────────────────────── */
  function _init(){
    console.log('✅ UYSA v95 patch loading...');
    _hookRender();
    var pane=document.getElementById('aylikPane-uretim');
    if(pane&&pane.style.display!=='none') setTimeout(window.v94RenderAylikRapor,300);
    console.log('✅ UYSA v95 patch loaded — tüm müşteriler özette gösterilir.');
  }
  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',_init);
  } else { _init(); }

})();
