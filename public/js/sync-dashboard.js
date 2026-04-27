// ════════════════════════════════════════════════════════════
// UYSA SYNC DASHBOARD v2 — QuotaExceeded fix dahil
// ════════════════════════════════════════════════════════════
(function() {
  'use strict';
  const DAPI_URL   = '/uysa_api.php';
  const _log = [];
  const MAX_LOG = 50;

  // QuotaExceeded fix: büyük key'leri hariç tut
  const BACKUP_SKIP_PATTERNS = [
    /^uysa_local_backups$/,   // kendisi
    /^uysa_audit_logs$/,      // çok büyük olabilir
    /raporlama/i,
    /chart/i,
    /pdf/i,
  ];
  // Kritik key önceliği — bunlar mutlaka yedeklenir
  const BACKUP_PRIORITY = ['uysa_users_v6','uysa_migrated_to_v6'];

  function _getAuthToken() {
    try { const s = JSON.parse(sessionStorage.getItem('uysa_session')||'null'); return s?.token||''; } catch(e){return '';}
  }

  async function dApi(action, body={}) {
    try {
      const r = await fetch(DAPI_URL + '?action=' + action, {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'Authorization': 'Bearer ' + _getAuthToken() },
        body: JSON.stringify(body)
      });
      return await r.json();
    } catch(e) { return { ok: false, error: e.message }; }
  }

  function addLog(type, msg) {
    _log.unshift({ ts: Date.now(), type, msg });
    if (_log.length > MAX_LOG) _log.length = MAX_LOG;
    _renderLog();
  }

  function relTime(ts) {
    const d = Math.floor((Date.now()-ts)/1000);
    if (d < 60) return d + 's önce';
    if (d < 3600) return Math.floor(d/60) + 'dk önce';
    return new Date(ts).toLocaleTimeString('tr-TR');
  }

  function _renderLog() {
    const el = document.getElementById('syncLogList');
    if (!el) return;
    if (!_log.length) { el.innerHTML = '<span style="color:#94a3b8">Henüz aktivite yok</span>'; return; }
    el.innerHTML = _log.slice(0,15).map(l => {
      const icon = l.type==='ok'?'✅':l.type==='warn'?'⚠️':l.type==='err'?'❌':'🔵';
      return '<div>' + icon + ' <span style="color:#94a3b8">' + relTime(l.ts) + '</span> — ' + l.msg + '</div>';
    }).join('');
  }

  // QuotaExceeded fix: akıllı yerel yedek
  window._uysaSmartLocalBackup = function() {
    try {
      const snap = {};
      let totalSize = 0;
      const MAX_BACKUP_SIZE = 800 * 1024; // 800KB limit

      // 1. Önce priority key'leri ekle
      BACKUP_PRIORITY.forEach(k => {
        const v = localStorage.getItem(k);
        if (v) { snap[k] = v; totalSize += v.length; }
      });

      // 2. Sonra diğer key'leri boyuta göre ekle
      for (let i = 0; i < localStorage.length; i++) {
        const k = localStorage.key(i);
        if (!k || !k.startsWith('uysa')) continue;
        if (BACKUP_PRIORITY.includes(k)) continue; // zaten eklendi
        if (BACKUP_SKIP_PATTERNS.some(p => p.test(k))) continue;
        const v = localStorage.getItem(k) || '';
        if (totalSize + v.length > MAX_BACKUP_SIZE) continue; // boyut limiti
        snap[k] = v;
        totalSize += v.length;
      }

      const meta = {
        ts: Date.now(),
        isoDate: new Date().toISOString(),
        keyCount: Object.keys(snap).length,
        sizeKb: Math.round(totalSize / 1024),
        data: snap
      };

      // Son 3 yerel yedeği tut - ama önce mevcut boyutu kontrol et
      let backups = [];
      try { backups = JSON.parse(localStorage.getItem('uysa_local_backups') || '[]'); } catch(e) {}
      backups.unshift(meta);
      if (backups.length > 3) backups.length = 3;

      // _orig.setItem kullan (override bypass)
      const setFn = (window._uysaOrigSetItem) || localStorage.setItem.bind(localStorage);
      try {
        setFn('uysa_local_backups', JSON.stringify(backups));
      } catch(quota) {
        // Quota hatası: sadece 1 yedek sakla
        try { setFn('uysa_local_backups', JSON.stringify([meta])); } catch(e2) {
          // Hala olmuyorsa data'yı çıkar, sadece meta sakla
          const metaNoData = { ts: meta.ts, isoDate: meta.isoDate, keyCount: meta.keyCount, sizeKb: meta.sizeKb };
          try { setFn('uysa_local_backups', JSON.stringify([metaNoData])); } catch(e3) {}
        }
      }

      window._uysaLastLocalBackup = meta.ts;
      window.dispatchEvent(new CustomEvent('uysa-backup-update', { detail: { local: meta.ts, keys: meta.keyCount, sizeKb: meta.sizeKb } }));
      addLog('ok', 'Yerel yedek: ' + meta.keyCount + ' anahtar, ' + meta.sizeKb + 'KB');
      return meta;
    } catch(e) {
      addLog('err', 'Yerel yedek hatası: ' + e.message);
      return null;
    }
  };

  // Eski _uysaLocalBackup'ı smart versiyonla değiştir
  window._uysaLocalBackup = window._uysaSmartLocalBackup;

  async function refreshDashboard() {
    // Sunucu stats
    const stats = await dApi('stats');
    const sv = document.getElementById('sc-server-val');
    const ss = document.getElementById('sc-server-sub');
    if (sv) {
      if (stats.ok) {
        sv.textContent = stats.key_count || 0;
        ss.textContent = (stats.data_size_kb||0) + ' KB — ' + (stats.last_update||'').slice(0,16);
        addLog('ok', 'Sunucu: ' + stats.key_count + ' anahtar, ' + stats.data_size_kb + 'KB');
      } else {
        sv.textContent = '✕'; ss.textContent = 'Bağlantı yok';
        addLog('err', 'Sunucu bağlantısı başarısız');
      }
    }

    // Yerel localStorage
    const lv = document.getElementById('sc-local-val');
    const ls = document.getElementById('sc-local-sub');
    if (lv) {
      let localKeys = 0, localSize = 0;
      for (let i = 0; i < localStorage.length; i++) {
        const k = localStorage.key(i);
        if (k && k.startsWith('uysa')) { localKeys++; localSize += (localStorage.getItem(k)||'').length; }
      }
      lv.textContent = localKeys;
      ls.textContent = '~' + Math.round(localSize/1024) + 'KB — ' + new Date().toLocaleTimeString('tr-TR');
    }

    // Kullanıcılar
    const userList = await dApi('userList');
    const uv = document.getElementById('sc-users-val');
    const us = document.getElementById('sc-users-sub');
    if (uv) {
      if (userList.ok) {
        uv.textContent = userList.users?.length || 0;
        const active = (userList.users||[]).filter(u=>u.active).length;
        us.textContent = active + ' aktif / ' + ((userList.users||[]).length - active) + ' pasif';
      } else { uv.textContent = '?'; us.textContent = 'Alınamadı'; }
    }

    // Yedek geçmişi
    const bkpEl = document.getElementById('backupHistoryList');
    if (bkpEl) {
      const bkpList = await dApi('backupList');
      const rows = bkpList.backups || [];
      let localBkp = [];
      try { localBkp = JSON.parse(localStorage.getItem('uysa_local_backups')||'[]'); } catch(e){}
      let html = '';
      localBkp.slice(0,3).forEach((b,i) => {
        const dt = new Date(b.ts).toLocaleString('tr-TR');
        const size = b.sizeKb ? b.sizeKb+'KB' : (b.keyCount||0)+' key';
        html += '<div>💾 Yerel #' + (i+1) + ' — ' + dt + ' — ' + size +
          (b.data ? ' <button onclick="window._uysaRestoreLocalBackup('+i+');document.getElementById(\'syncDashMsg\').textContent=\'Geri yüklendi!\'" style="margin-left:8px;background:#dbeafe;color:#2563eb;border:none;border-radius:4px;padding:2px 7px;cursor:pointer;font-size:10px">♻️</button>' : ' <span style="color:#94a3b8">(meta only)</span>') + '</div>';
      });
      rows.slice(0,5).forEach(b => {
        html += '<div>☁️ Sunucu #' + b.id + ' — ' + (b.created_at||'').slice(0,16) + ' — ' + (b.key_count||0) + ' key, ' + (b.size_kb||0) + 'KB' +
          ' <button onclick="window._uysaRestoreServerBackup('+b.id+')" style="margin-left:8px;background:#d1fae5;color:#059669;border:none;border-radius:4px;padding:2px 7px;cursor:pointer;font-size:10px">♻️</button></div>';
      });
      bkpEl.innerHTML = html || '<span style="color:#94a3b8">Henüz yedek yok</span>';
    }
  }

  // Aksiyon fonksiyonları
  window._uysaForceSync = async function() {
    const msg = document.getElementById('syncDashMsg');
    if (msg) msg.textContent = '⏳ Senkronize ediliyor...';
    if (typeof window._uysaFullSync === 'function') window._uysaFullSync();
    if (typeof window._uysaSyncLoad === 'function') await window._uysaSyncLoad();
    addLog('ok', 'Manuel tam sync');
    if (msg) msg.textContent = '✅ Sync tamamlandı!';
    setTimeout(refreshDashboard, 1000);
  };

  window._uysaManualBackup = async function() {
    const msg = document.getElementById('syncDashMsg');
    if (msg) msg.textContent = '⏳ Yedek alınıyor...';
    window._uysaSmartLocalBackup();
    const r = await dApi('backup', { trigger: 'manual' });
    addLog(r.ok?'ok':'warn', r.ok ? 'Sunucu yedeği: ' + r.size_kb + 'KB' : 'Sunucu yedeği başarısız');
    if (msg) msg.textContent = r.ok ? '✅ Yedek alındı: ' + r.size_kb + 'KB' : '⚠️ Yerel yedek alındı';
    setTimeout(refreshDashboard, 800);
  };

  window._uysaRestoreBackup = function() {
    let backups = [];
    try { backups = JSON.parse(localStorage.getItem('uysa_local_backups')||'[]'); } catch(e){}
    if (!backups.length || !backups[0].data) { alert('Geri yüklenebilir yerel yedek bulunamadı!'); return; }
    if (!confirm('En son yerel yedekten geri yüklensin mi?\nTarih: ' + new Date(backups[0].ts).toLocaleString('tr-TR'))) return;
    const n = window._uysaRestoreLocalBackup(0);
    document.getElementById('syncDashMsg').textContent = '✅ ' + n + ' anahtar geri yüklendi!';
    addLog('ok', 'Yerel yedek geri yüklendi: ' + n + ' anahtar');
  };

  window._uysaRestoreLocalBackup = function(index) {
    try {
      let backups = JSON.parse(localStorage.getItem('uysa_local_backups')||'[]');
      if (!backups[index] || !backups[index].data) return 0;
      let n = 0;
      const setFn = window._uysaOrigSetItem || localStorage.setItem.bind(localStorage);
      Object.entries(backups[index].data).forEach(([k,v]) => {
        if (k !== 'uysa_local_backups') { try { setFn(k,v); n++; } catch(e){} }
      });
      return n;
    } catch(e) { return 0; }
  };

  window._uysaRestoreServerBackup = async function(id) {
    const msg = document.getElementById('syncDashMsg');
    if (!confirm('Sunucu yedek #' + id + ' geri yüklensin mi?')) return;
    if (msg) msg.textContent = '⏳ Geri yükleniyor...';
    const r = await dApi('backupRestore', { id });
    addLog(r.ok?'ok':'err', r.ok ? 'Sunucu yedek #' + id + ' geri yüklendi' : 'Geri yükleme hatası');
    if (msg) msg.textContent = r.ok ? '✅ Geri yüklendi!' : '❌ Hata: ' + r.error;
    if (r.ok && typeof window._uysaSyncLoad==='function') setTimeout(window._uysaSyncLoad, 500);
  };

  window._uysaExportData = function() {
    try {
      const data = {};
      for (let i = 0; i < localStorage.length; i++) {
        const k = localStorage.key(i);
        if (k && k.startsWith('uysa')) data[k] = localStorage.getItem(k);
      }
      const blob = new Blob([JSON.stringify({ exportedAt: new Date().toISOString(), keyCount: Object.keys(data).length, data }, null, 2)], { type: 'application/json' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'uysa_backup_' + new Date().toISOString().slice(0,10) + '.json';
      a.click();
      addLog('ok', 'JSON dışa aktarıldı: ' + Object.keys(data).length + ' anahtar');
    } catch(e) { alert('Dışa aktarma hatası: ' + e.message); }
  };

  window.openSyncDashboard = function() {
    const el = document.getElementById('uysaSyncDashboard');
    if (!el) return;
    el.style.display = 'flex';
    refreshDashboard();
    addLog('ok', 'Dashboard açıldı');
  };

  window.addEventListener('uysa-sync-update', function(e) {
    // Offline kuyruk kartı güncelle
    if (e.detail && e.detail.offlineQueue !== undefined) {
      const card = document.getElementById('offlineQueueCard');
      const cnt  = document.getElementById('offlineQueueCount');
      if (card && cnt) {
        cnt.textContent = e.detail.offlineQueue;
        card.style.display = e.detail.offlineQueue > 0 ? 'block' : 'none';
      }
    }
    addLog('ok', 'Auto-sync: ' + (e.detail.saved||0) + ' anahtar');
  });
  window.addEventListener('uysa-backup-update', function(e) {
    addLog('ok', 'Yerel yedek: ' + (e.detail.keys||0) + ' anahtar, ' + (e.detail.sizeKb||0) + 'KB');
  });

  // _orig.setItem referansını kaydet (QuotaExceeded fix için)
  setTimeout(function() {
    if (window._uysaOrigSetItem === undefined) {
      window._uysaOrigSetItem = localStorage.setItem.bind(localStorage);
    }
  }, 100);

  setInterval(function() {
    if (document.getElementById('uysaSyncDashboard')?.style.display === 'flex') _renderLog();
  }, 5000);

  console.log('[UYSA-Dashboard] ✅ Sync dashboard v2 aktif (QuotaExceeded fix)');
})();
