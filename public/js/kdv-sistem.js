
  // ════════════════════════════════════════════════════════════
  // UYSA v93 — KDV Sistemi + SGK + Biriken Haklar + Aylık KDV Raporu + Üretim KDV Sütunu
  // ════════════════════════════════════════════════════════════
  (function(){
    'use strict';

    var fmt = window.fmt || function(n){ return Number(n||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}); };
    var _ls = window._ls || window.ls || { get:function(k,d){try{return JSON.parse(localStorage.getItem(k))||d;}catch(e){return d;}}, set:function(k,v){localStorage.setItem(k,JSON.stringify(v));} };

    // ── 1. KDV Seçici — Gelir ─────────────────────────────────
    var _gelirKdvPct = 10;
    window.setGelirKdv = function(pct){
      _gelirKdvPct = pct;
      // buton vurgula
      [0,1,10,20].forEach(function(p){
        var btn = document.getElementById('gelirKdv'+p);
        if(btn) btn.style.background = (p===pct) ? '#0b3aa6' : (p===0?'#e2e8f0':'');
        if(btn) btn.style.color = (p===pct) ? '#fff' : '';
        if(btn) btn.style.fontWeight = (p===pct) ? '800' : '';
      });
      // bilgi kutusu
      var info = document.getElementById('gelirKdvInfo');
      var tutar = parseFloat(document.getElementById('gelir-tutar')?.value)||0;
      if(info){
        if(pct===0){
          info.style.display='none';
        } else {
          var kdvTutar = tutar * (pct/100);
          info.style.display='';
          info.innerHTML = 'KDV tutarı: <b>'+fmt(kdvTutar)+' ₺</b> ('+pct+'%)'
            + (tutar>0 ? ' — Toplam (KDV dahil): <b>'+fmt(tutar+kdvTutar)+' ₺</b>' : '');
        }
      }
    };
    // Tutar değişince KDV bilgisini güncelle
    document.getElementById('gelir-tutar')?.addEventListener('input', function(){
      if(_gelirKdvPct>0) window.setGelirKdv(_gelirKdvPct);
    });

    // ── 2. KDV Seçici — Gider ─────────────────────────────────
    window._giderKdvPct = 1;
    window.setGiderKdv = function(pct){
      window._giderKdvPct = pct;
      [0,1,10,20].forEach(function(p){
        var btn = document.getElementById('giderKdv'+p);
        if(btn) btn.style.background = (p===pct) ? '#dc2626' : (p===0?'#e2e8f0':'');
        if(btn) btn.style.color = (p===pct) ? '#fff' : '';
        if(btn) btn.style.fontWeight = (p===pct) ? '800' : '';
      });
      var info = document.getElementById('giderKdvInfo');
      var tutar = parseFloat(document.getElementById('gider-tutar')?.value)||0;
      if(info){
        if(pct===0){
          info.style.display='none';
        } else {
          var kdvTutar = tutar * (pct/100);
          info.style.display='';
          info.innerHTML = 'KDV tutarı: <b>'+fmt(kdvTutar)+' ₺</b> ('+pct+'%)'
            + (tutar>0 ? ' — Toplam (KDV dahil): <b>'+fmt(tutar+kdvTutar)+' ₺</b>' : '');
        }
      }
    };
    document.getElementById('gider-tutar')?.addEventListener('input', function(){
      if(window._giderKdvPct>0) window.setGiderKdv(window._giderKdvPct);
    });

    // Varsayılan KDV butonlarını sayfa yüklenince aktif göster
    window.setGelirKdv(10);
    window.setGiderKdv(1);

    // ── 3. Gelir kaydet: KDV bilgisini dahil et ────────────────
    var origGelirBtn = document.getElementById('gelirKaydetBtn');
    if(origGelirBtn){
      var _origGelirHandler = origGelirBtn.onclick;
      origGelirBtn.onclick = null;
      // Remove existing listeners by cloning
      var newGelirBtn = origGelirBtn.cloneNode(true);
      origGelirBtn.parentNode.replaceChild(newGelirBtn, origGelirBtn);
      newGelirBtn.addEventListener('click', function(){
        var musteri = document.getElementById('gelir-musteri')?.value||'';
        var tarih   = document.getElementById('gelir-tarih')?.value||(function(){var d=new Date();return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0')})();
        var tutar   = parseFloat(document.getElementById('gelir-tutar')?.value)||0;
        var kisi    = parseInt(document.getElementById('gelir-kisi')?.value)||0;
        var fatura  = document.getElementById('gelir-fatura')?.value||'';
        var aciklama= document.getElementById('gelir-aciklama')?.value||'';
        if(!tutar){ alert('Tutar giriniz.'); return; }
        var kdvPct  = _gelirKdvPct;
        var kdvTutar= tutar * (kdvPct/100);
        var arr = (function(){try{return JSON.parse(localStorage.getItem('uysa_gelirler'))||[];}catch(e){return[];}})();
        arr.push({musteri:musteri,tarih:tarih,tutar:tutar,kisi:kisi,fatura:fatura,aciklama:aciklama,kdvPct:kdvPct,kdvTutar:kdvTutar,faturali:!!fatura});
        localStorage.setItem('uysa_gelirler', JSON.stringify(arr));
        if(typeof renderGelirler==='function') renderGelirler();
        refreshKdvOzet();
        try{if(typeof window.autoSaveToFolder==='function')window.autoSaveToFolder();}catch(e){}
        alert('Gelir kaydedildi.'+(kdvPct>0?' KDV: '+fmt(kdvTutar)+' ₺':''));
        document.getElementById('gelir-tutar').value='';
        document.getElementById('gelir-fatura').value='';
        document.getElementById('gelir-aciklama').value='';
        var info=document.getElementById('gelirKdvInfo'); if(info) info.style.display='none';
        _gelirKdvPct=0;
        [0,1,10,20].forEach(function(p){var b=document.getElementById('gelirKdv'+p);if(b){b.style.background=p===0?'#e2e8f0':'';b.style.color='';b.style.fontWeight='';}});
      });
    }

    // ── 4. Gider kaydet: KDV + faturalı bilgisini dahil et ─────
    var origGiderBtn = document.getElementById('giderKaydetBtn');
    if(origGiderBtn){
      origGiderBtn.onclick = null;
      var newGiderBtn = origGiderBtn.cloneNode(true);
      origGiderBtn.parentNode.replaceChild(newGiderBtn, origGiderBtn);
      newGiderBtn.addEventListener('click', function(){
        var kat      = document.getElementById('gider-kat')?.value||'diger';
        var tarih    = document.getElementById('gider-tarih')?.value||(function(){var d=new Date();return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0')})();
        var tutar    = parseFloat(document.getElementById('gider-tutar')?.value)||0;
        var aciklama = document.getElementById('gider-aciklama')?.value||'';
        var belge    = document.getElementById('gider-belge')?.value||'';
        var tedarikci= document.getElementById('gider-tedarikci')?.value||'';
        var faturali = document.getElementById('gider-faturali')?.checked||false;
        if(!tutar){ alert('Tutar giriniz.'); return; }
        var kdvPct   = window._giderKdvPct;
        var kdvTutar = tutar * (kdvPct/100);
        var arr = (function(){try{return JSON.parse(localStorage.getItem('uysa_giderler'))||[];}catch(e){return[];}})();
        arr.push({kat:kat,tarih:tarih,tutar:tutar,aciklama:aciklama,belge:belge,tedarikci:tedarikci,kdvPct:kdvPct,kdvTutar:kdvTutar,faturali:faturali});
        localStorage.setItem('uysa_giderler', JSON.stringify(arr));
        if(typeof renderGiderler==='function') renderGiderler();
        refreshKdvOzet();
        try{if(typeof window.autoSaveToFolder==='function')window.autoSaveToFolder();}catch(e){}
        alert('Gider kaydedildi.'+(kdvPct>0?' KDV: '+fmt(kdvTutar)+' ₺':''));
        document.getElementById('gider-tutar').value='';
        document.getElementById('gider-aciklama').value='';
        var cb=document.getElementById('gider-faturali'); if(cb) cb.checked=false;
        var info=document.getElementById('giderKdvInfo'); if(info) info.style.display='none';
        window._giderKdvPct=0;
        [0,1,10,20].forEach(function(p){var b=document.getElementById('giderKdv'+p);if(b){b.style.background=p===0?'#e2e8f0':'';b.style.color='';b.style.fontWeight='';}});
      });
    }

    // ── 5. KDV Özet hesaplama fonksiyonu ──────────────────────
    function refreshKdvOzet(){
      var gelirler = (function(){try{return JSON.parse(localStorage.getItem('uysa_gelirler'))||[];}catch(e){return[];}})();
      var giderler = (function(){try{return JSON.parse(localStorage.getItem('uysa_giderler'))||[];}catch(e){return[];}})();

      // KDV Tahsil: tüm gelirlerden kdvTutar toplamı
      var kdvGelir = gelirler.reduce(function(s,g){ return s+(g.kdvTutar||0); }, 0);
      // KDV İndirim: faturalı giderlerden kdvTutar toplamı
      var kdvGider = giderler.filter(function(g){ return g.faturali; }).reduce(function(s,g){ return s+(g.kdvTutar||0); }, 0);
      var kdvOdenecek = Math.max(0, kdvGelir - kdvGider);

      var el = function(id,v){ var e=document.getElementById(id); if(e) e.textContent=v; };
      el('kdvGelirToplam', fmt(kdvGelir)+' ₺');
      el('kdvGiderToplam', fmt(kdvGider)+' ₺');
      el('kdvOdenecek',    fmt(kdvOdenecek)+' ₺');

      // Ödenecek KDV renge göre
      var kdvEl = document.getElementById('kdvOdenecek');
      if(kdvEl) kdvEl.style.color = kdvOdenecek>0 ? '#b45309' : '#166534';

      // Gelir Vergisi: KDV'li tüm gelirler - faturalı tüm giderler
      var kdvliGelirler  = gelirler.filter(function(g){ return (g.kdvPct||0)>0; }).reduce(function(s,g){ return s+(g.tutar||0)+(g.kdvTutar||0); }, 0);
      // Eğer hiç KDV'li gelir yoksa tüm gelirleri al
      if(kdvliGelirler===0) kdvliGelirler = gelirler.reduce(function(s,g){ return s+(g.tutar||0); }, 0);
      var faturaliGiderler = giderler.filter(function(g){ return g.faturali; }).reduce(function(s,g){ return s+(g.tutar||0)+(g.kdvTutar||0); }, 0);
      var gvMatrah = Math.max(0, kdvliGelirler - faturaliGiderler);
      var gvVergi  = gvMatrah / 4.1;

      el('gvGelirler', fmt(kdvliGelirler)+' ₺');
      el('gvGiderler', fmt(faturaliGiderler)+' ₺');
      el('gvMatrah',   fmt(gvMatrah)+' ₺');
      el('gvVergi',    fmt(gvVergi)+' ₺');
    }
    window.refreshKdvOzet = refreshKdvOzet;
    // İlk yüklemede hesapla
    setTimeout(refreshKdvOzet, 800);

    // ── 6. Personel form: SGK + Biriken Haklar canlı hesap ─────
    window.calcPerMaliyet = function(){
      var maas   = parseFloat(document.getElementById('per-maas')?.value)||0;
      var sgk    = parseFloat(document.getElementById('per-sgk')?.value)||0;
      var biriken= maas * 1.3 / 12;
      var toplam = maas + sgk + biriken;

      var birikenEl = document.getElementById('perBirikenInfo');
      if(birikenEl) birikenEl.textContent = maas>0 ? fmt(biriken)+' ₺/ay' : 'Maaş giriniz...';

      var toplamEl = document.getElementById('perToplamMaliyet');
      if(toplamEl) toplamEl.textContent = fmt(toplam)+' ₺';
    };
    // SGK input da dinle
    document.getElementById('per-sgk')?.addEventListener('input', window.calcPerMaliyet);
    document.getElementById('per-maas')?.addEventListener('input', window.calcPerMaliyet);

    // ── 7. uysaPerEkle: SGK + biriken haklar kaydına dahil et ──
    var _origPerEkle = window.uysaPerEkle;
    window.uysaPerEkle = function(){
      var ad    = (document.getElementById('per-ad')?.value||'').trim();
      var soyad = (document.getElementById('per-soyad')?.value||'').trim();
      if(!ad||!soyad){ if(typeof _toast==='function') _toast('⚠️ Ad ve Soyad zorunlu!','#b91c1c'); else alert('Ad ve Soyad zorunlu!'); return; }
      var proje  = document.getElementById('per-proje')?.value||'';
      var gorev  = document.getElementById('per-gorev')?.value||'';
      var maas   = parseFloat(document.getElementById('per-maas')?.value)||0;
      var sgk    = parseFloat(document.getElementById('per-sgk')?.value)||0;
      var biriken= maas * 1.3 / 12;
      var tel    = document.getElementById('per-tel')?.value||'';
      var giris  = document.getElementById('per-giris')?.value||'';
      var personeller = (function(){try{return JSON.parse(localStorage.getItem('uysa_personeller'))||[];}catch(e){return[];}})();
      personeller.push({
        ad:ad, soyad:soyad, proje:proje, gorev:gorev,
        maas:maas, sgk:sgk, birikenHak:biriken,
        tel:tel, giris:giris, pasif:false
      });
      localStorage.setItem('uysa_personeller', JSON.stringify(personeller));
      if(typeof window.uysaRenderPerListesi==='function') window.uysaRenderPerListesi();
      if(typeof window.refreshIkSelectors==='function') try{window.refreshIkSelectors();}catch(e){}
      ['per-ad','per-soyad','per-maas','per-sgk','per-tel','per-giris','per-gorev'].forEach(function(id){
        var el=document.getElementById(id); if(el) el.value='';
      });
      var bEl=document.getElementById('perBirikenInfo'); if(bEl) bEl.textContent='Maaş giriniz...';
      var tEl=document.getElementById('perToplamMaliyet'); if(tEl) tEl.textContent='0 ₺';
      if(typeof _toast==='function') _toast('✅ '+ad+' '+soyad+' eklendi (SGK:'+fmt(sgk)+'₺ Biriken:'+fmt(biriken)+'₺)');
    };

    // ── 8. Personel Listesi render: 3 maliyet ayrı ayrı göster ─
    var _origRender = window.uysaRenderPerListesi;
    window.uysaRenderPerListesi = function(){
      if(typeof _origRender==='function') _origRender();
      // Maliyet özet satırını listede güncelle
      refreshPersonelMaliyetOzet();
    };

    function refreshPersonelMaliyetOzet(){
      var personeller = (function(){try{return JSON.parse(localStorage.getItem('uysa_personeller'))||[];}catch(e){return[];}})();
      var aktif = personeller.filter(function(p){ return !p.pasif; });
      var topMaas    = aktif.reduce(function(s,p){ return s+(p.maas||0); }, 0);
      var topSgk     = aktif.reduce(function(s,p){ return s+(p.sgk||0); }, 0);
      var topBiriken = aktif.reduce(function(s,p){ return s+(p.birikenHak || ((p.maas||0)*1.3/12)); }, 0);
      var topToplam  = topMaas + topSgk + topBiriken;

      var el = function(id,v){ var e=document.getElementById(id); if(e) e.innerHTML=v; };
      // Özet paneli yoksa oluştur
      var ozDiv = document.getElementById('perMaliyetOzetDiv');
      if(!ozDiv){
        var listDiv = document.getElementById('perListDiv');
        if(listDiv){
          ozDiv = document.createElement('div');
          ozDiv.id = 'perMaliyetOzetDiv';
          ozDiv.style.cssText = 'background:#f0fdf4;border:2px solid #86efac;border-radius:10px;padding:12px;margin-bottom:12px;font-size:12px';
          listDiv.parentNode.insertBefore(ozDiv, listDiv);
        }
      }
      if(ozDiv){
        ozDiv.innerHTML = '<div style="font-weight:800;color:#166534;margin-bottom:8px">💼 Aktif Personel Toplam Maliyet ('+aktif.length+' kişi)</div>'
          + '<div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:8px">'
          + '<div style="background:#fff;border-radius:8px;padding:8px;text-align:center"><div style="font-size:10px;color:#64748b">💰 Net Maaş</div><div style="font-weight:800;color:#1d4ed8">'+fmt(topMaas)+' ₺</div></div>'
          + '<div style="background:#fff;border-radius:8px;padding:8px;text-align:center"><div style="font-size:10px;color:#64748b">👷 SGK</div><div style="font-weight:800;color:#b45309">'+fmt(topSgk)+' ₺</div></div>'
          + '<div style="background:#fff;border-radius:8px;padding:8px;text-align:center"><div style="font-size:10px;color:#64748b">📊 Biriken Hak</div><div style="font-weight:800;color:#7c3aed">'+fmt(topBiriken)+' ₺</div></div>'
          + '<div style="background:#166534;border-radius:8px;padding:8px;text-align:center"><div style="font-size:10px;color:#bbf7d0">TOPLAM MALİYET</div><div style="font-weight:800;color:#fff;font-size:15px">'+fmt(topToplam)+' ₺</div></div>'
          + '</div>';
      }

      // refreshFinStats'e personel maliyeti dahil et
      window._perToplamMaliyet = topToplam;
      if(typeof refreshFinStats==='function') try{ refreshFinStats(); }catch(e){}
    }
    window.refreshPersonelMaliyetOzet = refreshPersonelMaliyetOzet;
    setTimeout(refreshPersonelMaliyetOzet, 1000);

    // ── 9. Her ay önceki SGK değeri otomatik carryforward ──────
    // Yeni ay başında uyarı göster — personel sayfasına girildiğinde
    (function checkSgkCarryforward(){
      var now = new Date();
      var thisMonth = now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0');
      var lastChecked = localStorage.getItem('uysa_sgk_check_month')||'';
      if(lastChecked !== thisMonth){
        localStorage.setItem('uysa_sgk_check_month', thisMonth);
        // SGK değeri olmayan personelleri bul
        var personeller = (function(){try{return JSON.parse(localStorage.getItem('uysa_personeller'))||[];}catch(e){return[];}})();
        var eksik = personeller.filter(function(p){ return !p.pasif && (!p.sgk || p.sgk===0); });
        if(eksik.length>0){
          setTimeout(function(){
            if(typeof _toast==='function')
              _toast('ℹ️ '+eksik.length+' personel için SGK maliyeti girilmemiş. Personel sekmesinden güncelleyiniz.','#0b3aa6',6000);
          }, 3000);
        }
      }
    })();

    
    // ═══════════════════════════════════════════════════════════════
    // UYSA v86 — Aylık KDV Raporu + Günlük Üretim KDV Sütunu
    // ═══════════════════════════════════════════════════════════════

    // ── Tab geçiş: Aylık Cari Rapor ──────────────────────────────
    window.switchAylikTab = function(tab){
      ['uretim','kdv'].forEach(function(t){
        var pane = document.getElementById('aylikPane-'+t);
        var btn  = document.getElementById('aylikTab-'+t);
        if(pane) pane.style.display = (t===tab)?'block':'none';
        if(btn){
          if(t===tab){
            btn.style.background='#2563eb'; btn.style.color='#fff';
          } else {
            btn.style.background='#f1f5f9'; btn.style.color='#475569';
          }
        }
      });
      if(tab==='kdv') window.v86RenderAylikKdv();
    };

    // ── Aylık KDV Raporu render ───────────────────────────────────
    window.v86RenderAylikKdv = function(){
      var div = document.getElementById('v86AylikKdvDiv');
      if(!div) return;

      var aySecici = document.getElementById('v77AySecici');
      var buAy = (aySecici && aySecici.value) ? aySecici.value : _thisMonth();

      // Gelirler (bu ayın KDV'li girişleri)
      var gelirler = (function(){try{return JSON.parse(localStorage.getItem('uysa_gelirler'))||[];}catch(e){return[];}})();
      var ayGelirler = gelirler.filter(function(g){
        return (g.tarih||'').startsWith(buAy);
      });

      // Giderler (bu ayın faturalı giderleri)
      var giderler = (function(){try{return JSON.parse(localStorage.getItem('uysa_giderler'))||[];}catch(e){return[];}})();
      var ayGiderler = giderler.filter(function(g){
        return (g.tarih||'').startsWith(buAy) && g.faturali;
      });

      // Günlük üretim kayıtları (KDV oranı kaydedilmişse)
      var gunlukArr = (function(){try{return JSON.parse(localStorage.getItem('uysa_gunluk_uretim'))||[];}catch(e){return[];}})();
      var ayGunluk = gunlukArr.filter(function(u){
        return (u.tarih||'').startsWith(buAy) && u.musteri && u.musteri!=='GENEL';
      });

      // Toplam hesaplar
      var gelirNetToplam  = ayGelirler.reduce(function(s,g){return s+(g.tutar||0);},0);
      var gelirKdvToplam  = ayGelirler.reduce(function(s,g){return s+(g.kdvTutar||0);},0);
      var gelirKdvliToplam = gelirNetToplam + gelirKdvToplam;

      var giderNetToplam  = ayGiderler.reduce(function(s,g){return s+(g.tutar||0);},0);
      var giderKdvToplam  = ayGiderler.reduce(function(s,g){return s+(g.kdvTutar||0);},0);

      // Günlük üretimden KDV
      var gunlukKdvToplam = ayGunluk.reduce(function(s,u){
        var net = u.tutar||0;
        var pct = u.kdvPct||0;
        return s + (net * pct / 100);
      },0);
      var gunlukNetToplam = ayGunluk.reduce(function(s,u){return s+(u.tutar||0);},0);

      // Toplam KDV tahsilatı (gelir + günlük üretim)
      var toplamKdvTahsilat = gelirKdvToplam + gunlukKdvToplam;
      var toplamKdvIndirimi = giderKdvToplam;
      var odenmesiGerekenKdv = Math.max(0, toplamKdvTahsilat - toplamKdvIndirimi);
      var kdvIadesi = Math.max(0, toplamKdvIndirimi - toplamKdvTahsilat);

      // Gelir vergisi hesabı: (KDV'li gelir - faturalı gider) / 4.1
      var gelirVergisiMatrah = gelirKdvliToplam - giderNetToplam;
      var gelirVergisi = gelirVergisiMatrah > 0 ? gelirVergisiMatrah / 4.1 : 0;

      var parts = buAy.split('-');
      var ayAdlari = ['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
      var ayAdi = ayAdlari[parseInt(parts[1],10)-1]+' '+parts[0];

      // KDV oran dağılımı — gelirler
      var gelirOranMap = {};
      ayGelirler.forEach(function(g){
        var pct = g.kdvPct||0;
        if(!gelirOranMap[pct]) gelirOranMap[pct]={net:0,kdv:0,sayi:0};
        gelirOranMap[pct].net  += g.tutar||0;
        gelirOranMap[pct].kdv  += g.kdvTutar||0;
        gelirOranMap[pct].sayi++;
      });

      // Günlük üretim KDV oran dağılımı
      var gunlukOranMap = {};
      ayGunluk.forEach(function(u){
        var pct = u.kdvPct||0;
        var net = u.tutar||0;
        var kdv = net * pct / 100;
        if(!gunlukOranMap[pct]) gunlukOranMap[pct]={net:0,kdv:0,sayi:0};
        gunlukOranMap[pct].net  += net;
        gunlukOranMap[pct].kdv  += kdv;
        gunlukOranMap[pct].sayi++;
      });

      // Gider KDV oran dağılımı
      var giderOranMap = {};
      ayGiderler.forEach(function(g){
        var pct = g.kdvPct||0;
        if(!giderOranMap[pct]) giderOranMap[pct]={net:0,kdv:0,sayi:0};
        giderOranMap[pct].net  += g.tutar||0;
        giderOranMap[pct].kdv  += g.kdvTutar||0;
        giderOranMap[pct].sayi++;
      });

      function _fmtL(n){ return Number(n||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}); }
      function oranSatir(oranMap, renk){
        var html='';
        Object.keys(oranMap).sort(function(a,b){return a-b;}).forEach(function(pct){
          var o=oranMap[pct];
          html+='<tr><td style="padding:7px 10px;color:#64748b">%'+pct+' KDV ('+o.sayi+' kayıt)</td>'
               +'<td style="padding:7px 10px;text-align:right">'+_fmtL(o.net)+' ₺</td>'
               +'<td style="padding:7px 10px;text-align:right;font-weight:700;color:'+renk+'">'+_fmtL(o.kdv)+' ₺</td></tr>';
        });
        return html || '<tr><td colspan="3" style="padding:7px 10px;color:#94a3b8">— Kayıt yok —</td></tr>';
      }

      var html = ''
        // Özet kartlar
        +'<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:16px">'

        +'<div style="background:#eff6ff;border-radius:12px;padding:14px;text-align:center;border:1px solid #c7d2fe">'
        +'<div style="font-size:11px;font-weight:700;color:#3730a3;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">KDV TAHSİLATI</div>'
        +'<div style="font-size:20px;font-weight:900;color:#2563eb">'+_fmtL(toplamKdvTahsilat)+' ₺</div>'
        +'<div style="font-size:11px;color:#64748b;margin-top:2px">Gelir + Üretim KDV</div></div>'

        +'<div style="background:#fff7ed;border-radius:12px;padding:14px;text-align:center;border:1px solid #fed7aa">'
        +'<div style="font-size:11px;font-weight:700;color:#9a3412;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">KDV İNDİRİMİ</div>'
        +'<div style="font-size:20px;font-weight:900;color:#ea580c">'+_fmtL(toplamKdvIndirimi)+' ₺</div>'
        +'<div style="font-size:11px;color:#64748b;margin-top:2px">Faturalı gider KDV</div></div>'

        +(odenmesiGerekenKdv>0
          ?'<div style="background:#fef2f2;border-radius:12px;padding:14px;text-align:center;border:1px solid #fca5a5">'
           +'<div style="font-size:11px;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">ÖDENECEk KDV</div>'
           +'<div style="font-size:20px;font-weight:900;color:#dc2626">'+_fmtL(odenmesiGerekenKdv)+' ₺</div>'
           +'<div style="font-size:11px;color:#64748b;margin-top:2px">Tahsilat − İndirim</div></div>'
          :'<div style="background:#f0fdf4;border-radius:12px;padding:14px;text-align:center;border:1px solid #86efac">'
           +'<div style="font-size:11px;font-weight:700;color:#166534;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">KDV İADE HAKKI</div>'
           +'<div style="font-size:20px;font-weight:900;color:#16a34a">'+_fmtL(kdvIadesi)+' ₺</div>'
           +'<div style="font-size:11px;color:#64748b;margin-top:2px">İndirim − Tahsilat</div></div>'
        )

        +'<div style="background:#fdf4ff;border-radius:12px;padding:14px;text-align:center;border:1px solid #e9d5ff">'
        +'<div style="font-size:11px;font-weight:700;color:#6b21a8;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">GELİR VERGİSİ</div>'
        +'<div style="font-size:20px;font-weight:900;color:#7c3aed">'+_fmtL(gelirVergisi)+' ₺</div>'
        +'<div style="font-size:11px;color:#64748b;margin-top:2px">(KDV&#39;li Gelir−Gider)÷4.1</div></div>'

        +'</div>'

        // Detay tablolar
        +'<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">'

        // Gelir KDV tablosu
        +'<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden">'
        +'<div style="background:#eff6ff;padding:10px 14px;font-weight:800;font-size:13px;color:#1e40af">📥 Gelir KDV Dağılımı</div>'
        +'<table style="width:100%;font-size:12px;border-collapse:collapse">'
        +'<thead><tr style="background:#f8fafc"><th style="padding:7px 10px;text-align:left;color:#64748b;font-weight:600">Oran</th>'
        +'<th style="padding:7px 10px;text-align:right;color:#64748b;font-weight:600">Net</th>'
        +'<th style="padding:7px 10px;text-align:right;color:#64748b;font-weight:600">KDV</th></tr></thead>'
        +'<tbody>'+oranSatir(gelirOranMap,'#2563eb')+'</tbody>'
        +'<tfoot><tr style="background:#eff6ff;font-weight:800">'
        +'<td style="padding:8px 10px">TOPLAM</td>'
        +'<td style="padding:8px 10px;text-align:right">'+_fmtL(gelirNetToplam)+' ₺</td>'
        +'<td style="padding:8px 10px;text-align:right;color:#2563eb">'+_fmtL(gelirKdvToplam)+' ₺</td>'
        +'</tr></tfoot></table></div>'

        // Günlük Üretim KDV tablosu
        +'<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden">'
        +'<div style="background:#fef9c3;padding:10px 14px;font-weight:800;font-size:13px;color:#92400e">🍳 Günlük Üretim KDV Dağılımı</div>'
        +'<table style="width:100%;font-size:12px;border-collapse:collapse">'
        +'<thead><tr style="background:#f8fafc"><th style="padding:7px 10px;text-align:left;color:#64748b;font-weight:600">Oran</th>'
        +'<th style="padding:7px 10px;text-align:right;color:#64748b;font-weight:600">Net</th>'
        +'<th style="padding:7px 10px;text-align:right;color:#64748b;font-weight:600">KDV</th></tr></thead>'
        +'<tbody>'+oranSatir(gunlukOranMap,'#ca8a04')+'</tbody>'
        +'<tfoot><tr style="background:#fef9c3;font-weight:800">'
        +'<td style="padding:8px 10px">TOPLAM</td>'
        +'<td style="padding:8px 10px;text-align:right">'+_fmtL(gunlukNetToplam)+' ₺</td>'
        +'<td style="padding:8px 10px;text-align:right;color:#ca8a04">'+_fmtL(gunlukKdvToplam)+' ₺</td>'
        +'</tr></tfoot></table></div>'

        +'</div>'

        // Gider KDV tablosu
        +'<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:14px">'
        +'<div style="background:#fff7ed;padding:10px 14px;font-weight:800;font-size:13px;color:#9a3412">📤 Gider KDV Dağılımı (Faturalı)</div>'
        +'<table style="width:100%;font-size:12px;border-collapse:collapse">'
        +'<thead><tr style="background:#f8fafc"><th style="padding:7px 10px;text-align:left;color:#64748b;font-weight:600">Oran</th>'
        +'<th style="padding:7px 10px;text-align:right;color:#64748b;font-weight:600">Net</th>'
        +'<th style="padding:7px 10px;text-align:right;color:#64748b;font-weight:600">KDV</th></tr></thead>'
        +'<tbody>'+oranSatir(giderOranMap,'#ea580c')+'</tbody>'
        +'<tfoot><tr style="background:#fff7ed;font-weight:800">'
        +'<td style="padding:8px 10px">TOPLAM</td>'
        +'<td style="padding:8px 10px;text-align:right">'+_fmtL(giderNetToplam)+' ₺</td>'
        +'<td style="padding:8px 10px;text-align:right;color:#ea580c">'+_fmtL(giderKdvToplam)+' ₺</td>'
        +'</tr></tfoot></table></div>'

        // Gelir vergisi açıklaması
        +(gelirVergisiMatrah>0
          ?'<div style="padding:12px 14px;background:#fdf4ff;border:1px solid #e9d5ff;border-radius:10px;font-size:12px;color:#6b21a8">'
           +'<b>📐 Gelir Vergisi Hesabı:</b> ('
           +_fmtL(gelirKdvliToplam)+' ₺ KDV\'li gelir &minus; '+_fmtL(giderNetToplam)+' ₺ faturalı gider) &div; 4.1'
           +' = <b>'+_fmtL(gelirVergisi)+' ₺</b>'
           +'<br><span style="color:#94a3b8;font-size:11px">Matrah: '+_fmtL(gelirVergisiMatrah)+' ₺</span>'
           +'</div>'
          :'<div style="padding:10px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;font-size:12px;color:#166534">'
           +'ℹ️ Giderler geliri aştığı için bu ay gelir vergisi hesaplanmıyor.'
           +'</div>'
        )

        +'<div style="margin-top:10px;padding:8px 12px;background:#f8fafc;border-radius:8px;font-size:11px;color:#94a3b8">'
        +'📅 <b>'+ayAdi+'</b> KDV raporu — v86 tarafından otomatik oluşturuldu'
        +'</div>';

      div.innerHTML = html;
    };

    // ── Günlük Üretim KDV Seçici ─────────────────────────────────
    window._gsKdvPct = 0;
    // Sayfa yüklenince %0 butonunu vurgula
    window.addEventListener('load', function(){
      setTimeout(function(){
        var b0 = document.getElementById('gsKdv0');
        if(b0){ b0.style.background='#2563eb'; b0.style.color='#fff'; b0.style.borderColor='#2563eb'; }
      }, 500);
    });
    window.setGsKdv = function(pct){
      window._gsKdvPct = pct;
      [0,1,10,20].forEach(function(p){
        var btn = document.getElementById('gsKdv'+p);
        if(!btn) return;
        if(p===pct){
          btn.style.background='#2563eb'; btn.style.color='#fff'; btn.style.borderColor='#2563eb';
        } else {
          btn.style.background='#f3f4f6'; btn.style.color='#374151'; btn.style.borderColor='#d1d5db';
        }
      });
      var lbl = document.getElementById('gsKdvAktifLabel');
      if(lbl) lbl.innerHTML = 'Aktif: <b>%'+pct+'</b>';
      // Tabloyu güncelle
      if(typeof window.gsRenderTableV76==='function') window.gsRenderTableV76();
    };

    // ── gsRenderTableV76 override: KDV sütunu ekle (load event ile) ──
    function _applyGsKdvOverride(){
      var _orig = window.gsRenderTableV76;
      if(!_orig){ setTimeout(_applyGsKdvOverride, 300); return; }

      window.gsRenderTableV76 = function(){
        // Önce orijinali çalıştır
        _orig.call(this);

        // Şimdi tabloya KDV sütununu inject et
        var tableDiv = document.getElementById('gsTableDiv');
        if(!tableDiv) return;

        var table = tableDiv.querySelector('table');
        if(!table) return;

        var pct = _gsKdvPct || 0;
        if(pct===0) return; // %0'da ek sütun gerekmez

        // Başlık ekle
        var thead = table.querySelector('thead tr');
        if(thead && !thead.querySelector('.gs-kdv-col')){
          var th = document.createElement('th');
          th.className = 'gs-kdv-col';
          th.innerHTML = 'KDV ('+pct+'%)<br><span style="font-weight:400;font-size:10px;color:#7c3aed">Günlük KDV</span>';
          th.style.cssText = 'padding:10px 12px;text-align:right;border-bottom:2px solid #c7d2fe;background:#fdf4ff;color:#6b21a8;min-width:100px';
          thead.appendChild(th);

          var thKdvli = document.createElement('th');
          thKdvli.className = 'gs-kdv-col';
          thKdvli.innerHTML = 'KDV\'li Toplam<br><span style="font-weight:400;font-size:10px;color:#7c3aed">Net + KDV</span>';
          thKdvli.style.cssText = 'padding:10px 12px;text-align:right;border-bottom:2px solid #c7d2fe;background:#fdf4ff;color:#6b21a8;min-width:110px';
          thead.appendChild(thKdvli);
        }

        // Her satıra KDV hesapla
        var rows = table.querySelectorAll('tbody tr');
        rows.forEach(function(row){
          if(row.querySelector('.gs-kdv-col')) return; // zaten eklenmiş
          var gunlukSpan = row.querySelector('.gsv76-gunluk');
          var netVal = 0;
          if(gunlukSpan){
            var txt = gunlukSpan.textContent.replace(/\./g,'').replace(',','.').replace(/[^\d.]/g,'');
            netVal = parseFloat(txt)||0;
          }
          var kdvTutar = netVal * pct / 100;
          var kdvliToplam = netVal + kdvTutar;

          var tdKdv = document.createElement('td');
          tdKdv.className = 'gs-kdv-col';
          tdKdv.style.cssText = 'padding:9px 12px;text-align:right;border-bottom:1px solid #e2e8f0;color:#7c3aed;font-weight:700;background:#fdf4ff';
          tdKdv.textContent = kdvTutar>0 ? kdvTutar.toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' ₺' : '—';
          row.appendChild(tdKdv);

          var tdKdvli = document.createElement('td');
          tdKdvli.className = 'gs-kdv-col';
          tdKdvli.style.cssText = 'padding:9px 12px;text-align:right;border-bottom:1px solid #e2e8f0;color:#581c87;font-weight:800;background:#faf5ff';
          tdKdvli.textContent = kdvliToplam>0 ? kdvliToplam.toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' ₺' : '—';
          row.appendChild(tdKdvli);
        });

        

        // KDV footer toplam satiri
        var tfoot = table.querySelector('tfoot');
        if(!tfoot){ tfoot = document.createElement('tfoot'); table.appendChild(tfoot); }
        var existFr = tfoot.querySelector('.gs-kdv-footer');
        if(existFr) existFr.remove();
        var netTotal = 0;
        table.querySelectorAll('tbody tr').forEach(function(row){
          var sp = row.querySelector('.gsv76-gunluk');
          if(sp){ var t=sp.textContent.replace(/[.]/g,'').replace(',','.').replace(/[^\d.]/g,''); netTotal+=parseFloat(t)||0; }
        });
        if(netTotal > 0){
          var kdvTotal   = netTotal * pct / 100;
          var kdvliTotal = netTotal + kdvTotal;
          var colCount   = thead ? thead.querySelectorAll('th').length : 6;
          var _fmtN = function(n){ return Number(n||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}); };
          var footRow = document.createElement('tr');
          footRow.className = 'gs-kdv-footer';
          footRow.style.cssText = 'background:#fdf4ff;border-top:3px solid #a855f7';
          var tdLbl = document.createElement('td');
          tdLbl.colSpan = Math.max(1, colCount - 3);
          tdLbl.style.cssText = 'padding:10px 12px;text-align:right;color:#6b21a8;font-weight:800;font-size:13px';
          tdLbl.textContent = 'Gunluk Toplam (KDV Dahil):';
          footRow.appendChild(tdLbl);
          var tdNet = document.createElement('td');
          tdNet.style.cssText = 'padding:10px 12px;text-align:right;color:#166534;font-weight:800;font-size:13px;border-left:1px solid #e9d5ff';
          tdNet.innerHTML = _fmtN(netTotal) + ' ₺<br><span style="font-size:10px;color:#64748b;font-weight:400">Net (KDVsiz)</span>';
          footRow.appendChild(tdNet);
          var tdKdv = document.createElement('td');
          tdKdv.style.cssText = 'padding:10px 12px;text-align:right;color:#7c3aed;font-weight:800;font-size:13px;border-left:1px solid #e9d5ff;background:#f5f3ff';
          tdKdv.innerHTML = _fmtN(kdvTotal) + ' ₺<br><span style="font-size:10px;color:#64748b;font-weight:400">+%' + pct + ' KDV</span>';
          footRow.appendChild(tdKdv);
          var tdKdvli = document.createElement('td');
          tdKdvli.style.cssText = 'padding:10px 12px;text-align:right;color:#4a148c;font-weight:900;font-size:14px;border-left:2px solid #a855f7;background:#ede9fe';
          tdKdvli.innerHTML = _fmtN(kdvliTotal) + ' ₺<br><span style="font-size:10px;color:#64748b;font-weight:400">KDV Dahil Toplam</span>';
          footRow.appendChild(tdKdvli);
          tfoot.appendChild(footRow);
        }

        // Günlük üretim kayıtlarına kdvPct kaydet
        var saveBtn = document.getElementById('gsKaydetBtn');
        if(saveBtn && !saveBtn._kdvPatchV86){
          saveBtn._kdvPatchV86 = true;
          saveBtn.addEventListener('click', function(){
            // Kısa gecikmeyle kaydedildikten sonra kdvPct'yi güncelle
            setTimeout(function(){
              var tarih = document.getElementById('gsTableDiv')?.dataset.tarih;
              if(!tarih) return;
              var arr = (function(){try{return JSON.parse(localStorage.getItem('uysa_gunluk_uretim'))||[];}catch(e){return[];}})();
              var updated = arr.map(function(u){
                if(u.tarih===tarih) return Object.assign({},u,{kdvPct:_gsKdvPct, kdvTutar:(u.tutar||0)*_gsKdvPct/100});
                return u;
              });
              try{localStorage.setItem('uysa_gunluk_uretim', JSON.stringify(updated));}catch(e){}
              // Aylık KDV raporu güncelle
              if(typeof window.v86RenderAylikKdv==='function'){
                var kdvPane = document.getElementById('aylikPane-kdv');
                if(kdvPane && kdvPane.style.display!=='none') window.v86RenderAylikKdv();
              }
            }, 500);
          }, true);
        }
      };
    }
    // Override'ı hemen + load sonrası çalıştır
    _applyGsKdvOverride();
    window.addEventListener('load', function(){ setTimeout(_applyGsKdvOverride, 200); });

    // ── Ay değiştiğinde KDV tab'ını da güncelle ──────────────────
    window.addEventListener('load', function(){
      setTimeout(function(){
        var aySecici = document.getElementById('v77AySecici');
        if(aySecici){
          // Ay değişince KDV paneyi de güncelle
          aySecici.addEventListener('change', function(){
            if(typeof window.v86RenderAylikKdv==='function') window.v86RenderAylikKdv();
          });
          // Varsayılan ay ayarla
          if(!aySecici.value){
            var now = new Date();
            aySecici.value = now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0');
          }
        }
        // Aylık Cari Rapor paneli göründüğünde KDV sekmesini de tetikle
        var origV75 = window.v75RenderAylikRapor;
        if(origV75 && !origV75._kdvHooked){
          window.v75RenderAylikRapor = function(){
            origV75.apply(this, arguments);
            var kdvPane = document.getElementById('aylikPane-kdv');
            if(kdvPane && kdvPane.style.display!=='none'){
              if(typeof window.v86RenderAylikKdv==='function') window.v86RenderAylikKdv();
            }
          };
          window.v75RenderAylikRapor._kdvHooked = true;
        }
      }, 800);
    });


    console.log('✅ UYSA v86 yüklendi — KDV Sistemi + SGK + Biriken Haklar + Aylık KDV Raporu + Üretim KDV Sütunu aktif');
  })();

