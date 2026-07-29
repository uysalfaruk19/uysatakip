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
  var sonPlan = []; // fable-026: onaylanan müşteriler [{cid, name}] — canlı ilerleme satırları

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
        sonPlan = [];
        r.satirlar.forEach(function (s) {
          if (s.ok) sonPlan.push({ cid: s.customer_id, name: s.name });
        });
        var html = "";
        var govdeler = [];
        // fable-028: gün gün öğün dökümü tablosu — fatura neyin toplamıysa o görünür
        // (haftalıkta kesilen irsaliyeler + numaraları, aylıkta girilen günlük sayılar).
        function gunTablosu(gunler, irsaliyeli) {
          if (!gunler || !gunler.length) return "";
          var t = { ogle: 0, aksam: 0, kumanya: 0, toplam: 0 };
          var rows = gunler
            .map(function (g) {
              t.ogle += g.ogle;
              t.aksam += g.aksam;
              t.kumanya += g.kumanya;
              t.toplam += g.toplam;
              var d = g.gun.slice(8, 10) + "." + g.gun.slice(5, 7);
              return (
                "<tr><td>" +
                d +
                (irsaliyeli && g.irsaliye
                  ? ' <span class="ftr-irsno">' + esc(g.irsaliye) + "</span>"
                  : "") +
                "</td><td>" +
                (g.ogle || "·") +
                "</td><td>" +
                (g.aksam || "·") +
                "</td><td>" +
                (g.kumanya || "·") +
                "</td><td><strong>" +
                g.toplam +
                "</strong></td></tr>"
              );
            })
            .join("");
          return (
            '<details class="ftr-gunler"><summary>Gün gün döküm (' +
            gunler.length +
            " gün · " +
            t.toplam +
            " kişi)</summary>" +
            '<div style="overflow-x:auto"><table class="mini-cal">' +
            "<thead><tr><th>Gün</th><th>Öğle</th><th>Akşam</th><th>Kumanya</th><th>Kişi</th></tr></thead>" +
            "<tbody>" +
            rows +
            '<tr class="is-total"><td>Toplam</td><td>' +
            t.ogle +
            "</td><td>" +
            t.aksam +
            "</td><td>" +
            t.kumanya +
            "</td><td><strong>" +
            t.toplam +
            "</strong></td></tr>" +
            "</tbody></table></div></details>"
          );
        }
        // fable-028b: BELGE görünümlü fatura önizlemesi — Paraşüt'te oluşacak faturanın aynısı
        // (kalem satırları, ara toplam, KDV, tevkifat, genel toplam, vade). "Öyle mi, kes" hissi.
        var URUN_AD = {
          Öğlen: "ÖĞLEN YEMEK BEDELİ",
          Akşam: "AKŞAM YEMEK BEDELİ",
          Kumanya: "KUMANYA BEDELİ",
        };
        function faturaBelge(baslik, kalemler, hesap, vade, tevkifat) {
          var rows = kalemler
            .map(function (k) {
              return (
                "<tr><td>" +
                esc(URUN_AD[k.ad] || k.ad) +
                "</td><td>" +
                k.miktar +
                "</td><td>" +
                money(k.birim) +
                "</td><td>%10</td><td>" +
                money(k.miktar * k.birim) +
                "</td></tr>"
              );
            })
            .join("");
          return (
            '<details class="ftr-belge-wrap"><summary>🧾 Fatura önizleme</summary>' +
            '<div class="ftr-belge">' +
            '<div class="ftr-belge-head"><strong>SATIŞ FATURASI</strong> <span class="ftr-belge-tag">ÖNİZLEME</span></div>' +
            '<div class="ftr-belge-meta">Satıcı: UYSA YEMEK · Alıcı: <strong>' +
            esc(baslik) +
            "</strong><br>" +
            "Fatura tarihi: " +
            esc(window.FTR.son) +
            " · Vade: " +
            esc(vade) +
            " · Dönem: " +
            esc(window.FTR.bas) +
            " → " +
            esc(window.FTR.son) +
            "</div>" +
            '<div style="overflow-x:auto"><table class="mini-cal ftr-belge-tablo">' +
            "<thead><tr><th>Açıklama</th><th>Miktar</th><th>Birim ₺</th><th>KDV</th><th>Tutar ₺</th></tr></thead>" +
            "<tbody>" +
            rows +
            "</tbody></table></div>" +
            '<div class="ftr-belge-alt">' +
            "<div>Ara toplam <strong>" +
            money(hesap.brut) +
            "</strong></div>" +
            "<div>KDV (%10) <strong>" +
            money(hesap.kdv) +
            "</strong></div>" +
            (hesap.tevkifat > 0
              ? "<div>KDV tevkifatı" +
                (tevkifat ? " (" + esc(tevkifat) + ")" : "") +
                " <strong>−" +
                money(hesap.tevkifat) +
                "</strong></div>"
              : "") +
            '<div class="ftr-belge-genel">TAHSİL EDİLECEK <strong>' +
            money(hesap.net) +
            "</strong></div>" +
            "</div></div></details>"
          );
        }
        // fable-029: sistem kontrolleri bloğu — Ömer'in elle yaptığı doğrulamalar otomatik
        // koşulur; hepsi yeşilse başlıkta "✔ kontroller temiz", değilse "⚠ N uyarı" rozeti.
        function kontrolBlok(kontroller) {
          if (!kontroller || !kontroller.length) return ["", ""];
          var uyari = kontroller.filter(function (k) {
            return !k.ok;
          }).length;
          var rozet = uyari
            ? ' <span class="badge-soft badge-warn">⚠ ' +
              uyari +
              " uyarı</span>"
            : ' <span class="badge-soft badge-ok">✔ kontroller temiz</span>';
          var blok =
            '<div class="ftr-kontrol">' +
            kontroller
              .map(function (k) {
                return (
                  '<div class="ftr-kontrol-satir ' +
                  (k.ok ? "ok" : "warn") +
                  '">' +
                  (k.ok ? "✔" : "⚠") +
                  " " +
                  esc(k.txt) +
                  "</div>"
                );
              })
              .join("") +
            "</div>";
          return [rozet, blok];
        }
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
          var kb = kontrolBlok(s.kontroller);
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
              kb[0] +
              "</div>" +
              kb[1] +
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
              faturaBelge(s.name, s.kalemler, s.hesap, s.vade, s.tevkifat) +
              gunTablosu(s.gunler, true) +
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
              ' <span class="badge-soft badge-blue">AYLIK</span>' +
              kb[0] +
              "</div>" +
              kb[1] +
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
              "</strong></div>" +
              s.parts
                .filter(function (p) {
                  return p.kisi > 0;
                })
                .map(function (p) {
                  // Aylık: HER parça ayrı fatura → her birine ayrı belge önizlemesi.
                  var brut = Math.round(p.kisi * s.birim * 100) / 100;
                  return faturaBelge(
                    p.ad,
                    [
                      {
                        ad: "Yemek hizmet bedeli",
                        miktar: p.kisi,
                        birim: s.birim,
                      },
                    ],
                    {
                      brut: brut,
                      kdv: Math.round((p.net - brut) * 100) / 100,
                      tevkifat: 0,
                      net: p.net,
                    },
                    s.vade || window.FTR.son,
                    "",
                  );
                })
                .join("") +
              gunTablosu(s.gunler, false) +
              "</div>";
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

  // ── adım 2 → 3: kesim — fable-026 kalıbı: müşteriler SIRAYLA faturalanır, sonuç ANINDA görünür.
  // (İrsaliyedeki ders: hepsi tek istekte gidince e-Fatura beklemeleri ekranı dakikalarca
  // sessiz bırakıyor. Aylık müşteri tek adımda ama parçaları — örn. CANTAŞ 3 fatura — ayrı satır döner.)
  function kes() {
    cutBtn.disabled = true; // çift tık kalkanı (asıl kalkan: sunucuda müşteri-bazlı plan claim)
    if (retryBtn) retryBtn.hidden = true;

    var html =
      '<p class="ftr-sum" id="ftr-progress">İşleniyor… 0/' +
      sonPlan.length +
      "</p>";
    sonPlan.forEach(function (p) {
      html +=
        '<div class="ftr-res wait" id="ftr-prog-' +
        p.cid +
        '"><div class="ftr-name">' +
        esc(p.name) +
        '</div><div class="ftr-why">Sırada bekliyor…</div></div>';
    });
    sonucEl.innerHTML = html;
    show("sonuc");

    var queue = sonPlan.slice();
    var faturaOk = 0;
    var faturaTop = 0;
    var islenen = 0;
    var basarisiz = 0;

    function ilerleme(sonMu) {
      var p = document.getElementById("ftr-progress");
      if (!p) return;
      p.textContent = sonMu
        ? faturaOk + "/" + faturaTop + " fatura kesildi"
        : "İşleniyor… " +
          islenen +
          "/" +
          sonPlan.length +
          (faturaOk ? " · " + faturaOk + " fatura kesildi" : "");
    }

    function blok(s) {
      // fable-052: mail hatası da sarıya düşürür (belge kesildi ama müşteriye gitmedi).
      var cls = s.ok
        ? s.resmilestirme === "gonderildi" && s.mail !== "hata"
          ? "ok"
          : "warn"
        : "err";
      return (
        '<div class="ftr-res ' +
        cls +
        '"><div class="ftr-name">' +
        esc(s.name) +
        (s.fatura_no
          ? ' <span class="ftr-meta">' + esc(s.fatura_no) + "</span>"
          : "") +
        '</div><div class="ftr-why">' +
        esc(s.mesaj) +
        "</div></div>"
      );
    }

    function siradaki() {
      if (!queue.length) {
        onayToken = ""; // plan tükendi
        ilerleme(true);
        if (retryBtn) retryBtn.hidden = basarisiz === 0;
        return;
      }
      var p = queue.shift();
      var row = document.getElementById("ftr-prog-" + p.cid);
      if (row) {
        row.className = "ftr-res wait busy";
        row.innerHTML =
          '<div class="ftr-name">' +
          esc(p.name) +
          '</div><div class="ftr-why">Fatura kesiliyor ve resmileştiriliyor…</div>';
      }
      post({ action: "kes", tek: p.cid, onay: onayToken }, function (r) {
        islenen++;
        if (!r.ok) {
          basarisiz++;
          faturaTop++;
          if (row) {
            row.className = "ftr-res err";
            row.innerHTML =
              '<div class="ftr-name">' +
              esc(p.name) +
              '</div><div class="ftr-why">' +
              esc(r.error || "Kesim başarısız.") +
              "</div>";
          }
        } else {
          var parcalar = r.sonuclar || [];
          faturaTop += parcalar.length;
          faturaOk += r.basarili || 0;
          parcalar.forEach(function (s) {
            if (!s.ok) basarisiz++;
          });
          if (row) row.outerHTML = parcalar.map(blok).join("") || "";
        }
        ilerleme(false);
        siradaki();
      });
    }
    ilerleme(false);
    siradaki();
  }
  if (cutBtn) cutBtn.addEventListener("click", kes);
  if (retryBtn)
    retryBtn.addEventListener("click", function () {
      // Başarısızlar için yeni seçim gerekir (durum değişmiş olabilir) → temiz sayfadan devam.
      location.reload();
    });

  refresh();
})();
