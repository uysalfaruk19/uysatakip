/* ═══════════════════════════════════════════════════════════════
   UYSA Catering (COZBIMESKİ) Frontend Module v5.0
   Backend: cat.* API endpoints (CateringModule.php)
   ═══════════════════════════════════════════════════════════════ */
(function(){
'use strict';

var API = '/uysa_api.php';
function getToken(){
  try{ var s=JSON.parse(sessionStorage.getItem('uysa_session')||'null'); return s&&s.token||''; }catch(e){ return ''; }
}
function toast(msg,tip){
  if(typeof window._uysaToast==='function') return window._uysaToast(msg,tip==='error'?3000:2000);
  alert(msg);
}
function fmt(n){ return Number(n||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function today(){
  var d=new Date();
  return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
}

function api(action, params, body){
  var qs='action='+action;
  if(params) Object.keys(params).forEach(function(k){ if(params[k]!==''&&params[k]!=null) qs+='&'+k+'='+encodeURIComponent(params[k]); });
  var opts={method:body?'POST':'GET', headers:{'Content-Type':'application/json','X-UYSA-Token':getToken()}};
  if(body) opts.body=JSON.stringify(body);
  return fetch(API+'?'+qs, opts).then(function(r){ return r.json(); });
}

// ── Tab Switching ────────────────────────────────────────────
var currentTab='dashboard';
window.catSwitchTab=function(tab){
  currentTab=tab;
  var tabs=['dashboard','dishes','menuPlans','parties','deliveries','haccp'];
  tabs.forEach(function(t){
    var panel=document.getElementById('catPanel'+t.charAt(0).toUpperCase()+t.slice(1));
    if(panel) panel.style.display=(t===tab)?'':'none';
    var btn=document.getElementById('catTab'+t.charAt(0).toUpperCase()+t.slice(1));
    if(btn) btn.style.fontWeight=(t===tab)?'700':'400';
  });
  if(tab==='dashboard') loadDashboard();
  else if(tab==='dishes') loadDishes();
  else if(tab==='menuPlans') loadMenuPlans();
  else if(tab==='parties') loadParties();
  else if(tab==='deliveries') loadDeliveries();
  else if(tab==='haccp') loadHaccp();
};

// ══════════════════════════════════════════════════════════════
// DASHBOARD
// ══════════════════════════════════════════════════════════════
function loadDashboard(){
  api('cat.dashboard',{}).then(function(r){
    if(!r.ok) return;
    var s=r.stats||{};
    var el=document.getElementById('catDashStats');
    if(el) el.innerHTML=
      '<div style="background:linear-gradient(135deg,#dbeafe,#bfdbfe);padding:16px;border-radius:10px;text-align:center"><div style="font-size:28px;font-weight:700;color:#1e40af">'+s.active_dishes+'</div><div style="font-size:12px;color:#3b82f6">Aktif Yemek</div></div>'+
      '<div style="background:linear-gradient(135deg,#d1fae5,#a7f3d0);padding:16px;border-radius:10px;text-align:center"><div style="font-size:28px;font-weight:700;color:#065f46">'+s.recipes_defined+'</div><div style="font-size:12px;color:#059669">Reçete Tanımlı</div></div>'+
      '<div style="background:linear-gradient(135deg,#fef3c7,#fde68a);padding:16px;border-radius:10px;text-align:center"><div style="font-size:28px;font-weight:700;color:#92400e">'+s.active_parties+'</div><div style="font-size:12px;color:#d97706">Aktif Cari</div></div>'+
      '<div style="background:linear-gradient(135deg,'+(s.haccp_fails_7d>0?'#fee2e2,#fecaca':'#ecfdf5,#d1fae5')+');padding:16px;border-radius:10px;text-align:center"><div style="font-size:28px;font-weight:700;color:'+(s.haccp_fails_7d>0?'#dc2626':'#16a34a')+'">'+s.haccp_fails_7d+'</div><div style="font-size:12px;color:#6b7280">HACCP Uygunsuz (7g)</div></div>';

    var menus=r.today_menus||[];
    var mel=document.getElementById('catTodayMenus');
    if(mel) mel.innerHTML=menus.length? menus.map(function(m){
      var ogun={'kahvalti':'Kahvaltı','ogle':'Öğle','aksam':'Akşam','ara_ogun':'Ara Öğün'};
      return '<div style="padding:8px;border-bottom:1px solid #e2e8f0"><b>'+( ogun[m.meal_type]||m.meal_type)+'</b> — '+(m.customer_name||'Genel')+' <span style="font-size:11px;color:#64748b">['+m.status+']</span></div>';
    }).join('') : '<div style="color:#9ca3af;padding:16px;text-align:center">Bugün menü planı yok</div>';

    var dlvs=r.today_deliveries||[];
    var del=document.getElementById('catTodayDeliveries');
    if(del) del.innerHTML=dlvs.length? dlvs.map(function(d){
      var st={'planned':'Planlandı','loading':'Yükleniyor','in_transit':'Yolda','delivered':'Teslim Edildi'};
      return '<div style="padding:8px;border-bottom:1px solid #e2e8f0"><b>'+d.delivery_no+'</b> — '+(d.customer_name||'-')+' <span style="font-size:11px;color:#64748b">'+d.portion_count+' porsiyon ['+( st[d.status]||d.status)+']</span></div>';
    }).join('') : '<div style="color:#9ca3af;padding:16px;text-align:center">Bugün teslimat yok</div>';
  }).catch(function(){ });
}

// ══════════════════════════════════════════════════════════════
// DISHES (Yemek Kataloğu)
// ══════════════════════════════════════════════════════════════
function loadDishes(){
  var search=(document.getElementById('catDishSearch')||{}).value||'';
  var cat=(document.getElementById('catDishCategory')||{}).value||'';
  api('cat.dishes',{search:search,category:cat,limit:200}).then(function(r){
    if(!r.ok) return;
    var tbody=document.getElementById('catDishTbody');
    if(!tbody) return;
    var catLabels={corba:'Çorba',ana_yemek:'Ana Yemek',salata:'Salata',tatli:'Tatlı',meze:'Meze',icecek:'İçecek',aperatif:'Aperatif',diger:'Diğer'};
    tbody.innerHTML=(r.dishes||[]).map(function(d){
      return '<tr>'+
        '<td><code>'+d.code+'</code></td>'+
        '<td><b>'+d.name+'</b></td>'+
        '<td>'+(catLabels[d.category]||d.category)+'</td>'+
        '<td>'+(d.portion_gram?d.portion_gram+'g':'—')+'</td>'+
        '<td>'+fmt(d.dish_price)+' ₺</td>'+
        '<td>'+(d.cost_price?fmt(d.cost_price)+' ₺':'—')+'</td>'+
        '<td>'+(d.is_active==1?'<span style="color:#16a34a">Aktif</span>':'<span style="color:#dc2626">Pasif</span>')+'</td>'+
        '<td><button class="btn" style="font-size:11px;padding:2px 8px" onclick="window.catDishEdit('+d.id+')">Düzenle</button> '+
        '<button class="btn danger" style="font-size:11px;padding:2px 8px" onclick="window.catDishDel('+d.id+')">Sil</button></td>'+
        '</tr>';
    }).join('')||'<tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:20px">Yemek bulunamadı</td></tr>';
  });
}
window.catLoadDishes=loadDishes;

window.catDishForm=function(data){
  var area=document.getElementById('catDishFormArea');
  area.style.display='';
  document.getElementById('catDishId').value=(data&&data.id)||'';
  document.getElementById('catDishCode').value=(data&&data.code)||'';
  document.getElementById('catDishName').value=(data&&data.name)||'';
  document.getElementById('catDishCat').value=(data&&data.category)||'ana_yemek';
  document.getElementById('catDishGram').value=(data&&data.portion_gram)||'';
  document.getElementById('catDishCalorie').value=(data&&data.calorie)||'';
  document.getElementById('catDishPrice').value=(data&&data.dish_price)||'';
  document.getElementById('catDishCost').value=(data&&data.cost_price)||'';
};

window.catDishEdit=function(id){
  api('cat.dishGet',{id:id}).then(function(r){
    if(r.ok) window.catDishForm(r.dish);
  });
};

window.catDishSave=function(){
  var id=document.getElementById('catDishId').value;
  var body={
    id:id?parseInt(id):0,
    code:document.getElementById('catDishCode').value.trim(),
    name:document.getElementById('catDishName').value.trim(),
    category:document.getElementById('catDishCat').value,
    portion_gram:document.getElementById('catDishGram').value||null,
    calorie:document.getElementById('catDishCalorie').value||null,
    dish_price:document.getElementById('catDishPrice').value||0,
    cost_price:document.getElementById('catDishCost').value||null,
    is_active:1
  };
  if(!body.code||!body.name) return toast('Kod ve ad zorunlu','error');
  api('cat.dishSave',{},body).then(function(r){
    if(!r.ok) return toast(r.error||'Hata','error');
    toast('Yemek kaydedildi');
    document.getElementById('catDishFormArea').style.display='none';
    loadDishes();
  });
};

window.catDishDel=function(id){
  if(!confirm('Bu yemeği silmek istediğinize emin misiniz?')) return;
  api('cat.dishDelete',{},{id:id}).then(function(r){
    if(!r.ok) return toast(r.error||'Hata','error');
    toast('Yemek silindi');
    loadDishes();
  });
};

// ══════════════════════════════════════════════════════════════
// MENU PLANS (Menü Planları)
// ══════════════════════════════════════════════════════════════
function loadMenuPlans(){
  var start=(document.getElementById('catMenuStartDate')||{}).value||'';
  var end=(document.getElementById('catMenuEndDate')||{}).value||'';
  var meal=(document.getElementById('catMenuMealType')||{}).value||'';
  api('cat.menuPlans',{start_date:start,end_date:end,meal_type:meal,limit:100}).then(function(r){
    if(!r.ok) return;
    var tbody=document.getElementById('catMenuTbody');
    if(!tbody) return;
    var ogun={kahvalti:'Kahvaltı',ogle:'Öğle',aksam:'Akşam',ara_ogun:'Ara Öğün'};
    var durum={draft:'Taslak',confirmed:'Onaylı',served:'Servis Edildi',cancelled:'İptal'};
    var durumRenk={draft:'#6b7280',confirmed:'#2563eb',served:'#16a34a',cancelled:'#dc2626'};
    tbody.innerHTML=(r.menu_plans||[]).map(function(p){
      return '<tr>'+
        '<td>'+p.plan_date+'</td>'+
        '<td>'+(ogun[p.meal_type]||p.meal_type)+'</td>'+
        '<td>'+(p.customer_name||'Genel')+'</td>'+
        '<td style="color:'+(durumRenk[p.status]||'#374151')+';font-weight:600">'+(durum[p.status]||p.status)+'</td>'+
        '<td><button class="btn" style="font-size:11px;padding:2px 8px" onclick="window.catMenuPlanEdit('+p.id+')">Düzenle</button> '+
        '<button class="btn danger" style="font-size:11px;padding:2px 8px" onclick="window.catMenuPlanDel('+p.id+')">Sil</button></td>'+
        '</tr>';
    }).join('')||'<tr><td colspan="5" style="text-align:center;color:#9ca3af;padding:20px">Menü planı bulunamadı</td></tr>';
  });
}
window.catLoadMenuPlans=loadMenuPlans;

var menuItemCounter=0;
window.catMenuAddItem=function(data){
  menuItemCounter++;
  var list=document.getElementById('catMenuItemsList');
  var div=document.createElement('div');
  div.id='catMenuItem_'+menuItemCounter;
  div.style.cssText='display:grid;grid-template-columns:200px 100px 100px 40px;gap:8px;margin-bottom:6px;align-items:center';
  div.innerHTML=
    '<select class="inp catMenuDishSel" style="font-size:12px"><option value="">Yemek seçin...</option></select>'+
    '<select class="inp" style="font-size:12px"><option value="ana">Ana</option><option value="corba">Çorba</option><option value="yan">Yan</option><option value="salata">Salata</option><option value="tatli">Tatlı</option><option value="icecek">İçecek</option></select>'+
    '<input class="inp" type="number" placeholder="Porsiyon" style="font-size:12px" value="'+(data&&data.portion_count||'')+'"/>'+
    '<button class="btn danger" style="font-size:10px;padding:2px 6px" onclick="this.parentElement.remove()">X</button>';
  list.appendChild(div);
  var sel=div.querySelector('.catMenuDishSel');
  if(data&&data.dish_id){
    var opt=document.createElement('option');
    opt.value=data.dish_id;opt.textContent=(data.dish_name||'#'+data.dish_id);opt.selected=true;
    sel.appendChild(opt);
  }
  api('cat.dishes',{limit:500}).then(function(r){
    if(!r.ok) return;
    (r.dishes||[]).forEach(function(d){
      if(data&&data.dish_id==d.id) return;
      var o=document.createElement('option');o.value=d.id;o.textContent=d.code+' — '+d.name;sel.appendChild(o);
    });
  });
  if(data&&data.slot){
    var slotSel=div.querySelectorAll('select')[1];
    if(slotSel) slotSel.value=data.slot;
  }
};

window.catMenuPlanForm=function(plan,items){
  var area=document.getElementById('catMenuFormArea');
  area.style.display='';
  document.getElementById('catMenuPlanId').value=(plan&&plan.id)||'';
  document.getElementById('catMenuDate').value=(plan&&plan.plan_date)||today();
  document.getElementById('catMenuMeal').value=(plan&&plan.meal_type)||'ogle';
  document.getElementById('catMenuStatus').value=(plan&&plan.status)||'draft';
  document.getElementById('catMenuItemsList').innerHTML='';
  menuItemCounter=0;
  if(items&&items.length){
    items.forEach(function(it){ window.catMenuAddItem(it); });
  }
};

window.catMenuPlanEdit=function(id){
  api('cat.menuPlanGet',{id:id}).then(function(r){
    if(r.ok) window.catMenuPlanForm(r.plan, r.items);
  });
};

window.catMenuPlanSave=function(){
  var id=document.getElementById('catMenuPlanId').value;
  var items=[];
  var rows=document.getElementById('catMenuItemsList').children;
  for(var i=0;i<rows.length;i++){
    var sels=rows[i].querySelectorAll('select');
    var inp=rows[i].querySelector('input[type=number]');
    var dishId=sels[0]?sels[0].value:0;
    if(!dishId) continue;
    items.push({dish_id:parseInt(dishId),slot:sels[1]?sels[1].value:'ana',portion_count:inp?parseInt(inp.value)||null:null,sort_order:i});
  }
  var body={
    id:id?parseInt(id):0,
    plan_date:document.getElementById('catMenuDate').value,
    meal_type:document.getElementById('catMenuMeal').value,
    customer_id:document.getElementById('catMenuCustomer').value||null,
    status:document.getElementById('catMenuStatus').value,
    items:items
  };
  if(!body.plan_date) return toast('Tarih zorunlu','error');
  api('cat.menuPlanSave',{},body).then(function(r){
    if(!r.ok) return toast(r.error||'Hata','error');
    toast('Menü planı kaydedildi');
    document.getElementById('catMenuFormArea').style.display='none';
    loadMenuPlans();
  });
};

window.catMenuPlanDel=function(id){
  if(!confirm('Bu menü planını silmek istediğinize emin misiniz?')) return;
  api('cat.menuPlanDelete',{},{id:id}).then(function(r){
    if(!r.ok) return toast(r.error||'Hata','error');
    toast('Menü planı silindi');
    loadMenuPlans();
  });
};

// ══════════════════════════════════════════════════════════════
// PARTIES (Cari Hesaplar)
// ══════════════════════════════════════════════════════════════
function loadParties(){
  var search=(document.getElementById('catPartySearch')||{}).value||'';
  var type=(document.getElementById('catPartyType')||{}).value||'';
  api('cat.parties',{search:search,type:type,limit:200}).then(function(r){
    if(!r.ok) return;
    var tbody=document.getElementById('catPartyTbody');
    if(!tbody) return;
    var tipler={musteri:'Müşteri',tedarikci:'Tedarikçi',diger:'Diğer'};
    tbody.innerHTML=(r.parties||[]).map(function(p){
      return '<tr>'+
        '<td><code>'+p.code+'</code></td>'+
        '<td><b>'+p.name+'</b></td>'+
        '<td>'+(tipler[p.type]||p.type)+'</td>'+
        '<td>'+(p.city||'—')+'</td>'+
        '<td>'+(p.phone||'—')+'</td>'+
        '<td style="font-weight:600;color:'+(p.balance>=0?'#16a34a':'#dc2626')+'">'+fmt(p.balance)+' ₺</td>'+
        '<td><button class="btn" style="font-size:11px;padding:2px 8px" onclick="window.catPartyEdit('+p.id+')">Düzenle</button> '+
        '<button class="btn danger" style="font-size:11px;padding:2px 8px" onclick="window.catPartyDel('+p.id+')">Sil</button></td>'+
        '</tr>';
    }).join('')||'<tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:20px">Cari bulunamadı</td></tr>';
  });
}
window.catLoadParties=loadParties;

window.catPartyForm=function(data){
  document.getElementById('catPartyFormArea').style.display='';
  document.getElementById('catPartyId').value=(data&&data.id)||'';
  document.getElementById('catPartyCode').value=(data&&data.code)||'';
  document.getElementById('catPartyName').value=(data&&data.name)||'';
  document.getElementById('catPartyTypeSel').value=(data&&data.type)||'musteri';
  document.getElementById('catPartyTaxNo').value=(data&&data.tax_no)||'';
  document.getElementById('catPartyPhone').value=(data&&data.phone)||'';
  document.getElementById('catPartyEmail').value=(data&&data.email)||'';
  document.getElementById('catPartyCityInp').value=(data&&data.city)||'';
};

window.catPartyEdit=function(id){
  api('cat.partyGet',{id:id}).then(function(r){
    if(r.ok) window.catPartyForm(r.party);
  });
};

window.catPartySave=function(){
  var id=document.getElementById('catPartyId').value;
  var body={
    id:id?parseInt(id):0,
    code:document.getElementById('catPartyCode').value.trim(),
    name:document.getElementById('catPartyName').value.trim(),
    type:document.getElementById('catPartyTypeSel').value,
    tax_no:document.getElementById('catPartyTaxNo').value.trim(),
    phone:document.getElementById('catPartyPhone').value.trim(),
    email:document.getElementById('catPartyEmail').value.trim(),
    city:document.getElementById('catPartyCityInp').value.trim(),
    is_active:1
  };
  if(!body.code||!body.name) return toast('Kod ve ad zorunlu','error');
  api('cat.partySave',{},body).then(function(r){
    if(!r.ok) return toast(r.error||'Hata','error');
    toast('Cari kaydedildi');
    document.getElementById('catPartyFormArea').style.display='none';
    loadParties();
  });
};

window.catPartyDel=function(id){
  if(!confirm('Bu cariyi silmek istediğinize emin misiniz?')) return;
  api('cat.partyDelete',{},{id:id}).then(function(r){
    if(!r.ok) return toast(r.error||'Hata','error');
    toast('Cari silindi');
    loadParties();
  });
};

// ══════════════════════════════════════════════════════════════
// DELIVERIES (Teslimatlar)
// ══════════════════════════════════════════════════════════════
function loadDeliveries(){
  var start=(document.getElementById('catDlvStartDate')||{}).value||'';
  var end=(document.getElementById('catDlvEndDate')||{}).value||'';
  api('cat.deliveries',{start_date:start,end_date:end,limit:100}).then(function(r){
    if(!r.ok) return;
    var tbody=document.getElementById('catDlvTbody');
    if(!tbody) return;
    var st={planned:'Planlandı',loading:'Yükleniyor',in_transit:'Yolda',delivered:'Teslim Edildi',cancelled:'İptal'};
    var stRenk={planned:'#6b7280',loading:'#d97706',in_transit:'#2563eb',delivered:'#16a34a',cancelled:'#dc2626'};
    tbody.innerHTML=(r.deliveries||[]).map(function(d){
      return '<tr>'+
        '<td><b>'+d.delivery_no+'</b></td>'+
        '<td>'+d.delivery_date+'</td>'+
        '<td>'+(d.customer_name||'—')+'</td>'+
        '<td>'+(d.meal_type||'—')+'</td>'+
        '<td>'+d.portion_count+'</td>'+
        '<td>'+(d.vehicle||'—')+'</td>'+
        '<td style="color:'+(stRenk[d.status]||'#374151')+';font-weight:600">'+(st[d.status]||d.status)+'</td>'+
        '<td><button class="btn" style="font-size:11px;padding:2px 8px" onclick="window.catDeliveryEdit('+d.id+')">Düzenle</button> '+
        '<button class="btn danger" style="font-size:11px;padding:2px 8px" onclick="window.catDeliveryDel('+d.id+')">Sil</button></td>'+
        '</tr>';
    }).join('')||'<tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:20px">Teslimat bulunamadı</td></tr>';
  });
}
window.catLoadDeliveries=loadDeliveries;

window.catDeliveryForm=function(data){
  document.getElementById('catDlvFormArea').style.display='';
  document.getElementById('catDlvId').value=(data&&data.id)||'';
  document.getElementById('catDlvNo').value=(data&&data.delivery_no)||'';
  document.getElementById('catDlvDate').value=(data&&data.delivery_date)||today();
  document.getElementById('catDlvVehicle').value=(data&&data.vehicle)||'';
  document.getElementById('catDlvDriver').value=(data&&data.driver)||'';
  document.getElementById('catDlvPortions').value=(data&&data.portion_count)||'';
  document.getElementById('catDlvTemp').value=(data&&data.temperature)||'';
  document.getElementById('catDlvDepart').value=(data&&data.departure_time)||'';
  document.getElementById('catDlvStatus').value=(data&&data.status)||'planned';
};

window.catDeliveryEdit=function(id){
  api('cat.deliveries',{}).then(function(r){
    if(!r.ok) return;
    var d=(r.deliveries||[]).find(function(x){return x.id==id;});
    if(d) window.catDeliveryForm(d);
  });
};

window.catDeliverySave=function(){
  var id=document.getElementById('catDlvId').value;
  var body={
    id:id?parseInt(id):0,
    delivery_no:document.getElementById('catDlvNo').value.trim(),
    delivery_date:document.getElementById('catDlvDate').value,
    customer_id:null,
    meal_type:'ogle',
    vehicle:document.getElementById('catDlvVehicle').value.trim(),
    driver:document.getElementById('catDlvDriver').value.trim(),
    portion_count:parseInt(document.getElementById('catDlvPortions').value)||0,
    temperature:document.getElementById('catDlvTemp').value||null,
    departure_time:document.getElementById('catDlvDepart').value||null,
    status:document.getElementById('catDlvStatus').value
  };
  api('cat.deliverySave',{},body).then(function(r){
    if(!r.ok) return toast(r.error||'Hata','error');
    toast('Teslimat kaydedildi');
    document.getElementById('catDlvFormArea').style.display='none';
    loadDeliveries();
  });
};

window.catDeliveryDel=function(id){
  if(!confirm('Bu teslimatı silmek istediğinize emin misiniz?')) return;
  api('cat.deliveryDelete',{},{id:id}).then(function(r){
    if(!r.ok) return toast(r.error||'Hata','error');
    toast('Teslimat silindi');
    loadDeliveries();
  });
};

// ══════════════════════════════════════════════════════════════
// HACCP (Gıda Güvenliği — Sunucu Entegrasyonu)
// ══════════════════════════════════════════════════════════════
function loadHaccp(){
  var start=(document.getElementById('catHaccpStartDate')||{}).value||'';
  var end=(document.getElementById('catHaccpEndDate')||{}).value||'';
  var type=(document.getElementById('catHaccpType')||{}).value||'';
  var fail=document.getElementById('catHaccpOnlyFail')&&document.getElementById('catHaccpOnlyFail').checked?'1':'';
  api('cat.haccpLogs',{start_date:start,end_date:end,check_type:type,only_fail:fail,limit:200}).then(function(r){
    if(!r.ok) return;
    var logs=r.haccp_logs||[];
    var okCount=logs.filter(function(l){return l.is_ok==1;}).length;
    var failCount=logs.filter(function(l){return l.is_ok==0;}).length;
    var stats=document.getElementById('catHaccpStats');
    if(stats) stats.innerHTML=
      '<div style="background:#d1fae5;padding:10px 16px;border-radius:8px;font-size:13px"><b>'+okCount+'</b><br>Uygun</div>'+
      '<div style="background:#fee2e2;padding:10px 16px;border-radius:8px;font-size:13px"><b>'+failCount+'</b><br>Uygunsuz</div>'+
      '<div style="background:#dbeafe;padding:10px 16px;border-radius:8px;font-size:13px"><b>'+logs.length+'</b><br>Toplam</div>'+
      '<div style="background:#f3f4f6;padding:10px 16px;border-radius:8px;font-size:13px"><b>'+(logs.length?Math.round(okCount/logs.length*100):0)+'%</b><br>Uyumluluk</div>';

    var tbody=document.getElementById('catHaccpTbody');
    if(!tbody) return;
    var tipLabel={temperature:'Sıcaklık',humidity:'Nem',visual:'Görsel',cleaning:'Temizlik',pest:'Haşere',other:'Diğer'};
    tbody.innerHTML=logs.map(function(l){
      var limitStr='';
      if(l.min_limit!=null&&l.max_limit!=null) limitStr=l.min_limit+' — '+l.max_limit;
      else if(l.min_limit!=null) limitStr='min '+l.min_limit;
      else if(l.max_limit!=null) limitStr='max '+l.max_limit;
      return '<tr style="'+(l.is_ok==0?'background:#fef2f2':'')+'">'+
        '<td>'+l.log_date+'</td>'+
        '<td>'+(l.log_time||'—')+'</td>'+
        '<td><b>'+l.check_point+'</b></td>'+
        '<td>'+(tipLabel[l.check_type]||l.check_type)+'</td>'+
        '<td>'+(l.value!=null?l.value:'—')+'</td>'+
        '<td style="font-size:11px;color:#6b7280">'+limitStr+'</td>'+
        '<td style="font-weight:700;color:'+(l.is_ok==1?'#16a34a':'#dc2626')+'">'+(l.is_ok==1?'Uygun':'Uygunsuz')+'</td>'+
        '<td><button class="btn" style="font-size:11px;padding:2px 8px" onclick="window.catHaccpEdit('+l.id+')">Düzenle</button></td>'+
        '</tr>';
    }).join('')||'<tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:20px">HACCP kaydı bulunamadı</td></tr>';
  });
}
window.catLoadHaccp=loadHaccp;

window.catHaccpForm=function(data){
  document.getElementById('catHaccpFormArea').style.display='';
  document.getElementById('catHaccpId').value=(data&&data.id)||'';
  document.getElementById('catHaccpDate').value=(data&&data.log_date)||today();
  document.getElementById('catHaccpTime').value=(data&&data.log_time)||'';
  document.getElementById('catHaccpPoint').value=(data&&data.check_point)||'';
  document.getElementById('catHaccpCheckType').value=(data&&data.check_type)||'temperature';
  document.getElementById('catHaccpValue').value=(data&&data.value)||'';
  document.getElementById('catHaccpMin').value=(data&&data.min_limit)||'';
  document.getElementById('catHaccpMax').value=(data&&data.max_limit)||'';
  document.getElementById('catHaccpBy').value=(data&&data.checked_by)||'';
  document.getElementById('catHaccpAction').value=(data&&data.corrective_action)||'';
};

window.catHaccpEdit=function(id){
  api('cat.haccpLogs',{limit:500}).then(function(r){
    if(!r.ok) return;
    var l=(r.haccp_logs||[]).find(function(x){return x.id==id;});
    if(l) window.catHaccpForm(l);
  });
};

window.catHaccpSave=function(){
  var id=document.getElementById('catHaccpId').value;
  var body={
    id:id?parseInt(id):0,
    log_date:document.getElementById('catHaccpDate').value,
    log_time:document.getElementById('catHaccpTime').value,
    check_point:document.getElementById('catHaccpPoint').value.trim(),
    check_type:document.getElementById('catHaccpCheckType').value,
    value:document.getElementById('catHaccpValue').value||null,
    min_limit:document.getElementById('catHaccpMin').value||null,
    max_limit:document.getElementById('catHaccpMax').value||null,
    checked_by:document.getElementById('catHaccpBy').value.trim(),
    corrective_action:document.getElementById('catHaccpAction').value.trim()
  };
  if(!body.check_point) return toast('Kontrol noktası zorunlu','error');
  api('cat.haccpLogSave',{},body).then(function(r){
    if(!r.ok) return toast(r.error||'Hata','error');
    toast('HACCP kaydı '+(r.is_ok?'(Uygun)':'(UYGUNSUZ!)')+' kaydedildi', r.is_ok?undefined:'error');
    document.getElementById('catHaccpFormArea').style.display='none';
    loadHaccp();
  });
};

// ── Init ─────────────────────────────────────────────────────
function init(){
  var navBtns=document.querySelectorAll('.mod-nav-item');
  navBtns.forEach(function(btn){
    btn.addEventListener('click',function(){
      if(btn.getAttribute('data-module')==='catering'){
        setTimeout(function(){ window.catSwitchTab(currentTab); },50);
      }
    });
  });
}

if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init);
else init();

})();
