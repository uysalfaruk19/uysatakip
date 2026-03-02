/**
 * UYSA ERP — Frontend Error & UX Manager v1.0
 * Tüm buton ve form işlemleri için merkezi hata yönetimi
 * Bu script index.html'in son <script> bloğundan önce eklenir
 */

// ══════════════════════════════════════════════════════════════
// 1. TOAST / NOTIFICATION SİSTEMİ
// ══════════════════════════════════════════════════════════════
window.UysaToast = (function () {
  let container;
  const TYPES = {
    success: { icon: '✅', color: '#16a34a', bg: '#f0fdf4' },
    error:   { icon: '❌', color: '#dc2626', bg: '#fef2f2' },
    warning: { icon: '⚠️', color: '#d97706', bg: '#fffbeb' },
    info:    { icon: 'ℹ️', color: '#2563eb', bg: '#eff6ff' },
    loading: { icon: '⏳', color: '#6b7280', bg: '#f9fafb' },
  };

  function init() {
    if (container) return;
    container = document.createElement('div');
    container.id = 'uysa-toast-container';
    Object.assign(container.style, {
      position: 'fixed', top: '16px', right: '16px',
      zIndex: '99999', display: 'flex', flexDirection: 'column',
      gap: '8px', maxWidth: '360px', width: 'calc(100% - 32px)',
      pointerEvents: 'none',
    });
    document.body.appendChild(container);
  }

  function show(message, type = 'info', duration = 4000) {
    init();
    const cfg = TYPES[type] || TYPES.info;
    const el  = document.createElement('div');
    el.style.cssText = `
      display:flex; align-items:flex-start; gap:10px;
      padding:12px 16px; border-radius:10px;
      background:${cfg.bg}; border-left:4px solid ${cfg.color};
      box-shadow:0 4px 16px rgba(0,0,0,.12);
      font-size:14px; line-height:1.5; color:#1f2937;
      pointer-events:all; cursor:pointer;
      animation: uysaToastIn .25s cubic-bezier(.22,.68,0,1.2);
      transition: opacity .3s, transform .3s;
    `;
    const iconSpan = document.createElement('span');
    iconSpan.style.cssText = 'font-size:16px;flex-shrink:0;margin-top:1px';
    iconSpan.textContent = cfg.icon;
    const textSpan = document.createElement('span');
    textSpan.style.flex = '1';
    textSpan.innerHTML = String(message).replace(/</g, '&lt;');
    const closeBtn = document.createElement('button');
    closeBtn.textContent = '×';
    closeBtn.style.cssText = `
      background:none;border:none;cursor:pointer;
      font-size:18px;color:#9ca3af;padding:0;margin-left:4px;flex-shrink:0;
    `;
    el.append(iconSpan, textSpan, closeBtn);
    container.appendChild(el);

    const dismiss = () => {
      el.style.opacity = '0';
      el.style.transform = 'translateX(110%)';
      setTimeout(() => el.remove(), 300);
    };

    closeBtn.addEventListener('click', dismiss);
    el.addEventListener('click', dismiss);

    if (duration > 0) setTimeout(dismiss, duration);
    return { dismiss };
  }

  return {
    success: (msg, dur) => show(msg, 'success', dur),
    error:   (msg, dur) => show(msg, 'error',   dur ?? 6000),
    warning: (msg, dur) => show(msg, 'warning', dur),
    info:    (msg, dur) => show(msg, 'info',     dur),
    loading: (msg)      => show(msg, 'loading',  0),  // otomatik kapanmaz
  };
})();

// Toast animasyon CSS
(function () {
  if (document.getElementById('uysa-toast-css')) return;
  const style = document.createElement('style');
  style.id = 'uysa-toast-css';
  style.textContent = `
    @keyframes uysaToastIn {
      from { opacity:0; transform:translateX(50px) scale(.9) }
      to   { opacity:1; transform:translateX(0)    scale(1)  }
    }
  `;
  document.head.appendChild(style);
})();

