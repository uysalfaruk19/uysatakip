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

  // fable-059: o güne ELLE kırılım girilmişse desen DEĞİL o kayıt geçerlidir.
  // Sunucudaki Repo::altFirmaElleDagit ile AYNI kural: tanınmayan (pasif) firmanın payı
  // varsayılana döner; kayıttan sonra sayı değiştiyse fark varsayılana yazılır / sondan kısılır.
  function firmVarsKod(firms) {
    var vars = null;
    firms.forEach(function (f) {
      if (vars === null && f.varsayilan) vars = f.kod;
    });
    firms.forEach(function (f) {
      if (vars === null && (f.sabit === null || f.sabit === undefined))
        vars = f.kod;
    });
    return vars === null ? firms[firms.length - 1].kod : vars;
  }

  function altFirmaElleDagit(total, elle, firms) {
    var out = {};
    firms.forEach(function (f) {
      out[f.kod] = 0;
    });
    if (!firms.length || total <= 0) return out;
    var vars = firmVarsKod(firms);
    Object.keys(elle).forEach(function (kod) {
      var n = clampP(elle[kod]);
      out[Object.prototype.hasOwnProperty.call(out, kod) ? kod : vars] += n;
    });
    var top = 0;
    firms.forEach(function (f) {
      top += out[f.kod];
    });
    var fark = total - top;
    if (fark > 0) {
      out[vars] += fark;
    } else if (fark < 0) {
      var kes = -fark;
      firms
        .slice()
        .reverse()
        .forEach(function (f) {
          if (kes <= 0) return;
          var d = Math.min(out[f.kod], kes);
          out[f.kod] -= d;
          kes -= d;
        });
    }
    return out;
  }

  function rowFirms(row) {
    try {
      return JSON.parse(row.getAttribute("data-altfirma") || "[]");
    } catch (e) {
      return [];
    }
  }

  function rowElle(row) {
    try {
      var x = JSON.parse(row.getAttribute("data-altfirma-elle") || "null");
      return x && Object.keys(x).length ? x : null;
    } catch (e) {
      return null;
    }
  }

  // Günün BÖLÜŞÜM HEDEFİ = o günün fatura kişisi. Sunucudaki kuralın birebir eşi:
  // hafta içi + resmi tatil DEĞİL + kural varsa (CANTAŞ 70) kural; aksi hâlde girilen sayı.
  function rowHedef(row) {
    var input = row.querySelector(".count-input");
    var p = clampP(input ? input.value : 0);
    var fk = parseInt(row.getAttribute("data-fatura-kisi") || "", 10);
    if (
      p > 0 &&
      !isNaN(fk) &&
      fk > 0 &&
      window.BUGUN_HAFTA_ICI &&
      !window.BUGUN_TATIL
    ) {
      return fk;
    }
    return p;
  }

  function firmPaylar(row, billP) {
    var firms = rowFirms(row);
    if (!firms.length) return null;
    var elle = rowElle(row);
    return elle
      ? altFirmaElleDagit(billP, elle, firms)
      : // fable-060: resmi tatilde oran kuralı UYGULANMAZ (hafta sonu gibi) — tamamı
        // varsayılan firmaya gelir, Ömer oradan istediği firmaya taşır.
        altFirmaDagit(
          billP,
          firms,
          !!window.BUGUN_HAFTA_ICI && !window.BUGUN_TATIL,
        );
  }

  function syncAltSplit(row, billP, p) {
    var el = row.querySelector(".alt-split");
    if (!el) return; // alt firması olmayan müşteri → hiçbir şey değişmez
    var firms = rowFirms(row);
    var parts = [];
    if (firms.length && p > 0) {
      var pay = firmPaylar(row, billP) || {};
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
      // fable-059: kural resmi tatilde uygulanmaz (rowHedef) — sunucu kaydıyla aynı rakam.
      var billP = rowHedef(row);
      var amt = billP * price;
      var amtEl = row.querySelector(".row-amt");
      // fable-030 (Ömer): satırda para GÖSTERİLMEZ — sadece "girilmedi" uyarısı
      // aksiyon-faz10: sunucunun bastığı "önerilen N" metnini EZMEZ. Eskiden recalc her
      // yüklemede "girilmedi" yazıp öneri rakamını siliyordu; kullanıcı rakamı görmeden
      // onaylamak zorunda kalıyordu (kendi kuralımızın ihlali).
      if (amtEl) {
        if (p > 0) {
          amtEl.textContent = "";
        } else {
          var oneriInput = row.querySelector(".count-input[data-oneri]");
          amtEl.textContent = oneriInput
            ? "önerilen " + oneriInput.getAttribute("data-oneri")
            : "girilmedi";
        }
      }
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
    // aksiyon-faz10: Bugün ekranında ciro kuruşsuz (data-tamsayi) — büyük punto taşmasın.
    if (sa) {
      sa.textContent = sa.getAttribute("data-tamsayi")
        ? Math.round(totAmount).toLocaleString("tr-TR")
        : fmt(totAmount);
    }
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

  // ── aksiyon-faz2: öneri onayı ────────────────────────────────
  // Sistem son 4 haftadan sayıyı biliyor; kullanıcı tek dokunuşla kabul eder. Otomatik
  // YAZILMAZ — değer input'a düşer, kayıt yine "Kaydet" ile olur (geri alınabilir kalsın).
  function oneriUygula(row) {
    var input = row.querySelector(".count-input[data-oneri]");
    if (!input || (parseInt(input.value, 10) || 0) > 0) return false;
    input.value = parseInt(input.getAttribute("data-oneri"), 10) || 0;
    row.classList.add("oneri-kabul");
    // aksiyon-faz10: onaylanan satırda buton kalmaz — iş bitti, ekran sussun.
    var btn = row.querySelector("[data-oneri-onay]");
    if (btn) btn.remove();
    input.removeAttribute("data-oneri");
    syncFromTotal(row);
    return true;
  }

  // Akıllı durum satırı ("N müşteri bekliyor") onaydan sonra da doğru sayıyı göstermeli;
  // sayfa yenilenene kadar eski sayı kalırsa ekran yalan söyler.
  function akilliDurumTazele() {
    var kart = document.querySelector(".akilli-durum");
    if (!kart) return;
    var kalan = 0;
    document.querySelectorAll(".customer-row").forEach(function (row) {
      var i = row.querySelector(".count-input");
      if (i && !(parseInt(i.value, 10) > 0)) kalan++;
    });
    var b = kart.querySelector(".ad-metin b");
    if (b) b.textContent = kalan + " müşteri bekliyor";
    // Onaylanacak öneri kalmadıysa toplu buton da kalkar — basınca hiçbir şey olmayan
    // buton bırakmak, ekranın "iş var" demesi demektir.
    var toplu = document.getElementById("oneri-toplu");
    if (toplu && !document.querySelector("[data-oneri-onay]:not([disabled])")) toplu.remove();
    if (kalan === 0) kart.remove();
  }

  document.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-oneri-onay]");
    if (!btn || btn.disabled) return;
    e.preventDefault();
    var row = btn.closest(".customer-row");
    if (row && oneriUygula(row)) {
      recalc();
      akilliDurumTazele();
    }
  });

  var toplu = document.getElementById("oneri-toplu");
  if (toplu) {
    toplu.addEventListener("click", function (e) {
      e.preventDefault();
      var n = 0;
      document.querySelectorAll(".customer-row").forEach(function (row) {
        var b = row.querySelector("[data-oneri-onay]");
        if (b && !b.disabled && oneriUygula(row)) n++;
      });
      if (n) {
        recalc();
        akilliDurumTazele();
      }
    });
  }

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
      return {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;",
      }[c];
    });
  }

  // fable-056: alt firmalı müşteride pencere FİRMA kırılımını gösterir (Ömer: "öğün kırılımı
  // değil firma kırılımı açılsın").
  // fable-059: kutular DÜZENLENEBİLİR — istisna günlerde (resmi tatil, özel iş) hangi şirkete
  // kaç kişi yazılacağını Ömer girer. Kayıt yoksa kutular DESENDEN dolu gelir (bugüne kadarki
  // davranış), toplam günün FATURA kişisine eşit olmadan Kaydet açılmaz.
  function renderFirmSplit() {
    var box = document.getElementById("meal-modal-firms");
    var list = document.getElementById("meal-modal-firmlist");
    if (!box || !list || !mealRow) return;
    var firms = rowFirms(mealRow);
    if (!firms.length) {
      box.hidden = true;
      return;
    }
    var elle = rowElle(mealRow);
    var billP = rowHedef(mealRow);
    var pay = firmPaylar(mealRow, billP) || {};
    var html = "";
    firms.forEach(function (f) {
      var n =
        elle && Object.prototype.hasOwnProperty.call(elle, f.kod)
          ? clampP(elle[f.kod])
          : pay[f.kod] || 0;
      html +=
        '<label class="firm-split-row"><span>' +
        esc(f.ad) +
        '</span><input class="firm-in" type="number" inputmode="numeric" min="0" ' +
        'data-kod="' +
        esc(f.kod) +
        '" value="' +
        n +
        '"></label>';
    });
    list.innerHTML = html;
    var badge = document.getElementById("firm-badge");
    if (badge) {
      badge.textContent = elle ? "elle girildi" : "otomatik (desen)";
      badge.classList.toggle("is-elle", !!elle);
    }
    var oto = document.getElementById("firm-oto");
    if (oto) oto.hidden = !elle;
    box.hidden = false;
    firmUpdateTotal();
  }

  // Canlı doğrulama: toplam = o günün FATURA kişisi olmalı. Değilse Kaydet PASİF + net mesaj
  // ("3 kişi eksik" / "5 kişi fazla") — sessiz yanlış kayıt YOK (ay sonu 3 ayrı e-Fatura).
  function firmUpdateTotal() {
    if (!mealRow) return 0;
    var ins = document.querySelectorAll("#meal-modal-firmlist .firm-in");
    var sum = 0;
    ins.forEach(function (el) {
      sum += clampP(el.value);
    });
    var hedef = rowHedef(mealRow);
    var totEl = document.getElementById("firm-total");
    if (totEl) {
      totEl.innerHTML =
        "Toplam: <strong>" +
        sum.toLocaleString("tr-TR") +
        "</strong> / hedef <strong>" +
        hedef.toLocaleString("tr-TR") +
        "</strong> kişi";
      totEl.classList.toggle("bad", sum !== hedef);
    }
    var warn = document.getElementById("firm-warn");
    var fark = sum - hedef;
    var msg = "";
    if (hedef <= 0) {
      msg =
        "Bu güne kişi sayısı girilmemiş — önce satırdaki sayacı doldurup kaydet.";
    } else if (fark !== 0) {
      msg =
        Math.abs(fark).toLocaleString("tr-TR") +
        " kişi " +
        (fark > 0 ? "fazla" : "eksik") +
        " — toplam hedefe eşit olmadan kaydedilmez.";
    }
    if (warn) {
      warn.textContent = msg;
      warn.hidden = msg === "";
    }
    ins.forEach(function (el) {
      el.classList.toggle("is-bad", msg !== "");
    });
    var btn = document.getElementById("meal-save");
    if (btn && !btn.hidden) btn.disabled = msg !== "";
    return sum;
  }

  // Tek POST: gün sayıları + firma kırılımı aynı formda gider (kırılım sunucuda üretim
  // kaydından SONRA doğrulanır → hedef her zaman güncel rakamdan hesaplanır).
  function submitFirmSplit(otomatige) {
    var form = document.getElementById("bugun-form");
    if (!form || !mealRow) return;
    var eski = form.querySelectorAll(".firm-post");
    eski.forEach(function (el) {
      el.remove();
    });
    function gizli(name, value) {
      var i = document.createElement("input");
      i.type = "hidden";
      i.name = name;
      i.value = value;
      i.className = "firm-post";
      form.appendChild(i);
    }
    gizli("altfirma_cid", mealRow.getAttribute("data-cid") || "0");
    if (otomatige) {
      gizli("altfirma_oto", "1");
    } else {
      document
        .querySelectorAll("#meal-modal-firmlist .firm-in")
        .forEach(function (el) {
          gizli(
            "altfirma[" + el.getAttribute("data-kod") + "]",
            String(clampP(el.value)),
          );
        });
    }
    closeMealModal();
    if (form.requestSubmit) form.requestSubmit();
    else form.submit();
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
    if (titleEl)
      titleEl.textContent = hasFirms ? "Firma kırılımı" : "Öğün kırılımı";
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
    // fable-059: firma kırılımı artık düzenlenebilir → Kaydet her iki pencerede de görünür.
    if (kaydetBtn) {
      kaydetBtn.hidden = false;
      kaydetBtn.disabled = false;
    }
    if (vazgecBtn) {
      vazgecBtn.textContent = "Vazgeç";
      vazgecBtn.classList.remove("btn-primaryx");
      vazgecBtn.classList.add("btn-secondaryx");
    }
    mealModalTotal();
    renderFirmSplit();
    mealModal.hidden = false;
    document.body.classList.add("meal-open");
    var first = hasFirms
      ? mealModal.querySelector("#meal-modal-firmlist .firm-in")
      : document.getElementById("meal-in-ogle");
    if (!first)
      first = mealModal.querySelector(".actions-row [data-meal-close]");
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
    if (!e.target.classList) return;
    if (e.target.classList.contains("meal-input")) {
      mealModalTotal();
      renderFirmSplit(); // fable-056: sayı değişince firma dağılımı anında tazelenir
    } else if (e.target.classList.contains("firm-in")) {
      firmUpdateTotal(); // fable-059: elle giriş → canlı toplam/hedef denetimi
    }
  });

  // fable-059: "Otomatiğe dön" — elle kaydı siler, gün desene döner (onay sorulur).
  var firmOtoBtn = document.getElementById("firm-oto");
  if (firmOtoBtn) {
    firmOtoBtn.addEventListener("click", function () {
      if (!mealRow) return;
      if (
        !confirm(
          "Bu günün elle girilen firma kırılımı silinsin ve dağılım yeniden firma desenine göre hesaplansın mı?",
        )
      ) {
        return;
      }
      submitFirmSplit(true);
    });
  }

  var mealSaveBtn = document.getElementById("meal-save");
  if (mealSaveBtn) {
    mealSaveBtn.addEventListener("click", function () {
      if (!mealRow) return;
      // fable-059: alt firmalı müşteride Kaydet = FİRMA kırılımı kaydı (öğün alanları gizli)
      if (rowFirms(mealRow).length) {
        if (firmUpdateTotal() !== rowHedef(mealRow) || rowHedef(mealRow) <= 0)
          return;
        mealSaveBtn.disabled = true; // çift tık koruması
        submitFirmSplit(false);
        return;
      }
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

  // fable-108: bildirim paneli aç/kapa. Zil tam sayfaya gitmiyor; tekrar basınca kapanır,
  // dışarı tıklayınca ve ESC ile de kapanır. Ekranı kaplamaz (CSS: sağ üstte dar panel).
  document.addEventListener("DOMContentLoaded", function () {
    var zil = document.getElementById("bildirimZil");
    var panel = document.getElementById("bildirimPanel");
    if (!zil || !panel) return;
    function kapat() { panel.hidden = true; zil.setAttribute("aria-expanded", "false"); }
    zil.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      var acik = !panel.hidden;
      panel.hidden = acik;                                   // tekrar basınca kapanır
      zil.setAttribute("aria-expanded", acik ? "false" : "true");
    });
    document.addEventListener("click", function (e) {
      if (panel.hidden) return;
      if (!panel.contains(e.target) && e.target !== zil && !zil.contains(e.target)) kapat();
    });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") kapat(); });
  });
})();
