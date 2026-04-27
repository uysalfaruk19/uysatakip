/* ═══════════════════════════════════════════════════════════════
   UYSA AI Bridge Widget v2.0 — OpenClaw Connected
   ═══════════════════════════════════════════════════════════════ */
(function(){
'use strict';

const POLL_INTERVAL = 5000;
const SESSION_KEY = 'uysa_ai_bridge_session';

let _sid = null, _poll = null, _authed = false, _sending = false;

// Bridge istekleri backend proxy üzerinden gider (secret client'ta tutulmaz)
async function api(path, method, body) {
  try {
    const sess = JSON.parse(sessionStorage.getItem('uysa_session')||'null');
    const token = sess?.token || '';
    const r = await fetch('/uysa_api.php?action=ai.bridgeProxy', {
      method: 'POST',
      headers: {'Content-Type':'application/json', 'Authorization':'Bearer '+token},
      body: JSON.stringify({path, method: method||'GET', body: body||null})
    });
    return await r.json();
  } catch(e) { return {error:e.message}; }
}

function erpOk() { try { return !!JSON.parse(sessionStorage.getItem('uysa_session')||'null'); } catch(e){return false;} }

function fabCheck() {
  const f = document.getElementById('uysa-ai-fab');
  if (f) f.style.display = erpOk() ? 'flex' : 'none';
}
setInterval(fabCheck, 10000);
document.readyState==='loading' ? document.addEventListener('DOMContentLoaded',fabCheck) : fabCheck();

function loadSess() { try { const s=JSON.parse(localStorage.getItem(SESSION_KEY)||'null'); if(s&&s.sid){_sid=s.sid;_authed=s.ok||false;} } catch(e){} }
function saveSess(id,ok) { _sid=id;_authed=ok;localStorage.setItem(SESSION_KEY,JSON.stringify({sid:id,ok:ok})); }
function clearSess() { _sid=null;_authed=false;localStorage.removeItem(SESSION_KEY); }

function dot(color) { const d=document.getElementById('uysa-ai-dot'); if(d) d.style.background=color; }

function panel(mode) {
  const c=document.getElementById('uysa-ai-connect'), m=document.getElementById('uysa-ai-messages'), i=document.getElementById('uysa-ai-input-area');
  if(mode==='connect'){c.style.display='flex';m.style.display='none';i.style.display='none';dot('#fbbf24');}
  else{c.style.display='none';m.style.display='flex';i.style.display='flex';dot('#22c55e');}
}

async function startConn(prov) {
  const el=document.getElementById;
  document.getElementById('uysa-ai-connect-error').style.display='none';
  document.getElementById('uysa-ai-connect-status').textContent='Baglaniyor...';
  const r = await api('/connect/start','POST',{provider:prov});
  if(r.error){document.getElementById('uysa-ai-connect-error').textContent=r.error;document.getElementById('uysa-ai-connect-error').style.display='block';return;}
  saveSess(r.session_id,false);
  if(r.status==='authenticated'){saveSess(r.session_id,true);panel('chat');document.getElementById('uysa-ai-input').focus();return;}
  document.getElementById('uysa-ai-connect-provider').textContent=r.provider||'';
  const u=document.getElementById('uysa-ai-connect-url');u.href=r.verification_url||'#';u.textContent=r.verification_url||'';
  document.getElementById('uysa-ai-connect-code').textContent=r.code||'';
  panel('connect');
  document.getElementById('uysa-ai-connect-status').textContent='Yetkilendirme bekleniyor...';
  pollStart();
}

function pollStart() { pollStop(); _poll=setInterval(async()=>{if(!_sid){pollStop();return;}const r=await api('/connect/status/'+_sid);if(!r)return;if(r.status==='authenticated'){pollStop();saveSess(_sid,true);panel('chat');document.getElementById('uysa-ai-input').focus();}else if(r.status==='failed'){pollStop();document.getElementById('uysa-ai-connect-error').textContent=r.error||'Basarisiz';document.getElementById('uysa-ai-connect-error').style.display='block';}},POLL_INTERVAL); }
function pollStop() { if(_poll){clearInterval(_poll);_poll=null;} }

async function checkSess() { if(!_sid)return false;const r=await api('/connect/status/'+_sid);if(r&&r.status==='authenticated'){_authed=true;saveSess(_sid,true);return true;}return false; }

function renderMsg(role, text) {
  const wrap=document.getElementById('uysa-ai-messages'), d=document.createElement('div');
  d.className='ai-msg ai-'+role;
  let h=text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  h=h.replace(/\*\*(.*?)\*\*/g,'<strong>$1</strong>');
  h=h.replace(/`(.*?)`/g,'<code>$1</code>');
  h=h.replace(/\n/g,'<br>');
  d.innerHTML=h; wrap.appendChild(d); wrap.scrollTop=wrap.scrollHeight;
}

function showTyping() {
  const wrap=document.getElementById('uysa-ai-messages'), d=document.createElement('div');
  d.className='ai-typing'; d.id='ai-typing-ind';
  d.innerHTML='<span></span><span></span><span></span>';
  wrap.appendChild(d); wrap.scrollTop=wrap.scrollHeight;
}
function hideTyping() { const t=document.getElementById('ai-typing-ind'); if(t)t.remove(); }

window.uysaAI = {
  async open() {
    if(!erpOk())return;
    document.getElementById('uysa-ai-chat').style.display='flex';
    document.getElementById('uysa-ai-fab').style.display='none';
    const ok=await api('/health');
    if(!ok||ok.status!=='ok'){panel('connect');document.getElementById('uysa-ai-connect-error').textContent='AI servisi ulasilamiyor';document.getElementById('uysa-ai-connect-error').style.display='block';return;}
    loadSess();
    if(_sid&&_authed){const v=await checkSess();if(v){panel('chat');document.getElementById('uysa-ai-input').focus();return;}}
    await startConn();
  },
  close() { document.getElementById('uysa-ai-chat').style.display='none';document.getElementById('uysa-ai-fab').style.display=erpOk()?'flex':'none';pollStop(); },
  clear() { document.getElementById('uysa-ai-messages').innerHTML='<div class="ai-msg ai-assistant">Sohbet temizlendi. Yeni bir soru sorabilirsiniz.</div>'; },
  async send() {
    if(_sending)return;
    const inp=document.getElementById('uysa-ai-input'), msg=inp.value.trim();
    if(!msg)return; inp.value=''; renderMsg('user',msg);
    if(!_sid||!_authed){renderMsg('assistant','Baglanti yok. Lutfen tekrar deneyin.');return;}
    _sending=true; const btn=document.getElementById('uysa-ai-send'); btn.disabled=true;
    showTyping();
    const r=await api('/chat','POST',{session_id:_sid,message:msg});
    hideTyping(); _sending=false; btn.disabled=false;
    if(r.message){renderMsg('assistant',r.message);}
    else if(r.error){
      if(r.error.indexOf('not authenticated')!==-1||r.error.indexOf('Session not')!==-1){clearSess();renderMsg('assistant','Oturum suresi doldu.');panel('connect');await startConn();}
      else renderMsg('assistant','Hata: '+r.error);
    } else renderMsg('assistant','Yanit alinamadi.');
    inp.focus();
  },
  copyCode() { const c=document.getElementById('uysa-ai-connect-code')?.textContent;if(c)navigator.clipboard.writeText(c).catch(()=>{}); },
  async retryConnect() { clearSess();await startConn(); },
  addMsg: renderMsg,
};

})();

