/* kokpit-ios (Capacitor) push kaydı — yalnız native app içinde çalışır, web'de sessiz no-op.
   Müşteri girişli sayfalarda (footer_m) VE admin sayfalarında (footer, opus-021) yüklenir;
   izin ister, APNs token'ını aktif guard'ın endpoint'ine yazar:
   müşteri → /m/push-register.php (default), admin → footer'ın set ettiği window.UYSA_PUSH_ENDPOINT. */
(function () {
  var C = window.Capacitor;
  if (!C || typeof C.isNativePlatform !== 'function' || !C.isNativePlatform()) return;
  var PN = C.Plugins && C.Plugins.PushNotifications;
  if (!PN) return;
  var endpoint = window.UYSA_PUSH_ENDPOINT || '/m/push-register.php';

  PN.addListener('registration', function (t) {
    fetch(endpoint, {
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
