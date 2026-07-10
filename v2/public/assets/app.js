// UYSA Kokpit v2 — hafif etkileşim (stepper + canlı tutar + gün toplamı). Çerçeve yok.
(function () {
  "use strict";

  function fmt(n) {
    return n.toLocaleString("tr-TR", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
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
      var amt = p * price;
      var amtEl = row.querySelector(".row-amt");
      if (amtEl) amtEl.textContent = p > 0 ? "₺ " + fmt(amt) : "girilmedi";
      var dot = row.querySelector(".status-dot");
      if (dot) dot.classList.toggle("warn", p === 0);
      row.classList.toggle("missing", p === 0);
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
    if (sa) sa.textContent = "₺ " + fmt(totAmount);
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
    recalc();
  });

  document.addEventListener("input", function (e) {
    if (e.target.classList && e.target.classList.contains("count-input"))
      recalc();
  });

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
