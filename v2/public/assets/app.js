// UYSA Kokpit v2 — hafif etkileşim (stepper + canlı tutar + gün toplamı). Çerçeve yok.
(function () {
  "use strict";

  function fmt(n) {
    return n.toLocaleString("tr-TR", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }

  // fable-023a: öğün kırılımı (öğlen/akşam/kumanya). Satırdaki gizli alanlar tek gerçek kaynak;
  // toplam kutusu onların toplamıdır. Sunucudaki Repo::mealsFromTotal ile AYNI kural.
  var MEALS = ["ogle", "aksam", "kumanya"];
  var MEAL_LABEL = { ogle: "öğlen", aksam: "akşam", kumanya: "kumanya" };
  var MEAL_MAX = 1000000;

  function clampP(v) {
    var n = parseInt(v, 10);
    if (isNaN(n) || n < 0) n = 0;
    if (n > MEAL_MAX) n = MEAL_MAX;
    return n;
  }

  function rowMeals(row) {
    var m = {};
    MEALS.forEach(function (k) {
      var el = row.querySelector(".m-" + k);
      m[k] = el ? clampP(el.value) : 0;
    });
    return m;
  }

  function setRowMeals(row, m, edited) {
    MEALS.forEach(function (k) {
      var el = row.querySelector(".m-" + k);
      if (el) el.value = m[k];
    });
    if (edited) {
      var f = row.querySelector(".m-edited");
      if (f) f.value = "1";
    }
    var lbl = row.querySelector(".meal-split");
    if (lbl) {
      var parts = [];
      MEALS.forEach(function (k) {
        if (m[k] > 0) parts.push(m[k] + " " + MEAL_LABEL[k]);
      });
      var show = m.aksam > 0 || m.kumanya > 0;
      lbl.textContent = show ? parts.join(" · ") : "";
      lbl.hidden = !show;
    }
  }

  // Toplam kutusu doğrudan değişti → fark öğlene; akşam/kumanya korunur.
  // Toplam akşam+kumanya altına düşerse önce kumanya, sonra akşam kısılır (ekran yalan söylemesin).
  function mealsFromTotal(total, cur) {
    total = clampP(total);
    var aksam = cur.aksam,
      kumanya = cur.kumanya;
    var rest = total - aksam - kumanya;
    if (rest >= 0) return { ogle: rest, aksam: aksam, kumanya: kumanya };
    var eksik = -rest;
    var kes = Math.min(kumanya, eksik);
    kumanya -= kes;
    eksik -= kes;
    aksam = Math.max(0, aksam - eksik);
    return { ogle: 0, aksam: aksam, kumanya: kumanya };
  }

  function syncFromTotal(row) {
    var input = row.querySelector(".count-input");
    if (!input) return;
    // fable-027c: taban DAİMA data-base (render'daki kırılım: dolu günde günün kendi kırılımı,
    // kopya akışında kopyalanan kırılım, boş günde son kayıtlı günün kırılımı) — rowMeals DEĞİL.
    // Yoksa "58"i tuş tuş yazarken ara değer ("5") hidden kırılımı bozar, ikinci tuş bozuk
    // tabana göre türetir (53/5/0 hatası). Modal kaydı sayfayı yeniden yüklediği için (tek POST)
    // data-base her zaman ekranda görünen kırılımla eşdeğerdir.
    var cur = { ogle: 0, aksam: 0, kumanya: 0 };
    try {
      var b = JSON.parse(row.getAttribute("data-base") || "{}");
      cur = {
        ogle: clampP(b.ogle),
        aksam: clampP(b.aksam),
        kumanya: clampP(b.kumanya),
      };
    } catch (e) {}
    setRowMeals(row, mealsFromTotal(input.value, cur), false);
  }

  // fable-051: alt firma bölüşümü — SALT GÖSTERİM (giriş yok; düzenleme yeri Fatura Kes).
  // Sunucudaki Repo::altFirmaGunDagit ile AYNI kural: hafta içi sabit kotalar sırayla,
  // KALAN varsayılana; cumartesi/pazar tamamı varsayılana. Kişi azsa sondan kısılır.
  function altFirmaDagit(total, firms, haftaIci) {
    var out = {};
    firms.forEach(function (f) {
      out[f.kod] = 0;
    });
    if (!firms.length || total <= 0) return out;
    var vars = null;
    firms.forEach(function (f) {
      if (vars === null && f.varsayilan) vars = f.kod;
    });
    firms.forEach(function (f) {
      if (vars === null && (f.sabit === null || f.sabit === undefined))
        vars = f.kod;
    });
    if (vars === null) vars = firms[firms.length - 1].kod;
    if (!haftaIci) {
      out[vars] = total;
      return out;
    }
    var kalan = total;
    firms.forEach(function (f) {
      if (f.kod === vars || f.sabit === null || f.sabit === undefined) return;
      var pay = Math.min(Math.max(0, f.sabit), kalan);
      out[f.kod] = pay;
      kalan -= pay;
    });
    out[vars] = kalan;
    return out;
  }

  function syncAltSplit(row, billP, p) {
    var el = row.querySelector(".alt-split");
    if (!el) return; // alt firması olmayan müşteri → hiçbir şey değişmez
    var firms = [];
    try {
      firms = JSON.parse(row.getAttribute("data-altfirma") || "[]");
    } catch (e) {}
    var parts = [];
    if (firms.length && p > 0) {
      var pay = altFirmaDagit(billP, firms, !!window.BUGUN_HAFTA_ICI);
      firms.forEach(function (f) {
        if (pay[f.kod] > 0) parts.push(pay[f.kod] + " " + f.ad);
      });
    }
    el.textContent = parts.join(" · ");
    el.hidden = parts.length === 0;
  }

  function recalc() {
    var rows = document.querySelectorAll(".customer-row[data-price]");
    var totPersons = 0,
      totAmount = 0,
      filled = 0;
    rows.forEach(function (row) {
      var input = row.querySelector(".count-input");
      var price = parseFloat(row.getAttribute("data-price")) || 0;
      var p = parseInt(input.value, 10) || 0;
      // fable-040: günlük ciro FATURA kişisinden — hafta içi kural varsa (data-fatura-kisi)
      //   ciro fatura kişisinden hesaplanır; "Toplam kişi" ise GERÇEK üretim (p) kalır.
      var fk = parseInt(row.getAttribute("data-fatura-kisi"), 10) || 0;
      var billP = window.BUGUN_HAFTA_ICI && fk > 0 && p > 0 ? fk : p;
      var amt = billP * price;
      var amtEl = row.querySelector(".row-amt");
      // fable-030 (Ömer): satırda para GÖSTERİLMEZ — sadece "girilmedi" uyarısı
      if (amtEl) amtEl.textContent = p > 0 ? "" : "girilmedi";
      var dot = row.querySelector(".status-dot");
      if (dot) dot.classList.toggle("warn", p === 0);
      row.classList.toggle("missing", p === 0);
      syncAltSplit(row, billP, p); // fable-051: salt gösterim etiketi

      if (p > 0) {
        filled++;
        totPersons += p;
        totAmount += amt;
      }
    });
    var sp = document.getElementById("sum-persons");
    var sa = document.getElementById("sum-amount");
    var sf = document.getElementById("sum-filled");
    if (sp) sp.textContent = totPersons.toLocaleString("tr-TR");
    // fable-023a: "₺" işareti span'ın DIŞINDA (bugun.php) — buraya da yazınca "₺ ₺" çıkıyordu
    if (sa) sa.textContent = fmt(totAmount);
    if (sf) sf.textContent = filled + "/" + rows.length + " girildi";
  }

  document.addEventListener("click", function (e) {
    var btn = e.target.closest(".step-btn[data-step]");
    if (!btn) return;
    e.preventDefault();
    var input = btn.closest(".counter").querySelector(".count-input");
    var v =
      (parseInt(input.value, 10) || 0) +
      parseInt(btn.getAttribute("data-step"), 10);
    if (v < 0) v = 0;
    input.value = v;
    var row = btn.closest(".customer-row");
    if (row) syncFromTotal(row); // fable-023a: toplam değişti → kırılım türetilir
    recalc();
  });

  document.addEventListener("input", function (e) {
    if (e.target.classList && e.target.classList.contains("count-input")) {
      var row = e.target.closest(".customer-row");
      if (row) syncFromTotal(row); // fable-023a
      recalc();
    }
  });

  // ── fable-023a: kırılım penceresi ────────────────────────────
  var mealModal = document.getElementById("meal-modal");
  var mealRow = null;

  function mealModalTotal() {
    var t = 0;
    MEALS.forEach(function (k) {
      var el = document.getElementById("meal-in-" + k);
      if (el) t += clampP(el.value);
    });
    var out = document.getElementById("meal-modal-total");
    if (out) out.textContent = t.toLocaleString("tr-TR");
    return t;
  }

  function esc(x) {
    return String(x == null ? "" : x).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  // fable-056: alt firmalı müşteride pencere FİRMA kırılımını gösterir (Ömer: "öğün kırılımı
  // değil firma kırılımı açılsın"). Rakamlar desenden hesaplanır ve toplam değişince tazelenir.
  function renderFirmSplit() {
    var box = document.getElementById("meal-modal-firms");
    var list = document.getElementById("meal-modal-firmlist");
    if (!box || !list || !mealRow) return;
    var firms = [];
    try {
      firms = JSON.parse(mealRow.getAttribute("data-altfirma") || "[]");
    } catch (e) {}
    if (!firms.length) {
      box.hidden = true;
      return;
    }
    var p = mealModalTotal();
    var fk = parseInt(mealRow.getAttribute("data-fatura-kisi") || "", 10);
    // Fatura kişisi kuralı olan müşteride (CANTAŞ 50/70) bölüşüm FATURA kişisinden doğar.
    var billP = p > 0 && !isNaN(fk) && window.BUGUN_HAFTA_ICI ? fk : p;
    var pay = altFirmaDagit(billP, firms, !!window.BUGUN_HAFTA_ICI);
    var html = "";
    firms.forEach(function (f) {
      var n = pay[f.kod] || 0;
      html +=
        '<div class="firm-split-row"><span>' +
        esc(f.ad) +
        "</span><strong>" +
        n.toLocaleString("tr-TR") +
        " kişi</strong></div>";
    });
    if (billP !== p) {
      html +=
        '<div class="firm-split-note">Fatura kişisi ' +
        billP.toLocaleString("tr-TR") +
        " (üretim " +
        p.toLocaleString("tr-TR") +
        ")</div>";
    }
    list.innerHTML = html;
    box.hidden = false;
  }

  function openMealModal(row) {
    if (!mealModal) return;
    mealRow = row;
    var m = rowMeals(row);
    MEALS.forEach(function (k) {
      var el = document.getElementById("meal-in-" + k);
      if (el) el.value = m[k];
    });
    var nameEl = document.getElementById("meal-modal-name");
    if (nameEl) nameEl.textContent = row.getAttribute("data-name") || "";
    var titleEl = document.getElementById("meal-modal-title");
    var hasFirms = (row.getAttribute("data-altfirma") || "") !== "";
    if (titleEl) titleEl.textContent = hasFirms ? "Firma kırılımı" : "Öğün kırılımı";
    // fable-058 (Ömer): alt firmalı müşteride ÖĞÜN kırılımı hiç gösterilmez — tek öğün
    // çalışıyorlar ve pencere ekrana sığmıyordu. Sayı satırdaki sayaçtan girilir.
    var ogunAlan = mealModal.querySelector(".meal-fields");
    var ogunTotal = mealModal.querySelector(".meal-total");
    var ogunHint = mealModal.querySelector(".meal-hint");
    var kaydetBtn = document.getElementById("meal-save");
    var vazgecBtn = mealModal.querySelector(".actions-row [data-meal-close]");
    if (ogunAlan) ogunAlan.hidden = hasFirms;
    if (ogunTotal) ogunTotal.hidden = hasFirms;
    if (ogunHint) ogunHint.hidden = hasFirms;
    if (kaydetBtn) kaydetBtn.hidden = hasFirms;
    if (vazgecBtn) {
      vazgecBtn.textContent = hasFirms ? "Kapat" : "Vazgeç";
      vazgecBtn.classList.toggle("btn-primaryx", hasFirms);
      vazgecBtn.classList.toggle("btn-secondaryx", !hasFirms);
    }
    mealModalTotal();
    renderFirmSplit();
    mealModal.hidden = false;
    document.body.classList.add("meal-open");
    var first = hasFirms ? mealModal.querySelector(".actions-row [data-meal-close]")
      : document.getElementById("meal-in-ogle");
    if (first) first.focus();
  }

  function closeMealModal() {
    if (!mealModal) return;
    mealModal.hidden = true;
    document.body.classList.remove("meal-open");
    if (mealRow) {
      var btn = mealRow.querySelector(".row-name-btn");
      if (btn) btn.focus();
    }
    mealRow = null;
  }

  document.addEventListener("click", function (e) {
    var open = e.target.closest("[data-meal-open]");
    if (open) {
      e.preventDefault();
      var row = open.closest(".customer-row");
      if (row) openMealModal(row);
      return;
    }
    if (e.target.closest("[data-meal-close]")) {
      e.preventDefault();
      closeMealModal();
    }
  });

  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && mealModal && !mealModal.hidden) closeMealModal();
  });

  document.addEventListener("input", function (e) {
    if (e.target.classList && e.target.classList.contains("meal-input")) {
      mealModalTotal();
      renderFirmSplit(); // fable-056: sayı değişince firma dağılımı anında tazelenir
    }
  });

  var mealSaveBtn = document.getElementById("meal-save");
  if (mealSaveBtn) {
    mealSaveBtn.addEventListener("click", function () {
      if (!mealRow) return;
      var m = {};
      MEALS.forEach(function (k) {
        var el = document.getElementById("meal-in-" + k);
        m[k] = el ? clampP(el.value) : 0;
      });
      var row = mealRow;
      setRowMeals(row, m, true);
      var input = row.querySelector(".count-input");
      var tot = m.ogle + m.aksam + m.kumanya;
      if (input) input.value = tot > 0 ? tot : "";
      recalc();
      closeMealModal();
      // Tek Kaydet / tek POST: kırılım anında kalıcı olur (yarım kayıt riski yok)
      mealSaveBtn.disabled = true; // çift tık koruması
      var form = document.getElementById("bugun-form");
      if (form) {
        if (form.requestSubmit) form.requestSubmit();
        else form.submit();
      } else {
        mealSaveBtn.disabled = false;
      }
    });
  }

  window.toggleSheet = function (id) {
    var el = document.getElementById(id);
    if (el)
      el.style.display =
        el.style.display === "none" || !el.style.display ? "block" : "none";
  };

  window.toggleFabMenu = function () {
    var m = document.getElementById("fab-menu");
    var b = document.getElementById("fab-backdrop");
    if (!m || !b) return;
    var open = m.classList.toggle("open");
    b.classList.toggle("open", open);
  };

  // iOS: klavye açıkken fixed alt bar ortada asılı kalıyor → kb-open ile gizle.
  // focusout sonrası kısa gecikme: alanlar arası geçişte bar zıplamasın.
  var kbTimer;
  function isField(el) {
    return (
      el &&
      el.matches &&
      el.matches(
        "input:not([type=checkbox]):not([type=radio]), textarea, select",
      )
    );
  }
  document.addEventListener("focusin", function (e) {
    if (!isField(e.target)) return;
    clearTimeout(kbTimer);
    document.body.classList.add("kb-open");
  });
  document.addEventListener("focusout", function () {
    clearTimeout(kbTimer);
    kbTimer = setTimeout(function () {
      if (!isField(document.activeElement))
        document.body.classList.remove("kb-open");
    }, 120);
  });

  // Ağ durumu: çevrimdışı kalınca üstte şerit (app/WebView beyaz ekran yerine geri bildirim)
  function netBanner(show) {
    var el = document.getElementById("net-banner");
    if (show && !el) {
      el = document.createElement("div");
      el.id = "net-banner";
      el.className = "net-banner";
      el.textContent =
        "Bağlantı yok — internete bağlanınca kaldığınız yerden devam edin.";
      document.body.appendChild(el);
    } else if (!show && el) {
      el.remove();
    }
  }
  window.addEventListener("offline", function () {
    netBanner(true);
  });
  window.addEventListener("online", function () {
    netBanner(false);
  });
  if (navigator.onLine === false) netBanner(true);

  document.addEventListener("DOMContentLoaded", recalc);
})();
