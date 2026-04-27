/* ══════════════════════════════════════════════════════════════════
   UYSA v78 PATCH — Puantaj Modülü
   • Aylık devam çizelgesi (her gün durum seçimi)
   • İzin türleri + onay durumu
   • Maaş hakediş otomatik hesabı
   • İzin bakiyesi takibi
   • Toplu bordro özeti
   ══════════════════════════════════════════════════════════════════ */
(function(){
  'use strict';

  var _ls={
    get:function(k,d){try{var v=localStorage.getItem(k);return v===null?d:JSON.parse(v);}catch(e){return d;}},
    set:function(k,v){try{localStorage.setItem(k,JSON.stringify(v));}catch(e){}}
  };
  function _today(){var d=new Date();return d.getFullYear()+'-'+(''+(d.getMonth()+1)).padStart(2,'0')+'-'+(''+d.getDate()).padStart(2,'0');}
  function _thisMonth(){var d=new Date();return d.getFullYear()+'-'+(''+(d.getMonth()+1)).padStart(2,'0');}
  function _fmt(n){return Number(n||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});}
  function _toast(msg){if(typeof window.toast==='function'){window.toast(msg);return;}var t=document.createElement('div');t.textContent=msg;t.style.cssText='position:fixed;bottom:24px;right:24px;background:#1e293b;color:#fff;padding:12px 20px;border-radius:10px;font-size:13px;z-index:99999;max-width:360px;box-shadow:0 4px 20px rgba(0,0,0,.35)';document.body.appendChild(t);setTimeout(function(){t.remove();},3500);}

  function _getPersoneller(){return _ls.get('uysa_personeller',[]);}

  // ── Durum tanımları ────────────────────────────────────────────
  var DURUMLAR = {
    'iste'    : {label:'İşte',    kisa:'İ',  renk:'durum-iste',    maas:1,    icon:'✅'},
    'ucretli' : {label:'Ü.İzin', kisa:'Ü',  renk:'durum-ucretli', maas:1,    icon:'🟢'},
    'ucretsiz': {label:'Üc.İzn', kisa:'UI', renk:'durum-ucretsiz',maas:0,    icon:'🔴'},
    'raporlu' : {label:'Rapor',  kisa:'R',  renk:'durum-raporlu', maas:1,    icon:'🔵'},
    'tatil'   : {label:'Tatil',  kisa:'T',  renk:'durum-tatil',   maas:1,    icon:'🏛️'},
    'devsz'   : {label:'Dev.',   kisa:'D',  renk:'durum-devsz',   maas:0,    icon:'❌'},
    'yari'    : {label:'Yarım',  kisa:'Y',  renk:'durum-yari',    maas:0.5,  icon:'🌗'},
    'hb'      : {label:'—',      kisa:'',   renk:'durum-bos',     maas:1,    icon:''}
  };

  // ── localStorage anahtarları ───────────────────────────────────
  function _puantajKey(ad, ay){ return 'uysa_puantaj_'+ay+'_'+ad; }
  function _getPuantaj(ad, ay){ return _ls.get(_puantajKey(ad,ay),{}); }
  function _savePuantaj(ad, ay, data){ _ls.set(_puantajKey(ad,ay), data); }

  function _getIzinler(){ return _ls.get('uysa_izinler',[]); }
  function _saveIzinler(arr){ _ls.set('uysa_izinler',arr); }

  /* ────────────────────────────────────────────────────────────────
     1. IK TAB SWITCHING
     ──────────────────────────────────────────────────────────────── */
  window.switchIkTab = function(tab){
    ['personel','puantaj','izin','bordro'].forEach(function(t){
      var pane = document.getElementById('ikPane-'+t);
      var btn  = document.getElementById('ikTab-'+t);
      if(pane) pane.style.display = t===tab ? '' : 'none';
      if(btn)  btn.className = t===tab ? 'btn primary' : 'btn';
    });
    if(tab==='puantaj'){
      _syncPuantajPersonelSel();
      var ayEl=document.getElementById('puantaj-ay');
      if(ayEl&&!ayEl.value) ayEl.value=_thisMonth();
      puantajRender();
    }
    if(tab==='izin'){
      _syncIzinPersonelSels();
      renderIzinListesi();
    }
    if(tab==='bordro'){
      _syncBordroPersonelSels();
      var ayEl2=document.getElementById('bordro2-ay');
      if(ayEl2&&!ayEl2.value) ayEl2.value=_thisMonth();
      var ayEl3=document.getElementById('topluBordro-ay');
      if(ayEl3&&!ayEl3.value) ayEl3.value=_thisMonth();
      renderTopluBordro();
    }
  };

  function _syncPuantajPersonelSel(){
    var sel=document.getElementById('puantaj-personel');
    if(!sel) return;
    var pers=_getPersoneller();
    var prev=sel.value;
    sel.innerHTML='<option value="">— Personel seçin —</option>'
      +pers.map(function(p){var n=p.ad+' '+p.soyad;return '<option value="'+n+'">'+n+'</option>';}).join('');
    if(prev) sel.value=prev;
  }
  function _syncIzinPersonelSels(){
    var pers=_getPersoneller();
    var opts='<option value="">Seçiniz...</option>'+pers.map(function(p){var n=p.ad+' '+p.soyad;return '<option value="'+n+'">'+n+'</option>';}).join('');
    ['izin-personel','izinBakiye-personel','izinFiltre-personel'].forEach(function(id){
      var el=document.getElementById(id);
      if(!el) return;
      var prev=el.value;
      el.innerHTML = id==='izinFiltre-personel'
        ? '<option value="">Tüm personel</option>'+pers.map(function(p){var n=p.ad+' '+p.soyad;return '<option value="'+n+'">'+n+'</option>';}).join('')
        : opts;
      if(prev) el.value=prev;
    });
  }
  function _syncBordroPersonelSels(){
    var pers=_getPersoneller();
    var opts='<option value="">Seçiniz...</option>'+pers.map(function(p){var n=p.ad+' '+p.soyad;return '<option value="'+n+'">'+n+'</option>';}).join('');
    ['bordro2-personel'].forEach(function(id){
      var el=document.getElementById(id); if(!el) return;
      var prev=el.value; el.innerHTML=opts; if(prev) el.value=prev;
    });
  }

  /* ────────────────────────────────────────────────────────────────
     2. PUANTAJ ÇİZELGESİ
     ──────────────────────────────────────────────────────────────── */
  window.puantajRender = function(){
    var personelAd = document.getElementById('puantaj-personel')?.value||'';
    var ay         = document.getElementById('puantaj-ay')?.value||_thisMonth();
    var div        = document.getElementById('puantajChizelge');
    var ozetPnl    = document.getElementById('puantajOzetPanel');
    if(!div) return;

    if(!personelAd){
      div.innerHTML='<div style="color:var(--muted);padding:16px;text-align:center">Personel seçin.</div>';
      if(ozetPnl) ozetPnl.style.display='none';
      return;
    }

    var parts  = ay.split('-');
    var yil    = parseInt(parts[0]);
    var ay_num = parseInt(parts[1]);
    var gunSay = new Date(yil, ay_num, 0).getDate();
    var ayAdlari=['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
    var ayAdi  = ayAdlari[ay_num-1];
    var kayit  = _getPuantaj(personelAd, ay);

    // Personel bilgisi
    var per = _getPersoneller().find(function(p){ return p.ad+' '+p.soyad===personelAd; });
    var maas = per ? (per.maas||0) : 0;

    // Gün başlıkları
    var gunAdlari=['Paz','Pzt','Sal','Çar','Per','Cum','Cmt'];

    var html='<div class="no-print" style="margin-bottom:10px">'
      +'<b>'+personelAd+'</b> — <span style="color:#2563eb">'+ayAdi+' '+yil+'</span>'
      +'<span style="font-size:11px;color:#64748b;margin-left:8px">Net Maaş: <b>'+_fmt(maas)+' ₺</b></span>'
      +'</div>';

    // Renk açıklamaları
    html+='<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;font-size:11px">';
    Object.keys(DURUMLAR).filter(function(k){return k!=='hb';}).forEach(function(k){
      var d=DURUMLAR[k];
      html+='<span class="puantaj-cell '+d.renk+'" style="padding:3px 8px">'+d.icon+' '+d.label+'</span>';
    });
    html+='</div>';

    // Çizelge tablosu
    html+='<div style="overflow-x:auto"><table style="border-collapse:collapse;font-size:12px;width:100%">';
    html+='<thead><tr style="background:#eef3ff">'
      +'<th style="padding:8px 10px;text-align:left;border-bottom:2px solid #c7d2fe;white-space:nowrap">Personel</th>';

    for(var g=1; g<=gunSay; g++){
      var tarih_obj = new Date(yil, ay_num-1, g);
      var hafta_gun = tarih_obj.getDay(); // 0=Paz
      var isWeekend = (hafta_gun===0||hafta_gun===6);
      var gunAdi    = gunAdlari[hafta_gun];
      html+='<th style="padding:4px 2px;text-align:center;border-bottom:2px solid #c7d2fe;min-width:38px;color:'
        +(isWeekend?'#dc2626':'#1e293b')+'">'
        +'<div>'+g+'</div>'
        +'<div style="font-size:9px;font-weight:400">'+gunAdi+'</div>'
        +'</th>';
    }
    html+='<th style="padding:8px;text-align:center;border-bottom:2px solid #c7d2fe;min-width:50px">İşte</th>'
        +'<th style="padding:8px;text-align:center;border-bottom:2px solid #c7d2fe;min-width:50px">İzin</th>'
        +'<th style="padding:8px;text-align:center;border-bottom:2px solid #c7d2fe;min-width:50px">Dev.</th>'
        +'<th style="padding:8px;text-align:center;border-bottom:2px solid #c7d2fe;min-width:70px">Hakediş</th>'
        +'</tr></thead><tbody>';

    // Personel satırı
    html+='<tr style="background:#fff">'
      +'<td style="padding:8px 10px;font-weight:700;white-space:nowrap;border-right:1px solid #e2e8f0">'+personelAd+'</td>';

    var isteSay=0, izinSay=0, devszSay=0, maasKat=0, ucretsizSay2=0, yariSay2=0;

    for(var g2=1; g2<=gunSay; g2++){
      var tarih_obj2 = new Date(yil, ay_num-1, g2);
      var hafta_gun2 = tarih_obj2.getDay();
      var isWeekend2 = (hafta_gun2===0||hafta_gun2===6);
      var tarihStr   = ay+'-'+((''+g2).padStart(2,'0'));
      var mevcut     = kayit[tarihStr] || (isWeekend2 ? 'tatil' : 'hb');
      var d          = DURUMLAR[mevcut] || DURUMLAR['hb'];

      html+='<td style="padding:2px;border:1px solid #e2e8f0;background:'+(isWeekend2?'#fff7ed':'#fff')+'">'
        +'<select class="puantaj-select '+d.renk+'" data-tarih="'+tarihStr+'" onchange="puantajGunDegis(this)">';

      Object.keys(DURUMLAR).filter(function(k){return k!=='hb';}).forEach(function(k){
        var opt=DURUMLAR[k];
        html+='<option value="'+k+'"'+(mevcut===k?' selected':'')+'>'+opt.kisa||opt.label+'</option>';
      });
      html+='<option value="hb"'+(mevcut==='hb'?' selected':'')+'> </option>';
      html+='</select></td>';

      // İstatistik
      if(mevcut==='iste') isteSay++;
      else if(mevcut==='ucretli'||mevcut==='raporlu'||mevcut==='tatil'||mevcut==='dogum'||mevcut==='evlilik'||mevcut==='olum') izinSay++;
      else if(mevcut==='devsz') devszSay++;
      // FIX-V16: maas/30 formula — count kesilecek days (weekdays only)
      if(!isWeekend2){
        maasKat += (DURUMLAR[mevcut]||{maas:1}).maas;
        if(mevcut==='ucretsiz') ucretsizSay2++;
        else if(mevcut==='yari') yariSay2++;
      }
    }

    // İş günü sayısı (hafta sonu hariç)
    var isGunSay=0;
    for(var g3=1; g3<=gunSay; g3++){
      var hg=new Date(yil,ay_num-1,g3).getDay();
      if(hg!==0&&hg!==6) isGunSay++;
    }

    // FIX-V16: maaş/30 hesaplama — her kesilen gün = maaş/30
    var kesilecekGun16 = ucretsizSay2 + devszSay + yariSay2*0.5;
    var hakedis = Math.max(0, maas - kesilecekGun16*(maas/30));

    html+='<td style="padding:6px;text-align:center;font-weight:700;color:#16a34a;border-left:2px solid #e2e8f0">'+isteSay+'</td>'
        +'<td style="padding:6px;text-align:center;font-weight:700;color:#2563eb">'+izinSay+'</td>'
        +'<td style="padding:6px;text-align:center;font-weight:700;color:#dc2626">'+devszSay+'</td>'
        +'<td style="padding:6px;text-align:right;font-weight:800;color:#166534;font-size:13px">'+_fmt(hakedis)+' ₺</td>'
        +'</tr>';

    html+='</tbody></table></div>';
    div.innerHTML=html;

    // Özet panel
    _renderPuantajOzet(personelAd, ay, gunSay, isGunSay, isteSay, izinSay, devszSay, maasKat, maas, hakedis, kayit, yil, ay_num);
  };

  window.puantajGunDegis = function(sel){
    var tarih = sel.dataset.tarih;
    var ayEl  = document.getElementById('puantaj-ay');
    var perEl = document.getElementById('puantaj-personel');
    var ay    = ayEl ? ayEl.value : _thisMonth();
    var per   = perEl ? perEl.value : '';
    if(!per||!tarih) return;
    var d = DURUMLAR[sel.value]||DURUMLAR['hb'];
    sel.className = 'puantaj-select '+d.renk;
    // Otomatik kaydet
    var kayit = _getPuantaj(per, ay);
    kayit[tarih] = sel.value;
    _savePuantaj(per, ay, kayit);
    // Özet güncelle
    setTimeout(puantajRender, 50);
  };

  window.puantajKaydet = function(){
    var per = document.getElementById('puantaj-personel')?.value||'';
    var ay  = document.getElementById('puantaj-ay')?.value||_thisMonth();
    if(!per){ _toast('Personel seçin.'); return; }
    // Tüm select'leri oku
    var kayit={};
    document.querySelectorAll('.puantaj-select[data-tarih]').forEach(function(sel){
      if(sel.value&&sel.value!=='hb') kayit[sel.dataset.tarih]=sel.value;
    });
    _savePuantaj(per, ay, kayit);
    _toast('✅ Puantaj kaydedildi: '+per+' — '+ay);
    puantajRender();
  };

  window.puantajYazdir = function(){
    window.print();
  };

  function _renderPuantajOzet(per, ay, gunSay, isGunSay, isteSay, izinSay, devszSay, maasKat, maas, hakedis, kayit, yil, ay_num){
    var div=document.getElementById('puantajOzetDiv');
    var pnl=document.getElementById('puantajOzetPanel');
    if(!div||!pnl) return;
    pnl.style.display='';

    var kesinti = maas - hakedis;
    var ucretliIzin=0,ucretsizIzin=0,raporlu=0,tatil=0,yari=0;
    Object.values(kayit).forEach(function(v){
      if(v==='ucretli') ucretliIzin++;
      else if(v==='ucretsiz') ucretsizIzin++;
      else if(v==='raporlu') raporlu++;
      else if(v==='tatil') tatil++;
      else if(v==='yari') yari++;
    });

    div.innerHTML='<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:14px">'
      +'<div style="background:#f0fdf4;border-radius:10px;padding:12px;text-align:center"><div style="font-size:20px">✅</div><div style="font-weight:800;font-size:22px;color:#16a34a">'+isteSay+'</div><div style="font-size:11px;color:#64748b">İşte Geçen Gün</div></div>'
      +'<div style="background:#dbeafe;border-radius:10px;padding:12px;text-align:center"><div style="font-size:20px">🟢</div><div style="font-weight:800;font-size:22px;color:#1d4ed8">'+ucretliIzin+'</div><div style="font-size:11px;color:#64748b">Ücretli İzin</div></div>'
      +'<div style="background:#fee2e2;border-radius:10px;padding:12px;text-align:center"><div style="font-size:20px">🔴</div><div style="font-weight:800;font-size:22px;color:#dc2626">'+ucretsizIzin+'</div><div style="font-size:11px;color:#64748b">Ücretsiz İzin</div></div>'
      +'<div style="background:#e0e7ff;border-radius:10px;padding:12px;text-align:center"><div style="font-size:20px">🔵</div><div style="font-weight:800;font-size:22px;color:#4338ca">'+raporlu+'</div><div style="font-size:11px;color:#64748b">Raporlu</div></div>'
      +'<div style="background:#fef9c3;border-radius:10px;padding:12px;text-align:center"><div style="font-size:20px">🏛️</div><div style="font-weight:800;font-size:22px;color:#a16207">'+tatil+'</div><div style="font-size:11px;color:#64748b">Resmi Tatil</div></div>'
      +'<div style="background:#fecaca;border-radius:10px;padding:12px;text-align:center"><div style="font-size:20px">❌</div><div style="font-weight:800;font-size:22px;color:#dc2626">'+devszSay+'</div><div style="font-size:11px;color:#64748b">Devamsızlık</div></div>'
      +'</div>'
      +'<table style="width:100%;border-collapse:collapse;font-size:14px">'
      +'<tr style="background:#f8fafc"><td style="padding:10px 14px">💼 Toplam İş Günü (ay)</td><td style="padding:10px 14px;font-weight:700;text-align:right">'+isGunSay+' gün</td></tr>'
      +'<tr><td style="padding:10px 14px">💰 Net Maaş (tam ay)</td><td style="padding:10px 14px;font-weight:700;text-align:right">'+_fmt(maas)+' ₺</td></tr>'
      +'<tr style="background:#f8fafc"><td style="padding:10px 14px">📉 Kesilecek Gün</td><td style="padding:10px 14px;font-weight:700;text-align:right;color:#dc2626">'+(ucretsizIzin+devszSay+yari*0.5)+' gün</td></tr>'
      +'<tr><td style="padding:10px 14px">📉 Günlük Ücret (maaş/30)</td><td style="padding:10px 14px;font-weight:700;text-align:right;color:#64748b">'+_fmt(maas/30)+' ₺/gün</td></tr>'
      +'<tr style="background:#f8fafc"><td style="padding:10px 14px">✂️ Toplam Kesinti</td><td style="padding:10px 14px;font-weight:700;text-align:right;color:#dc2626">'+_fmt(kesinti)+' ₺</td></tr>'
      +'<tr style="background:#1e293b"><td style="padding:12px 14px;color:#fff;font-weight:900;font-size:15px">💳 ÖDENECEK NET MAAŞ</td>'
      +'<td style="padding:12px 14px;color:#34d399;font-weight:900;font-size:18px;text-align:right">'+_fmt(hakedis)+' ₺</td></tr>'
      +'</table>';
  }

  /* ────────────────────────────────────────────────────────────────
     3. İZİN YÖNETİMİ
     ──────────────────────────────────────────────────────────────── */
  var IZ_IKONLAR={ucretli:'🟢',ucretsiz:'🔴',raporlu:'🔵',dogum:'🩷',evlilik:'💍',olum:'🖤',resmitatil:'🏛️',yari:'🌗'};
  var IZ_ADLAR={ucretli:'Ücretli İzin',ucretsiz:'Ücretsiz İzin',raporlu:'Raporlu (SGK)',dogum:'Doğum İzni',evlilik:'Evlilik İzni',olum:'Ölüm İzni',resmitatil:'Resmi Tatil',yari:'Yarım Gün'};
  var ONAY_IKONLAR={bekliyor:'⏳',onaylandi:'✅',reddedildi:'❌'};

  function _izinGunSay(bas, bit){
    try{
      var d1=new Date(bas), d2=new Date(bit);
      return Math.max(1, Math.round((d2-d1)/(1000*60*60*24))+1);
    }catch(e){return 1;}
  }

  window.renderIzinListesi = function(){
    var div=document.getElementById('izinListDiv');
    if(!div) return;
    var izinler=_getIzinler();
    var filtrePer=document.getElementById('izinFiltre-personel')?.value||'';
    var filtreTur=document.getElementById('izinFiltre-tur')?.value||'';
    var filtreOnay=document.getElementById('izinFiltre-onay')?.value||'';

    var liste=izinler.filter(function(iz){
      return (!filtrePer||iz.personel===filtrePer)
          && (!filtreTur||iz.tur===filtreTur)
          && (!filtreOnay||iz.onay===filtreOnay);
    }).sort(function(a,b){return (b.bas||'').localeCompare(a.bas||'');});

    if(!liste.length){
      div.innerHTML='<div style="color:var(--muted);padding:16px;text-align:center">Kayıt bulunamadı.</div>';
      return;
    }

    div.innerHTML=liste.map(function(iz,_i){
      var realIdx=izinler.indexOf(iz);
      var gun=_izinGunSay(iz.bas,iz.bit);
      return '<div style="border:1px solid #e2e8f0;border-radius:10px;padding:10px 14px;margin-bottom:8px;background:#fff">'
        +'<div style="display:flex;justify-content:space-between;align-items:start;gap:8px">'
        +'<div style="flex:1">'
        +'<div style="font-weight:700;font-size:13px">'+(IZ_IKONLAR[iz.tur]||'📋')+' '+iz.personel
        +'<span style="font-weight:400;font-size:11px;color:#64748b;margin-left:8px">'+( IZ_ADLAR[iz.tur]||iz.tur)+'</span>'
        +'</div>'
        +'<div style="font-size:12px;color:#64748b;margin-top:2px">'
        +'📅 '+( iz.bas||'?')+' → '+(iz.bit||'?')+' <b>('+gun+' gün)</b>'
        +(iz.aciklama?'<br>📝 '+iz.aciklama:'')
        +'</div>'
        +'</div>'
        +'<div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px">'
        +'<span style="font-size:12px;font-weight:700">'+(ONAY_IKONLAR[iz.onay]||'⏳')+' '+iz.onay+'</span>'
        +'<div style="display:flex;gap:4px">'
        +(iz.onay==='bekliyor'
          ?'<button onclick="izinOnayla('+realIdx+')" style="font-size:11px;padding:3px 8px;border:1px solid #86efac;background:#f0fdf4;color:#16a34a;border-radius:6px;cursor:pointer">✅ Onayla</button>'
          +'<button onclick="izinReddet('+realIdx+')" style="font-size:11px;padding:3px 8px;border:1px solid #fca5a5;background:#fff1f2;color:#dc2626;border-radius:6px;cursor:pointer">❌ Reddet</button>'
          :'')
        +'<button onclick="izinSil('+realIdx+')" style="font-size:11px;padding:3px 8px;border:none;background:none;color:#dc2626;cursor:pointer">🗑️</button>'
        +'</div></div></div></div>';
    }).join('');
  };

  window.izinOnayla = function(i){
    var arr=_getIzinler(); if(!arr[i]) return;
    arr[i].onay='onaylandi';
    _saveIzinler(arr);
    // Ücretsiz izin ise puantaja otomatik yaz
    var izin = arr[i];
    // İZİN FIX: izin.tip yerine izin.tur kullan (kayıt tur: ile yapılıyor)
    var _izinTur = izin.tur || izin.tip || '';
    if(_izinTur === 'ucretsiz' || _izinTur === 'Ücretsiz İzin') {
      _puantajIzinYaz(izin, 'ucretsiz');
    } else if(_izinTur === 'ucretli' || _izinTur === 'Ücretli İzin' || _izinTur === 'Yıllık İzin') {
      _puantajIzinYaz(izin, 'ucretli');
    } else if(_izinTur === 'raporlu' || _izinTur === 'Hastalık Raporu') {
      _puantajIzinYaz(izin, 'raporlu');
    } else if(_izinTur === 'resmitatil') {
      _puantajIzinYaz(izin, 'tatil');
    }
    renderIzinListesi();
    _toast('✅ İzin onaylandı — puantaj güncellendi.');
  };

  // İzin onayında puantaj günlerini yaz
  function _puantajIzinYaz(izin, durum) {
    // FIX: izin.baslangic → izin.bas, izin.gun → bit-bas hesapla
    var _bas = izin.baslangic || izin.bas;
    var _bit = izin.bit || _bas;
    if(!_bas || !izin.personel) return;
    try {
      var bas = new Date(_bas);
      var bit2 = new Date(_bit);
      var gunSay = Math.max(1, Math.round((bit2-bas)/(1000*60*60*24))+1);
      var ad = izin.personel.replace(/[^a-zA-Z0-9çÇğĞıİöÖşŞüÜ]/g,'_');
      for(var g=0; g<gunSay; g++) {
        var d = new Date(bas);
        d.setDate(d.getDate() + g);
        var ay = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0'); // FIX-A: hyphen separator to match _puantajKey
        var key = 'uysa_puantaj_' + ay + '_' + ad;
        var tarihStr = d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
        var kayit = {};
        try { kayit = JSON.parse(localStorage.getItem(key)||'{}'); } catch(e){}
        kayit[tarihStr] = durum;
        localStorage.setItem(key, JSON.stringify(kayit));
      }
      console.log('[UYSA-Puantaj] İzin puantaja yazıldı:', izin.personel, gunSay, 'gün →', durum);
    } catch(e) { console.warn('[UYSA-Puantaj] Yazma hatası:', e); }
  };
  window.izinReddet = function(i){
    var arr=_getIzinler(); if(!arr[i]) return;
    arr[i].onay='reddedildi'; _saveIzinler(arr);
    renderIzinListesi(); _toast('❌ İzin reddedildi.');
  };
  window.izinSil = function(i){
    var arr=_getIzinler(); if(!arr[i]) return;
    if(!confirm('İzin kaydı silinsin mi?')) return;
    arr.splice(i,1); _saveIzinler(arr);
    renderIzinListesi(); _toast('🗑️ Silindi.');
  };

  window.renderIzinBakiye = function(){
    var div=document.getElementById('izinBakiyeDiv');
    var perEl=document.getElementById('izinBakiye-personel');
    if(!div||!perEl||!perEl.value){ if(div) div.innerHTML=''; return; }
    var per=perEl.value;
    var yil=new Date().getFullYear();
    var izinler=_getIzinler().filter(function(iz){
      return iz.personel===per && iz.onay==='onaylandi' && (iz.bas||'').startsWith(''+yil);
    });
    var per_obj=_getPersoneller().find(function(p){ return p.ad+' '+p.soyad===per; });
    var girisYil=per_obj&&per_obj.giris ? parseInt(per_obj.giris.split('-')[0]) : yil;
    var calismaYil=Math.max(1, yil-girisYil+1);
    var hakEdisGun=Math.min(14+calismaYil,26); // 14 gün başlangıç, her yıl +1, max 26

    var kullanilanUcretli=izinler.filter(function(iz){return iz.tur==='ucretli';})
      .reduce(function(s,iz){return s+_izinGunSay(iz.bas,iz.bit);},0);
    var kalan=Math.max(0,hakEdisGun-kullanilanUcretli);

    div.innerHTML='<table style="width:100%;font-size:12px;border-collapse:collapse">'
      +'<tr style="background:#eff6ff"><td style="padding:8px">📅 Çalışma süresi</td><td style="padding:8px;font-weight:700;text-align:right">'+calismaYil+' yıl</td></tr>'
      +'<tr><td style="padding:8px">🎯 Yıllık izin hakkı</td><td style="padding:8px;font-weight:700;text-align:right">'+hakEdisGun+' gün</td></tr>'
      +'<tr style="background:#eff6ff"><td style="padding:8px">✅ Kullanılan ('+yil+')</td><td style="padding:8px;font-weight:700;text-align:right;color:#16a34a">'+kullanilanUcretli+' gün</td></tr>'
      +'<tr style="background:'+(kalan>5?'#f0fdf4':'#fff1f2')+'"><td style="padding:8px;font-weight:800">💼 Kalan Bakiye</td><td style="padding:8px;font-weight:900;text-align:right;font-size:14px;color:'+(kalan>5?'#16a34a':'#dc2626')+'">'+kalan+' gün</td></tr>'
      +'</table>';
  };

  /* ────────────────────────────────────────────────────────────────
     4. BORDRO (gelişmiş)
     ──────────────────────────────────────────────────────────────── */
  window.bordro2PersonelSec = function(){
    var sel=document.getElementById('bordro2-personel');
    var per=_getPersoneller().find(function(p){ return p.ad+' '+p.soyad===sel?.value; });
    if(per){
      var el=document.getElementById('bordro2-maas'); if(el) el.value=per.maas;
      bordro2Hesapla();
    }
  };

  window.bordro2Hesapla = function(){
    var per  = document.getElementById('bordro2-personel')?.value||'';
    var ay   = document.getElementById('bordro2-ay')?.value||_thisMonth();
    var maas = parseFloat(document.getElementById('bordro2-maas')?.value)||0;
    var fm   = parseFloat(document.getElementById('bordro2-fm')?.value)||0;
    var prim = parseFloat(document.getElementById('bordro2-prim')?.value)||0;
    var div  = document.getElementById('bordro2SonucDiv');
    if(!div) return;
    if(!maas){ div.style.display='none'; return; }

    var parts=ay.split('-'); var yil=parseInt(parts[0]); var ay_num=parseInt(parts[1]);
    var gunSay=new Date(yil,ay_num,0).getDate();
    var isGunSay=0;
    for(var g=1;g<=gunSay;g++){ var hg=new Date(yil,ay_num-1,g).getDay(); if(hg!==0&&hg!==6) isGunSay++; }

    // Puantaj'dan devamsızlık ve ücretsiz izin gün sayısı
    var kayit=_getPuantaj(per,ay);
    var kesilecek=0, ucretsizGun=0, devszGun=0, yariGun=0;
    Object.values(kayit).forEach(function(v){
      if(v==='ucretsiz'){kesilecek+=1;ucretsizGun++;}
      else if(v==='devsz'){kesilecek+=1;devszGun++;}
      else if(v==='yari'){kesilecek+=0.5;yariGun++;}
    });

    var gunlukUcret = maas/30; // FIX-V16: maaş/30
    var kesilenTutar= gunlukUcret*kesilecek;
    var fmUcret     = (maas/240)*1.5*fm; // 1.5 katsayılı FM
    var netOdeme    = maas - kesilenTutar + fmUcret + prim;

    div.style.display='';
    div.innerHTML='<table style="width:100%;font-size:13px;border-collapse:collapse">'
      +'<tr style="background:#f8fafc"><td style="padding:8px">👤 Personel</td><td style="padding:8px;font-weight:700;text-align:right">'+per+'</td></tr>'
      +'<tr><td style="padding:8px">📅 Ay</td><td style="padding:8px;text-align:right">'+ay+'</td></tr>'
      +'<tr style="background:#f8fafc"><td style="padding:8px">💰 Net Maaş</td><td style="padding:8px;font-weight:700;text-align:right">'+_fmt(maas)+' ₺</td></tr>'
      +'<tr><td style="padding:8px">📉 Ücretsiz İzin ('+ucretsizGun+' gün)</td><td style="padding:8px;font-weight:700;text-align:right;color:#dc2626">-'+_fmt(gunlukUcret*ucretsizGun)+' ₺</td></tr>'
      +'<tr style="background:#f8fafc"><td style="padding:8px">📉 Devamsızlık ('+devszGun+' gün)</td><td style="padding:8px;font-weight:700;text-align:right;color:#dc2626">-'+_fmt(gunlukUcret*devszGun)+' ₺</td></tr>'
      +(yariGun?'<tr><td style="padding:8px">📉 Yarım Gün ('+yariGun+'×0.5)</td><td style="padding:8px;font-weight:700;text-align:right;color:#dc2626">-'+_fmt(gunlukUcret*yariGun*0.5)+' ₺</td></tr>':'')
      +(fm?'<tr style="background:#f8fafc"><td style="padding:8px">⏱️ Fazla Mesai ('+fm+' saat × 1.5)</td><td style="padding:8px;font-weight:700;text-align:right;color:#16a34a">+'+_fmt(fmUcret)+' ₺</td></tr>':'')
      +(prim?'<tr><td style="padding:8px">🏆 Prim</td><td style="padding:8px;font-weight:700;text-align:right;color:#16a34a">+'+_fmt(prim)+' ₺</td></tr>':'')
      +'<tr style="background:#1e293b"><td style="padding:12px 8px;color:#fff;font-weight:900">💳 ÖDENECEK</td><td style="padding:12px 8px;color:#34d399;font-weight:900;font-size:16px;text-align:right">'+_fmt(netOdeme)+' ₺</td></tr>'
      +'</table>';
  };

  window.renderTopluBordro = function(){
    var div=document.getElementById('topluBordroDiv');
    var ayEl=document.getElementById('topluBordro-ay');
    if(!div) return;
    var ay=ayEl?ayEl.value:_thisMonth();
    var pers=_getPersoneller();
    if(!pers.length){ div.innerHTML='<div style="color:var(--muted);padding:16px;text-align:center">Personel yok.</div>'; return; }

    var parts=ay.split('-'); var yil=parseInt(parts[0]); var ay_num=parseInt(parts[1]);
    var gunSay=new Date(yil,ay_num,0).getDate();
    var isGunSay=0;
    for(var g=1;g<=gunSay;g++){ var hg=new Date(yil,ay_num-1,g).getDay(); if(hg!==0&&hg!==6) isGunSay++; }

    var toplamOdeme=0;
    var rows=pers.map(function(p){
      var ad=p.ad+' '+p.soyad; var maas=p.maas||0;
      var kayit=_getPuantaj(ad,ay);
      var ucretsiz=0,devsz=0,yari=0,iste=0;
      Object.values(kayit).forEach(function(v){
        if(v==='ucretsiz')ucretsiz++;
        else if(v==='devsz')devsz++;
        else if(v==='yari')yari++;
        else if(v==='iste')iste++;
      });
      var kesilecek=ucretsiz+devsz+yari*0.5;
      var gunluk=maas/30; // FIX-V16: maaş/30
      var net=maas-gunluk*kesilecek;
      toplamOdeme+=net;
      return '<tr style="border-bottom:1px solid #e2e8f0">'
        +'<td style="padding:9px 10px;font-weight:700">'+ad+'</td>'
        +'<td style="padding:9px 10px;text-align:center">'+p.proje||'—'+'</td>'
        +'<td style="padding:9px 10px;text-align:right">'+_fmt(maas)+' ₺</td>'
        +'<td style="padding:9px 10px;text-align:center;color:#16a34a;font-weight:700">'+iste+'</td>'
        +'<td style="padding:9px 10px;text-align:center;color:#dc2626;font-weight:700">'+(ucretsiz+devsz)+'</td>'
        +'<td style="padding:9px 10px;text-align:right;font-weight:800;color:'+(kesilecek>0?'#dc2626':'#16a34a')+'">'+( kesilecek>0?'-'+_fmt(gunluk*kesilecek)+' ₺':'—')+'</td>'
        +'<td style="padding:9px 10px;text-align:right;font-weight:800;color:#166534">'+_fmt(net)+' ₺</td>'
        +'</tr>';
    }).join('');

    div.innerHTML='<table style="width:100%;border-collapse:collapse;font-size:12px">'
      +'<thead><tr style="background:#eef3ff">'
      +'<th style="padding:9px 10px;text-align:left;border-bottom:2px solid #c7d2fe">Personel</th>'
      +'<th style="padding:9px 10px;text-align:center;border-bottom:2px solid #c7d2fe">Proje</th>'
      +'<th style="padding:9px 10px;text-align:right;border-bottom:2px solid #c7d2fe">Net Maaş</th>'
      +'<th style="padding:9px 10px;text-align:center;border-bottom:2px solid #c7d2fe">İşte</th>'
      +'<th style="padding:9px 10px;text-align:center;border-bottom:2px solid #c7d2fe">Devs./Ücretsiz</th>'
      +'<th style="padding:9px 10px;text-align:right;border-bottom:2px solid #c7d2fe">Kesinti</th>'
      +'<th style="padding:9px 10px;text-align:right;border-bottom:2px solid #c7d2fe">Ödenecek</th>'
      +'</tr></thead>'
      +'<tbody>'+rows+'</tbody>'
      +'<tfoot><tr style="background:#1e293b">'
      +'<td colspan="6" style="padding:10px;color:#fff;font-weight:900">💳 TOPLAM BORDRO — '+ay+'</td>'
      +'<td style="padding:10px;text-align:right;font-weight:900;color:#34d399;font-size:14px">'+_fmt(toplamOdeme)+' ₺</td>'
      +'</tr></tfoot>'
      +'</table>';
  };

  /* ────────────────────────────────────────────────────────────────
     5. İZİN KAYDET BUTONU
     ──────────────────────────────────────────────────────────────── */
  function _bindIzinKaydet(){
    var btn=document.getElementById('izinKaydetBtn');
    if(!btn) return;
    var fresh=btn.cloneNode(true);
    btn.parentNode.replaceChild(fresh,btn);
    fresh.addEventListener('click',function(){
      var per    =document.getElementById('izin-personel')?.value||'';
      var tur    =document.getElementById('izin-tur')?.value||'ucretli';
      var onay   =document.getElementById('izin-onay')?.value||'bekliyor';
      var bas    =document.getElementById('izin-bas')?.value||'';
      var bit    =document.getElementById('izin-bit')?.value||'';
      var acik   =document.getElementById('izin-aciklama')?.value||'';
      if(!per){_toast('Personel seçin.');return;}
      if(!bas){_toast('Başlangıç tarihi giriniz.');return;}
      if(!bit) bit=bas;
      if(bit<bas){_toast('Bitiş başlangıçtan önce olamaz.');return;}
      var arr=_getIzinler();
      arr.push({personel:per,tur:tur,onay:onay,bas:bas,bit:bit,aciklama:acik,tarihEklendi:_today()});
      _saveIzinler(arr);
      // İzin günlerini puantaja yaz
      if(onay==='onaylandi'){
        var d=new Date(bas);
        var dBit=new Date(bit);
        var turPuantaj = tur==='raporlu'?'raporlu':tur==='ucretsiz'?'ucretsiz':tur==='resmitatil'?'tatil':'ucretli';
        while(d<=dBit){
          var tarihStr=d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
          var ay=tarihStr.slice(0,7);
          var kayit=_getPuantaj(per,ay);
          kayit[tarihStr]=turPuantaj;
          _savePuantaj(per,ay,kayit);
          d.setDate(d.getDate()+1);
        }
      }
      renderIzinListesi();
      _toast('✅ İzin eklendi: '+per+' — '+(IZ_ADLAR[tur]||tur)+' ('+_izinGunSay(bas,bit)+' gün)');
    });
  }

  /* ────────────────────────────────────────────────────────────────
     6. refreshIkSelectors override — puantaj seçicilerini de güncelle
     ──────────────────────────────────────────────────────────────── */
  var _origRefreshIk = window.refreshIkSelectors;
    window.refreshIkSelectors = function() {
    // Aktif personelleri filtrele
    const aktifPer = (window._personellerGetir ? window._personellerGetir() : (()=>{
      try { return (JSON.parse(localStorage.getItem('uysa_personeller')||'[]')).filter(p=>!p.pasif); } catch(e){return [];} 
    })());
    const perAdlari = aktifPer.map(p => (p.ad||'') + ' ' + (p.soyad||''));

    // Bordro, Vardiya, PDKS, Puantaj seçicilerini doldur
    ['bordro-personel','vardiya-isim','pdks-isim','puantaj-personel'].forEach(id => {
      const el = document.getElementById(id);
      if (!el) return;
      const prev = el.value;
      el.innerHTML = '<option value="">-- Personel Seç --</option>' +
        perAdlari.map(n => `<option value="${n}">${n}</option>`).join('');
      if (perAdlari.includes(prev)) el.value = prev;
    });

    try { window.uysaRenderPerListesi(); } catch(e){}

    // Proje dropdown'ını doldur
    try {
      const projeEl = document.getElementById('per-proje');
      if (projeEl) {
        const musteriler = (()=>{
          try {
            const obj = JSON.parse(localStorage.getItem('uysa_customers_v1')||'{}');
            const pasifler = JSON.parse(localStorage.getItem('uysa_pasif_musteriler')||'[]');
            return (obj.customers||[]).filter(c=>c&&c!=='GENEL'&&!pasifler.includes(c));
          } catch(e){ return []; }
        })();
        const prev = projeEl.value;
        projeEl.innerHTML = '<option value="MERKEZ">MERKEZ</option>' +
          musteriler.map(m => `<option value="${m}">${m}</option>`).join('');
        if ([...projeEl.options].some(o => o.value === prev)) projeEl.value = prev;
      }
    } catch(e){}

    // Maliyet dağılımı müşteri seçicisini doldur
    try {
      const malEl = document.getElementById('perMalMusteriSel');
      if (malEl) {
        const musteriler = (()=>{
          try {
            const obj = JSON.parse(localStorage.getItem('uysa_customers_v1')||'{}');
            const pasifler = JSON.parse(localStorage.getItem('uysa_pasif_musteriler')||'[]');
            return (obj.customers||[]).filter(c=>c&&c!=='GENEL'&&!pasifler.includes(c));
          } catch(e){ return []; }
        })();
        const prev = malEl.value;
        malEl.innerHTML = '<option value="TUMU">Tüm Müşteriler</option>' +
          musteriler.map(m => `<option value="${m}">${m}</option>`).join('');
        if (prev && [...malEl.options].some(o=>o.value===prev)) malEl.value = prev;
      }
    } catch(e){}
  };

  /* ────────────────────────────────────────────────────────────────
     INIT
     ──────────────────────────────────────────────────────────────── */
  function _initV78(){
    console.log('✅ UYSA v78 patch loading...');
    // İlk tab personel aktif
    switchIkTab('personel');
    // Ay seçicilere bu ayı koy
    ['puantaj-ay','bordro2-ay','topluBordro-ay'].forEach(function(id){
      var el=document.getElementById(id); if(el&&!el.value) el.value=_thisMonth();
    });
    _syncPuantajPersonelSel();
    _syncIzinPersonelSels();
    _syncBordroPersonelSels();
    _bindIzinKaydet();
    console.log('✅ UYSA v78 loaded.');
  }

  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',_initV78);
  } else {
    _initV78();
  }

})();