// ══════════════════════════════════════════════════════════════
// 2. BUTON LOADING STATE YÖNETİCİSİ
// ══════════════════════════════════════════════════════════════
window.UysaBtn = (function () {
  const state = new WeakMap();

  function setLoading(btn, loading, loadingText = null) {
    if (!btn || btn.tagName !== 'BUTTON') return;
    if (loading) {
      if (state.has(btn)) return; // zaten loading
      state.set(btn, {
        text:     btn.innerHTML,
        disabled: btn.disabled,
      });
      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');
      btn.innerHTML = `<span style="display:inline-flex;align-items:center;gap:6px">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
          style="animation:uysaSpin 0.8s linear infinite">
          <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4
                   M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
        </svg>
        ${loadingText ?? 'İşleniyor…'}
      </span>`;
    } else {
      const prev = state.get(btn);
      if (!prev) return;
      btn.innerHTML  = prev.text;
      btn.disabled   = prev.disabled;
      btn.removeAttribute('aria-busy');
      state.delete(btn);
    }
  }

  // CSS spin animasyonu
  if (!document.getElementById('uysa-btn-css')) {
    const s = document.createElement('style');
    s.id = 'uysa-btn-css';
    s.textContent = '@keyframes uysaSpin{to{transform:rotate(360deg)}}';
    document.head.appendChild(s);
  }

  return { setLoading };
})();

// ══════════════════════════════════════════════════════════════
// 3. FORM DOĞRULAMA YÖNETİCİSİ
// ══════════════════════════════════════════════════════════════
window.UysaForm = (function () {

  const RULES = {
    required:  (v) => v.trim().length > 0    || 'Bu alan zorunludur',
    minLen:    (n) => (v) => v.trim().length >= n || `En az ${n} karakter girilmeli`,
    maxLen:    (n) => (v) => v.trim().length <= n || `En fazla ${n} karakter girilebilir`,
    email:     (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v) || 'Geçerli bir e-posta adresi girin',
    phone:     (v) => /^[\d\s\+\-\(\)]{7,20}$/.test(v)     || 'Geçerli bir telefon numarası girin',
    numeric:   (v) => /^\d+$/.test(v)                       || 'Sadece sayı girilebilir',
    decimal:   (v) => /^\d*\.?\d+$/.test(v)                 || 'Geçerli bir sayı girin',
    positive:  (v) => parseFloat(v) > 0                     || 'Pozitif bir değer girin',
    noScript:  (v) => !/<script/i.test(v)                   || 'Geçersiz karakter',
    password:  (v) => v.length >= 8                         || 'Şifre en az 8 karakter olmalı',
  };

  /**
   * Field doğrula
   * @param {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} field
   * @param {Array} rules
   * @returns {string|null} hata mesajı veya null
   */
  function validateField(field, rules = []) {
    const val = field.value ?? '';
    for (const rule of rules) {
      const fn  = typeof rule === 'function' ? rule : RULES[rule];
      if (!fn) continue;
      const res = fn(val);
      if (res !== true) {
        showFieldError(field, typeof res === 'string' ? res : 'Geçersiz değer');
        return typeof res === 'string' ? res : 'Geçersiz değer';
      }
    }
    clearFieldError(field);
    return null;
  }

  /**
   * Form'daki tüm işaretlenmiş field'ları doğrula
   * @param {HTMLFormElement} form
   * @returns {boolean} valid mi?
   */
  function validateForm(form) {
    const fields = form.querySelectorAll('[data-rules]');
    let isValid  = true;
    let firstErr = null;

    fields.forEach(field => {
      const rules  = (field.dataset.rules || '').split(',').map(r => r.trim()).filter(Boolean);
      const result = validateField(field, rules);
      if (result) {
        isValid = false;
        if (!firstErr) firstErr = field;
      }
    });

    if (firstErr) firstErr.focus();
    return isValid;
  }

  function showFieldError(field, message) {
    field.classList.add('uysa-field-error');
    field.setAttribute('aria-invalid', 'true');
    let errEl = field.parentElement.querySelector('.uysa-field-error-msg');
    if (!errEl) {
      errEl = document.createElement('span');
      errEl.className = 'uysa-field-error-msg';
      errEl.style.cssText = 'display:block;color:#dc2626;font-size:12px;margin-top:4px';
      field.parentElement.appendChild(errEl);
    }
    errEl.textContent = message;
    errEl.setAttribute('role', 'alert');
  }

  function clearFieldError(field) {
    field.classList.remove('uysa-field-error');
    field.removeAttribute('aria-invalid');
    const errEl = field.parentElement?.querySelector('.uysa-field-error-msg');
    if (errEl) errEl.remove();
  }

  // Field error CSS
  if (!document.getElementById('uysa-form-css')) {
    const s = document.createElement('style');
    s.id = 'uysa-form-css';
    s.textContent = `
      .uysa-field-error { border-color:#dc2626!important; outline-color:#dc2626!important; }
      .uysa-field-error:focus { box-shadow:0 0 0 3px rgba(220,38,38,.2)!important; }
    `;
    document.head.appendChild(s);
  }

  return { validateField, validateForm, showFieldError, clearFieldError, RULES };
})();

