(function(){
'use strict';

/* ── Yardımcılar ─────────────────────────────────────────────────────────── */
var _ls={
  get:function(k,d){try{var v=localStorage.getItem(k);return v!==null?JSON.parse(v):d;}catch(e){return d;}},
  set:function(k,v){try{localStorage.setItem(k,JSON.stringify(v));}catch(e){}}
};
function _fmt(n){return Number(n||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});}
function _toast(msg,col){
  col=col||'#166534';
  var t=document.createElement('div');
  t.textContent=msg;
  t.style.cssText='position:fixed;bottom:24px;right:24px;background:'+col+';color:#fff;padding:10px 18px;border-radius:10px;font-size:13px;font-weight:700;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.25);pointer-events:none;opacity:0;transition:opacity .3s';
  document.body.appendChild(t);
  requestAnimationFrame(function(){t.style.opacity='1';});
  setTimeout(function(){t.style.opacity='0';setTimeout(function(){t.parentNode&&t.parentNode.removeChild(t);},400);},3000);
}

/* ── Müşteri listesi ──────────────────────────────────────────────────────── */
function _getCustomers(){
  try{
    var raw=localStorage.getItem('uysa_customers_v1');
    if(!raw)return['MERKEZ'];
    var obj=JSON.parse(raw);
    var all=(obj.customers||[]).filter(function(x){return x&&x!=='GENEL';});
    if(!all.includes('MERKEZ'))all.unshift('MERKEZ');
    return all;
  }catch(e){return['MERKEZ'];}
}

/* ═══════════════════════════════════════════════════════════════════════════
   A) PERSONEL MODÜLÜ
   ═══════════════════════════════════════════════════════════════════════════ */

/* — Personel listesini render et — */
window.uysaRenderPerListesi = function(){
  var div=document.getElementById('perListDiv');
  if(!div)return;
  var q=(document.getElementById('perArama')?.value||'').toLowerCase();
  var personeller=_ls.get('uysa_personeller',[]);
  // Filtre: aktif tab'e göre
  var showPasif = (window._perTab==='pasif');
  var filtered = personeller.filter(function(p){
    var isPasif = !!p.pasif;
    return showPasif ? isPasif : !isPasif;
  });
  var arr = q ? filtered.filter(function(p){return (p.ad+' '+p.soyad).toLowerCase().includes(q);}) : filtered;
  if(!arr.length){
    div.innerHTML='<div style="padding:14px;text-align:center;color:#94a3b8">'+(showPasif?'📂 Pasif personel yok.':'📭 Aktif personel yok.')+'</div>';
    return;
  }
  div.innerHTML=arr.map(function(p){
    var realIdx=personeller.indexOf(p);
    var isPasif=!!p.pasif;
    var badgeColor=isPasif?'#fee2e2':'#dcfce7';
    var badgeText=isPasif?'⛔ Pasif':'✅ Aktif';
    var togBtn = isPasif
      ? '<button onclick="uysaTogglePasif('+realIdx+')" title="Aktife Al" style="border:none;background:#dcfce7;color:#166534;cursor:pointer;border-radius:6px;padding:4px 8px;font-size:11px;font-weight:700;flex-shrink:0">▶ Aktif</button>'
      : '<button onclick="uysaTogglePasif('+realIdx+')" title="Pasife Al" style="border:none;background:#fef9c3;color:#854d0e;cursor:pointer;border-radius:6px;padding:4px 8px;font-size:11px;font-weight:700;flex-shrink:0">⏸ Pasif</button>';
    return '<div style="border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:10px 12px;margin-bottom:6px;background:'+(isPasif?'#fafafa':'#fff')+';display:flex;justify-content:space-between;align-items:start;gap:6px">'
      +'<div style="flex:1;min-width:0">'
        +'<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">'
          +'<span style="font-weight:700;font-size:13px">'+p.ad+' '+p.soyad+'</span>'
          +'<span style="font-size:10px;padding:2px 6px;border-radius:8px;background:'+badgeColor+';font-weight:700">'+badgeText+'</span>'
        +'</div>'
        +'<div style="font-size:11px;color:#64748b;margin-top:2px">📌 '+(p.proje||'—')+' &nbsp;|&nbsp; 🔧 '+(p.gorev||'—')+' &nbsp;|&nbsp; 💰 '+_fmt(p.maas)+' ₺</div>'
        +'<div style="font-size:11px;color:#94a3b8;margin-top:1px">📱 '+(p.tel||'—')+' &nbsp;|&nbsp; 📅 '+(p.giris||'—')+(p.cikis?' &nbsp;|&nbsp; 🚪 Çıkış: '+p.cikis:'')+'</div>'
      +'</div>'
      +'<div style="display:flex;gap:4px;flex-shrink:0">'
        +togBtn
        +'<button onclick="uysaSilPersonel('+realIdx+')" title="Sil" style="border:none;background:#fee2e2;color:#dc2626;cursor:pointer;border-radius:6px;padding:4px 8px;font-size:14px">🗑️</button>'
      +'</div>'
    +'</div>';
  }).join('');
};

/* — Aktif/Pasif tab init — */
window._perTab = 'aktif';
function _initPerTabs(){
  var wrap = document.getElementById('perListDiv');
  if(!wrap) return;
  // Tab header ekle (sadece bir kez)
  var tabWrap = document.getElementById('perTabWrap');
  if(!tabWrap){
    tabWrap = document.createElement('div');
    tabWrap.id = 'perTabWrap';
    tabWrap.style.cssText='display:flex;gap:6px;margin-bottom:8px;';
    tabWrap.innerHTML=
      '<button id="perTabAktif" onclick="uysaPerTab(\'aktif\')" class="btn primary" style="font-size:12px">✅ Aktif Personel</button>'
      +'<button id="perTabPasif" onclick="uysaPerTab(\'pasif\')" class="btn" style="font-size:12px">⛔ Pasif / İşten Ayrılanlar</button>';
    var panel = wrap.closest('.panel');
    if(panel){
      var searchRow = panel.querySelector('#perArama')?.parentElement?.parentElement;
      if(searchRow) searchRow.insertBefore(tabWrap, searchRow.firstChild);
      else wrap.parentNode.insertBefore(tabWrap, wrap);
    } else {
      wrap.parentNode.insertBefore(tabWrap, wrap);
    }
  }
}

window.uysaPerTab = function(tab){
  window._perTab = tab;
  var aktifBtn = document.getElementById('perTabAktif');
  var pasifBtn = document.getElementById('perTabPasif');
  if(aktifBtn){ aktifBtn.className = tab==='aktif' ? 'btn primary' : 'btn'; }
  if(pasifBtn){ pasifBtn.className = tab==='pasif' ? 'btn primary' : 'btn'; }
  window.uysaRenderPerListesi();
};

/* — Personel Ekle — */
window.uysaPerEkle = function(){
  var ad   =(document.getElementById('per-ad')?.value||'').trim();
  var soyad=(document.getElementById('per-soyad')?.value||'').trim();
  if(!ad||!soyad){_toast('⚠️ Ad ve Soyad zorunlu!','#b91c1c');return;}
  var proje=document.getElementById('per-proje')?.value||'';
  var gorev=document.getElementById('per-gorev')?.value||'';
  var maas =parseFloat(document.getElementById('per-maas')?.value)||0;
  var tel  =document.getElementById('per-tel')?.value||'';
  var giris=document.getElementById('per-giris')?.value||'';
  var personeller=_ls.get('uysa_personeller',[]);
  personeller.push({ad:ad,soyad:soyad,proje:proje,gorev:gorev,maas:maas,tel:tel,giris:giris,pasif:false});
  _ls.set('uysa_personeller',personeller);
  window.uysaRenderPerListesi();
  if(typeof window.refreshIkSelectors==='function')try{window.refreshIkSelectors();}catch(e){}
  ['per-ad','per-soyad','per-maas','per-tel','per-giris','per-gorev'].forEach(function(id){
    var el=document.getElementById(id);if(el)el.value='';
  });
  _toast('✅ '+ad+' '+soyad+' eklendi!');
};

/* — Personel Pasif/Aktif Toggle — */
window.uysaTogglePasif = function(idx){
  var personeller=_ls.get('uysa_personeller',[]);
  if(idx<0||idx>=personeller.length)return;
  var p=personeller[idx];
  var isPasif=!!p.pasif;
  if(!isPasif){
    // Pasife al — çıkış tarihi sor
    var cikis = prompt(p.ad+' '+p.soyad+' pasife alınacak.\nİşten ayrılış tarihi (YYYY-AA-GG) — boş bırakabilirsiniz:','');
    if(cikis===null)return; // iptal
    p.pasif=true;
    p.cikis=cikis||(function(){var d=new Date();return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0')})();
    _toast('⛔ '+p.ad+' '+p.soyad+' pasife alındı','#854d0e');
  }else{
    if(!confirm(p.ad+' '+p.soyad+' tekrar aktife alınsın mı?'))return;
    p.pasif=false;
    p.cikis='';
    _toast('✅ '+p.ad+' '+p.soyad+' aktife alındı');
  }
  _ls.set('uysa_personeller',personeller);
  window.uysaRenderPerListesi();
  if(typeof window.refreshIkSelectors==='function')try{window.refreshIkSelectors();}catch(e){}
};

/* — Personel Sil — */
window.uysaSilPersonel = function(idx){
  if(!confirm('Bu personeli kalıcı olarak silmek istiyor musunuz?\n(Silmek yerine "Pasif" yapmayı tercih edebilirsiniz.)'))return;
  var personeller=_ls.get('uysa_personeller',[]);
  if(idx<0||idx>=personeller.length)return;
  personeller.splice(idx,1);
  _ls.set('uysa_personeller',personeller);
  window.uysaRenderPerListesi();
  if(typeof window.refreshIkSelectors==='function')try{window.refreshIkSelectors();}catch(e){}
  _toast('🗑️ Personel silindi','#dc2626');
};

/* — refreshIkSelectors override: Proje dropdown + liste — */
var _origIkRef = window.refreshIkSelectors;
window.refreshIkSelectors = function(){
  /* Proje dropdown: MERKEZ + tüm müşteriler */
  var projeEl=document.getElementById('per-proje');
  if(projeEl){
    var custs=_getCustomers();
    var prev=projeEl.value;
    projeEl.innerHTML='<option value="">— Seçiniz —</option>'
      +custs.map(function(c){
        var icon=c==='MERKEZ'?'🏢 ':'🤝 ';
        return '<option value="'+c+'">'+icon+c+'</option>';
      }).join('');
    if(prev)projeEl.value=prev;
  }
  /* Orijinal çağır */
  if(typeof _origIkRef==='function')try{_origIkRef.apply(this,arguments);}catch(e){}
  /* Listeyi yenile */
  window.uysaRenderPerListesi();
};

/* — perArama bağla — */
setTimeout(function(){
  var el=document.getElementById('perArama');
  if(el&&!el._v82){el._v82=true;el.addEventListener('input',window.uysaRenderPerListesi);}
  _initPerTabs();
  window.refreshIkSelectors();
},800);

/* ═══════════════════════════════════════════════════════════════════════════
   B) CRM MÜŞTERİ ÖZET — FIX-V18: pure CSS sticky (scroll is inside .content)
   The old fixed-mode JS used window.scroll which never fires for inner-div
   scrolling. Removed. CSS sticky with min-height grid handles positioning.
   ═══════════════════════════════════════════════════════════════════════════ */
/* FIX-V18: CRM sticky JS removed – handled by CSS */

console.log('✅ UYSA v82 yüklendi — Personel + Pasif/Aktif + CRM Sticky');
})();