// ══ STUB FONKSİYONLAR — Henüz implemente edilmemiş modüller ══
// Bu fonksiyonlar HTML onclick'te çağrılıyor ancak JS implementasyonu henüz eklenmemiş.
// ReferenceError yerine kullanıcıya bilgi mesajı gösterir.
(function(){
  var stubMsg = 'Bu özellik henüz aktif değil. Yakında eklenecektir.';
  var stubs = [
    'patronSayfasiYazdir','renderAylikOzetRapor','aylikOzetPdf',
    'irsaliyeListGoster','irsaliyeOlustur','irsaliyeMenudenCek',
    'mesaiKaydet',
    'rcYeniYemek','rcAddIngredientRow','rcSaveRecipe','rcSilRecipe',
    'rcGunToggleAllCust','rcLoadGunlukMenu','rcUretimFisiOlustur',
    'rcGecmisGunlerDashboard','rcSaveGunlukUretim','rcRenderAnaliz','rcOrtalamaAl',
    'rcFilterYemekList','rcRecalc','rcImportExcel','rcToggleAralik','rcRecalcGunluk'
  ];
  stubs.forEach(function(name){
    if(typeof window[name]==='undefined'){
      window[name] = function(){ alert('⚠️ '+stubMsg); };
    }
  });
})();