// ══════════════════════════════════════════════════════════════
// 4. MERKEZİ API ÇAĞRI YÖNETİCİSİ (hata yakalama ile)
// ══════════════════════════════════════════════════════════════
window.UysaAPI = (function () {
  const BASE  = '/uysa_api.php';
  let   token = null;

  function setToken(t) { token = t; }

  /**
   * API çağrısı yap
   * @param {string} action
   * @param {object} body
   * @param {object} opts  – {method, showErrors, showSuccess, successMsg, errorMsg, btn, btnText}
   */
  async function call(action, body = {}, opts = {}) {
    const {
      method      = 'POST',
      showErrors  = true,
      showSuccess = false,
      successMsg  = 'İşlem başarılı',
      errorMsg    = null,
      btn         = null,
      btnText     = null,
    } = opts;

    if (btn) UysaBtn.setLoading(btn, true, btnText);

    try {
      const apiToken = token
        || window.uysaGetToken?.()
        || localStorage.getItem('uysa_api_token')
        || window.UYSA_CONFIG?.token
        || 'UysaERP2026xProdKey3f7a9c1b';

      const url      = BASE + '?action=' + encodeURIComponent(action);
      const fetchOpts = {
        method:  method === 'GET' ? 'GET' : 'POST',
        headers: {
          'Content-Type':  'application/json',
          'X-UYSA-Token':  apiToken,
        },
      };

      if (method !== 'GET' && Object.keys(body).length > 0) {
        fetchOpts.body = JSON.stringify(body);
      }

      const resp = await fetch(url, fetchOpts);

      // HTTP hata kodları
      if (resp.status === 429) {
        const data = await resp.json().catch(() => ({}));
        const retryAfter = data.retry_after || 60;
        if (showErrors) UysaToast.warning(`Çok fazla istek. ${retryAfter} saniye sonra tekrar deneyin.`);
        return { ok: false, _httpCode: 429, error: 'Rate limit', retry_after: retryAfter };
      }

      if (resp.status === 401 || resp.status === 403) {
        if (showErrors) UysaToast.error('Oturum süresi dolmuş veya yetki yok. Lütfen tekrar giriş yapın.', 8000);
        // Auto logout tetikle
        setTimeout(() => window.uysaDoLogout?.(), 2000);
        return { ok: false, _httpCode: resp.status };
      }

      if (!resp.ok && resp.status >= 500) {
        if (showErrors) UysaToast.error('Sunucu hatası oluştu. Lütfen daha sonra tekrar deneyin.');
        return { ok: false, _httpCode: resp.status };
      }

      let data;
      try {
        data = await resp.json();
      } catch {
        if (showErrors) UysaToast.error('Sunucu yanıtı okunamadı.');
        return { ok: false, error: 'parse_error' };
      }

      if (!data.ok && showErrors) {
        const msg = errorMsg || data.error || 'Bir hata oluştu';
        UysaToast.error(msg);
      }

      if (data.ok && showSuccess) {
        UysaToast.success(successMsg);
      }

      return data;

    } catch (err) {
      // Network hatası
      const msg = navigator.onLine
        ? 'Sunucuya bağlanılamadı.'
        : 'İnternet bağlantısı yok.';
      if (showErrors) UysaToast.error(msg);
      console.error('[UysaAPI]', action, err);
      return { ok: false, error: 'network_error', message: msg };
    } finally {
      if (btn) UysaBtn.setLoading(btn, false);
    }
  }

  // Hazır metodlar
  return {
    setToken,
    call,
    get:     (action, opts) => call(action, {}, { ...opts, method: 'GET' }),
    post:    (action, body, opts) => call(action, body, { ...opts, method: 'POST' }),
    delete:  (action, body, opts) => call(action, body, { ...opts, method: 'DELETE' }),
  };
})();

