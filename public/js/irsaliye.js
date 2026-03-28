// ═══════════════════════════════════════════════════════════════
// İRSALİYE MODÜLÜ — Teslimat Belgesi Oluşturma
// Menü → Otomatik İrsaliye akışı
// ═══════════════════════════════════════════════════════════════

(function(){
  'use strict';

  var _ls = {
    get: function(k,d){ try{ var v=localStorage.getItem(k); return v?JSON.parse(v):d; }catch(e){ return d; } },
    set: function(k,v){ try{ localStorage.setItem(k, JSON.stringify(v)); }catch(e){} }
  };

  var IRS_KEY = 'uysa_irsaliyeler';
  var IRS_NO_KEY = 'uysa_irsaliye_no';

  function getIrsaliyeler(){ return _ls.get(IRS_KEY, []); }
  function saveIrsaliyeler(d){ _ls.set(IRS_KEY, d); }

  function nextIrsNo(){
    var no = _ls.get(IRS_NO_KEY, 1000);
    _ls.set(IRS_NO_KEY, no+1);
    return 'IRS-' + no;
  }

  function fmt(n){ return Number(n||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function bugun(){ return new Date().toISOString().slice(0,10); }

  // İrsaliye müşteri dropdown'ını doldur
  function fillIrsMusteri(){
    var sel = document.getElementById('irsaliyeMusteri');
    if(!sel) return;
    try{
      var raw = JSON.parse(localStorage.getItem('uysa_customers_v1')||'{}');
      var custs = (raw.customers||[]).filter(function(c){ return c && c!=='GENEL'; });
      sel.innerHTML = '<option value="">— Seçiniz —</option>' + custs.map(function(c){ return '<option value="'+c+'">'+c+'</option>'; }).join('');
    }catch(e){}
  }

  // Menüden otomatik çek
  window.irsaliyeMenudenCek = function(){
    var tarih = document.getElementById('irsaliyeTarih')?.value;
    var musteri = document.getElementById('irsaliyeMusteri')?.value;
    if(!tarih){ alert('Önce tarih seçin.'); return; }

    // Menüden yemekleri çek (rc.getDayMenu)
    var dishes = [];
    if(typeof window._rc !== 'undefined' && typeof window._rc.getDayMenu === 'function'){
      dishes = window._rc.getDayMenu(tarih) || [];
    }

    if(!dishes.length){
      alert('Bu tarih için menü bulunamadı. Menü modülünden önce menüyü girin.');
      return;
    }

    // CRM'den kişi sayısını çek
    if(musteri){
      var crm = _ls.get('uysa_crm_'+musteri, {});
      var kisiInp = document.getElementById('irsaliyeKisi');
      if(kisiInp && crm.kisi) kisiInp.value = crm.kisi;
    }

    // Önizleme
    var kisi = parseInt(document.getElementById('irsaliyeKisi')?.value) || 0;
    _renderIrsaliyeOnizleme(musteri||'—', tarih, dishes, kisi);
  };

  // İrsaliye oluştur
  window.irsaliyeOlustur = function(){
    var musteri = document.getElementById('irsaliyeMusteri')?.value;
    var tarih = document.getElementById('irsaliyeTarih')?.value;
    var ogun = document.getElementById('irsaliyeOgun')?.value || 'ogle';
    var kisi = parseInt(document.getElementById('irsaliyeKisi')?.value) || 0;

    if(!musteri){ alert('Müşteri seçiniz.'); return; }
    if(!tarih){ alert('Tarih seçiniz.'); return; }
    if(kisi<=0){ alert('Kişi sayısı giriniz.'); return; }

    // Menüden yemekleri çek
    var dishes = [];
    if(typeof window._rc !== 'undefined' && typeof window._rc.getDayMenu === 'function'){
      dishes = window._rc.getDayMenu(tarih) || [];
    }

    if(!dishes.length){
      if(!confirm('Bu tarih için menü bulunamadı. Manuel irsaliye oluşturmak ister misiniz?')) return;
      var manuelYemek = prompt('Yemek listesi (virgülle ayırın):');
      if(manuelYemek) dishes = manuelYemek.split(',').map(function(x){ return x.trim(); }).filter(Boolean);
    }

    // CRM bilgileri
    var crm = _ls.get('uysa_crm_'+musteri, {});
    var porsiyonKatsayi = parseFloat(crm.porsiyonKatsayi) || 1.0;
    var ogunLabels = {'ogle':'Öğle','aksam':'Akşam','sabah':'Kahvaltı','gece':'Gece'};

    var irsNo = nextIrsNo();
    var irsaliye = {
      no: irsNo,
      musteri: musteri,
      tarih: tarih,
      ogun: ogun,
      ogunLabel: ogunLabels[ogun]||ogun,
      kisi: kisi,
      porsiyonKatsayi: porsiyonKatsayi,
      yemekler: dishes,
      servisConfig: {
        anaYemek: crm.anaYemek||'1',
        yardimci: crm.yardimci||'1',
        corba: crm.corba||'1',
        salata: crm.salata||'1',
        ekmek: crm.ekmek||'roll'
      },
      olusturma: new Date().toISOString(),
      durum: 'hazirlaniyor'
    };

    var arr = getIrsaliyeler();
    arr.push(irsaliye);
    saveIrsaliyeler(arr);

    _renderIrsaliyeOnizleme(musteri, tarih, dishes, kisi, irsNo, porsiyonKatsayi);
    alert('✅ İrsaliye oluşturuldu!\n\nİrsaliye No: '+irsNo+'\nMüşteri: '+musteri+'\nTarih: '+tarih+'\nÖğün: '+ogunLabels[ogun]+'\nKişi: '+kisi);
  };

  function _renderIrsaliyeOnizleme(musteri, tarih, dishes, kisi, irsNo, katsayi){
    var div = document.getElementById('irsaliyeOnizlemeDiv');
    if(!div) return;
    var no = irsNo || 'ÖNİZLEME';
    var kat = katsayi || 1.0;

    // Tarih formatlama
    var parts = tarih.split('-');
    var gunler = ['Pazar','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi'];
    var dt = new Date(parseInt(parts[0]), parseInt(parts[1])-1, parseInt(parts[2]));
    var tarihStr = parts[2]+'.'+parts[1]+'.'+parts[0]+' '+gunler[dt.getDay()];

    div.innerHTML = '<div style="border:2px solid #0891b2;border-radius:12px;padding:16px;background:#fff">'
      +'<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px">'
      +'<div><h3 style="margin:0;color:#0e7490;font-size:18px">📜 İRSALİYE</h3>'
      +'<div style="font-size:11px;color:#64748b;margin-top:2px">UYSA Yemek Üretim</div></div>'
      +'<div style="text-align:right"><div style="font-size:16px;font-weight:900;color:#0e7490">'+no+'</div>'
      +'<div style="font-size:11px;color:#64748b">'+tarihStr+'</div></div>'
      +'</div>'
      +'<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;padding:10px;background:#f0fdfa;border-radius:8px">'
      +'<div><b>Müşteri:</b> '+musteri+'</div>'
      +'<div><b>Kişi Sayısı:</b> '+kisi+(kat!==1?' (×'+kat.toFixed(2)+' porsiyon)':'')+'</div>'
      +'</div>'
      +'<table class="monthTable" style="width:100%"><thead><tr style="background:#0891b2;color:#fff"><th>#</th><th>Yemek Adı</th><th>Porsiyon</th></tr></thead>'
      +'<tbody>'+ dishes.map(function(d,i){
        return '<tr><td style="width:40px;text-align:center">'+(i+1)+'</td><td><b>'+d+'</b></td><td style="text-align:center">'+kisi+'</td></tr>';
      }).join('') +'</tbody></table>'
      +'<div style="display:flex;gap:8px;margin-top:12px">'
      +(irsNo ? '<button class="btn" onclick="irsaliyeYazdir(\''+irsNo+'\')" style="background:#0891b2;color:#fff;border:none;font-weight:700">🖨️ İrsaliyeyi Yazdır</button>' : '')
      +'</div></div>';
  }

  // İrsaliye yazdır
  window.irsaliyeYazdir = function(irsNo){
    var arr = getIrsaliyeler();
    var irs = arr.find(function(i){ return i.no===irsNo; });
    if(!irs){ alert('İrsaliye bulunamadı.'); return; }

    var parts = irs.tarih.split('-');
    var gunler = ['Pazar','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi'];
    var dt = new Date(parseInt(parts[0]), parseInt(parts[1])-1, parseInt(parts[2]));
    var tarihStr = parts[2]+'.'+parts[1]+'.'+parts[0]+' '+gunler[dt.getDay()];

    var w = window.open('','_blank','width=700,height=600');
    w.document.write('<!DOCTYPE html><html><head><title>İrsaliye — '+irsNo+'</title>'
      +'<style>body{font-family:Arial,sans-serif;padding:30px;font-size:13px}'
      +'h2{text-align:center;color:#0e7490;margin-bottom:5px}'
      +'.sub{text-align:center;font-size:11px;color:#64748b;margin-bottom:20px}'
      +'table{width:100%;border-collapse:collapse;margin:15px 0}th,td{padding:8px 10px;border:1px solid #d1d5db;text-align:left}'
      +'th{background:#0891b2;color:#fff}.info{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:15px 0;padding:12px;border:1px solid #e5e7eb;border-radius:8px}'
      +'.sign{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:40px;padding-top:20px;border-top:1px dashed #ccc}'
      +'.sign div{text-align:center;padding-top:40px;border-top:1px solid #333;font-size:12px}'
      +'@media print{body{padding:15px}}</style></head><body>'
      +'<h2>📜 İRSALİYE</h2>'
      +'<div class="sub">UYSA Yemek Üretim Sistemi | '+irsNo+'</div>'
      +'<div class="info">'
      +'<div><b>Müşteri:</b> '+irs.musteri+'</div>'
      +'<div><b>Tarih:</b> '+tarihStr+'</div>'
      +'<div><b>Öğün:</b> '+(irs.ogunLabel||irs.ogun)+'</div>'
      +'<div><b>Kişi Sayısı:</b> '+irs.kisi+'</div>'
      +'</div>'
      +'<table><thead><tr><th style="width:40px">#</th><th>Yemek Adı</th><th style="width:100px;text-align:center">Porsiyon</th></tr></thead>'
      +'<tbody>'+irs.yemekler.map(function(d,i){
        return '<tr><td style="text-align:center">'+(i+1)+'</td><td>'+d+'</td><td style="text-align:center">'+irs.kisi+'</td></tr>';
      }).join('')+'</tbody></table>'
      +'<div class="sign"><div>Teslim Eden</div><div>Teslim Alan</div></div>'
      +'</body></html>');
    w.document.close();
    setTimeout(function(){ w.print(); },500);
  };

  // Geçmiş irsaliyeler listesi
  window.irsaliyeListGoster = function(){
    var arr = getIrsaliyeler();
    if(!arr.length){ alert('Henüz irsaliye kaydı yok.'); return; }
    var div = document.getElementById('irsaliyeOnizlemeDiv');
    if(!div) return;
    var son = arr.slice(-20).reverse();
    div.innerHTML = '<h4 style="color:#0e7490">📋 Son İrsaliyeler</h4>'
      +'<table class="monthTable" style="width:100%"><thead><tr><th>İrsaliye No</th><th>Müşteri</th><th>Tarih</th><th>Öğün</th><th>Kişi</th><th>Yemek</th><th>İşlem</th></tr></thead>'
      +'<tbody>'+son.map(function(irs){
        return '<tr>'
          +'<td style="font-weight:700;color:#0891b2">'+irs.no+'</td>'
          +'<td><b>'+irs.musteri+'</b></td>'
          +'<td>'+irs.tarih+'</td>'
          +'<td>'+(irs.ogunLabel||irs.ogun)+'</td>'
          +'<td>'+irs.kisi+'</td>'
          +'<td style="font-size:11px">'+irs.yemekler.join(', ')+'</td>'
          +'<td><button onclick="irsaliyeYazdir(\''+irs.no+'\')" style="font-size:10px;padding:2px 8px;border:1px solid #0891b2;background:#ecfeff;border-radius:4px;cursor:pointer">🖨️</button></td>'
          +'</tr>';
      }).join('')+'</tbody></table>';
  };

  // Init
  setTimeout(function(){
    fillIrsMusteri();
    var tarihInp = document.getElementById('irsaliyeTarih');
    if(tarihInp && !tarihInp.value) tarihInp.value = bugun();
  }, 2000);

})();
