(function(){
  'use strict';

  /* ══════════════════════════════════════════════════════════════════
     UYSA v78 PATCH
     • Inline takvim (menü ana sayfası sağ panel)
     • Tıklayarak gün düzenleme
     • Maliyet hesaplama (günlük / haftalık / aylık)
     • PDF ihracat
     • Geçmiş ay arşivi
  ══════════════════════════════════════════════════════════════════ */

  const STORE_KEY   = 'uysa_menu_grid_v2_dates';
  const CATALOG_KEY = 'uysa_catalog_v1';
  const PRICE_KEY   = 'uysa_ingredient_prices';

  const TR_MONTHS_ARR = ['','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran',
                         'Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
  const TR_DAYS_FULL  = ['Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi','Pazar'];
  const TR_DAYS_SHORT = ['Pzt','Sal','Çar','Per','Cum','Cmt','Paz'];

  /* ── Yardımcılar ────────────────────────────────────────────── */
  function _p2(n){ return String(n).padStart(2,'0'); }
  function _dow(d){ return (d.getDay()+6)%7; }
  function _monOf(d){ const x=new Date(d); x.setDate(d.getDate()-_dow(d)); x.setHours(0,0,0,0); return x; }
  function _mondays(y,m0){
    const last=new Date(y,m0+1,0); last.setHours(23,59,59);
    let c=_monOf(new Date(y,m0,1)); const out=[];
    while(c<=last){ out.push(new Date(c)); c=new Date(c); c.setDate(c.getDate()+7); }
    return out;
  }
  function _getStore(){ try{return JSON.parse(localStorage.getItem(STORE_KEY)||'{}');}catch{return {};} }
  function _setStore(s){ localStorage.setItem(STORE_KEY,JSON.stringify(s)); if(window._idbSet) window._idbSet(STORE_KEY,s); }
  function _ymKey(y,m1,c){ return `${y}-${_p2(m1)}::${c||'GENEL'}`; }
  function _getCatalog(){
    try{ const v=JSON.parse(localStorage.getItem(CATALOG_KEY)||'null'); if(v&&v.soups) return v; }catch{}
    return {soups:['Mercimek','Tarhana','Yayla','Domates','Ezogelin','Düğün','Şehriye','Tavuk Suyu','Mantar','Brokoli'],
      mainsByType:{meat:['Tas Kebabı','Et Sote','Fırın Et Patates','Orman Kebabı','Kavurma'],
        legume:['Kuru Fasulye','Nohut','Yeşil Mercimek','Barbunya Pilaki','Börülce'],
        chicken:['Tavuk Sote','Fırın Tavuk','Tavuk Haşlama','Körili Tavuk','Tavuk Şiş'],
        vegetable:['Taze Fasulye','Karnabahar','Ispanak','Bamya','Kabak'],
        kebab:['Izgara Köfte','Fırın Köfte','Kadınbudu Köfte','Hasanpaşa Köfte','Köfte Patates']},
      sidesByGroup:{rice:['Pirinç Pilavı'],bulgur:['Bulgur Pilavı'],pasta:['Makarna']}};
  }
  function _getWkData(s,y,m1,wi,c){
    const w=`W${wi}`;
    return s[_ymKey(y,m1,c)]?.weeks?.[w]||s[_ymKey(y,m1,'GENEL')]?.weeks?.[w]||null;
  }
  function _getDayMenu(s,y,m1,c,dt){
    const mons=_mondays(y,m1-1),dow=_dow(dt); let wi=-1;
    for(let i=0;i<mons.length;i++){const e=new Date(mons[i]);e.setDate(mons[i].getDate()+6);if(dt>=mons[i]&&dt<=e){wi=i+1;break;}}
    if(wi<0) return null;
    const w=_getWkData(s,y,m1,wi,c); if(!w) return null;
    return {weekIdx1:wi,dowIdx:dow,
      soup:w.soups?.[dow]||'', soup2:w.soups2?.[dow]||'',
      main:w.mains?.[dow]||'', main2:w.mains2?.[dow]||'',
      side:w.sides?.[dow]||'', side2:w.sides2?.[dow]||'',
      salad:w.salads?.[dow]||'', dessert:w.dessertFruit?.[dow]||'',
      dessertName:w.dessertFruitName?.[dow]||'', ayran:w.ayran?.[dow]||'', drink:w.drinks?.[dow]||''};
  }
  function _saveDayMenu(y,m1,c,wi,di,data){
    const s=_getStore(), yk=_ymKey(y,m1,c), wk=`W${wi}`;
    if(!s[yk]) s[yk]={weeks:{}};
    if(!s[yk].weeks[wk]) s[yk].weeks[wk]={
      soups:Array(7).fill(''),soups2:Array(7).fill(''),
      mains:Array(7).fill(''),mains2:Array(7).fill(''),
      sides:Array(7).fill(''),sides2:Array(7).fill(''),
      salads:Array(7).fill(''),dessertFruit:Array(7).fill(''),
      dessertFruitName:Array(7).fill(''),ayran:Array(7).fill(''),drinks:Array(7).fill('')};
    const w=s[yk].weeks[wk];
    const ensure=(k)=>{if(!w[k]||w[k].length<7)w[k]=Array(7).fill('');};
    ['soups','soups2','mains','mains2','sides','sides2','salads','dessertFruit','dessertFruitName','ayran','drinks'].forEach(ensure);
    w.soups[di]=data.soup||''; w.soups2[di]=data.soup2||'';
    w.mains[di]=data.main||''; w.mains2[di]=data.main2||'';
    w.sides[di]=data.side||''; w.sides2[di]=data.side2||'';
    w.salads[di]=data.salad||''; w.dessertFruit[di]=data.dessert||'';
    w.dessertFruitName[di]=data.dessertName||''; w.ayran[di]=data.ayran||''; w.drinks[di]=data.drink||'';
    _setStore(s);
  }
  function _toast(msg,ms=2500){
    const t=document.createElement('div');
    t.textContent=msg;
    t.style.cssText='position:fixed;bottom:24px;right:24px;background:#1e293b;color:#fff;padding:10px 18px;border-radius:10px;font-size:13px;font-weight:600;z-index:99999;box-shadow:0 4px 20px rgba(0,0,0,.35)';
    document.body.appendChild(t); setTimeout(()=>t.remove(),ms);
  }
  function _getParams(){
    const y=parseInt(document.getElementById('year')?.value||new Date().getFullYear());
    const m=parseInt(document.getElementById('month')?.value||(new Date().getMonth()+1));
    const c=document.getElementById('customerSelect')?.value||'GENEL';
    return {year:y,mo1:m,cust:c};
  }

  /* ══════════════════════════════════════════════════════════════
     GÜN DÜZENLEME MODALİ
  ══════════════════════════════════════════════════════════════ */
  function _openEditModal(year,mo1,cust,dateObj,menu,onSaved){
    const cat=_getCatalog();
    const dayLbl=`${_p2(dateObj.getDate())}.${_p2(mo1)}.${year} ${TR_DAYS_FULL[_dow(dateObj)]}`;
    const sOpts=(arr,cur)=>{
      let html=arr.map(s=>`<option value="${s}"${cur===s?' selected':''}>${s}</option>`).join('');
      // If current value exists but not in catalog, add as custom option
      if(cur && !arr.includes(cur)) html+=`<option value="${cur}" selected style="color:#7c3aed">${cur} (Excel)</option>`;
      return html;
    };
    const mGroups=(cur)=>{
      const GL={meat:'🥩 Et',legume:'🫘 Bakliyat',chicken:'🍗 Tavuk',vegetable:'🥦 Sebze',kebab:'🧆 Köfte'};
      let allMains=[];
      let html=Object.entries(cat.mainsByType||{}).map(([t,a])=>{
        allMains=allMains.concat(a||[]);
        return `<optgroup label="${GL[t]||t}">${(a||[]).map(n=>`<option value="${n}"${cur===n?' selected':''}>${n}</option>`).join('')}</optgroup>`;
      }).join('');
      if(cur && !allMains.includes(cur)) html+=`<option value="${cur}" selected style="color:#7c3aed">${cur} (Excel)</option>`;
      return html;
    };
    const sgOpts=(cur,empty='— Seçiniz —')=>{
      let r=`<option value="">${empty}</option>`;
      let allSides=[];
      Object.entries(cat.sidesByGroup||{}).forEach(([g,a])=>{
        allSides=allSides.concat(a||[]);
        r+=`<optgroup label="${g}">${(a||[]).map(n=>`<option value="${n}"${cur===n?' selected':''}>${n}</option>`).join('')}</optgroup>`;
      });
      if(cur && cur!==empty && !allSides.includes(cur)) r+=`<option value="${cur}" selected style="color:#7c3aed">${cur} (Excel)</option>`;
      return r;
    };
    const saladList=['1 Çeşit','2 Çeşit','3 Çeşit','4 Çeşit','5 Çeşit','6 Çeşit'];
    const dessertList=['Sütlaç','Kemalpaşa','Revani','Şekerpare','İrmik Helvası','Kadayıf','Elma','Portakal','Muz','Armut','Mandalina'];
    const ayranList=['Ayran','Yoğurt','Her İkisi'];
    const mid='uysa-day-edit-modal';
    document.getElementById(mid)?.remove();
    const modal=document.createElement('div');
    modal.id=mid;
    modal.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:20000;display:flex;align-items:center;justify-content:center;padding:16px';
    modal.innerHTML=`
      <div style="background:#fff;border-radius:16px;width:100%;max-width:600px;box-shadow:0 24px 64px rgba(0,0,0,.4);overflow:hidden;max-height:90vh;display:flex;flex-direction:column">
        <div style="background:linear-gradient(135deg,#1e3a5f,#7c3aed);color:#fff;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;flex-shrink:0">
          <div>
            <div style="font-size:15px;font-weight:800">✏️ Menü Düzenle</div>
            <div style="font-size:12px;opacity:.85;margin-top:2px">📅 ${dayLbl} · 👤 ${cust}</div>
          </div>
          <button id="${mid}-x" style="background:none;border:none;color:#fff;font-size:28px;cursor:pointer;line-height:1">×</button>
        </div>
        <div style="overflow-y:auto;padding:18px;flex:1">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div><label style="font-size:11px;font-weight:700;color:#475569;display:block;margin-bottom:4px">🍲 Çorba 1</label>
              <select id="${mid}-soup" style="width:100%;padding:8px;border:1.5px solid #bfdbfe;border-radius:8px;font-size:13px;background:#eff6ff">
                <option value="">— Seçiniz —</option>${sOpts(cat.soups||[],menu?.soup||'')}</select></div>
            <div><label style="font-size:11px;font-weight:700;color:#475569;display:block;margin-bottom:4px">🍲 Çorba 2</label>
              <select id="${mid}-soup2" style="width:100%;padding:8px;border:1.5px solid #bfdbfe;border-radius:8px;font-size:13px;background:#eff6ff">
                <option value="">— Yok —</option>${sOpts(cat.soups||[],menu?.soup2||'')}</select></div>
            <div><label style="font-size:11px;font-weight:700;color:#475569;display:block;margin-bottom:4px">🍽️ Ana Yemek 1</label>
              <select id="${mid}-main" style="width:100%;padding:8px;border:1.5px solid #86efac;border-radius:8px;font-size:13px;background:#f0fdf4">
                <option value="">— Seçiniz —</option>${mGroups(menu?.main||'')}</select></div>
            <div><label style="font-size:11px;font-weight:700;color:#475569;display:block;margin-bottom:4px">🍽️ Ana Yemek 2</label>
              <select id="${mid}-main2" style="width:100%;padding:8px;border:1.5px solid #86efac;border-radius:8px;font-size:13px;background:#f0fdf4">
                <option value="">— Yok —</option>${mGroups(menu?.main2||'')}</select></div>
            <div><label style="font-size:11px;font-weight:700;color:#475569;display:block;margin-bottom:4px">🥘 Yardımcı 1</label>
              <select id="${mid}-side" style="width:100%;padding:8px;border:1.5px solid #fde047;border-radius:8px;font-size:13px;background:#fefce8">
                ${sgOpts(menu?.side||'')}</select></div>
            <div><label style="font-size:11px;font-weight:700;color:#475569;display:block;margin-bottom:4px">🥘 Yardımcı 2</label>
              <select id="${mid}-side2" style="width:100%;padding:8px;border:1.5px solid #fde047;border-radius:8px;font-size:13px;background:#fefce8">
                ${sgOpts(menu?.side2||'— Yok —')}</select></div>
            <div><label style="font-size:11px;font-weight:700;color:#475569;display:block;margin-bottom:4px">🥗 Salata</label>
              <select id="${mid}-salad" style="width:100%;padding:8px;border:1.5px solid #7dd3fc;border-radius:8px;font-size:13px;background:#f0f9ff">
                <option value="">— Yok —</option>${sOpts(saladList,menu?.salad||'')}</select></div>
            <div><label style="font-size:11px;font-weight:700;color:#475569;display:block;margin-bottom:4px">🍮 Tatlı / Meyve</label>
              <select id="${mid}-dessert" style="width:100%;padding:8px;border:1.5px solid #d8b4fe;border-radius:8px;font-size:13px;background:#fdf4ff">
                <option value="">— Yok —</option>${sOpts(dessertList,menu?.dessertName||'')}</select></div>
            <div><label style="font-size:11px;font-weight:700;color:#475569;display:block;margin-bottom:4px">🥛 Ayran / Yoğurt</label>
              <select id="${mid}-ayran" style="width:100%;padding:8px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:13px;background:#f8fafc">
                <option value="yok">Yok</option>${sOpts(ayranList,menu?.ayran||'')}</select></div>
            <div><label style="font-size:11px;font-weight:700;color:#475569;display:block;margin-bottom:4px">🥤 Meşrubat</label>
              <input id="${mid}-drink" type="text" value="${menu?.drink||''}" placeholder="Limonata, Su…"
                style="width:100%;padding:8px;border:1.5px solid #fed7aa;border-radius:8px;font-size:13px;background:#fff7ed;box-sizing:border-box"></div>
          </div>
        </div>
        <div style="padding:12px 18px;background:#f8fafc;border-top:1px solid #e5e7eb;display:flex;gap:8px;justify-content:flex-end;flex-shrink:0">
          <button id="${mid}-clr" style="padding:8px 14px;background:#fee2e2;border:1.5px solid #fca5a5;color:#dc2626;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600">🗑️ Temizle</button>
          <button id="${mid}-cnl" style="padding:8px 14px;background:#f1f5f9;border:1.5px solid #cbd5e1;color:#475569;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600">İptal</button>
          <button id="${mid}-sav" style="padding:8px 20px;background:linear-gradient(135deg,#1e3a5f,#1a56db);border:none;color:#fff;border-radius:8px;cursor:pointer;font-size:13px;font-weight:700;box-shadow:0 2px 8px rgba(26,86,219,.3)">💾 Kaydet</button>
        </div>
      </div>`;
    document.body.appendChild(modal);
    const g=id=>document.getElementById(`${mid}-${id}`)?.value||'';
    const close=()=>modal.remove();
    document.getElementById(`${mid}-x`).onclick=close;
    document.getElementById(`${mid}-cnl`).onclick=close;
    modal.addEventListener('click',e=>{if(e.target===modal)close();});
    document.getElementById(`${mid}-clr`).onclick=()=>{
      ['soup','soup2','main','main2','side','side2','salad','dessert','ayran'].forEach(k=>{
        const el=document.getElementById(`${mid}-${k}`); if(el) el.value='';});
      document.getElementById(`${mid}-drink`).value='';
    };
    document.getElementById(`${mid}-sav`).onclick=()=>{
      const data={soup:g('soup'),soup2:g('soup2'),main:g('main'),main2:g('main2'),
        side:g('side'),side2:g('side2'),salad:g('salad'),dessert:'',
        dessertName:g('dessert'),ayran:g('ayran'),drink:g('drink')};
      if(menu){
        _saveDayMenu(year,mo1,cust,menu.weekIdx1,menu.dowIdx,data);
      } else {
        const mons=_mondays(year,mo1-1),dow=_dow(dateObj); let wi=-1;
        for(let i=0;i<mons.length;i++){const e=new Date(mons[i]);e.setDate(mons[i].getDate()+6);if(dateObj>=mons[i]&&dateObj<=e){wi=i+1;break;}}
        if(wi<0){_toast('❌ Hafta bulunamadı');return;}
        _saveDayMenu(year,mo1,cust,wi,dow,data);
      }
      _toast(`✅ ${_p2(dateObj.getDate())}.${_p2(mo1)}.${year} kaydedildi`);
      close(); if(typeof onSaved==='function') onSaved();
    };
  }

  /* ══════════════════════════════════════════════════════════════
     INLINE TAKVİM — ANA SAYFADA GÖSTERİLİR
  ══════════════════════════════════════════════════════════════ */
  function _renderInlineCalendar(){
    const {year,mo1,cust}=_getParams();
    const container=document.getElementById('inlineCalendarGrid');
    if(!container) return;

    const store    =_getStore();
    const _td2=new Date(); const todayStr =_td2.getFullYear()+'-'+String(_td2.getMonth()+1).padStart(2,'0')+'-'+String(_td2.getDate()).padStart(2,'0');
    const firstD   =new Date(year,mo1-1,1);
    const lastD    =new Date(year,mo1,0);
    const startDow =_dow(firstD);

    /* Gün başlıkları */
    let header='';
    TR_DAYS_SHORT.forEach((d,i)=>{
      header+=`<div style="text-align:center;font-size:10px;font-weight:800;
        color:${i>=5?'#7c3aed':'#64748b'};padding:3px 0;
        border-bottom:2px solid ${i>=5?'#ede9fe':'#e2e8f0'}">${d}</div>`;
    });

    /* Boş hücreler */
    let cells='';
    for(let i=0;i<startDow;i++)
      cells+=`<div style="min-height:80px;background:#f8fafc;border-radius:8px;opacity:.35;border:1px dashed #e2e8f0"></div>`;

    /* Gün kartları */
    for(let day=1;day<=lastD.getDate();day++){
      const dt  =new Date(year,mo1-1,day);
      const dow =_dow(dt);
      const ds  =`${year}-${_p2(mo1)}-${_p2(day)}`;
      const isT =ds===todayStr;
      const isW =dow>=5;
      const m   =_getDayMenu(store,year,mo1,cust,dt);
      const hasMenu=m&&(m.soup||m.main||m.side);

      let mHtml='';
      if(hasMenu){
        const rows=[
          m.soup?`<div style="color:#1e40af;font-size:9.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">🍲 ${m.soup}${m.soup2?' /'+m.soup2:''}</div>`:'',
          m.main?`<div style="color:#166534;font-size:9.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">🍽️ ${m.main}${m.main2?' /'+m.main2:''}</div>`:'',
          m.side?`<div style="color:#854d0e;font-size:9.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">🥘 ${m.side}${m.side2?' /'+m.side2:''}</div>`:'',
          m.salad?`<div style="color:#0369a1;font-size:9px">🥗 ${m.salad}</div>`:'',
          m.dessertName?`<div style="color:#7c3aed;font-size:9px">🍮 ${m.dessertName}</div>`:'',
          (m.ayran&&m.ayran!=='yok')?`<div style="color:#374151;font-size:9px">🥛 ${m.ayran}</div>`:'',
        ].filter(Boolean).join('');
        mHtml=`<div style="margin-top:3px;display:flex;flex-direction:column;gap:1px">${rows}</div>`;
      } else {
        mHtml=`<div style="margin-top:5px;text-align:center;font-size:9px;color:#cbd5e1">ekle →</div>`;
      }

      cells+=`<div data-inline-day="${day}" style="
        min-height:110px;cursor:pointer;
        background:${isT?'#eff6ff':isW?'#faf5ff':'#fff'};
        border:${isT?'2px solid #1a56db':isW?'1.5px solid #c4b5fd':'1px solid #e5e7eb'};
        border-radius:8px;padding:5px 4px;overflow:hidden;
        transition:box-shadow .12s,transform .12s;
      "
      onmouseenter="this.style.boxShadow='0 3px 14px rgba(26,86,219,.2)';this.style.transform='translateY(-2px)'"
      onmouseleave="this.style.boxShadow='none';this.style.transform='none'"
      >
        <div style="display:flex;justify-content:space-between;align-items:center">
          <span style="font-size:12px;font-weight:900;color:${isT?'#1a56db':isW?'#6d28d9':'#334155'}">${day}</span>
          <div style="display:flex;gap:2px">
            ${isW?`<span style="font-size:8px;background:#ede9fe;color:#7c3aed;padding:1px 4px;border-radius:4px">${dow===5?'Cmt':'Paz'}</span>`:''}
            ${isT?`<span style="font-size:8px;background:#dbeafe;color:#1d4ed8;padding:1px 4px;border-radius:4px">🔵</span>`:''}
          </div>
        </div>
        ${mHtml}
      </div>`;
    }

    /* Son boş hücreler */
    const total=startDow+lastD.getDate(), rem=total%7;
    if(rem>0) for(let i=0;i<7-rem;i++)
      cells+=`<div style="min-height:80px;background:#f8fafc;border-radius:8px;opacity:.35;border:1px dashed #e2e8f0"></div>`;

    container.innerHTML=`
      <div style="font-size:12px;font-weight:800;color:#1e293b;margin-bottom:6px">
        ${TR_MONTHS_ARR[mo1]} ${year} · ${cust}
      </div>
      <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:3px;margin-bottom:3px">${header}</div>
      <div id="inlineCalGrid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px">${cells}</div>
      <div style="margin-top:8px;font-size:10px;color:#94a3b8">📌 Karta tıklayarak menü girin · ✏️ Değiştirmek için tekrar tıklayın</div>
    `;

    /* Tıklama olayları */
    document.getElementById('inlineCalGrid')?.addEventListener('click',e=>{
      const card=e.target.closest('[data-inline-day]');
      if(!card) return;
      const day2=parseInt(card.dataset.inlineDay);
      const dt2=new Date(year,mo1-1,day2);
      const s2=_getStore(), m2=_getDayMenu(s2,year,mo1,cust,dt2);
      _openEditModal(year,mo1,cust,dt2,m2,()=>_renderInlineCalendar());
    });
  }

  /* ══════════════════════════════════════════════════════════════
     MALİYET HESAPLAMA MODALİ
  ══════════════════════════════════════════════════════════════ */
  function _openCostModal(){
    const {year,mo1,cust}=_getParams();
    const store=_getStore();
    const prices=_getPrices();
    const mons=_mondays(year,mo1-1);
    const lastD=new Date(year,mo1,0);

    // Tüm günleri topla
    const days=[];
    for(let d=1;d<=lastD.getDate();d++){
      const dt=new Date(year,mo1-1,d);
      const m=_getDayMenu(store,year,mo1,cust,dt);
      days.push({day:d,dt,menu:m});
    }

    // Günlük maliyet hesapla (basit: tahmini kg başına fiyat)
    const DISH_COSTS={
      'default_soup':3.5,'default_main':12,'default_side':5,'default_salad':4,'default_dessert':6,'default_ayran':2
    };

    let totalMonth=0;
    let dayCount=0;
    const weekGroups={};

    const dayRows=days.map(({day,dt,menu})=>{
      if(!menu||(!(menu.soup||menu.main))) return null;
      const dow=_dow(dt),isW=dow>=5;
      const mons2=mons; let wi=-1;
      for(let i=0;i<mons2.length;i++){const e=new Date(mons2[i]);e.setDate(mons2[i].getDate()+6);if(dt>=mons2[i]&&dt<=e){wi=i+1;break;}}

      const soupCost=(menu.soup?DISH_COSTS.default_soup:0)+(menu.soup2?DISH_COSTS.default_soup*0.5:0);
      const mainCost=(menu.main?DISH_COSTS.default_main:0)+(menu.main2?DISH_COSTS.default_main*0.5:0);
      const sideCost=(menu.side?DISH_COSTS.default_side:0)+(menu.side2?DISH_COSTS.default_side*0.5:0);
      const saladCost=menu.salad?DISH_COSTS.default_salad:0;
      const dessertCost=menu.dessertName?DISH_COSTS.default_dessert:0;
      const ayranCost=(menu.ayran&&menu.ayran!=='yok')?DISH_COSTS.default_ayran:0;
      const total=soupCost+mainCost+sideCost+saladCost+dessertCost+ayranCost;
      totalMonth+=total;
      dayCount++;
      if(!weekGroups[wi]) weekGroups[wi]=0; weekGroups[wi]+=total;

      return `<tr style="background:${isW?'#faf5ff':'#fff'}">
        <td style="padding:5px 8px;border:1px solid #e5e7eb;font-size:12px;font-weight:600;color:${isW?'#7c3aed':'#334155'}">${_p2(day)}.${_p2(mo1)} ${TR_DAYS_FULL[dow]}</td>
        <td style="padding:5px 8px;border:1px solid #e5e7eb;font-size:11px">${menu.soup||'—'}${menu.soup2?' /'+menu.soup2:''}</td>
        <td style="padding:5px 8px;border:1px solid #e5e7eb;font-size:11px">${menu.main||'—'}${menu.main2?' /'+menu.main2:''}</td>
        <td style="padding:5px 8px;border:1px solid #e5e7eb;font-size:11px">${menu.side||'—'}</td>
        <td style="padding:5px 8px;border:1px solid #e5e7eb;font-size:11px">${menu.salad||'—'}</td>
        <td style="padding:5px 8px;border:1px solid #e5e7eb;font-size:11px">${menu.dessertName||'—'}</td>
        <td style="padding:5px 8px;border:1px solid #e5e7eb;font-size:12px;font-weight:700;color:#166534;text-align:right">${total.toFixed(2)} ₺</td>
      </tr>`;
    }).filter(Boolean).join('');

    const weekRows=Object.entries(weekGroups).map(([wi,tot])=>
      `<tr><td style="padding:5px 10px;border:1px solid #e5e7eb;font-weight:700">Hafta ${wi}</td>
       <td style="padding:5px 10px;border:1px solid #e5e7eb;font-weight:800;color:#1a56db;text-align:right">${tot.toFixed(2)} ₺</td></tr>`
    ).join('');

    const mid='uysa-cost-modal';
    document.getElementById(mid)?.remove();
    const modal=document.createElement('div');
    modal.id=mid;
    modal.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:15000;display:flex;align-items:flex-start;justify-content:center;padding:16px;overflow:auto';
    modal.addEventListener('click',e=>{if(e.target===modal)modal.remove();});
    modal.innerHTML=`
      <div style="background:#fff;border-radius:16px;width:100%;max-width:1100px;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden">
        <div style="background:linear-gradient(135deg,#064e3b,#047857);color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center">
          <div>
            <b style="font-size:17px">💰 Maliyet Analizi — ${TR_MONTHS_ARR[mo1]} ${year}</b>
            <div style="font-size:12px;opacity:.8;margin-top:2px">👤 ${cust} · Tahmini 1 kişi başına günlük maliyet</div>
          </div>
          <button onclick="document.getElementById('${mid}').remove()" style="background:none;border:none;color:#fff;font-size:28px;cursor:pointer;line-height:1">×</button>
        </div>
        <div style="padding:16px;display:grid;grid-template-columns:1fr 200px;gap:16px">
          <div>
            <h4 style="margin:0 0 8px;color:#064e3b">📋 Günlük Maliyet Dökümü</h4>
            <div style="overflow-x:auto">
              <table style="width:100%;border-collapse:collapse;font-size:12px">
                <thead><tr style="background:linear-gradient(135deg,#064e3b,#047857);color:#fff">
                  <th style="padding:7px 8px;text-align:left;font-size:11px">Tarih</th>
                  <th style="padding:7px 8px;text-align:left;font-size:11px">Çorba</th>
                  <th style="padding:7px 8px;text-align:left;font-size:11px">Ana Yemek</th>
                  <th style="padding:7px 8px;text-align:left;font-size:11px">Yardımcı</th>
                  <th style="padding:7px 8px;text-align:left;font-size:11px">Salata</th>
                  <th style="padding:7px 8px;text-align:left;font-size:11px">Tatlı</th>
                  <th style="padding:7px 8px;text-align:right;font-size:11px">Toplam (₺)</th>
                </tr></thead>
                <tbody>${dayRows||'<tr><td colspan="7" style="text-align:center;padding:20px;color:#94a3b8">Bu ay için menü kaydı bulunamadı</td></tr>'}</tbody>
              </table>
            </div>
          </div>
          <div>
            <h4 style="margin:0 0 8px;color:#064e3b">📊 Haftalık Özet</h4>
            <table style="width:100%;border-collapse:collapse;margin-bottom:12px">
              <tbody>${weekRows}</tbody>
            </table>
            <div style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:2px solid #86efac;border-radius:10px;padding:14px;text-align:center;margin-bottom:10px">
              <div style="font-size:10px;color:#166534;font-weight:800;letter-spacing:.5px;text-transform:uppercase">📊 Aylık Toplam</div>
              <div style="font-size:26px;font-weight:900;color:#064e3b;margin:4px 0">${totalMonth.toFixed(2)} ₺</div>
              <div style="font-size:10px;color:#166534">${dayCount} gün · 1 kişi tahmini</div>
            </div>
            <div style="background:linear-gradient(135deg,#eff6ff,#dbeafe);border:2px solid #93c5fd;border-radius:10px;padding:14px;text-align:center;margin-bottom:10px">
              <div style="font-size:10px;color:#1d4ed8;font-weight:800;letter-spacing:.5px;text-transform:uppercase">📅 Gün Başı Ortalama</div>
              <div style="font-size:26px;font-weight:900;color:#1e3a5f;margin:4px 0">${dayCount>0?(totalMonth/dayCount).toFixed(2):'0.00'} ₺</div>
              <div style="font-size:10px;color:#1d4ed8">${dayCount} gün ortalaması · 1 kişi</div>
            </div>
            <div style="background:linear-gradient(135deg,#fdf4ff,#f3e8ff);border:2px solid #d8b4fe;border-radius:10px;padding:10px;text-align:center;margin-bottom:10px">
              <div style="font-size:10px;color:#7c3aed;font-weight:800;text-transform:uppercase">👥 30 Kişi Tahmini (Aylık)</div>
              <div style="font-size:20px;font-weight:900;color:#4c1d95;margin:4px 0">${(totalMonth*30).toFixed(2)} ₺</div>
              <div style="font-size:10px;color:#7c3aed">Servis büyüklüğüne göre ayarlayın</div>
            </div>
            <div style="background:#fef9c3;border:1px solid #fde047;border-radius:8px;padding:10px;font-size:10px;color:#854d0e">
              ⚠️ Tahmini fiyatlar kullanılmaktadır. Gerçek maliyet için hammadde fiyatlarını güncelleyin.
            </div>
          </div>
        </div>
      </div>`;
    document.body.appendChild(modal);
  }

  function _getPrices(){
    try{return JSON.parse(localStorage.getItem(PRICE_KEY)||'{}');}catch{return {};}
  }

  /* ══════════════════════════════════════════════════════════════
     PDF İHRACATI
  ══════════════════════════════════════════════════════════════ */
  function _exportPdf(){
    const {year,mo1,cust}=_getParams();
    const store=_getStore();
    const mons=_mondays(year,mo1-1);
    const lastD=new Date(year,mo1,0);

    let weeksHtml='';
    for(let wi=0;wi<mons.length;wi++){
      const days=[];
      for(let d=0;d<7;d++){const dt=new Date(mons[wi]);dt.setDate(mons[wi].getDate()+d);days.push(dt);}
      const menuByDay=days.map(dt=>{
        if(dt.getMonth()!==mo1-1) return null;
        return _getDayMenu(store,year,mo1,cust,dt);
      });
      const hasAny=menuByDay.some(m=>m&&(m.soup||m.main));
      let hdrs=days.map((dt,i)=>{
        const inM=dt.getMonth()===mo1-1, isW=i>=5;
        return `<th style="background:${isW?'#4c1d95':'#1e3a5f'};color:${inM?'#fff':'rgba(255,255,255,.4)'};padding:6px 4px;font-size:10px;text-align:center">
          <div style="font-weight:900">${_p2(dt.getDate())}.${_p2(dt.getMonth()+1)}</div>
          <div style="font-size:9px;opacity:.85">${TR_DAYS_FULL[i]}</div></th>`;
      }).join('');
      const rowDefs=[
        {key:'soup',key2:'soup2',label:'🍲 Çorba'},
        {key:'main',key2:'main2',label:'🍽️ Ana Yemek'},
        {key:'side',key2:'side2',label:'🥘 Yardımcı'},
        {key:'salad',key2:null,label:'🥗 Salata'},
        {key:'dessertName',key2:null,label:'🍮 Tatlı/Meyve'},
        {key:'ayran',key2:null,label:'🥛 Ayran'},
      ];
      let rows=rowDefs.map(rd=>{
        let cells=days.map((dt,i)=>{
          const m=menuByDay[i], inM=dt.getMonth()===mo1-1, isW=i>=5;
          if(!inM||!m) return `<td style="background:#f9fafb;border:1px solid #e5e7eb;padding:4px;text-align:center"><span style="color:#ddd">—</span></td>`;
          const v=m[rd.key]||'', v2=rd.key2?(m[rd.key2]||''):'';
          return `<td style="background:${isW?'#faf5ff':'#fff'};border:1px solid #e5e7eb;padding:4px;text-align:center;font-size:10px">
            ${v?`<div style="font-weight:700;color:#1e293b">${v}</div>`:''} 
            ${v2?`<div style="color:#64748b;font-size:9px">${v2}</div>`:''} 
            ${!v?'<div style="color:#ddd">—</div>':''}</td>`;
        }).join('');
        return `<tr><td style="background:#f1f5f9;border:1px solid #e5e7eb;padding:5px 8px;font-size:10px;font-weight:700;white-space:nowrap">${rd.label}</td>${cells}</tr>`;
      }).join('');
      weeksHtml+=`<div style="margin-bottom:16px;page-break-inside:avoid">
        <div style="background:#1e3a5f;color:#fff;padding:8px 12px;font-weight:700;font-size:12px;border-radius:6px 6px 0 0">
          Hafta ${wi+1} — ${_p2(days[0].getDate())}.${_p2(days[0].getMonth()+1)} – ${_p2(days[6].getDate())}.${_p2(days[6].getMonth()+1)}.${year}
          ${!hasAny?'<span style="background:rgba(255,255,255,.2);padding:2px 8px;border-radius:4px;font-size:10px;margin-left:8px">Kayıt yok</span>':''}
        </div>
        <div style="overflow:hidden;border-radius:0 0 6px 6px">
          <table style="width:100%;border-collapse:collapse">
            <thead><tr><th style="background:#0f172a;color:#fff;padding:6px 8px;font-size:10px;text-align:left">YEMEK / TARİH</th>${hdrs}</tr></thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </div>`;
    }

    const pdfHtml=`<!DOCTYPE html><html><head><meta charset="utf-8">
      <title>UYSA Menü — ${TR_MONTHS_ARR[mo1]} ${year}</title>
      <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI',sans-serif}
        @page{size:A4 landscape;margin:15mm}
        body{padding:0;background:#fff;color:#1e293b}
        h1{font-size:18px;color:#1e3a5f;margin-bottom:4px}
        .sub{font-size:12px;color:#64748b;margin-bottom:16px}
        @media print{button{display:none!important}}
      <\/style>
<style>
/* UYSA v78 - Puantaj */
.ik-pane { display: block; }
.puantaj-cell {
  padding: 4px 2px;
  text-align: center;
  font-size: 11px;
  cursor: pointer;
  border-radius: 4px;
  min-width: 32px;
}
.puantaj-select {
  width: 36px;
  padding: 2px;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  font-size: 10px;
  text-align: center;
  cursor: pointer;
}
.durum-iste     { background:#d1fae5; color:#065f46; }
.durum-ucretli  { background:#dbeafe; color:#1e40af; }
.durum-ucretsiz { background:#fee2e2; color:#991b1b; }
.durum-raporlu  { background:#e0e7ff; color:#3730a3; }
.durum-tatil    { background:#fef9c3; color:#854d0e; }
.durum-devsz    { background:#fecaca; color:#7f1d1d; }
.durum-yari     { background:#f3e8ff; color:#6b21a8; }
.durum-bos      { background:#f1f5f9; color:#94a3b8; }
@media print {
  .no-print { display:none!important; }
  #puantajChizelge { box-shadow:none; }
}
<\/style>

<style id="v80-sidebar-css">
/* ── v80: CRM Detay Sidebar ───────────────────────────────── */
#crmDetailSidebar {
  font-family: inherit;
}
#crmDetailSidebar::-webkit-scrollbar { width: 6px; }
#crmDetailSidebar::-webkit-scrollbar-track { background: #f1f5f9; }
#crmDetailSidebar::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 3px; }

#crmSidebarBody::-webkit-scrollbar { width: 5px; }
#crmSidebarBody::-webkit-scrollbar-track { background: #f8fafc; }
#crmSidebarBody::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }

/* Müşteri listesi satır hover */
#v75MusteriListeDiv tr { transition: background .12s; }
#v75MusteriListeDiv tr:hover td { background: #eff6ff !important; }

/* Animasyon */
@keyframes sidebarIn {
  from { transform: translateX(100%); opacity: 0; }
  to   { transform: translateX(0);    opacity: 1; }
}

/* Mobil: tam genişlik */
@media (max-width: 480px) {
  #crmDetailSidebar { width: 100vw !important; }
}
<\/style>

</head><body>
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;border-bottom:2px solid #1e3a5f;padding-bottom:10px">
        <div>
          <h1>📅 UYSA — Aylık Menü Planı</h1>
          <div class="sub">${TR_MONTHS_ARR[mo1]} ${year} · Müşteri: ${cust}</div>
        </div>
        <div style="text-align:right;font-size:10px;color:#94a3b8">
          Oluşturulma: ${new Date().toLocaleDateString('tr-TR')}<br>UYSA Yemek Yönetim Sistemi
        </div>
      </div>
      ${weeksHtml||'<p style="text-align:center;color:#94a3b8;padding:40px">Bu ay için menü kaydı bulunamadı.</p>'}
      <div style="margin-top:12px;border-top:1px solid #e5e7eb;padding-top:8px;font-size:9px;color:#94a3b8;display:flex;justify-content:space-between">
        <span>UYSA Yemek Yönetim Sistemi — Gizli</span><span>Sayfa: <span class="pg"></span></span>
      </div>
      <script>window.onload=()=>window.print();<\/script>
    </body></html>`;

    const w=window.open('','_blank','width=1200,height=800');
    if(w){ w.document.write(pdfHtml); w.document.close(); }
    else { _toast('❌ Popup engellendi. Tarayıcı ayarlarından popup iznini etkinleştirin.'); }
  }

  /* ══════════════════════════════════════════════════════════════
     ARŞİV MODALİ — Kayıtlı tüm aylar
  ══════════════════════════════════════════════════════════════ */
  function _openArchiveModal(){
    const store=_getStore();
    const cust=document.getElementById('customerSelect')?.value||'GENEL';

    // Tüm kayıtlı yıl-ay kombinasyonlarını çıkar
    const entries=[];
    Object.keys(store).forEach(k=>{
      const m=k.match(/^(\d{4})-(\d{2})::(.+)$/);
      if(m){
        const [,y,mo,c]=m;
        const weeks=store[k]?.weeks||{};
        const wkCount=Object.keys(weeks).length;
        const hasData=Object.values(weeks).some(w=>(w.soups||[]).some(s=>s));
        entries.push({year:parseInt(y),mo1:parseInt(mo),cust:c,wkCount,hasData,key:k});
      }
    });
    entries.sort((a,b)=>b.year!==a.year?b.year-a.year:b.mo1!==a.mo1?b.mo1-a.mo1:a.cust.localeCompare(b.cust,'tr'));

    // Müşteriye göre filtrele
    const custEntries=entries.filter(e=>e.cust===cust);
    const otherEntries=entries.filter(e=>e.cust!==cust);

    const buildRows=(list)=>list.map(e=>`
      <tr style="cursor:pointer" onclick="window._archiveLoad(${e.year},${e.mo1},'${e.cust}')"
        onmouseenter="this.style.background='#eff6ff'" onmouseleave="this.style.background=''"
      >
        <td style="padding:8px 12px;border:1px solid #e5e7eb;font-weight:700;color:#1e3a5f">${TR_MONTHS_ARR[e.mo1]} ${e.year}</td>
        <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#475569">${e.cust}</td>
        <td style="padding:8px 12px;border:1px solid #e5e7eb;text-align:center">${e.wkCount} hafta</td>
        <td style="padding:8px 12px;border:1px solid #e5e7eb;text-align:center">
          ${e.hasData?'<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700">✅ Veri var</span>':'<span style="background:#fee2e2;color:#dc2626;padding:2px 8px;border-radius:6px;font-size:11px">❌ Boş</span>'}
        </td>
        <td style="padding:8px 12px;border:1px solid #e5e7eb;text-align:center">
          <button onclick="event.stopPropagation();window._archiveLoad(${e.year},${e.mo1},'${e.cust}')" 
            style="background:#1a56db;color:#fff;border:none;padding:4px 12px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600">
            📂 Yükle
          </button>
          <button onclick="event.stopPropagation();window._archivePdf(${e.year},${e.mo1},'${e.cust}')" 
            style="background:#047857;color:#fff;border:none;padding:4px 12px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;margin-left:4px">
            📄 PDF
          </button>
        </td>
      </tr>`).join('');

    const mid='uysa-archive-modal';
    document.getElementById(mid)?.remove();
    const modal=document.createElement('div');
    modal.id=mid;
    modal.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:15000;display:flex;align-items:flex-start;justify-content:center;padding:16px;overflow:auto';
    modal.addEventListener('click',e=>{if(e.target===modal)modal.remove();});
    modal.innerHTML=`
      <div style="background:#fff;border-radius:16px;width:100%;max-width:800px;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden">
        <div style="background:linear-gradient(135deg,#1e3a5f,#4c1d95);color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center">
          <div>
            <b style="font-size:17px">📚 Menü Arşivi</b>
            <div style="font-size:12px;opacity:.8;margin-top:2px">Kayıtlı tüm aylık menüler · Tıklayarak yükle veya PDF al</div>
          </div>
          <button onclick="document.getElementById('${mid}').remove()" style="background:none;border:none;color:#fff;font-size:28px;cursor:pointer;line-height:1">×</button>
        </div>
        <div style="padding:16px;max-height:75vh;overflow-y:auto">
          ${custEntries.length>0?`
          <h4 style="margin:0 0 8px;color:#1e3a5f">👤 ${cust} — Menü Kayıtları (${custEntries.length})</h4>
          <table style="width:100%;border-collapse:collapse;margin-bottom:16px">
            <thead><tr style="background:linear-gradient(135deg,#1e3a5f,#1a56db);color:#fff">
              <th style="padding:8px 12px;text-align:left;font-size:11px">Ay / Yıl</th>
              <th style="padding:8px 12px;text-align:left;font-size:11px">Müşteri</th>
              <th style="padding:8px 12px;text-align:center;font-size:11px">Hafta</th>
              <th style="padding:8px 12px;text-align:center;font-size:11px">Durum</th>
              <th style="padding:8px 12px;text-align:center;font-size:11px">İşlem</th>
            </tr></thead>
            <tbody>${buildRows(custEntries)}</tbody>
          </table>`:
          `<div style="text-align:center;padding:20px;color:#94a3b8">👤 ${cust} için kayıtlı menü bulunamadı.</div>`}

          ${otherEntries.length>0?`
          <details style="margin-top:8px">
            <summary style="cursor:pointer;font-size:13px;font-weight:700;color:#475569;padding:8px;background:#f8fafc;border-radius:8px">
              📋 Diğer Müşteriler (${otherEntries.length} kayıt)
            </summary>
            <table style="width:100%;border-collapse:collapse;margin-top:8px">
              <thead><tr style="background:#f1f5f9">
                <th style="padding:7px 10px;text-align:left;font-size:11px">Ay / Yıl</th>
                <th style="padding:7px 10px;text-align:left;font-size:11px">Müşteri</th>
                <th style="padding:7px 10px;text-align:center;font-size:11px">Hafta</th>
                <th style="padding:7px 10px;text-align:center;font-size:11px">Durum</th>
                <th style="padding:7px 10px;text-align:center;font-size:11px">İşlem</th>
              </tr></thead>
              <tbody>${buildRows(otherEntries)}</tbody>
            </table>
          </details>`:''
          }
          ${entries.length===0?'<div style="text-align:center;padding:48px;color:#94a3b8;font-size:15px">📭 Henüz kayıtlı menü bulunmuyor.<br><small>Menü sekmesinden haftalık menüleri kaydedip başlayın.</small></div>':''}
        </div>
      </div>`;
    document.body.appendChild(modal);

    // Global yükleme fonksiyonu
    window._archiveLoad=(y,m,c)=>{
      const yearEl=document.getElementById('year');
      const monthEl=document.getElementById('month');
      const custEl=document.getElementById('customerSelect');
      if(yearEl) yearEl.value=y;
      if(monthEl) monthEl.value=_p2(m);
      if(custEl){
        // Müşteriyi seç
        const opt=[...custEl.options].find(o=>o.value===c);
        if(opt) custEl.value=c;
      }
      document.getElementById(mid)?.remove();
      _renderInlineCalendar();
      _toast(`✅ ${TR_MONTHS_ARR[m]} ${y} — ${c} yüklendi`);
      // Mevcut state'i güncelle (varsa)
      if(typeof window._menuRenderTable==='function') {
        try{ window._menuRenderTable(); }catch{}
      }
    };
    window._archivePdf=(y,m,c)=>{
      const origY=document.getElementById('year')?.value;
      const origM=document.getElementById('month')?.value;
      const origC=document.getElementById('customerSelect')?.value;
      const ye=document.getElementById('year');
      const me=document.getElementById('month');
      const ce=document.getElementById('customerSelect');
      if(ye) ye.value=y; if(me) me.value=_p2(m);
      if(ce&&[...ce.options].find(o=>o.value===c)) ce.value=c;
      setTimeout(()=>{
        _exportPdf();
        setTimeout(()=>{
          if(ye&&origY) ye.value=origY;
          if(me&&origM) me.value=origM;
          if(ce&&origC) ce.value=origC;
          _renderInlineCalendar();
        },500);
      },100);
    };
  }

  /* ══════════════════════════════════════════════════════════════
     MEVCUT statusLine/errors referanslarını override et
  ══════════════════════════════════════════════════════════════ */
  function _patchStatusLine(){
    const origSL=document.getElementById('statusLine');
    const origErr=document.getElementById('errors');
    const newSL=document.getElementById('inlineStatusLine');
    const newErr=document.getElementById('inlineErrors');
    if(!origSL||!newSL) return;
    // Proxy: eski elementin innerHTML/textContent yazımlarını yeni panele yönlendir
    const observer=new MutationObserver(()=>{
      newSL.innerHTML=origSL.innerHTML;
    });
    observer.observe(origSL,{childList:true,characterData:true,subtree:true});
    if(origErr&&newErr){
      const observer2=new MutationObserver(()=>{
        newErr.innerHTML=origErr.innerHTML;
      });
      observer2.observe(origErr,{childList:true,characterData:true,subtree:true});
    }
  }

  /* ══════════════════════════════════════════════════════════════
     INIT
  ══════════════════════════════════════════════════════════════ */
  function _init69(){
    // Inline takvimi ilk kez render et
    _renderInlineCalendar();

    // Ay/Yıl/Müşteri değişince takvimi güncelle
    ['year','month','customerSelect'].forEach(id=>{
      document.getElementById(id)?.addEventListener('change',()=>_renderInlineCalendar());
    });

    // Buton bağlamaları
    const costBtn=document.getElementById('inlineCostBtn');
    if(costBtn){ costBtn.onclick=()=>_openCostModal(); }

    const pdfBtn=document.getElementById('inlinePdfBtn');
    if(pdfBtn){ pdfBtn.onclick=()=>_exportPdf(); }

    const histBtn=document.getElementById('inlineHistoryBtn');
    if(histBtn){ histBtn.onclick=()=>_openArchiveModal(); }

    // Aylık Formatı Göster (bar'daki buton)
    const mBtn=document.getElementById('monthlyViewBtn');
    if(mBtn){
      const nb=mBtn.cloneNode(true);
      mBtn.parentNode.replaceChild(nb,mBtn);
      nb.addEventListener('click',e=>{
        e.stopImmediatePropagation();
        if(typeof openMonthlyExcelModal==='function') openMonthlyExcelModal();
        else _renderMonthlyFull();
      },true);
    }
    window._renderMonthlyFull=_renderMonthlyFull;

    // Takvim butonu (bar'daki)
    const cBtn=document.getElementById('calendarViewBtn');
    if(cBtn){
      const nb2=cBtn.cloneNode(true);
      cBtn.parentNode.replaceChild(nb2,cBtn);
      nb2.addEventListener('click',e=>{e.stopImmediatePropagation();_openArchiveModal();},true);
    }
    window.renderCalendarModal=_renderInlineCalendar;
    window._renderCalendarFull=_renderInlineCalendar;
    window._renderInlineCalendar=_renderInlineCalendar;
    window._openDayEditModal=_openEditModal;
    window._exportMenuPdf=_exportPdf;
    window._openArchiveModal=_openArchiveModal;
    window._openCostModal=_openCostModal;

    // statusLine observer
    setTimeout(_patchStatusLine,500);

    // saveBtn'i dinle — kaydettikten sonra takvimi güncelle
    document.getElementById('saveBtn')?.addEventListener('click',()=>{
      setTimeout(_renderInlineCalendar,300);
    });

    console.log('✅ UYSA v78 inline calendar+cost+pdf+archive loaded');
  }

  /* ══════════════════════════════════════════════════════════════
     AYLIK FORMAT MODAL (v68'den taşındı)
  ══════════════════════════════════════════════════════════════ */
  function _renderMonthlyFull(){
    const {year,mo1,cust}=_getParams();
    const store=_getStore();
    const mons=_mondays(year,mo1-1);
    let weeksHtml='';
    for(let wi=0;wi<mons.length;wi++){
      const weekIdx1=wi+1, mon=mons[wi];
      const days=[];
      for(let d=0;d<7;d++){const dt=new Date(mon);dt.setDate(mon.getDate()+d);days.push(dt);}
      const menuByDay=days.map(dt=>{
        if(dt.getMonth()!==mo1-1) return null;
        return _getDayMenu(store,year,mo1,cust,dt);
      });
      const hasAny=menuByDay.some(m=>m&&(m.soup||m.main));
      let hdrs=days.map((dt,i)=>{
        const inM=dt.getMonth()===mo1-1,isW=i>=5;
        return `<th style="padding:6px 4px;font-size:11px;text-align:center;min-width:100px;background:${isW?'linear-gradient(135deg,#6d28d9,#4c1d95)':'linear-gradient(135deg,#1e3a5f,#1a56db)'};color:${inM?'#fff':'rgba(255,255,255,.35)'};border-right:1px solid rgba(255,255,255,.15)">
          <div style="font-weight:900;font-size:13px">${_p2(dt.getDate())}.${_p2(dt.getMonth()+1)}</div>
          <div style="font-size:10px;opacity:.8;margin-top:1px">${TR_DAYS_FULL[i]}</div></th>`;
      }).join('');
      const rowDefs=[
        {key:'soup',key2:'soup2',label:'🍲 Çorba',bg:'#eff6ff',border:'#bfdbfe',tc:'#1e40af'},
        {key:'main',key2:'main2',label:'🍽️ Ana Yemek',bg:'#f0fdf4',border:'#86efac',tc:'#166534'},
        {key:'side',key2:'side2',label:'🥘 Yardımcı',bg:'#fefce8',border:'#fde047',tc:'#854d0e'},
        {key:'salad',key2:null,label:'🥗 Salata',bg:'#f0f9ff',border:'#7dd3fc',tc:'#0369a1'},
        {key:'dessertName',key2:null,label:'🍮 Tatlı/Meyve',bg:'#fdf4ff',border:'#d8b4fe',tc:'#7c3aed'},
        {key:'ayran',key2:null,label:'🥛 Ayran',bg:'#f8fafc',border:'#cbd5e1',tc:'#374151'},
      ];
      let rows=rowDefs.map(rd=>{
        const hasD=menuByDay.some(m=>m&&(m[rd.key]||(rd.key2&&m[rd.key2])));
        let cells=days.map((dt,i)=>{
          const m=menuByDay[i],inM=dt.getMonth()===mo1-1,isW=i>=5;
          if(!inM||!m) return `<td style="background:#f9fafb;border:1px solid #e5e7eb;padding:5px;text-align:center"><span style="color:#eee;font-size:10px">—</span></td>`;
          const v=m[rd.key]||'',v2=rd.key2?(m[rd.key2]||''):'';
          return `<td style="background:${isW?rd.bg:rd.bg};border:1px solid ${rd.border};padding:5px 4px;text-align:center;vertical-align:middle">
            ${v?`<div style="font-size:11px;font-weight:700;color:${rd.tc}">${v}</div>`:'<div style="color:#ddd;font-size:10px">—</div>'}
            ${v2?`<div style="font-size:10px;color:#64748b;margin-top:2px;padding-top:2px;border-top:1px dashed ${rd.border}">${v2}</div>`:''}</td>`;
        }).join('');
        return `<tr><td style="background:linear-gradient(135deg,${rd.bg},#fff);border:1px solid ${rd.border};padding:6px 10px;font-size:11px;font-weight:800;color:${rd.tc};white-space:nowrap;min-width:130px">${rd.label}</td>${cells}</tr>`;
      }).join('');
      weeksHtml+=`<div style="margin-bottom:16px;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.07)">
        <div style="background:linear-gradient(135deg,#1e3a5f,#2d4e7a);color:#fff;padding:9px 14px;display:flex;justify-content:space-between;align-items:center">
          <b style="font-size:13px">Hafta ${weekIdx1}</b>
          <span style="font-size:11px;opacity:.8">${_p2(days[0].getDate())}.${_p2(days[0].getMonth()+1)} — ${_p2(days[6].getDate())}.${_p2(days[6].getMonth()+1)}.${year}</span>
          ${!hasAny?'<span style="background:rgba(255,255,255,.2);padding:2px 8px;border-radius:6px;font-size:10px">Kayıt yok</span>':''}
        </div>
        <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:700px">
          <thead><tr><th style="background:#0f172a;color:#fff;padding:7px 10px;font-size:11px;min-width:130px">YEMEK / TARİH</th>${hdrs}</tr></thead>
          <tbody>${rows}</tbody></table></div></div>`;
    }
    const modalId='uysa-monthly-modal';
    document.getElementById(modalId)?.remove();
    const modal=document.createElement('div');
    modal.id=modalId;
    modal.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:flex-start;justify-content:center;padding:16px;overflow:auto';
    modal.addEventListener('click',e=>{if(e.target===modal)modal.remove();});
    modal.innerHTML=`<div style="background:#f8fafc;border-radius:16px;width:100%;max-width:1200px;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden">
      <div style="background:linear-gradient(135deg,#1e3a5f,#1a56db);color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center">
        <div><b style="font-size:17px">📅 ${TR_MONTHS_ARR[mo1]} ${year} — Aylık Menü</b>
          <div style="font-size:12px;opacity:.8;margin-top:2px">👤 ${cust}</div></div>
        <div style="display:flex;gap:8px">
          <button onclick="window.print()" style="background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.4);color:#fff;padding:7px 14px;border-radius:8px;cursor:pointer;font-size:12px">🖨️ Yazdır</button>
          <button onclick="document.getElementById('${modalId}').remove()" style="background:none;border:none;color:#fff;cursor:pointer;font-size:28px;line-height:1;padding:0">×</button>
        </div>
      </div>
      <div style="padding:16px;max-height:82vh;overflow-y:auto">
        ${weeksHtml||'<div style="text-align:center;padding:48px;color:#94a3b8">Bu ay için menü kaydı bulunamadı.</div>'}
      </div></div>`;
    document.body.appendChild(modal);
  }

  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',_init69);
  } else {
    _init69();
  }

})();