// ══════════════════════════════════════════════════════════════
// 5. GLOBAL ERROR HANDLER
// ══════════════════════════════════════════════════════════════
(function () {
  // JS hatalarını yakala
  window.addEventListener('error', (e) => {
    console.error('[UYSA Global Error]', e.message, e.filename, e.lineno);
    // Sadece kritik UI hatalarını kullanıcıya göster
    if (e.message && !e.message.includes('Script error')) {
      // Sessiz log — toast spam'ı önlemek için
    }
  });

  // Promise reject yakala
  window.addEventListener('unhandledrejection', (e) => {
    console.warn('[UYSA Unhandled Promise]', e.reason);
    if (e.reason instanceof TypeError && e.reason.message.includes('fetch')) {
      // Network hatası — UysaAPI zaten ele alıyor
      e.preventDefault();
    }
  });

  // Online/Offline durumu
  window.addEventListener('offline', () => {
    UysaToast.warning('İnternet bağlantısı kesildi. Çevrimdışı modda çalışıyorsunuz.', 0);
  });
  window.addEventListener('online', () => {
    UysaToast.success('İnternet bağlantısı yeniden sağlandı.', 3000);
  });
})();

// ══════════════════════════════════════════════════════════════
// 6. CONFIRM DIALOG (native confirm yerine)
// ══════════════════════════════════════════════════════════════
window.UysaConfirm = function (message, opts = {}) {
  return new Promise((resolve) => {
    const {
      title       = 'Onay Gerekli',
      confirmText = 'Evet, Devam Et',
      cancelText  = 'Vazgeç',
      danger      = false,
    } = opts;

    const overlay = document.createElement('div');
    overlay.style.cssText = `
      position:fixed;inset:0;background:rgba(0,0,0,.5);
      z-index:100000;display:flex;align-items:center;justify-content:center;
      animation:uysaFadeIn .2s ease;
    `;

    const dialog = document.createElement('div');
    dialog.style.cssText = `
      background:#fff;border-radius:14px;padding:28px;max-width:420px;width:calc(100%-32px);
      box-shadow:0 20px 60px rgba(0,0,0,.25);animation:uysaSlideUp .25s cubic-bezier(.22,.68,0,1.2);
    `;
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');

    dialog.innerHTML = `
      <h3 style="margin:0 0 10px;font-size:18px;font-weight:700;color:#111">${title}</h3>
      <p style="margin:0 0 24px;color:#4b5563;font-size:15px;line-height:1.6">${message}</p>
      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button id="uysa-confirm-cancel" style="
          padding:9px 20px;border-radius:8px;border:1px solid #d1d5db;
          background:#fff;cursor:pointer;font-size:14px;font-weight:500;color:#374151">
          ${cancelText}
        </button>
        <button id="uysa-confirm-ok" style="
          padding:9px 20px;border-radius:8px;border:none;cursor:pointer;
          font-size:14px;font-weight:600;color:#fff;
          background:${danger ? '#dc2626' : '#2563eb'}">
          ${confirmText}
        </button>
      </div>
    `;

    overlay.appendChild(dialog);
    document.body.appendChild(overlay);

    const close = (val) => {
      overlay.style.opacity = '0';
      setTimeout(() => overlay.remove(), 200);
      resolve(val);
    };

    dialog.querySelector('#uysa-confirm-ok').addEventListener('click', () => close(true));
    dialog.querySelector('#uysa-confirm-cancel').addEventListener('click', () => close(false));
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(false); });

    // Focus trap
    dialog.querySelector('#uysa-confirm-cancel').focus();
  });
};

