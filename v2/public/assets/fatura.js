// fable-024 — Fatura Kes: seçim (+aylık bölüşüm) → onay/kuru deneme → sonuç.
// Sunucu tek gerçek kaynak: seçilebilirlik, tutar, gövde ve kesim kararı fatura-kes.php'de verilir.
// Buradaki koruma (çift tık, boş seçim, bölüşüm toplamı) UX içindir; asıl kalkanlar sunucuda.
(function () {
  "use strict";
  if (!window.FTR) return;

  var stepSecim = document.getElementById("ftr-step-secim");
  var stepOnay = document.getElementById("ftr-step-onay");
  var stepSonuc = document.getElementById("ftr-step-sonuc");
  var allBox = document.getElementById("ftr-all");
  var countEl = document.getElementById("ftr-count");
  var nextBtn = document.getElementById("ftr-next");
  var backBtn = document.getElementById("ftr-back");
  var cutBtn = document.getElementById("ftr-cut");
  var yeniBtn = document.getElementById("ftr-yeni");
  var retryBtn = document.getElementById("ftr-retry");
  var ozetEl = document.getElementById("ftr-ozet");
  var govdeEl = document.getElementById("ftr-govde");
  var onayGenelEl = document.getElementById("ftr-onay-genel");
  var sonucEl = document.getElementById("ftr-sonuc");
  var onayToken = "";

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;",
      }[c];
    });
  }
  function money(n) {
    return (
      "₺" +
      (Math.round(Number(n) * 100) / 100).toLocaleString("tr-TR", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      })
    );
  }
  function picks() {
    return [].slice.call(document.querySelectorAll(".ftr-pick:checked"));
  }
  function rowOf(cb) {
    return cb.closest(".ftr-row");
  }

  function refresh() {
    var n = picks().length;
    if (countEl) countEl.textContent = n + " seçili";
    if (nextBtn) nextBtn.disabled = n === 0;
  }

  function show(step) {
    stepSecim.hidden = step !== "secim";
    stepOnay.hidden = step !== "onay";
    stepSonuc.hidden = step !== "sonuc";
    window.scrollTo(0, 0);
    // fable-025: admin'de kayan eleman artık main.app-shell (sabit-kabuk deseni)
    var shell = document.querySelector("main.app-shell");
    if (shell) shell.scrollTop = 0;
  }

  // Aylık bölüşüm: seçiliyken panel açılır; toplam ≠ hedef ise kırmızı uyarı (bloklamaz).
  function toggleBolusum(row, on) {
    var bol = row.querySelector(".ftr-bolusum");
    if (bol) bol.hidden = !on;
  }
  function bolusumSum(row) {
    var t = 0;
    [].slice.call(row.querySelectorAll(".ftr-bol-in")).forEach(function (i) {
      t += parseInt(i.value, 10) || 0;
    });
    return t;
  }
  function checkBolusum(row) {
    var bol = row.querySelector(".ftr-bolusum");
    if (!bol) return;
    var hedef = parseInt(row.getAttribute("data-adet"), 10) || 0;
    var sum = bolusumSum(row);
    var w = bol.querySelector(".ftr-bol-uyari");
    if (sum !== hedef) {
      w.hidden = false;
      w.textContent =
        "Toplam " +
        sum +
        " ≠ dönem " +
        hedef +
        " (fark " +
        (sum - hedef) +
        ") — bilerek devam edebilirsiniz.";
      w.className = "ftr-bol-uyari warn";
    } else {
      w.hidden = true;
    }
  }

  document.addEventListener("change", function (e) {
    if (e.target === allBox) {
      var on = allBox.checked;
      [].slice
        .call(document.querySelectorAll(".ftr-pick:not([disabled])"))
        .forEach(function (c) {
          c.checked = on;
          toggleBolusum(rowOf(c), on);
        });
      refresh();
    } else if (e.target.classList.contains("ftr-pick")) {
      toggleBolusum(rowOf(e.target), e.target.checked);
      refresh();
    } else if (e.target.classList.contains("ftr-bol-in")) {
      checkBolusum(e.target.closest(".ftr-row"));
    }
  });
  document.addEventListener("input", function (e) {
    if (e.target.classList.contains("ftr-bol-in"))
      checkBolusum(e.target.closest(".ftr-row"));
  });

  function post(payload, done) {
    payload.csrf = window.FTR.csrf;
    payload.bas = window.FTR.bas;
    payload.son = window.FTR.son;
    fetch("fatura-kes.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify(payload),
    })
      .then(function (r) {
        return r.json().catch(function () {
          return {
            ok: false,
            error: "Sunucu yanıtı okunamadı (HTTP " + r.status + ")",
          };
        });
      })
      .then(done)
      .catch(function (e) {
        done({ ok: false, error: "Bağlantı hatası: " + e.message });
      });
  }

  // seçimi payload'a çevir (aylık müşteride bölüşüm dağılımı KEY bazında; contact id sunucuda çözülür)
  function buildSecim() {
    return picks().map(function (cb) {
      var row = rowOf(cb);
      var item = { customer_id: parseInt(cb.value, 10) };
      if (row.getAttribute("data-tip") === "aylik") {
        var dag = {};
        [].slice
          .call(row.querySelectorAll(".ftr-bol-in"))
          .forEach(function (i) {
            dag[i.getAttribute("data-key")] = parseInt(i.value, 10) || 0;
          });
        item.dagilim = dag;
      }
      return item;
    });
  }

  // ── adım 1 → 2: onay özeti + kuru deneme + onay imzası ──
  if (nextBtn) {
    nextBtn.addEventListener("click", function () {
      var secim = buildSecim();
      if (!secim.length) return;
      nextBtn.disabled = true;
      post({ action: "hazirla", secim: secim }, function (r) {
        nextBtn.disabled = false;
        if (!r.ok) {
          alert(r.error || "Hazırlanamadı.");
          return;
        }
        onayToken = r.onay || "";
        var html = "";
        var govdeler = [];
        r.satirlar.forEach(function (s) {
          if (!s.ok) {
            html +=
              '<div class="ftr-res err"><span class="ftr-name">' +
              esc(s.name) +
              '</span><span class="ftr-why">' +
              esc(s.sebep) +
              "</span></div>";
            return;
          }
          if (s.tip === "irsaliye") {
            var kal = s.kalemler
              .map(function (k) {
                return esc(k.ad) + " × " + k.miktar + " × " + money(k.birim);
              })
              .join(" · ");
            html +=
              '<div class="ftr-res ok"><div class="ftr-name">' +
              esc(s.name) +
              (s.tevkifat
                ? ' <span class="badge-soft badge-warn">TEVKİFAT ' +
                  esc(s.tevkifat) +
                  "</span>"
                : "") +
              "</div>" +
              '<div class="ftr-lines">' +
              kal +
              "</div>" +
              '<div class="ftr-calc">Brüt ' +
              money(s.hesap.brut) +
              " · KDV " +
              money(s.hesap.kdv) +
              (s.hesap.tevkifat > 0
                ? " · Tevkifat −" + money(s.hesap.tevkifat)
                : "") +
              " · <strong>Tahsil " +
              money(s.hesap.net) +
              "</strong> · Vade " +
              esc(s.vade) +
              "</div>" +
              (s.despatch_nolar && s.despatch_nolar.length
                ? '<div class="ftr-irs">İrsaliye: ' +
                  esc(s.despatch_nolar.join(", ")) +
                  "</div>"
                : "") +
              "</div>";
            govdeler.push(s.name + ":\n" + JSON.stringify(s.govde, null, 2));
          } else {
            var pl = s.parts
              .map(function (p) {
                return esc(p.ad) + ": " + p.kisi + " kişi → " + money(p.net);
              })
              .join(" · ");
            html +=
              '<div class="ftr-res ok"><div class="ftr-name">' +
              esc(s.name) +
              ' <span class="badge-soft badge-blue">AYLIK</span></div>' +
              '<div class="ftr-lines">' +
              pl +
              "</div>" +
              (s.fark !== 0
                ? '<div class="ftr-why warn">Bölüşüm toplamı ' +
                  s.sum_kisi +
                  " ≠ dönem " +
                  s.adet +
                  " (fark " +
                  s.fark +
                  ")</div>"
                : "") +
              '<div class="ftr-calc"><strong>Tahsil toplam ' +
              money(s.net) +
              "</strong></div></div>";
            Object.keys(s.govde).forEach(function (k) {
              govdeler.push(
                s.name +
                  " · " +
                  k +
                  ":\n" +
                  JSON.stringify(s.govde[k], null, 2),
              );
            });
          }
        });
        if (!r.gecerli_sayi)
          html += '<p class="ftr-why">Kesilebilecek müşteri yok.</p>';
        ozetEl.innerHTML = html;
        govdeEl.textContent = govdeler.join("\n\n");
        onayGenelEl.textContent =
          r.gecerli_sayi + " fatura · toplam tahsil " + money(r.genel_net);
        cutBtn.disabled = !r.gecerli_sayi;
        cutBtn.innerHTML = window.FTR.kapali
          ? '<i class="bi bi-shield-lock"></i> Kesim kapalı — dene'
          : '<i class="bi bi-receipt"></i> Faturaları Kes';
        show("onay");
      });
    });
  }

  if (backBtn)
    backBtn.addEventListener("click", function () {
      show("secim");
    });
  if (yeniBtn)
    yeniBtn.addEventListener("click", function () {
      location.reload();
    });

  // ── adım 2 → 3: kesim ──
  function kes() {
    cutBtn.disabled = true; // çift tık kalkanı (asıl kalkan: sunucuda tek kullanımlık imza)
    if (retryBtn) retryBtn.hidden = true;
    post({ action: "kes", onay: onayToken }, function (r) {
      onayToken = "";
      if (!r.ok) {
        sonucEl.innerHTML =
          '<div class="ftr-res err"><span class="ftr-why">' +
          esc(r.error || "Kesim başarısız.") +
          "</span></div>";
        show("sonuc");
        return;
      }
      var html =
        '<p class="ftr-sum">' +
        r.basarili +
        "/" +
        r.sonuclar.length +
        " fatura kesildi</p>";
      var basarisiz = 0;
      r.sonuclar.forEach(function (s) {
        var cls = s.ok
          ? s.resmilestirme === "gonderildi"
            ? "ok"
            : "warn"
          : "err";
        if (!s.ok) basarisiz++;
        html +=
          '<div class="ftr-res ' +
          cls +
          '"><div class="ftr-name">' +
          esc(s.name) +
          (s.fatura_no
            ? ' <span class="ftr-meta">' + esc(s.fatura_no) + "</span>"
            : "") +
          "</div>" +
          '<div class="ftr-why">' +
          esc(s.mesaj) +
          "</div></div>";
      });
      sonucEl.innerHTML = html;
      if (retryBtn) retryBtn.hidden = basarisiz === 0;
      show("sonuc");
    });
  }
  if (cutBtn) cutBtn.addEventListener("click", kes);
  if (retryBtn)
    retryBtn.addEventListener("click", function () {
      // Başarısızlar için yeni seçim gerekir (durum değişmiş olabilir) → temiz sayfadan devam.
      location.reload();
    });

  refresh();
})();
