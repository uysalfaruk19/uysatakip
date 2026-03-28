// ═══════════════════════════════════════════════════════════════
// MESAİ TAKİP — Giriş/Çıkış Saatleri & Fazla Mesai Hesabı
// ═══════════════════════════════════════════════════════════════

(function(){
  'use strict';

  var _ls = {
    get: function(k,d){ try{ var v=localStorage.getItem(k); return v?JSON.parse(v):d; }catch(e){ return d; } },
    set: function(k,v){ try{ localStorage.setItem(k, JSON.stringify(v)); }catch(e){} }
  };

  var MESAI_KEY = 'uysa_mesai_kayitlari';
  var STANDART_SAAT = 8; // Günlük standart çalışma saati (mola hariç)

  function getMesaiKayitlari(){ return _ls.get(MESAI_KEY, []); }
  function saveMesaiKayitlari(d){ _ls.set(MESAI_KEY, d); }

  function fmt(n){ return Number(n||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function bugun(){ return new Date().toISOString().slice(0,10); }
  function buAy(){ var d=new Date(); return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0'); }

  // Saat farkı hesapla (saat olarak)
  function saatFarki(giris, cikis, molaDk){
    if(!giris || !cikis) return 0;
    var gParts = giris.split(':'), cParts = cikis.split(':');
    var gDk = parseInt(gParts[0])*60 + parseInt(gParts[1]);
    var cDk = parseInt(cParts[0])*60 + parseInt(cParts[1]);
    if(cDk < gDk) cDk += 24*60; // gece vardiyası
    var netDk = cDk - gDk - (molaDk||0);
    return Math.max(0, netDk/60);
  }

  // Personel dropdown doldur
  function fillMesaiPersonel(){
    var sel = document.getElementById('mesaiPersonel');
    if(!sel) return;
    try{
      var personeller = _ls.get('uysa_personeller',[]);
      sel.innerHTML = '<option value="">— Personel seçin —</option>'
        + personeller.map(function(p){ var ad=p.ad+' '+p.soyad; return '<option value="'+ad+'">'+ad+'</option>'; }).join('');
    }catch(e){}
  }

  // Mesai kaydet
  window.mesaiKaydet = function(){
    var personel = document.getElementById('mesaiPersonel')?.value;
    if(!personel){ alert('Personel seçin.'); return; }
    var tarih = document.getElementById('mesaiTarih')?.value;
    if(!tarih){ alert('Tarih seçin.'); return; }
    var giris = document.getElementById('mesaiGiris')?.value || '08:00';
    var cikis = document.getElementById('mesaiCikis')?.value || '17:00';
    var mola = parseInt(document.getElementById('mesaiMola')?.value) || 60;
    var not = document.getElementById('mesaiNot')?.value || '';

    var netSaat = saatFarki(giris, cikis, mola);
    var fazlaMesai = Math.max(0, netSaat - STANDART_SAAT);

    var arr = getMesaiKayitlari();

    // Aynı gün/personel varsa güncelle
    var idx = arr.findIndex(function(m){ return m.personel===personel && m.tarih===tarih; });
    var kayit = {
      personel: personel,
      tarih: tarih,
      giris: giris,
      cikis: cikis,
      molaDk: mola,
      netSaat: netSaat,
      fazlaMesai: fazlaMesai,
      not: not,
      kaydedilme: new Date().toISOString()
    };

    if(idx>=0) arr[idx] = kayit;
    else arr.push(kayit);
    saveMesaiKayitlari(arr);

    renderMesaiTablosu();
    document.getElementById('mesaiNot').value = '';
  };

  // Mesai tablosu render
  window.renderMesaiTablosu = function(){
    var div = document.getElementById('mesaiTabloDiv');
    var ozetDiv = document.getElementById('mesaiOzetDiv');
    if(!div) return;

    var personel = document.getElementById('mesaiPersonel')?.value;
    var ay = document.getElementById('mesaiAy')?.value || buAy();
    if(!personel){
      div.innerHTML='<div style="color:var(--muted);text-align:center;padding:20px">Personel ve ay seçin.</div>';
      if(ozetDiv) ozetDiv.innerHTML='';
      return;
    }

    var all = getMesaiKayitlari();
    var filtered = all.filter(function(m){
      return m.personel===personel && (m.tarih||'').startsWith(ay);
    }).sort(function(a,b){ return a.tarih<b.tarih?-1:1; });

    if(!filtered.length){
      div.innerHTML='<div style="color:var(--muted);text-align:center;padding:20px">Bu dönemde kayıt yok. Yukarıdan giriş/çıkış kaydı ekleyin.</div>';
      if(ozetDiv) ozetDiv.innerHTML='';
      return;
    }

    var topNet=0, topFazla=0, topGun=filtered.length;
    var gunler=['Paz','Pzt','Sal','Çar','Per','Cum','Cmt'];

    div.innerHTML = '<table class="monthTable" style="width:100%"><thead><tr>'
      +'<th>Tarih</th><th>Gün</th><th>Giriş</th><th>Çıkış</th><th>Mola</th><th>Net Saat</th><th>Fazla Mesai</th><th>Not</th><th>İşlem</th>'
      +'</tr></thead><tbody>'
      + filtered.map(function(m){
        var dt = new Date(m.tarih+'T00:00:00');
        var gunAdi = gunler[dt.getDay()];
        var isWeekend = dt.getDay()===0||dt.getDay()===6;
        topNet += m.netSaat||0;
        topFazla += m.fazlaMesai||0;
        return '<tr style="background:'+(isWeekend?'#fff7ed':(m.fazlaMesai>0?'#fef3c7':''))+'">'
          +'<td>'+m.tarih+'</td>'
          +'<td style="'+(isWeekend?'color:#dc2626;font-weight:700':'')+'">'+gunAdi+'</td>'
          +'<td style="font-weight:700;color:#16a34a">'+m.giris+'</td>'
          +'<td style="font-weight:700;color:#dc2626">'+m.cikis+'</td>'
          +'<td>'+m.molaDk+' dk</td>'
          +'<td style="font-weight:700">'+fmt(m.netSaat)+' sa</td>'
          +'<td style="font-weight:700;color:'+(m.fazlaMesai>0?'#f59e0b':'#64748b')+'">'+(m.fazlaMesai>0?fmt(m.fazlaMesai)+' sa':'—')+'</td>'
          +'<td style="font-size:11px;color:var(--muted)">'+(m.not||'')+'</td>'
          +'<td><button onclick="mesaiSil(\''+m.personel.replace(/'/g,"\\'")+'\',\''+m.tarih+'\')" style="font-size:10px;padding:2px 6px;border:1px solid #dc2626;background:#fef2f2;border-radius:4px;cursor:pointer">🗑️</button></td>'
          +'</tr>';
      }).join('')
      +'<tr style="background:#eef3ff;font-weight:900">'
      +'<td colspan="5">TOPLAM ('+topGun+' gün)</td>'
      +'<td>'+fmt(topNet)+' sa</td>'
      +'<td style="color:#f59e0b">'+fmt(topFazla)+' sa</td>'
      +'<td colspan="2"></td></tr>'
      +'</tbody></table>';

    // Özet
    if(ozetDiv){
      // Personel bilgisi
      var personeller = _ls.get('uysa_personeller',[]);
      var per = personeller.find(function(p){ return p.ad+' '+p.soyad===personel; });
      var maas = per ? (parseFloat(per.maas)||0) : 0;
      var saatlikUcret = maas > 0 ? maas / (26 * STANDART_SAAT) : 0; // 26 iş günü
      var mesaiUcret = topFazla * saatlikUcret * 1.5; // Fazla mesai %50 zamlı

      ozetDiv.innerHTML = '<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px">'
        +'<div style="text-align:center;padding:10px;background:#fff;border-radius:8px;border:1px solid #e5e7eb"><div style="font-size:10px;color:#64748b">Çalışma Günü</div><div style="font-size:18px;font-weight:900;color:#1e40af">'+topGun+'</div></div>'
        +'<div style="text-align:center;padding:10px;background:#fff;border-radius:8px;border:1px solid #e5e7eb"><div style="font-size:10px;color:#64748b">Toplam Saat</div><div style="font-size:18px;font-weight:900;color:#166534">'+fmt(topNet)+'</div></div>'
        +'<div style="text-align:center;padding:10px;background:#fffbeb;border-radius:8px;border:1px solid #fde68a"><div style="font-size:10px;color:#92400e">Fazla Mesai</div><div style="font-size:18px;font-weight:900;color:#f59e0b">'+fmt(topFazla)+' sa</div></div>'
        +'<div style="text-align:center;padding:10px;background:#fff;border-radius:8px;border:1px solid #e5e7eb"><div style="font-size:10px;color:#64748b">Saatlik Ücret</div><div style="font-size:18px;font-weight:900;color:#7c3aed">'+fmt(saatlikUcret)+' ₺</div></div>'
        +'<div style="text-align:center;padding:10px;background:#fef3c7;border-radius:8px;border:1px solid #fbbf24"><div style="font-size:10px;color:#92400e">Mesai Ücreti (%150)</div><div style="font-size:18px;font-weight:900;color:#f59e0b">'+fmt(mesaiUcret)+' ₺</div></div>'
        +'</div>';
    }
  };

  // Mesai sil
  window.mesaiSil = function(personel, tarih){
    if(!confirm('Bu mesai kaydı silinecek. Emin misiniz?')) return;
    var arr = getMesaiKayitlari();
    arr = arr.filter(function(m){ return !(m.personel===personel && m.tarih===tarih); });
    saveMesaiKayitlari(arr);
    renderMesaiTablosu();
  };

  // Init
  setTimeout(function(){
    fillMesaiPersonel();
    var ayInp = document.getElementById('mesaiAy');
    if(ayInp && !ayInp.value) ayInp.value = buAy();
    var tarihInp = document.getElementById('mesaiTarih');
    if(tarihInp && !tarihInp.value) tarihInp.value = bugun();
  }, 2000);

})();
