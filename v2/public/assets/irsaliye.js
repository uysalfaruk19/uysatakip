// fable-023b — İrsaliyelendir penceresi: seçim → onay → sonuç.
// Sunucu tek gerçek kaynak: seçilebilirlik, gövde ve kesim kararı irsaliye.php'de verilir.
// Buradaki tek koruma katmanı UX içindir (çift tık, boş seçim); asıl kalkanlar sunucuda.
(function () {
  "use strict";

  var modal = document.getElementById("irs-modal");
  if (!modal || !window.IRS) return;

  var openBtn = document.getElementById("irs-open");
  var stepSecim = document.getElementById("irs-step-secim");
  var stepOnay = document.getElementById("irs-step-onay");
  var stepSonuc = document.getElementById("irs-step-sonuc");
  var allBox = document.getElementById("irs-all");
  var countEl = document.getElementById("irs-count");
  var nextBtn = document.getElementById("irs-next");
  var backBtn = document.getElementById("irs-back");
  var cutBtn = document.getElementById("irs-cut");
  var retryBtn = document.getElementById("irs-retry");
  var ozetEl = document.getElementById("irs-ozet");
  var govdeEl = document.getElementById("irs-govde");
  var sonucEl = document.getElementById("irs-sonuc");
  var onayToken = "";
  var sonHedef = [];
  var sonAd = {}; // fable-026: cid → müşteri adı (canlı ilerleme satırları için)

  function picks() {
    return [].slice.call(modal.querySelectorAll(".irs-pick:checked"));
  }

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

  function refresh() {
    var n = picks().length;
    if (countEl) countEl.textContent = n + " seçili";
    if (nextBtn) nextBtn.disabled = n === 0;
  }

  function show(step) {
    stepSecim.hidden = step !== "secim";
    stepOnay.hidden = step !== "onay";
    stepSonuc.hidden = step !== "sonuc";
  }

  function open() {
    show("secim");
    refresh();
    modal.hidden = false;
    document.body.classList.add("meal-open");
  }

  function close() {
    modal.hidden = true;
    document.body.classList.remove("meal-open");
    if (openBtn) openBtn.focus();
  }

  function post(payload, done) {
    payload.csrf = window.IRS.csrf;
    payload.date = window.IRS.date;
    fetch("irsaliye.php", {
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

  if (openBtn) openBtn.addEventListener("click", open);

  modal.addEventListener("click", function (e) {
    if (e.target.closest("[data-irs-close]")) {
      e.preventDefault();
      close();
      return;
    }
    // "durum bilinmiyor" kilidini insan kararıyla çözme (timeout sonrası çıkmaz sokak olmasın)
    var fix = e.target.closest("[data-irs-cozum]");
    if (fix) {
      e.preventDefault();
      var karar = fix.getAttribute("data-irs-cozum");
      var soru =
        karar === "kesildi"
          ? "Paraşüt'te bu belgenin VAR olduğunu doğruladınız mı? Kayıt 'kesildi' olarak işaretlenecek."
          : "Paraşüt'te bu belgenin OLMADIĞINI doğruladınız mı? Kilit açılacak, tekrar kesim yapılabilecek.";
      if (!confirm(soru)) return;
      fix.disabled = true;
      post(
        {
          action: "cozum",
          customer_id: parseInt(fix.getAttribute("data-cid"), 10),
          karar: karar,
        },
        function (r) {
          if (!r.ok) {
            fix.disabled = false;
            alert(r.error || "İşlem yapılamadı.");
            return;
          }
          location.reload(); // liste tek gerçek kaynaktan yeniden çizilsin
        },
      );
    }
  });
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && !modal.hidden) close();
  });

  modal.addEventListener("change", function (e) {
    if (e.target === allBox) {
      var on = allBox.checked;
      [].slice
        .call(modal.querySelectorAll(".irs-pick:not([disabled])"))
        .forEach(function (c) {
          c.checked = on;
        });
      refresh();
    } else if (e.target.classList.contains("irs-pick")) {
      refresh();
    }
  });

  // ── adım 1 → 2: sunucudan özet + gövde + tek kullanımlık onay imzası ──
  if (nextBtn) {
    nextBtn.addEventListener("click", function () {
      var ids = picks().map(function (c) {
        return parseInt(c.value, 10);
      });
      if (!ids.length) return;
      nextBtn.disabled = true;
      post({ action: "hazirla", ids: ids }, function (r) {
        nextBtn.disabled = false;
        if (!r.ok) {
          alert(r.error || "Hazırlanamadı.");
          return;
        }
        onayToken = r.onay || "";
        sonHedef = [];
        sonAd = {};
        var html =
          '<p class="irs-sum"><strong>' +
          esc(r.tarih) +
          "</strong> · " +
          r.gecerli_sayi +
          " müşteri · toplam " +
          r.toplam_kisi +
          " kişi</p>";
        var govdeler = [];
        r.satirlar.forEach(function (s) {
          if (s.ok) {
            sonHedef.push(s.customer_id);
            sonAd[s.customer_id] = s.name;
            var kal = s.kalemler
              .map(function (k) {
                return esc(k.ogun) + " × " + k.miktar;
              })
              .join(" · ");
            // fable-027c: tek öğüne çökmüş kırılım uyarısı onay ekranında SARI görünür
            html +=
              '<div class="irs-res ' +
              (s.uyari ? "warn" : "ok") +
              '"><span class="irs-name">' +
              esc(s.name) +
              '</span><span class="irs-meta">' +
              kal +
              "</span>" +
              (s.uyari
                ? '<span class="irs-why">⚠ ' + esc(s.uyari) + "</span>"
                : "") +
              "</div>";
            govdeler.push(s.name + ":\n" + JSON.stringify(s.govde, null, 2));
          } else {
            html +=
              '<div class="irs-res err"><span class="irs-name">' +
              esc(s.name) +
              '</span><span class="irs-why">' +
              esc(s.sebep) +
              "</span></div>";
          }
        });
        if (!r.gecerli_sayi) {
          html += '<p class="irs-why">Kesilebilecek müşteri yok.</p>';
        }
        ozetEl.innerHTML = html;
        govdeEl.textContent = govdeler.join("\n\n");
        cutBtn.disabled = !r.gecerli_sayi;
        cutBtn.textContent = window.IRS.kapali
          ? "Kesim kapalı — dene"
          : "İrsaliyeleri Kes";
        show("onay");
      });
    });
  }

  if (backBtn)
    backBtn.addEventListener("click", function () {
      show("secim");
    });

  // ── adım 2 → 3: kesim — fable-026: müşteriler SIRAYLA kesilir, her sonuç ANINDA görünür.
  // (Eskiden hepsi tek istekteydi; gönderim beklemeleri yüzünden dakikalarca sessiz kalıyordu —
  // Ömer: "kesilip kesilmediğini anlamıyorum". Şimdi: bekleyen ▫ / işleniyor ⏳ / ✅-❌ canlı.)
  function kes(ids) {
    cutBtn.disabled = true; // çift tık kalkanı (asıl kalkan: sunucuda müşteri-bazlı claim)
    if (retryBtn) retryBtn.hidden = true;

    var html =
      '<p class="irs-sum" id="irs-progress">İşleniyor… 0/' +
      ids.length +
      "</p>";
    ids.forEach(function (cid) {
      html +=
        '<div class="irs-res wait" id="irs-row-' +
        cid +
        '"><span class="irs-name">' +
        esc(sonAd[cid] || "#" + cid) +
        '</span><span class="irs-why">Sırada bekliyor…</span></div>';
    });
    sonucEl.innerHTML = html;
    show("sonuc");

    var queue = ids.slice();
    var basarili = 0;
    var tamam = 0;
    var basarisiz = [];

    function satir(cid, cls, meta, why) {
      var row = document.getElementById("irs-row-" + cid);
      if (!row) return;
      row.className = "irs-res " + cls;
      row.innerHTML =
        '<span class="irs-name">' +
        esc(sonAd[cid] || "#" + cid) +
        "</span>" +
        (meta ? '<span class="irs-meta">' + esc(meta) + "</span>" : "") +
        '<span class="irs-why">' +
        esc(why || "") +
        "</span>";
    }

    function ilerleme(sonMu) {
      var p = document.getElementById("irs-progress");
      if (!p) return;
      p.textContent = sonMu
        ? basarili + "/" + ids.length + " irsaliye kesildi"
        : "İşleniyor… " +
          tamam +
          "/" +
          ids.length +
          (basarili ? " · " + basarili + " kesildi" : "");
    }

    function bitir() {
      onayToken = ""; // küme tükendi
      ilerleme(true);
      if (retryBtn) {
        // Sadece BAŞARISIZLAR tekrar denenir; "durum bilinmiyor" olanlar sunucuda zaten kilitli.
        retryBtn.hidden = basarisiz.length === 0;
        retryBtn.onclick = function () {
          post({ action: "hazirla", ids: basarisiz }, function (h) {
            if (!h.ok || !h.gecerli_sayi) {
              alert((h && h.error) || "Tekrar denenebilecek müşteri yok.");
              return;
            }
            onayToken = h.onay;
            h.satirlar.forEach(function (s) {
              if (s.ok) sonAd[s.customer_id] = s.name;
            });
            kes(basarisiz);
          });
        };
      }
    }

    function siradaki() {
      if (!queue.length) {
        bitir();
        return;
      }
      var cid = queue.shift();
      satir(cid, "wait busy", "", "Kesiliyor ve gönderiliyor…");
      post({ action: "kes", tek: cid, onay: onayToken }, function (r) {
        tamam++;
        if (!r.ok) {
          basarisiz.push(cid);
          satir(cid, "err", "", r.error || "Kesim başarısız.");
        } else {
          var s = r.sonuc || {};
          if (s.ok) {
            basarili++;
            satir(
              cid,
              s.durum === "kesildi" && !s.tasiyici_ok ? "warn" : "ok",
              s.despatch_no || "",
              s.mesaj || "",
            );
          } else {
            basarisiz.push(cid);
            satir(cid, "err", "", s.mesaj || "Kesim başarısız.");
          }
        }
        ilerleme(false);
        siradaki();
      });
    }
    ilerleme(false);
    siradaki();
  }

  if (cutBtn) {
    cutBtn.addEventListener("click", function () {
      if (!sonHedef.length) return;
      kes(sonHedef);
    });
  }
})();