// ══════════════════════════════════════════════════════════════
// 7. GLOBAL BUTON DELEGASYONU (data-action attribute)
// ══════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', function () {
  // Tüm [data-uysa-action] butonlarını yakala
  document.addEventListener('click', async function (e) {
    const btn = e.target.closest('[data-uysa-action]');
    if (!btn) return;

    const action    = btn.dataset.uysaAction;
    const confirmMsg = btn.dataset.confirm;
    const successMsg = btn.dataset.success || 'İşlem başarılı';

    // Onay gerekiyor mu?
    if (confirmMsg) {
      const ok = await UysaConfirm(confirmMsg, {
        danger: btn.dataset.danger === 'true',
      });
      if (!ok) return;
    }

    // Form bul ve doğrula
    const formId = btn.dataset.form;
    if (formId) {
      const form = document.getElementById(formId);
      if (form && !UysaForm.validateForm(form)) {
        UysaToast.warning('Lütfen formdaki hataları düzeltin.');
        return;
      }
    }

    // Action işle — kullanıcı kendi handler'ını ekler
    btn.dispatchEvent(new CustomEvent('uysa-btn-click', {
      bubbles: true,
      detail: { action, btn, successMsg },
    }));
  });

  // Enter tuşu form submit
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.matches('[data-form]')) {
      const formId = e.target.dataset.form;
      const form   = formId ? document.getElementById(formId) : e.target.closest('form');
      if (form) {
        const submitBtn = form.querySelector('[type="submit"], [data-submit="true"]');
        if (submitBtn) submitBtn.click();
      }
    }
  });
});

// ══════════════════════════════════════════════════════════════
// 8. LOGIN FORMU HATA YÖNETİMİ (mevcut uysaDoLogin ile entegre)
// ══════════════════════════════════════════════════════════════
(function patchLoginErrorHandling() {
  // Login input'larına live validation ekle
  const addLiveValidation = () => {
    const usernameInput = document.getElementById('loginUsername');
    const passwordInput = document.getElementById('loginPassword');
    const loginBtn      = document.querySelector('#loginOverlay button[onclick*="uysaDoLogin"]')
                       || document.querySelector('#loginOverlay .login-btn');

    if (!usernameInput || !passwordInput) return;

    usernameInput.addEventListener('input', () => {
      if (!usernameInput.value.trim()) {
        UysaForm.showFieldError(usernameInput, 'Kullanıcı adı boş olamaz');
      } else {
        UysaForm.clearFieldError(usernameInput);
      }
    });

    passwordInput.addEventListener('input', () => {
      if (!passwordInput.value) {
        UysaForm.showFieldError(passwordInput, 'Şifre boş olamaz');
      } else {
        UysaForm.clearFieldError(passwordInput);
      }
    });

    // Enter ile login
    [usernameInput, passwordInput].forEach(inp => {
      inp.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          if (typeof window.uysaDoLogin === 'function') window.uysaDoLogin();
        }
      });
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addLiveValidation);
  } else {
    addLiveValidation();
  }
})();

console.log('[UYSA] 🛡️ Error & UX Manager v1.0 yüklendi');
