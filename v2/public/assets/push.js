/* kokpit-ios (Capacitor) push kaydı — yalnız native app içinde çalışır, web'de sessiz no-op.
   Müşteri girişli sayfalarda (footer_m) yüklenir; izin ister, APNs token'ı push-register.php'ye yazar. */
(function () {
  var C = window.Capacitor;
  if (!C || typeof C.isNativePlatform !== 'function' || !C.isNativePlatform()) return;
  var PN = C.Plugins && C.Plugins.PushNotifications;
  if (!PN) return;

  PN.addListener('registration', function (t) {
    fetch('/m/push-register.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ token: t.value, platform: C.getPlatform() })
    }).catch(function () {});
  });

  PN.checkPermissions()
    .then(function (s) {
      if (s.receive === 'prompt' || s.receive === 'prompt-with-rationale') {
        return PN.requestPermissions();
      }
      return s;
    })
    .then(function (s) {
      if (s.receive === 'granted') PN.register();
    })
    .catch(function () {});
})();
