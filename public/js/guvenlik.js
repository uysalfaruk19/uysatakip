/* ═══════════════════════════════════════════════════════════
   UYSA GÜVENLİK KATMANI v1.0
   - Brute-force / rate-limit koruması
   - CSRF token yönetimi
   - Input sanitization (XSS önleme)
   - Şüpheli aktivite tespiti
   ═══════════════════════════════════════════════════════════ */
(function() {
  'use strict';

  // ── Brute-Force Koruması ────────────────────────────────────
  const MAX_ATTEMPTS   = 5;      // Maksimum yanlış deneme
  const LOCKOUT_MS     = 5 * 60 * 1000; // 5 dakika kilit
  const ATTEMPT_WINDOW = 10 * 60 * 1000; // 10 dakika pencere

  function _getLoginState() {
    try {
      return JSON.parse(sessionStorage.getItem('_uysa_ls') || '{"attempts":[],"lockedUntil":0}');
    } catch(e) { return { attempts: [], lockedUntil: 0 }; }
  }
  function _saveLoginState(s) {
    try { sessionStorage.setItem('_uysa_ls', JSON.stringify(s)); } catch(e) {}
  }

  // Login denemesi kaydet - true: devam et, false: engelle
  window.uysaLoginCheck = function(username) {
    const now = Date.now();
    const s = _getLoginState();

    // Kilit kontrolü
    if (s.lockedUntil && now < s.lockedUntil) {
      const remaining = Math.ceil((s.lockedUntil - now) / 1000);
      const mins = Math.floor(remaining / 60);
      const secs = remaining % 60;
      const msg = mins > 0
        ? `Çok fazla hatalı deneme! ${mins} dakika ${secs} saniye sonra tekrar deneyin.`
        : `Çok fazla hatalı deneme! ${secs} saniye sonra tekrar deneyin.`;
      const err = document.getElementById('loginError');
      if (err) { err.textContent = msg; err.classList.add('show'); }
      // Şifre alanını temizle
      const passEl = document.getElementById('loginPass');
      if (passEl) passEl.value = '';
      return false;
    }

    // Pencere dışındaki denemeleri temizle
    s.attempts = (s.attempts || []).filter(t => now - t < ATTEMPT_WINDOW);
    _saveLoginState(s);
    return true;
  };

  // Başarısız giriş kaydı
  window.uysaLoginFailed = function(username) {
    const now = Date.now();
    const s = _getLoginState();
    s.attempts = (s.attempts || []).filter(t => now - t < ATTEMPT_WINDOW);
    s.attempts.push(now);

    if (s.attempts.length >= MAX_ATTEMPTS) {
      s.lockedUntil = now + LOCKOUT_MS;
      s.attempts = [];
      const err = document.getElementById('loginError');
      if (err) { err.textContent = 'Çok fazla hatalı deneme! Hesabınız 5 dakika kilitlendi.'; err.classList.add('show'); }
      console.warn('[UYSA-SEC] Brute-force: ' + username + ' - ' + MAX_ATTEMPTS + ' başarısız deneme');
    }
    _saveLoginState(s);
  };

  // Başarılı giriş - kilidi sıfırla
  window.uysaLoginSuccess = function() {
    try { sessionStorage.removeItem('_uysa_ls'); } catch(e) {}
  };

  // ── CSRF Token ─────────────────────────────────────────────
  function _generateCSRF() {
    const arr = new Uint8Array(32);
    crypto.getRandomValues(arr);
    return Array.from(arr, b => b.toString(16).padStart(2, '0')).join('');
  }

  function uysaGetCSRFToken() {
    let token = sessionStorage.getItem('_uysa_csrf');
    if (!token) {
      token = _generateCSRF();
      sessionStorage.setItem('_uysa_csrf', token);
    }
    return token;
  }
  window.uysaGetCSRFToken = uysaGetCSRFToken;

  // Tüm API çağrılarına CSRF token'ı ekle
  const _origFetch = window.fetch;
  window.fetch = function(url, opts) {
    if (typeof url === 'string' && url.includes('uysa_api.php')) {
      opts = opts || {};
      opts.headers = opts.headers || {};
      if (opts.headers instanceof Headers) {
        opts.headers.set('X-UYSA-CSRF', uysaGetCSRFToken());
      } else {
        opts.headers['X-UYSA-CSRF'] = uysaGetCSRFToken();
      }
    }
    return _origFetch.call(this, url, opts);
  };

  // ── Input Sanitization / XSS Önleme ───────────────────────
  // Güvenli HTML escape fonksiyonu (global olarak kullanılabilir)
  window.uysaSanitize = function(str) {
    if (str == null) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#x27;')
      .replace(/\//g, '&#x2F;');
  };

  // ── Session Timeout (30 dakika inaktivite) ─────────────────
  const SESSION_TIMEOUT = 30 * 60 * 1000;
  let _lastActivity = Date.now();

  ['click','keydown','mousemove','touchstart'].forEach(evt => {
    document.addEventListener(evt, () => { _lastActivity = Date.now(); }, { passive: true });
  });

  setInterval(function() {
    const sess = typeof _getSession === 'function' ? _getSession() : null;
    if (!sess) return;
    if (Date.now() - _lastActivity > SESSION_TIMEOUT) {
      console.warn('[UYSA-SEC] Session timeout - otomatik çıkış');
      if (typeof uysaLogout === 'function') {
        uysaLogout();
        const err = document.getElementById('loginError');
        if (err) { err.textContent = '⏱ Oturum zaman aşımına uğradı. Lütfen tekrar giriş yapın.'; err.classList.add('show'); }
      }
    }
  }, 60 * 1000);

  // ── Şüpheli Aktivite Tespiti ────────────────────────────────
  // DevTools açma girişimi tespiti (temel)
  let _devToolsWarned = false;
  const _dt = /./;
  _dt.toString = function() {
    if (!_devToolsWarned) {
      _devToolsWarned = true;
      console.warn('%c⚠️ UYSA ERP - Güvenlik Uyarısı', 'color:red;font-size:20px;font-weight:bold');
      console.warn('%cBu konsol sadece geliştiriciler içindir. Başka kişi tarafından bir kod çalıştırmanız isteniyorsa ÇALIŞTIRMAYIN!', 'color:orange;font-size:14px');
    }
    return '';
  };

  console.log('%c', _dt);

  console.log('[UYSA-SEC] ✅ Güvenlik katmanı aktif');
})();
