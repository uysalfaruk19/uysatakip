(function(){
  'use strict';

  /* ── localStorage yardımcıları ────────────────────────────────── */
  var _ls = {
    get: function(k, def){ try{ var v=localStorage.getItem(k); return v!==null?JSON.parse(v):def; }catch(e){ return def; } },
    set: function(k, v){ try{ localStorage.setItem(k,JSON.stringify(v)); }catch(e){} }
  };

  function _thisMonth(){
    var d=new Date(); var m=String(d.getMonth()+1).padStart(2,'0');
    return d.getFullYear()+'-'+m;
  }

  function _fmtTL(n){ return Number(n||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}); }

  /* ── Gider CRUD (v78 ile uyumlu) ──────────────────────────────── */
  function _getGiderler(){ return _ls.get('uysa_giderler',[]); }
  function _saveGiderler(arr){ _ls.set('uysa_giderler',arr); }

  /* ── Personel CRUD ─────────────────────────────────────────────── */
  function _getPersoneller(){ return _ls.get('uysa_personeller',[]); }

  /* ── Puantaj CRUD ──────────────────────────────────────────────── */
  function _puantajKey(ad,ay){ return 'uysa_puantaj_'+ay+'_'+ad; }
  function _getPuantaj(ad,ay){ return _ls.get(_puantajKey(ad,ay),{}); }

  /* ── İş günü hesabı ────────────────────────────────────────────── */
  function _isGunSay(yil, ay_num){
    var gunSay = new Date(yil,ay_num,0).getDate();
    var cnt=0;
    for(var g=1;g<=gunSay;g++){
      var hg=new Date(yil,ay_num-1,g).getDay();
      if(hg!==0&&hg!==6) cnt++;
    }
    return cnt;
  }

  /* ── Personel net ödeme hesapla ────────────────────────────────── */
  function _hesaplaNet(p, ay){
    var ad = p.ad+' '+p.soyad;
    var maas = parseFloat(p.maas)||0;
    if(!maas) return { ad:ad, maas:0, net:0, kesinti:0, ucretsiz:0, devsz:0, yari:0 };

    var parts=ay.split('-');
    var yil=parseInt(parts[0]); var ay_num=parseInt(parts[1]);
    var isGs = _isGunSay(yil,ay_num);
    var kayit = _getPuantaj(ad, ay);

    var ucretsiz=0, devsz=0, yari=0;
    Object.values(kayit).forEach(function(v){
      if(v==='ucretsiz') ucretsiz++;
      else if(v==='devsz') devsz++;
      else if(v==='yari') yari++;
    });

    var kesilecek = ucretsiz + devsz + yari*0.5;
    var gunluk = isGs>0 ? maas/isGs : 0;
    var kesilenTutar = gunluk * kesilecek;
    var net = maas - kesilenTutar;

    return { ad:ad, maas:maas, net:net, kesinti:kesilenTutar, ucretsiz:ucretsiz, devsz:devsz, yari:yari };
  }

  /* ══════════════════════════════════════════════════════════════════
     ANA FONKSİYON: Finansa Aktar
     ══════════════════════════════════════════════════════════════════ */
  window.v79FinansaAktar = function(){
    var ayEl = document.getElementById('topluBordro-ay');
    var ay   = ayEl ? ayEl.value : _thisMonth();
    if(!ay){ alert('Lütfen ay seçin.'); return; }

    var pers = _getPersoneller();
    if(!pers.length){ alert('Personel kaydı yok.'); return; }

    var giderler = _getGiderler();

    /* Duplicate kontrol: aynı ay için zaten aktarılmış kayıtları bul */
    var mevcutAktarim = giderler.filter(function(g){
      return g.kat==='personel-maas' && g.tarih && g.tarih.startsWith(ay);
    });

    if(mevcutAktarim.length > 0){
      var toplam = mevcutAktarim.reduce(function(s,g){ return s+(g.tutar||0); }, 0);
      var conf = confirm(
        '⚠️ ' + ay + ' ayı için daha önce ' + mevcutAktarim.length +
        ' kayıt aktarılmış (Toplam: ' + _fmtTL(toplam) + ' ₺).\n\n' +
        'Tekrar aktarmak eski kayıtları SİLER ve yeniden oluşturur.\n' +
        'Devam etmek istiyor musunuz?'
      );
      if(!conf) return;
      // Eski aktarımı sil
      giderler = giderler.filter(function(g){
        return !(g.kat==='personel-maas' && g.tarih && g.tarih.startsWith(ay));
      });
    }

    /* Her personel için net ödeme hesapla ve gider olarak ekle */
    var parts = ay.split('-');
    var yil = parts[0]; var ay_num = parseInt(parts[1]);
    var ayAdlari=['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran',
                  'Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
    var ayAdi = ayAdlari[ay_num-1];
    var tarih = ay + '-01'; // ay'ın 1'i olarak kaydet

    var eklenenler = [];
    var toplamNet = 0;

    pers.forEach(function(p){
      var h = _hesaplaNet(p, ay);
      if(h.net <= 0) return; // maassız personeli atla

      var aciklama = h.ad + ' — ' + ayAdi + ' ' + yil + ' maaş ödemesi';
      if(h.kesinti > 0){
        aciklama += ' (Kesinti: -' + _fmtTL(h.kesinti) + ' ₺';
        if(h.ucretsiz) aciklama += ', Ücretsiz İzin: '+h.ucretsiz+'g';
        if(h.devsz)    aciklama += ', Devamsız: '+h.devsz+'g';
        if(h.yari)     aciklama += ', Yarım: '+h.yari+'g';
        aciklama += ')';
      }

      giderler.push({
        kat      : 'personel-maas',
        tarih    : tarih,
        tutar    : Math.round(h.net * 100) / 100,
        aciklama : aciklama,
        belge    : 'BORDRO-' + ay + '-' + h.ad.replace(/\s+/g,'-').toUpperCase(),
        _kaynak  : 'v79-ik-otomatik'
      });

      eklenenler.push(h);
      toplamNet += h.net;
    });

    _saveGiderler(giderler);

    /* Durum bandını güncelle */
    var durumDiv = document.getElementById('v79AktarDurum');
    if(durumDiv){
      durumDiv.style.display = '';
      durumDiv.innerHTML =
        '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:12px 16px;font-size:13px">'
        +'<span style="font-size:18px">✅</span> '
        +'<b>' + eklenenler.length + ' personel</b> için <b>' + _fmtTL(toplamNet) + ' ₺</b> '
        +'maaş ödemesi Finans → Giderler\'e (<b>💰 Maaş Ödemesi</b> kategorisi) aktarıldı. '
        +'<span style="color:#16a34a;font-weight:700">' + ayAdi + ' ' + yil + '</span>'
        +'<br><span style="color:#64748b;font-size:11px;margin-top:4px;display:block">'
        + eklenenler.map(function(h){ return h.ad+': '+_fmtTL(h.net)+' ₺'; }).join(' · ')
        +'</span>'
        +'</div>';
    }

    /* Finans modülü açıksa giderleri yenile */
    try {
      if(typeof renderGiderler === 'function') renderGiderler();
      if(typeof refreshFinStats === 'function') refreshFinStats();
    } catch(e){}

    /* Toplu bordro tablosunu yenile */
    try{
      if(typeof renderTopluBordro === 'function') renderTopluBordro();
    } catch(e){}

    /* Bildirim toast */
    _v79Toast('✅ ' + eklenenler.length + ' maaş kaydı Finans\'a aktarıldı — Toplam: ' + _fmtTL(toplamNet) + ' ₺', '#16a34a');
  };

  /* ── Mini toast ───────────────────────────────────────────────── */
  function _v79Toast(msg, bg){
    bg = bg || '#1e293b';
    var t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:'+bg+
      ';color:#fff;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;'+
      'z-index:99999;box-shadow:0 4px 16px rgba(0,0,0,.3);max-width:420px;'+
      'animation:fadeIn .3s ease';
    document.body.appendChild(t);
    setTimeout(function(){ if(t.parentNode) t.parentNode.removeChild(t); }, 4000);
  }

  /* ══════════════════════════════════════════════════════════════════
     Toplu Bordro tablosuna "Finansa Aktarıldı mı?" sütunu ekle
     renderTopluBordro'yu override et
     ══════════════════════════════════════════════════════════════════ */
  var _origRenderToplu = window.renderTopluBordro;
  window.renderTopluBordro = function(){
    /* Önce orijinali çalıştır */
    if(_origRenderToplu) _origRenderToplu.call(this);

    /* Tablonun altına aktarım durumu mini özet ekle */
    var ayEl = document.getElementById('topluBordro-ay');
    var ay   = ayEl ? ayEl.value : _thisMonth();
    var div  = document.getElementById('topluBordroDiv');
    if(!div || !ay) return;

    var giderler = _getGiderler();
    var aktarimlar = giderler.filter(function(g){
      return g.kat==='personel-maas' && g.tarih && g.tarih.startsWith(ay);
    });
    var aktarilanToplam = aktarimlar.reduce(function(s,g){ return s+(g.tutar||0); }, 0);

    /* Eski özet kartını sil */
    var eskiOzet = document.getElementById('v79BordroAltOzet');
    if(eskiOzet) eskiOzet.parentNode.removeChild(eskiOzet);

    var ozet = document.createElement('div');
    ozet.id = 'v79BordroAltOzet';

    if(aktarimlar.length > 0){
      var parts = ay.split('-'); var ay_num=parseInt(parts[1]);
      var ayAdlari=['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran',
                    'Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
      var ayAdi = ayAdlari[ay_num-1];
      ozet.innerHTML =
        '<div style="margin-top:12px;background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:12px 16px;font-size:13px">'
        +'<span style="font-size:16px">✅</span> '
        +'<b>' + ayAdi + ' ' + parts[0] + '</b> dönemi için <b>'
        + aktarimlar.length + ' personel</b> — Toplam <b style="color:#166534">'
        + _fmtTL(aktarilanToplam) + ' ₺</b> Finans\'a aktarıldı.'
        +'<br><span style="color:#64748b;font-size:11px">'
        + aktarimlar.map(function(g){ return g.aciklama.split('—')[0].trim(); }).join(', ')
        +'</span></div>';
    } else {
      ozet.innerHTML =
        '<div style="margin-top:12px;background:#fefce8;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;font-size:13px">'
        +'⚠️ Bu ay henüz Finans\'a aktarım yapılmamış. '
        +'<a href="#" onclick="v79FinansaAktar();return false;" style="color:#2563eb;font-weight:700">📤 Şimdi Aktar</a>'
        +'</div>';
    }

    div.parentNode.insertBefore(ozet, div.nextSibling);
  };

  /* ══════════════════════════════════════════════════════════════════
     Finans Gider sekmesi: Personel/Maaş filtresi için katLabel ekle
     ══════════════════════════════════════════════════════════════════ */
  /* giderKatLabel nesnesini güvenli şekilde genişlet */
  setTimeout(function(){
    try {
      /* v78'de giderKatLabel global değil, fonksiyon kapsamında.
         Burada renderGiderler'i wrap ederek label'ı inject edeceğiz. */
      var _origRenderGiderler = window.renderGiderler;
      if(_origRenderGiderler && typeof _origRenderGiderler === 'function'){
        window.renderGiderler = function(filtreKat){
          /* personel-maas için özel label yaması — fonksiyon içi giderKatLabel
             erişilemez olduğundan DOM'a render sonrası düzeltiyoruz */
          _origRenderGiderler.apply(this, arguments);
          /* Etiket düzeltme: "undefined" veya "personel-maas" göründüyse düzelt */
          var listDiv = document.getElementById('giderListDiv');
          if(listDiv){
            listDiv.innerHTML = listDiv.innerHTML
              .replace(/personel-maas/g, '💰 Maaş Ödemesi')
              .replace(/>undefined</g, '>💰 Maaş Ödemesi<');
          }
        };
      }
    } catch(e){}
  }, 800);

  /* ══════════════════════════════════════════════════════════════════
     Finans Dashboard: Personel gider kartına maaş-maas dahil olsun
     (uysa_giderler zaten 'personel-maas' ile kaydediliyor,
      renderFinStats giderleri filtrelerken tüm kategorileri topluyor — OK)
     ══════════════════════════════════════════════════════════════════ */

  /* ── Sayfa yüklendiğinde toplu bordroyu render et ─────────────── */
  window.addEventListener('load', function(){
    setTimeout(function(){
      try{ if(typeof renderTopluBordro==='function') renderTopluBordro(); }catch(e){}
    }, 600);
  });

  console.log('[UYSA v79] Bordro→Finans aktarım modülü yüklendi.');
})();
