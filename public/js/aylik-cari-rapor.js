/* ══════════════════════════════════════════════════════════════════
   UYSA v78 PATCH
   1. Aylık Cari Rapor — v77AySecici'den ay oku, gs-tarih'e fallback
   2. Dokümanlar — kategori listesi + dijital arşiv tam liste
   ══════════════════════════════════════════════════════════════════ */
(function(){
  'use strict';

  var _ls = {
    get: function(k,d){ try{ var v=localStorage.getItem(k); return v===null?d:JSON.parse(v); }catch(e){ return d; } },
    set: function(k,v){ try{ localStorage.setItem(k,JSON.stringify(v)); }catch(e){} }
  };
  function _today(){
    var d=new Date();
    return d.getFullYear()+'-'+(''+(d.getMonth()+1)).padStart(2,'0')+'-'+(''+d.getDate()).padStart(2,'0');
  }
  function _thisMonth(){
    var d=new Date();
    return d.getFullYear()+'-'+(''+(d.getMonth()+1)).padStart(2,'0');
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

  /* ────────────────────────────────────────────────────────────────
     1. AYLIK CARİ RAPOR — v77AySecici'den ay seç
     ──────────────────────────────────────────────────────────────── */
  var _ayAdlari = ['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran',
                   'Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];

  window.v75RenderAylikRapor = function(){
    var div = document.getElementById('v75AylikRaporDiv');
    if(!div) return;

    // Ay seçiciden al; yoksa gs-tarih'ten, o da yoksa bu ay
    var aySecici = document.getElementById('v77AySecici');
    var seciliAy = (aySecici && aySecici.value) ? aySecici.value : null;
    if(!seciliAy){
      var tarihEl = document.getElementById('gs-tarih');
      seciliAy = tarihEl&&tarihEl.value ? tarihEl.value.slice(0,7) : _thisMonth();
      // Seçiciyi güncelle
      if(aySecici) aySecici.value = seciliAy;
    }
    var buAy = seciliAy; // YYYY-MM

    var gunlukArr = _ls.get('uysa_gunluk_uretim',[]);
    var ayKayitlar = gunlukArr.filter(function(u){
      return (u.tarih||'').startsWith(buAy) && u.musteri && u.musteri!=='GENEL';
    });

    if(!ayKayitlar.length){
      var parts0=buAy.split('-');
      var ayAdi0=_ayAdlari[parseInt(parts0[1],10)-1]+' '+parts0[0];
      div.innerHTML='<div style="color:var(--muted);padding:12px">📭 <b>'+ayAdi0+'</b> ayına ait kayıt bulunamadı.</div>';
      return;
    }

    // Müşteri bazlı grupla
    var musteriMap = {};
    ayKayitlar.forEach(function(u){
      if(!musteriMap[u.musteri]) musteriMap[u.musteri]=[];
      musteriMap[u.musteri].push(u);
    });

    var parts=buAy.split('-');
    var ayAdi=_ayAdlari[parseInt(parts[1],10)-1]+' '+parts[0];

    var toplamMusteriler = Object.keys(musteriMap).length;
    var toplamGunSayisi  = new Set(ayKayitlar.map(function(u){ return u.tarih; })).size;
    var toplamKisi       = ayKayitlar.reduce(function(s,u){ return s+(u.kisi||0); },0);
    var toplamTutar      = ayKayitlar.reduce(function(s,u){ return s+(u.tutar||0); },0);

    // Özet kartlar
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

    // Tablo satırları
    var tableRows='';
    Object.keys(musteriMap).sort(function(a,b){ return a.localeCompare(b,'tr'); }).forEach(function(cust){
      var kayitlar=musteriMap[cust].sort(function(a,b){ return (a.tarih||'').localeCompare(b.tarih||''); });
      var altTopKisi  = kayitlar.reduce(function(s,u){ return s+(u.kisi||0); },0);
      var altTopTutar = kayitlar.reduce(function(s,u){ return s+(u.tutar||0); },0);

      kayitlar.forEach(function(u,i){
        var isFirst=i===0;
        tableRows+='<tr style="background:'+(isFirst?'#eff6ff':'#fff')+'">'
          +'<td style="padding:8px 10px;font-weight:'+(isFirst?'700':'400')+';color:'+(isFirst?'#1e40af':'#475569')+';border-bottom:1px solid #e2e8f0">'+(isFirst?cust:'')+'</td>'
          +'<td style="padding:8px 10px;text-align:center;border-bottom:1px solid #e2e8f0;color:#64748b">'+u.tarih+'</td>'
          +'<td style="padding:8px 10px;text-align:center;border-bottom:1px solid #e2e8f0;font-weight:700">'+u.kisi+'</td>'
          +'<td style="padding:8px 10px;text-align:right;border-bottom:1px solid #e2e8f0;color:#64748b">'+_fmt(u.fiyat||0)+' ₺</td>'
          +'<td style="padding:8px 10px;text-align:right;border-bottom:1px solid #e2e8f0;font-weight:700;color:#166534">'+_fmt(u.tutar||0)+' ₺</td>'
          +'</tr>';
      });
      tableRows+='<tr style="background:#f0fdf4">'
        +'<td colspan="2" style="padding:7px 10px;font-weight:800;color:#166534;font-size:12px;border-bottom:2px solid #bbf7d0">📊 '+cust+' Alt Toplam</td>'
        +'<td style="padding:7px 10px;text-align:center;font-weight:800;color:#166534;border-bottom:2px solid #bbf7d0">'+altTopKisi+'</td>'
        +'<td style="border-bottom:2px solid #bbf7d0"></td>'
        +'<td style="padding:7px 10px;text-align:right;font-weight:800;color:#166534;border-bottom:2px solid #bbf7d0">'+_fmt(altTopTutar)+' ₺</td>'
        +'</tr>';
    });
    tableRows+='<tr style="background:#1e293b">'
      +'<td colspan="2" style="padding:10px;font-weight:900;color:#fff;font-size:13px">🏆 '+ayAdi+' — GENEL TOPLAM ('+toplamMusteriler+' müşteri, '+toplamGunSayisi+' gün)</td>'
      +'<td style="padding:10px;text-align:center;font-weight:900;color:#fbbf24;font-size:13px">'+toplamKisi.toLocaleString('tr-TR')+' kişi</td>'
      +'<td style="padding:10px"></td>'
      +'<td style="padding:10px;text-align:right;font-weight:900;color:#34d399;font-size:14px">'+_fmt(toplamTutar)+' ₺</td>'
      +'</tr>';

    var tableHtml='<div style="overflow-x:auto"><table style="width:100%;font-size:13px;border-collapse:collapse;border-radius:10px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,.08)">'
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

  /* ────────────────────────────────────────────────────────────────
     2. DOKÜMAN — kategori filtresi + dijital arşiv tam liste
     ──────────────────────────────────────────────────────────────── */
  var _dokIkonlar = {teklif:'📄',sozlesme:'📝',fatura:'🧾',irsaliye:'🚚',
                     personel:'👤',sirket:'🏢',musteri:'🤝',diger:'📁'};
  var _dokKatAdlar = {teklif:'Teklifler',sozlesme:'Sözleşmeler',fatura:'Faturalar',
                      irsaliye:'İrsaliyeler',personel:'Personel Evrakları',
                      sirket:'Şirket Evrakları',musteri:'Müşteri Evrakları',diger:'Diğer'};
  var _aktifKat = '';

  function _getDokuman(){ return _ls.get('uysa_dokumanlar',[]); }

  function _dokRow(d, idx, dokümanlar){
    var realIdx = dokümanlar.indexOf(d);
    return '<div style="border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;margin-bottom:6px;background:#fff;display:flex;justify-content:space-between;align-items:start;gap:8px">'
      +'<div style="flex:1;min-width:0">'
      +'<div style="font-weight:700;font-size:13px;color:#1e293b">'+(_dokIkonlar[d.kategori]||'📁')+' '+(d.adi||'—')
      +'<span style="font-weight:400;font-size:11px;color:#94a3b8;margin-left:6px">['+(_dokKatAdlar[d.kategori]||d.kategori)+']</span>'
      +'<span style="font-size:11px;color:#64748b;margin-left:8px">'+( d.tarih||'')+'</span>'
      +'</div>'
      +(d.not?'<div style="font-size:11px;color:#64748b;margin-top:2px">'+d.not+'</div>':'')
      +(d.dosyaAdi?'<div style="font-size:11px;color:#2563eb;margin-top:2px">📎 '+d.dosyaAdi+'</div>':'')
      +'</div>'
      +'<div style="display:flex;gap:4px;flex-shrink:0">'
      +(d.dosyaData?'<button onclick="v77Indir('+realIdx+')" style="border:1px solid #c7d2fe;background:#eff6ff;color:#2563eb;border-radius:6px;padding:4px 8px;cursor:pointer;font-size:11px">⬇️ İndir</button>':'')
      +'<button onclick="v77SilDok('+realIdx+')" style="border:none;background:none;color:#dc2626;cursor:pointer;font-size:16px;padding:2px 4px" title="Sil">🗑️</button>'
      +'</div>'
      +'</div>';
  }

  // Kategori filtreli liste (üst kutu)
  function _renderKatListesi(){
    var div = document.getElementById('dokListDiv');
    var baslik = document.getElementById('dokKatBaslik');
    if(!div) return;
    var dokümanlar = _getDokuman();
    var liste = _aktifKat
      ? dokümanlar.filter(function(d){ return d.kategori===_aktifKat; })
      : dokümanlar;

    if(baslik){
      baslik.textContent = _aktifKat
        ? ((_dokIkonlar[_aktifKat]||'📁')+' '+(_dokKatAdlar[_aktifKat]||_aktifKat)+' — '+liste.length+' kayıt')
        : 'Tüm kategoriler — '+liste.length+' kayıt';
    }

    if(!liste.length){
      div.innerHTML='<div style="text-align:center;color:var(--muted);padding:16px">Bu kategoride kayıt yok.</div>';
      return;
    }
    div.innerHTML = liste.map(function(d,i){ return _dokRow(d,i,dokümanlar); }).join('');
  }

  // Dijital arşiv — HER ZAMAN TÜMÜ
  function _renderArsivTum(aramaQ){
    var div = document.getElementById('dokArsivTumDiv');
    if(!div) return;
    var dokümanlar = _getDokuman();
    var liste = dokümanlar;
    if(aramaQ){
      var q=aramaQ.toLowerCase();
      liste=dokümanlar.filter(function(d){
        return (d.adi||'').toLowerCase().includes(q)
          || (d.not||'').toLowerCase().includes(q)
          || (d.kategori||'').toLowerCase().includes(q)
          || (d.dosyaAdi||'').toLowerCase().includes(q);
      });
    }

    if(!liste.length){
      div.innerHTML='<div style="text-align:center;color:var(--muted);padding:20px">'+(aramaQ?'Arama sonucu bulunamadı.':'Henüz kayıtlı doküman yok.')+'</div>';
      return;
    }

    // Kategoriye göre grupla
    var katMap={};
    liste.forEach(function(d){
      var k=d.kategori||'diger';
      if(!katMap[k]) katMap[k]=[];
      katMap[k].push(d);
    });

    var html='';
    var katSira=['teklif','sozlesme','fatura','irsaliye','personel','sirket','musteri','diger'];
    katSira.forEach(function(k){
      if(!katMap[k]||!katMap[k].length) return;
      html+='<div style="margin-bottom:12px">'
        +'<div style="font-weight:700;font-size:12px;color:#2563eb;background:#eff6ff;padding:6px 10px;border-radius:6px;margin-bottom:6px">'
        +(_dokIkonlar[k]||'📁')+' '+(_dokKatAdlar[k]||k)+' ('+katMap[k].length+')</div>';
      html+=katMap[k].map(function(d,i){ return _dokRow(d,i,liste); }).join('');
      html+='</div>';
    });
    div.innerHTML = html;
  }

  // Her iki listeyi birden güncelle
  function _renderDokAll(aramaQ){
    _renderKatListesi();
    _renderArsivTum(aramaQ||'');
  }

  // window.renderDokumanlar override
  window.renderDokumanlar = function(filtre){
    if(filtre && typeof filtre==='string' && filtre.length<=20 && ['teklif','sozlesme','fatura','irsaliye','personel','sirket','musteri','diger',''].includes(filtre)){
      _aktifKat = filtre;
    }
    _renderDokAll('');
  };

  // filterDok override
  window.filterDok = function(kat){
    _aktifKat = kat;
    ['teklif','sozlesme','fatura','irsaliye','personel','sirket','musteri','diger'].forEach(function(k){
      var b=document.getElementById('dokKat-'+k);
      if(b) b.className=(k===kat?'btn primary':'btn');
    });
    _renderKatListesi();
    // Dijital arşiv değişmez
  };

  // silDok ve indir override
  window.v77SilDok = function(i){
    var dokümanlar=_getDokuman();
    if(i<0||i>=dokümanlar.length) return;
    var ad=dokümanlar[i].adi||'?';
    if(!confirm('Silinsin mi? '+ad)) return;
    dokümanlar.splice(i,1);
    _ls.set('uysa_dokumanlar',dokümanlar);
    _renderDokAll('');
    _toast('🗑️ Silindi: '+ad);
  };
  window.v77Indir = function(i){
    var dokümanlar=_getDokuman();
    var d=dokümanlar[i];
    if(!d||!d.dosyaData) return;
    var a=document.createElement('a');
    a.href=d.dosyaData; a.download=d.dosyaAdi||d.adi||'dosya';
    document.body.appendChild(a); a.click(); a.remove();
  };
  // Eski alias
  window.silDok = window.v77SilDok;
  window.indir  = window.v77Indir;

  // Kaydet butonu override — v75'teki clone trick üzerine ekleyelim
  function _bindDokKaydetV77(){
    var btn = document.getElementById('dokKaydetBtn');
    if(!btn) return;
    var fresh = btn.cloneNode(true);
    btn.parentNode.replaceChild(fresh, btn);
    fresh.addEventListener('click', function(){
      var adi      = (document.getElementById('dok-adi')?.value||'').trim();
      var kategori = document.getElementById('dok-kategori')?.value||'diger';
      var tarih    = document.getElementById('dok-tarih')?.value||_today();
      var not      = document.getElementById('dok-not')?.value||'';
      var statusDiv= document.getElementById('dokKaydetStatus');
      if(!adi){ alert('Doküman adı gerekli.'); return; }

      var dosyaInput = document.getElementById('dok-dosya');
      var dokümanlar = _getDokuman();

      function _afterSave(dosyaAdi, dosyaData){
        dokümanlar.push({adi:adi,kategori:kategori,tarih:tarih,not:not,dosyaAdi:dosyaAdi,dosyaData:dosyaData});
        _ls.set('uysa_dokumanlar', dokümanlar);
        _renderDokAll('');
        if(dosyaInput) dosyaInput.value='';
        var adiEl=document.getElementById('dok-adi'); if(adiEl) adiEl.value='';
        var notEl=document.getElementById('dok-not');  if(notEl) notEl.value='';
        // Klasöre kaydet
        if(window._v75DokFolder && dosyaData){
          _saveDokToFolderV77(dosyaAdi||adi+'.dat', dosyaData).then(function(r){
            if(r && statusDiv) statusDiv.textContent='📁 Klasöre kaydedildi: '+r;
          });
        } else {
          if(statusDiv) statusDiv.textContent='✅ Kaydedildi'+(dosyaAdi?' — '+dosyaAdi:'')+'!';
        }
        _toast('✅ Doküman eklendi: '+adi+' ['+(_dokKatAdlar[kategori]||kategori)+']');
      }

      if(dosyaInput && dosyaInput.files && dosyaInput.files.length>0){
        var file=dosyaInput.files[0];
        if(file.size>10*1024*1024){ alert('Dosya 10 MB sınırını aşıyor.'); return; }
        if(statusDiv) statusDiv.textContent='Okunuyor...';
        var reader=new FileReader();
        reader.onload=function(e){ _afterSave(file.name, e.target.result); };
        reader.onerror=function(){ alert('Dosya okunamadı.'); };
        reader.readAsDataURL(file);
      } else {
        _afterSave('','');
      }
    });
  }

  // Klasöre kaydet yardımcı (v75 ile uyumlu)
  window._v75DokFolder = null;
  window.v75SelectDokFolder = function(){
    if(!('showDirectoryPicker' in window)){
      alert('Klasöre kaydetme Chrome/Edge gerektirir.'); return;
    }
    window.showDirectoryPicker({mode:'readwrite'}).then(function(handle){
      window._v75DokFolder = handle;
      var s=document.getElementById('dokKlasorStatus');
      if(s) s.textContent='📁 '+handle.name;
      _toast('✅ Klasör seçildi: '+handle.name);
    }).catch(function(e){ if(e.name!=='AbortError') console.warn(e); });
  };

  async function _saveDokToFolderV77(fileName, dataUrl){
    var folder = window._v75DokFolder;
    if(!folder) return false;
    try{
      var arr=dataUrl.split(',');
      var mime=arr[0].match(/:(.*?);/)[1];
      var bstr=atob(arr[1]); var n=bstr.length; var u8=new Uint8Array(n);
      while(n--) u8[n]=bstr.charCodeAt(n);
      var blob=new Blob([u8],{type:mime});
      var safe=_today().replace(/-/g,'')+'_'+fileName.replace(/[/\\:*?"<>|]/g,'_');
      var fh=await folder.getFileHandle(safe,{create:true});
      var wr=await fh.createWritable();
      await wr.write(blob); await wr.close();
      return safe;
    }catch(e){ console.error(e); return false; }
  }

  // Arama
  function _bindDokArama(){
    var btn=document.getElementById('dokAramaBtn');
    var inp=document.getElementById('dokArama');
    if(btn){
      var fb=btn.cloneNode(true); btn.parentNode.replaceChild(fb,btn);
      fb.addEventListener('click',function(){ _renderArsivTum(inp?.value||''); });
    }
    if(inp) inp.addEventListener('input',function(){ _renderArsivTum(inp.value); });
    var silBtn=document.getElementById('dokSilSecBtn');
    if(silBtn){
      var fs=silBtn.cloneNode(true); silBtn.parentNode.replaceChild(fs,silBtn);
      fs.addEventListener('click',function(){
        if(confirm('Tüm arşivdeki dokümanları silmek istediğinizden emin misiniz?')){
          _ls.set('uysa_dokumanlar',[]); _renderDokAll('');
        }
      });
    }
    var kbtn=document.getElementById('dokKlasorSecBtn');
    if(kbtn){
      var fk=kbtn.cloneNode(true); kbtn.parentNode.replaceChild(fk,kbtn);
      fk.addEventListener('click', window.v75SelectDokFolder);
    }
  }

  /* ────────────────────────────────────────────────────────────────
     INIT
     ──────────────────────────────────────────────────────────────── */
  function _initV77(){
    console.log('✅ UYSA v78 patch loading...');

    // Ay seçiciye bu ayı koy
    var aySecici = document.getElementById('v77AySecici');
    if(aySecici && !aySecici.value) aySecici.value = _thisMonth();

    // Doküman butonlarını bağla
    _bindDokKaydetV77();
    _bindDokArama();

    // İlk render
    _renderDokAll('');

    /* switchFinTab sayi hook — artık ana fonksiyonda birleştirildi */

    console.log('✅ UYSA v78 patch loaded.');
  }

  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded', _initV77);
  } else {
    _initV77();
  }

})();
