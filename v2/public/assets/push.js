/* UYSA Kokpit native köprüsü: APNs kayıt, güvenli deep-link, badge temizleme ve app-içi UX. */
(function () {
  "use strict";

  var C = window.Capacitor;
  if (!C || typeof C.isNativePlatform !== "function" || !C.isNativePlatform())
    return;

  var context = window.UYSA_NATIVE_CONTEXT || {};
  var authenticated = context.authenticated === true;
  var guard = context.guard || (window.location.pathname.indexOf("/m/") === 0 ? "customer" : "admin");
  var endpoint = context.pushEndpoint || (guard === "customer" ? "/m/push-register.php" : "/push-register.php");
  var PN = C.Plugins && C.Plugins.PushNotifications;
  var tokenKey = "uysaPushToken:" + guard;

  document.documentElement.classList.add("native-app");
  if (document.body) document.body.classList.add("native-app");
  document.querySelectorAll("[data-web-only]").forEach(function (el) {
    el.hidden = true;
  });
  if (authenticated) localStorage.setItem("uysaNativeSeen:" + guard, "1");

  function safeAppPath(raw) {
    if (typeof raw !== "string") return null;
    var value = raw.trim();
    if (value.charAt(0) !== "/" || value.indexOf("//") === 0) return null;
    try {
      var url = new URL(value, window.location.origin);
      if (url.origin !== window.location.origin) return null;
      return url.pathname + url.search + url.hash;
    } catch (_) {
      return null;
    }
  }

  function routeNotification(data) {
    var path = safeAppPath(data && data.url);
    if (path) window.location.assign(path);
  }

  function clearDelivered() {
    if (!PN || typeof PN.removeAllDeliveredNotifications !== "function") return;
    Promise.resolve(PN.removeAllDeliveredNotifications()).catch(function () {});
  }

  function registerToken(token) {
    if (!authenticated || !endpoint || !token) return Promise.resolve();
    localStorage.setItem(tokenKey, token);
    return fetch(endpoint, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({ token: token, platform: C.getPlatform() }),
    }).then(function (response) {
      if (!response.ok) throw new Error("push-register:" + response.status);
      return response;
    });
  }

  function dismissToast(el) {
    if (!el) return;
    el.classList.remove("show");
    setTimeout(function () {
      if (el.parentNode) el.parentNode.removeChild(el);
    }, 180);
  }

  function showPushToast(notification) {
    var existing = document.querySelector(".native-push-toast");
    if (existing) dismissToast(existing);

    var path = safeAppPath(notification && notification.data && notification.data.url);
    var toast = document.createElement(path ? "button" : "div");
    if (path) toast.type = "button";
    toast.className = "native-push-toast";
    if (path) {
      toast.setAttribute("aria-label", "Bildirimi görüntüle");
    } else {
      toast.setAttribute("role", "status");
    }

    var icon = document.createElement("i");
    icon.className = "bi bi-bell-fill";
    var copy = document.createElement("span");
    var title = document.createElement("strong");
    title.textContent = (notification && notification.title) || "UYSA Kokpit";
    var body = document.createElement("small");
    body.textContent = (notification && notification.body) || "Yeni bildiriminiz var.";
    copy.appendChild(title);
    copy.appendChild(body);
    toast.appendChild(icon);
    toast.appendChild(copy);

    if (path) {
      toast.addEventListener("click", function () {
        dismissToast(toast);
        window.location.assign(path);
      });
    }
    document.body.appendChild(toast);
    requestAnimationFrame(function () {
      toast.classList.add("show");
    });
    setTimeout(function () {
      dismissToast(toast);
    }, 5200);
  }

  function installPullToRefresh() {
    if (!authenticated || !document.body) return;

    var indicator = document.createElement("div");
    indicator.className = "pull-refresh";
    indicator.setAttribute("aria-hidden", "true");
    var icon = document.createElement("i");
    icon.className = "bi bi-arrow-down";
    var label = document.createElement("span");
    label.textContent = "Yenile";
    indicator.appendChild(icon);
    indicator.appendChild(label);
    document.body.appendChild(indicator);

    var tracking = false;
    var refreshing = false;
    var startX = 0;
    var startY = 0;
    var pull = 0;

    function reset() {
      tracking = false;
      pull = 0;
      indicator.classList.remove("active", "armed");
      indicator.style.transform = "";
      icon.className = "bi bi-arrow-down";
      label.textContent = "Yenile";
    }

    document.addEventListener(
      "touchstart",
      function (event) {
        if (refreshing || window.scrollY > 0 || !event.touches || event.touches.length !== 1) return;
        var target = event.target;
        if (target && target.closest && target.closest("input, textarea, select, button, a, .counter, .bottom-tabs")) return;
        tracking = true;
        startX = event.touches[0].clientX;
        startY = event.touches[0].clientY;
      },
      { passive: true },
    );

    document.addEventListener(
      "touchmove",
      function (event) {
        if (!tracking || !event.touches || event.touches.length !== 1) return;
        var dx = event.touches[0].clientX - startX;
        var dy = event.touches[0].clientY - startY;
        if (dy <= 0 || Math.abs(dx) > Math.abs(dy)) {
          reset();
          return;
        }
        pull = Math.min(92, dy * 0.55);
        if (pull > 8) event.preventDefault();
        indicator.classList.add("active");
        indicator.classList.toggle("armed", pull >= 64);
        indicator.style.transform = "translate(-50%, " + Math.min(0, pull - 58) + "px)";
        icon.className = pull >= 64 ? "bi bi-arrow-up" : "bi bi-arrow-down";
        label.textContent = pull >= 64 ? "Bırak, yenile" : "Yenile";
      },
      { passive: false },
    );

    document.addEventListener("touchend", function () {
      if (!tracking) return;
      if (pull < 64) {
        reset();
        return;
      }
      refreshing = true;
      tracking = false;
      indicator.classList.add("active", "refreshing");
      indicator.classList.remove("armed");
      indicator.style.transform = "translate(-50%, 0)";
      icon.className = "bi bi-arrow-repeat";
      label.textContent = "Yenileniyor";
      setTimeout(function () {
        window.location.reload();
      }, 120);
    });

    document.addEventListener("touchcancel", reset);
  }

  installPullToRefresh();
  if (!PN) return;

  PN.addListener("pushNotificationActionPerformed", function (event) {
    clearDelivered();
    routeNotification(event && event.notification && event.notification.data);
  });

  PN.addListener("pushNotificationReceived", function (notification) {
    clearDelivered();
    showPushToast(notification || {});
  });

  PN.addListener("registration", function (token) {
    registerToken(token && token.value).catch(function () {});
  });

  if (!authenticated) return;
  clearDelivered();

  var savedToken = localStorage.getItem(tokenKey);
  if (savedToken) registerToken(savedToken).catch(function () {});

  function requestRegistration() {
    PN.checkPermissions()
      .then(function (status) {
        if (status.receive === "prompt" || status.receive === "prompt-with-rationale") {
          return PN.requestPermissions();
        }
        return status;
      })
      .then(function (status) {
        if (status.receive === "granted") return PN.register();
      })
      .catch(function () {});
  }

  requestRegistration();
  window.addEventListener("online", function () {
    var token = localStorage.getItem(tokenKey);
    if (token) registerToken(token).catch(function () {});
    requestRegistration();
  });
})();
