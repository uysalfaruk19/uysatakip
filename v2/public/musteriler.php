<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Uysa\Auth;
use Uysa\Db;
use Uysa\Helpers;
use Uysa\Repo;

$u = Auth::requireLogin();
$pdo = Db::pdo();
$repo = new Repo($pdo);

$month = (string) ($_GET['ay'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$flash = '';
$flashOk = true;
$formOpen = isset($_GET['yeni']) || isset($_GET['edit']);
$editId = (int) ($_GET['edit'] ?? 0) ?: null;
// fable-092: aylık sayım paneli (müşteri satırındaki takvim ikonu)
$sayimId = (int) ($_GET['sayim'] ?? 0) ?: null;

// ── fable-093: aylık sayımın EXCEL çıktısı (Ömer: "bir de Excel olarak indirebileyim") ──
// Ekranla AYNI kaynaktan üretilir (aylikSayimGrid + altFirmaGunGun) — dosyada başka, ekranda
// başka rakam çıkmasın. Sayılar gerçek sayı yazılır ki Ömer dosyada toplam alabilsin.
if ($sayimId && ($_GET['xlsx'] ?? '') === '1') {
    $xm = (string) ($_GET['ay'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $xm)) {
        $xm = date('Y-m');
    }
    $xc = $repo->customer($sayimId);
    if ($xc === null) {
        http_response_code(404);
        exit('Müşteri bulunamadı.');
    }
    $xGrid = $repo->aylikSayimGrid($sayimId, $xm);
    $xBirim = (float) $repo->priceFor($sayimId, $xm)['unit_price'];
    $xFirma = $repo->altFirmalar($sayimId);
    $xKir = $xFirma ? $repo->altFirmaGunGun($sayimId, $xm . '-01', date('Y-m-t', strtotime($xm . '-01'))) : [];
    $xAksam = $xKumanya = false;
    foreach ($xGrid as $g) {
        if ($g['aksam'] > 0) { $xAksam = true; }
        if ($g['kumanya'] > 0) { $xKumanya = true; }
    }

    $rows = [];
    $kalin = [];
    $rows[] = [mb_strtoupper((string) $xc['name'], 'UTF-8') . ' — ' . mb_strtoupper(ay_label_tr($xm), 'UTF-8')
        . ($xFirma ? ' · FİRMA BAZLI YEMEK DAĞILIMI' : ' · AYLIK YEMEK SAYIMI')];
    $kalin[] = 1;
    $rows[] = [];
    // Ömer'in Temmuz dosyasındaki desen: TARİH | HC | CANTAŞ | BAKIR — ÜRETİM sütunu YOK.
    // Bölüşümlü müşteride Excel de aynı: firma sayıları + fatura sayısı.
    $bas = ['Tarih', 'Gün'];
    foreach ($xFirma as $f) { $bas[] = (string) $f['ad']; }
    if (!$xFirma) {
        $bas[] = 'Öğle';
        if ($xAksam) { $bas[] = 'Akşam'; }
        if ($xKumanya) { $bas[] = 'Kumanya'; }
    }
    $bas[] = $xFirma ? 'Fatura sayısı' : 'Fatura kişisi';
    $bas[] = 'Durum / Tutar';
    $rows[] = $bas;
    $kalin[] = count($rows);

    $fToplam = [];
    foreach ($xFirma as $f) { $fToplam[$f['kod']] = 0; }
    $tF = 0;
    for ($dn = 1; $dn <= 5; $dn++) {
        $grup = array_values(array_filter($xGrid, static fn(array $x): bool => $x['donem'] === $dn));
        if (!$grup) { continue; }
        $gF = 0; $gFirma = [];
        foreach ($xFirma as $f) { $gFirma[$f['kod']] = 0; }
        foreach ($grup as $g) {
            $sat = [date('d.m.Y', (int) strtotime($g['gun'])), $g['gun_adi']];
            foreach ($xFirma as $f) {
                $fk = (int) ($xKir[$g['gun']][$f['kod']] ?? 0);
                $sat[] = $fk ?: null;
                $gFirma[$f['kod']] += $fk;
                $fToplam[$f['kod']] += $fk;
            }
            if (!$xFirma) {
                $sat[] = $g['ogle'] ?: null;
                if ($xAksam) { $sat[] = $g['aksam'] ?: null; }
                if ($xKumanya) { $sat[] = $g['kumanya'] ?: null; }
            }
            $sat[] = $g['fatura_kisi'] ?: null;
            $sat[] = $g['kilit'] !== '' ? $g['kilit'] : ($g['uretim'] === 0 ? 'boş' : 'açık');
            $rows[] = $sat;
            $gF += $g['fatura_kisi'];
        }
        $ara = [Repo::donemEtiketi($dn, $xm) . ' TOPLAM', ''];
        foreach ($xFirma as $f) { $ara[] = $gFirma[$f['kod']]; }
        if (!$xFirma) {
            $ara[] = null;
            if ($xAksam) { $ara[] = null; }
            if ($xKumanya) { $ara[] = null; }
        }
        $ara[] = $gF;
        $ara[] = round($gF * $xBirim, 2);
        $rows[] = $ara;
        $kalin[] = count($rows);
        $rows[] = [];
        $tF += $gF;
    }

    $son = ['AY TOPLAMI', ''];
    foreach ($xFirma as $f) { $son[] = $fToplam[$f['kod']]; }
    if (!$xFirma) {
        $son[] = null;
        if ($xAksam) { $son[] = null; }
        if ($xKumanya) { $son[] = null; }
    }
    $son[] = $tF;
    $son[] = round($tF * $xBirim, 2);
    $rows[] = $son;
    $kalin[] = count($rows);
    $rows[] = [];
    $rows[] = ['Kişi başı', $xBirim];
    $rows[] = ['Toplam tutar', round($tF * $xBirim, 2)];
    $kalin[] = count($rows);
    if ($xFirma) {
        $rows[] = [];
        $rows[] = ['FATURA BAZLI TOPLAM (' . count($xFirma) . ' ayrı e-Fatura)'];
        $kalin[] = count($rows);
        foreach ($xFirma as $f) {
            $rows[] = [(string) $f['ad'], $fToplam[$f['kod']], round($fToplam[$f['kod']] * $xBirim, 2)];
        }
    }

    // Dosya adı ASCII: Türkçe karakter indirme başlığında (Content-Disposition) bozulur.
    $adHam = strtr((string) $xc['name'], ['ç'=>'c','Ç'=>'C','ğ'=>'g','Ğ'=>'G','ı'=>'i','İ'=>'I',
        'ö'=>'o','Ö'=>'O','ş'=>'s','Ş'=>'S','ü'=>'u','Ü'=>'U']);
    $ad = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $adHam), '-') . '-' . $xm . '-sayim.xlsx';
    $bin = \Uysa\XlsxSayim::yaz($rows, 'Firma Dağılım', $kalin);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $ad . '"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;
}
// fable-046: işlem sonrası hangi liste bloğu açık kalsın ('uretim' | 'tasima' | '').
$acikPost = '';

// ── Kaydet / pasifleştir ─────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Helpers::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Oturum doğrulaması başarısız.';
        $flashOk = false;
        $formOpen = true;
    } elseif (($_POST['action'] ?? '') === 'pasif') {
        $pid = (int) ($_POST['id'] ?? 0);
        if ($pid > 0) {
            $eski = $repo->customer($pid);
            $acikPost = ($eski['category'] ?? 'uretim') === 'tasima' ? 'tasima' : 'uretim';
            $repo->setCustomerActive($pid, false);
            uysa_audit('musteri_pasif', $u['username'], (string) $pid, null, client_ip());
            $flash = 'Müşteri pasifleştirildi.';
        }
    } elseif (($_POST['action'] ?? '') === 'sayim_kaydet') {
        // fable-092 (Ömer, 28 Ağu): "boş güne yemek sayısı ekleyeceğim... sayı değiştirdiğimde
        // kaydettiğimde faturaya da senkronize olsun." Fatura zaten production'dan doğduğu için
        // senkron ayrı bir iş değil — kayıt production'a yazılır, fatura oradan okur.
        //
        // ÜRETİM ve FATURA kişisi AYRI yazılır (fable-040: CANTAŞ üretim 50 / fatura 70; fark
        // amount'a biner). Tek sayıya indirilirse ya fatura eksik kesilir ya kişi başı gıda
        // maliyeti bozulur. Fatura kişisi boş bırakılırsa üretimle aynı kabul edilir.
        $pid = (int) ($_POST['id'] ?? 0);
        $sAy = (string) ($_POST['ay'] ?? date('Y-m'));
        if ($pid > 0 && preg_match('/^\d{4}-\d{2}$/', $sAy)) {
            $birim = (float) $repo->priceFor($pid, $sAy)['unit_price'];
            $mevcut = [];
            foreach ($repo->aylikSayimGrid($pid, $sAy) as $sg) {
                $mevcut[$sg['gun']] = $sg;
            }
            $yazilan = 0;
            $kilitli = 0;
            // Bölüşümlü müşteride (CANTAŞ/Marmara) ekranda TEK giriş vardır ve o FATURA sayısıdır
            // (Ömer: "üretim toplamı 50'yi istemiyorum, fatura istiyorum"). Öğün kutuları
            // gönderilmez → üretim kaydı KORUNUR; yalnız faturalanacak tutar güncellenir.
            $gunAnahtarlari = array_unique(array_merge(
                array_keys((array) ($_POST['ogle'] ?? [])),
                array_keys((array) ($_POST['fatura'] ?? []))
            ));
            foreach ($gunAnahtarlari as $gun) {
                $gun = (string) $gun;
                $m = $mevcut[$gun] ?? null;
                if ($m === null) {
                    continue;
                }
                if ($m['kilit'] !== '') {
                    $kilitli++;
                    continue;   // belgesi kesilmiş gün DEĞİŞTİRİLMEZ (sessiz de geçilmez, sayılır)
                }
                $fatRaw = trim((string) ($_POST['fatura'][$gun] ?? ''));
                if (!isset($_POST['ogle'][$gun])) {
                    // Yalnız fatura sayısı geldi: üretim olduğu gibi kalır. Gün boşsa (hiç üretim
                    // kaydı yok) girilen sayı üretim olarak da yazılır — o gün gerçekten o kadar
                    // yemek çıkmış demektir, yoksa kayıt hiç oluşmaz ve fatura da doğmaz.
                    $fatKisi = $fatRaw === '' ? 0 : max(0, (int) $fatRaw);
                    $yeni = [
                        'ogle'    => $m['ogle'] > 0 ? $m['ogle'] : $fatKisi,
                        'aksam'   => $m['aksam'],
                        'kumanya' => $m['kumanya'],
                    ];
                    if ($fatKisi <= 0) {
                        $yeni = ['ogle' => 0, 'aksam' => 0, 'kumanya' => 0];   // 0 = günü sil
                    }
                    $uToplam = array_sum($yeni);
                } else {
                    $yeni = [
                        'ogle'    => max(0, (int) ($_POST['ogle'][$gun] ?? 0)),
                        'aksam'   => max(0, (int) ($_POST['aksam'][$gun] ?? 0)),
                        'kumanya' => max(0, (int) ($_POST['kumanya'][$gun] ?? 0)),
                    ];
                    $uToplam = array_sum($yeni);
                    $fatKisi = $fatRaw === '' ? $uToplam : max(0, (int) $fatRaw);
                }
                $degisti = $yeni['ogle'] !== $m['ogle'] || $yeni['aksam'] !== $m['aksam']
                    || $yeni['kumanya'] !== $m['kumanya'] || $fatKisi !== $m['fatura_kisi'];
                if (!$degisti) {
                    continue;
                }
                // Fatura kişisi farkı ÖĞLE kaydının tutarına bindirilir (fable-040 deseni).
                foreach (['ogle', 'aksam', 'kumanya'] as $ogun) {
                    $kisi = $yeni[$ogun];
                    if ($kisi <= 0 && ($m[$ogun] ?? 0) <= 0) {
                        continue;   // hiç olmayan öğüne 0 yazma
                    }
                    if ($kisi <= 0) {
                        $repo->deleteProduction($pid, $gun, $ogun);
                        continue;
                    }
                    $tutar = $ogun === 'ogle'
                        ? round(($fatKisi - $yeni['aksam'] - $yeni['kumanya']) * $birim, 2)
                        : null;
                    // entered_by ENUM('musteri','uysa','bot') — kullanıcı adı YAZILAMAZ
                    // (denendi: "Data truncated" fatal'ı). Kimin girdiği audit kaydında duruyor.
                    $repo->upsertProduction($pid, $gun, $kisi, $birim, $ogun,
                        'uysa', null, null, $tutar !== null ? max(0.0, $tutar) : null);
                }
                $yazilan++;
            }
            uysa_audit('sayim_kaydet', $u['username'], (string) $pid,
                json_encode(['ay' => $sAy, 'gun' => $yazilan, 'kilitli' => $kilitli], JSON_UNESCAPED_UNICODE), client_ip());
            $flash = $yazilan > 0
                ? $yazilan . ' gün güncellendi — fatura bu sayılardan kesilir.'
                : 'Değişiklik yok.';
            if ($kilitli > 0) {
                $flash .= ' ' . $kilitli . ' gün belgesi kesildiği için değiştirilmedi.';
            }
        }
        header('Location: musteriler.php?sayim=' . $pid . '&ay=' . urlencode($sAy)
             . '&m=' . urlencode((string) $flash));
        exit;
    } elseif (($_POST['action'] ?? '') === 'parasut_cari') {
        // fable-076: oto-bağlama karar veremediğinde (0 ya da >1 aday) seçimi Ömer yapar.
        // Cari BAŞKA müşteriye bağlıysa kabul edilmez — bir cari iki müşteriye bağlanırsa
        // aynı fatura iki kez gelir sayılır.
        $pid = (int) ($_POST['id'] ?? 0);
        $sec = trim((string) ($_POST['pc_id'] ?? ''));
        if ($pid > 0) {
            $eski = $repo->customer($pid);
            $acikPost = ($eski['category'] ?? 'uretim') === 'tasima' ? 'tasima' : 'uretim';
            $sahipli = $repo->parasutSahipliCariler();
            if ($sec !== '' && ($sahipli[$sec] ?? 0) > 0 && ($sahipli[$sec] ?? 0) !== $pid) {
                $baskasi = $repo->customer((int) $sahipli[$sec]);
                $flash = 'Bu Paraşüt carisi zaten ' . ($baskasi['name'] ?? '?') . ' müşterisine bağlı.';
                $flashOk = false;
            } else {
                $pdo->prepare('UPDATE customers SET parasut_id = ? WHERE id = ?')
                    ->execute([$sec !== '' ? $sec : null, $pid]);
                uysa_audit('parasut_cari_sec', $u['username'], (string) $pid,
                    json_encode(['parasut_id' => $sec]), client_ip());
                $flash = $sec !== ''
                    ? 'Paraşüt carisi bağlandı — bundan sonraki senkronda faturaları bu müşteriye düşer.'
                    : 'Paraşüt cari bağı kaldırıldı.';
            }
            $formOpen = true;   // kart açık kalsın (alt firma/sabit kalem akışıyla aynı desen)
            $_GET['edit'] = (string) $pid;
            $editId = $pid;
        }
    } elseif (in_array($_POST['action'] ?? '', ['altfirma', 'altfirma_pasif'], true)) {
        // fable-051: faturası birden çok şirkete kesilen müşterinin (CANTAŞ) alt firmaları.
        // Bölüşüm deseni burada yaşar (hafta içi sabit kota) — koda gömülü değil, Ömer değiştirir.
        $pid = (int) ($_POST['id'] ?? 0);
        if ($pid > 0) {
            try {
                if (($_POST['action'] ?? '') === 'altfirma_pasif') {
                    $repo->setAltFirmaAktif((int) ($_POST['af_id'] ?? 0), false);
                    uysa_audit('altfirma_pasif', $u['username'], (string) $pid,
                        json_encode(['af' => (int) ($_POST['af_id'] ?? 0)]), client_ip());
                    $flash = 'Alt firma pasifleştirildi (kayıt silinmedi).';
                } else {
                    $sabitRaw = trim((string) ($_POST['af_sabit'] ?? ''));
                    $afId = $repo->upsertAltFirma(
                        $pid,
                        (string) ($_POST['af_kod'] ?? ''),
                        (string) ($_POST['af_ad'] ?? ''),
                        (string) ($_POST['af_contact'] ?? ''),
                        !empty($_POST['af_varsayilan']),
                        $sabitRaw === '' ? null : max(0, (int) $sabitRaw),
                        (int) ($_POST['af_sira'] ?? 0),
                        ((int) ($_POST['af_id'] ?? 0)) ?: null
                    );
                    uysa_audit('altfirma_kaydet', $u['username'], (string) $pid,
                        json_encode(['af' => $afId], JSON_UNESCAPED_UNICODE), client_ip());
                    $flash = 'Alt firma kaydedildi.';
                }
            } catch (\Throwable $e) {
                $flash = 'Alt firma kaydedilemedi (kod benzersiz olmalı; migrate_048 uygulandı mı?).';
                $flashOk = false;
            }
            $formOpen = true;
            $_GET['edit'] = (string) $pid;
            $editId = $pid;
        }
    } elseif (in_array($_POST['action'] ?? '', ['sabit_kalem', 'sabit_kalem_pasif'], true)) {
        // fable-065: yemek faturasından AYRI, üretimden BAĞIMSIZ, her ay AYNI tutarlı kalem
        // (BOMİ → PERSONEL HİZMET). Ay kapanınca Fatura Kes ekranında ayrı aday satırı olur.
        $pid = (int) ($_POST['id'] ?? 0);
        if ($pid > 0) {
            try {
                if (($_POST['action'] ?? '') === 'sabit_kalem_pasif') {
                    $repo->setSabitFaturaKalemAktif((int) ($_POST['sk_id'] ?? 0), false);
                    uysa_audit('sabit_kalem_pasif', $u['username'], (string) $pid,
                        json_encode(['sk' => (int) ($_POST['sk_id'] ?? 0)]), client_ip());
                    $flash = 'Sabit kalem pasifleştirildi (kayıt silinmedi).';
                } else {
                    $kdvRaw = trim((string) ($_POST['sk_kdv'] ?? ''));
                    $skId = $repo->upsertSabitFaturaKalem(
                        $pid,
                        (string) ($_POST['sk_ad'] ?? ''),
                        Helpers::parseMoney((string) ($_POST['sk_fiyat'] ?? '0')),
                        // KDV oranı yüzde — parseMoney binlik nokta kuralı burada yanıltır ("20.00"→2000)
                        $kdvRaw === '' ? 20.0 : (float) str_replace(',', '.', $kdvRaw),
                        (string) ($_POST['sk_urun'] ?? ''),
                        (string) ($_POST['sk_contact'] ?? ''),
                        (string) ($_POST['sk_aciklama'] ?? ''),
                        ((int) ($_POST['sk_id'] ?? 0)) ?: null
                    );
                    uysa_audit('sabit_kalem_kaydet', $u['username'], (string) $pid,
                        json_encode(['sk' => $skId], JSON_UNESCAPED_UNICODE), client_ip());
                    $flash = 'Sabit aylık kalem kaydedildi.';
                }
            } catch (\InvalidArgumentException $e) {
                $flash = $e->getMessage();
                $flashOk = false;
            } catch (\Throwable $e) {
                $flash = 'Sabit kalem kaydedilemedi (ad benzersiz olmalı; migrate_052 uygulandı mı?).';
                $flashOk = false;
            }
            $formOpen = true;
            $_GET['edit'] = (string) $pid;
            $editId = $pid;
        }
    } elseif (($_POST['action'] ?? '') === 'aylik_fiyat') {
        // opus-017: bir ayın fiyatını düzenle → o ay production güncellenir (her yere yansır).
        $pid = (int) ($_POST['id'] ?? 0);
        $fiyatAy = (string) ($_POST['fiyat_ay'] ?? $month);
        if (!preg_match('/^\d{4}-\d{2}$/', $fiyatAy)) {
            $fiyatAy = $month;
        }
        $cust = $pid > 0 ? $repo->customer($pid) : null;
        if ($cust) {
            $unitPrice = Helpers::parseMoney((string) ($_POST['ay_unit_price'] ?? '0'));
            $maliyet = null; $gider = null;
            if (($cust['category'] ?? 'uretim') === 'tasima') {
                $maliyet = Helpers::parseMoney((string) ($_POST['ay_maliyet_birim'] ?? '0'));
                $gider = Helpers::parseMoney((string) ($_POST['ay_sabit_gider'] ?? '0'));
            }
            try {
                $repo->setCustomerPrice($pid, $fiyatAy, $unitPrice, $maliyet, $gider);
                uysa_audit('musteri_aylik_fiyat', $u['username'], (string) $pid,
                    json_encode(['ay' => $fiyatAy, 'fiyat' => $unitPrice]), client_ip());
                $flash = ay_label_tr($fiyatAy) . ' fiyatı güncellendi · o ayın cirosu/analizi her yerde yenilendi.';
                $month = $fiyatAy;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flash = 'Aylık fiyat kaydedilemedi.';
                $flashOk = false;
            }
            $formOpen = true;
            $_GET['edit'] = (string) $pid;
            $editId = $pid;
        }
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $category = ($_POST['category'] ?? 'uretim') === 'tasima' ? 'tasima' : 'uretim';
        $unitPrice = Helpers::parseMoney((string) ($_POST['unit_price'] ?? '0'));
        $id = (int) ($_POST['id'] ?? 0) ?: null;
        $postMonth = (string) ($_POST['ay'] ?? $month);
        if (!preg_match('/^\d{4}-\d{2}$/', $postMonth)) {
            $postMonth = $month;
        }
        if ($name === '') {
            $flash = 'Müşteri adı zorunlu.';
            $flashOk = false;
            $formOpen = true;
        } else {
            try {
                // Taşıma kartı = 4 alan: unit_price (satış) + maliyet_birim (alış)
                // + tasima_sabit_gider (opsiyonel) + tasima_not (opsiyonel). adet KARTTA YOK
                // (adet = o ay production.persons toplamı, Bugün sayımlarından).
                $maliyet = null; $gider = null; $tnot = null;
                if ($category === 'tasima') {
                    $maliyet = Helpers::parseMoney((string) ($_POST['maliyet_birim'] ?? '0'));
                    $gider = Helpers::parseMoney((string) ($_POST['sabit_gider'] ?? '0'));
                    $tnot = trim((string) ($_POST['note'] ?? ''));
                }
                $contact = trim((string) ($_POST['contact'] ?? '')) ?: null;
                $phone = trim((string) ($_POST['phone'] ?? '')) ?: null;
                $email = trim((string) ($_POST['email'] ?? '')) ?: null;
                // fable-040: fatura kişisi (hafta içi). Boş = kural yok (null). Taşımada anlamsız → null.
                $fkRaw = trim((string) ($_POST['fatura_kisi_haftaici'] ?? ''));
                $faturaKisi = ($category === 'tasima' || $fkRaw === '') ? null : max(0, (int) $fkRaw);
                $cid = $repo->upsertCustomer($name, $unitPrice, $category, $id, $contact, $phone, null, $maliyet, $gider, $tnot, $email, $faturaKisi);
                // Reaktif ilke: karttaki fiyat da seçili aydan itibaren AY-BAZLI uygulanır.
                // Yoksa ay kaydı olan müşteride carry-forward current default'u her zaman
                // ezdiğinden karttan girilen fiyat hiçbir hesaba yansımaz (ölü alan tuzağı).
                $fiyatNotu = '';
                if ($unitPrice > 0) {
                    $cur = $repo->priceFor($cid, $postMonth);
                    $degisti = abs($cur['unit_price'] - $unitPrice) > 0.009
                        || ($category === 'tasima'
                            && (abs($cur['maliyet_birim'] - (float) $maliyet) > 0.009
                                || abs($cur['tasima_sabit_gider'] - (float) $gider) > 0.009));
                    if ($degisti) {
                        $repo->setCustomerPrice($cid, $postMonth, $unitPrice, $maliyet, $gider);
                        uysa_audit('musteri_aylik_fiyat', $u['username'], (string) $cid,
                            json_encode(['ay' => $postMonth, 'fiyat' => $unitPrice, 'kaynak' => 'kart']), client_ip());
                        $fiyatNotu = ' · ' . ay_label_tr($postMonth) . ' fiyatı güncellendi (bu aydan itibaren geçerli)';
                    }
                }
                uysa_audit('musteri_kaydet', $u['username'], (string) $cid, json_encode(['cat' => $category]), client_ip());
                // fable-076 (Ömer, 14 Ağu): "yeni müşteri eklediğimde otomatik senkron olsun."
                // Cari bağı yoksa Paraşüt carisiyle bağla — bağ kurulmazsa müşterinin faturası
                // "eşleşmemiş gelir" kalır, kâr/zarar karnesinde GÖRÜNMEZ. Tek aday varsa bağlanır;
                // birden çoksa (Paraşüt'te 3 ayrı CANTAŞ carisi var) KÖRÜ KÖRÜNE yazmaz, sorar.
                $cariNotu = '';
                if (!$repo->parasutBagliMi($cid) && \Uysa\Parasut::configured()) {
                    $sonuc = $repo->parasutCariOtoBagla($cid, $name,
                        $repo->parasutCariListesi(static fn(): array => \Uysa\Parasut::contacts()));
                    if ($sonuc['durum'] === 'baglandi') {
                        uysa_audit('parasut_cari_otobagla', $u['username'], (string) $cid,
                            json_encode(['parasut_id' => $sonuc['parasut_id'], 'ad' => $sonuc['ad']],
                                JSON_UNESCAPED_UNICODE), client_ip());
                        $cariNotu = ' · Paraşüt carisi bağlandı: ' . $sonuc['ad'];
                        if ($sonuc['digerleri'] ?? []) {
                            // Kardeş şirket varsa SESSİZ kalma — onların faturası bu müşteriye
                            // sayılmaz, "eşleşmemiş gelir" olur. Alt firma olarak eklenebilir.
                            $cariNotu .= ' (Paraşüt\'te ' . count($sonuc['digerleri'])
                                . ' benzer cari daha var — aynı firmanınsa Alt firmalar\'dan ekle)';
                        }
                    } elseif ($sonuc['durum'] === 'secim') {
                        $cariNotu = ' · Paraşüt\'te ' . count($sonuc['adaylar'])
                            . ' aday cari var — karttan seç (bağlanmadan faturası kâr/zarara girmez)';
                    } else {
                        $cariNotu = ' · Paraşüt carisi bulunamadı — karttan elle seç';
                    }
                }
                $flash = 'Müşteri kaydedildi · ' . $name . $fiyatNotu . $cariNotu;
                $month = $postMonth;
                $acikPost = $category;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flash = 'Kayıt hatası (ad benzersiz olmalı).';
                $flashOk = false;
                $formOpen = true;
            }
        }
    }
}

$uretim = $repo->listCustomersByCategory('uretim');
$tasima = $repo->listCustomersByCategory('tasima');

// fable-046 (Ömer): listeler her zaman açık durmasın — önce ÖZET, tıklayınca liste.
// Özet rakamları mevcut Repo metotlarından; yeni hesap YOK (tek kaynak korunur).
$uretimCiro = 0.0;
$uretimKisi = 0;
$musteriAy = [];   // aksiyon-faz6: müşteri bazlı ay özeti — AYNI döngüden, yeni sorgu yok
foreach ($repo->monthProductionByCustomer($month, 'uretim') as $r) {
    $uretimCiro += (float) $r['ciro'];
    $uretimKisi += (int) $r['persons'];
    $musteriAy[(int) $r['customer_id']] = ['ciro' => (float) $r['ciro'], 'kisi' => (int) $r['persons']];
}
// Taşıma kârı zaten satır satır tasimaProfit ile hesaplanıyordu; ÖNCE topla, listede yeniden çağırma.
$tasimaKar = [];
$tasimaAdet = 0.0;
$tasimaNet = 0.0;
foreach ($tasima as $c) {
    $t = $repo->tasimaProfit((int) $c['id'], $month);
    $tasimaKar[(int) $c['id']] = $t;
    $tasimaAdet += (float) $t['adet'];
    $tasimaNet += (float) $t['net'];
}
// Açık kalacak blok: POST'un dokunduğu kategori > ?ac= (deep-link) > kapalı.
$acikBolum = $acikPost !== '' ? $acikPost : (string) ($_GET['ac'] ?? '');

// Düzenlenen müşteri (form ön-dolum)
$edit = $editId ? $repo->customer($editId) : null;
$fName = $edit['name'] ?? '';
$fCat = $edit['category'] ?? 'uretim';
$fContact = $edit['contact'] ?? '';
$fPhone = $edit['phone'] ?? '';
$fEmail = $edit['email'] ?? '';
// fable-040: hafta içi sabit fatura kişisi (üretim ≠ fatura; boş = üretimle aynı)
$fFaturaKisi = ($edit && $edit['fatura_kisi_haftaici'] !== null) ? (int) $edit['fatura_kisi_haftaici'] : '';
// opus-017: seçilen ayın ay-bazlı fiyatı (o ay > carry-forward > current default).
// Kart da bu değeri gösterir — ekranda görünen fiyat = hesaplarda geçerli fiyat.
$ayFiyat = $edit ? $repo->priceFor($editId, $month) : null;
$fPrice = $ayFiyat ? (float) $ayFiyat['unit_price'] : 0.0;           // satış
$fAlis = $ayFiyat ? (float) $ayFiyat['maliyet_birim'] : 0.0;         // alış
$fGider = $ayFiyat ? (float) $ayFiyat['tasima_sabit_gider'] : 0.0;   // sabit gider
$fNote = $edit['tasima_not'] ?? '';
$fBirimKar = $fPrice - $fAlis;
// Bu ayki adet + net kâr (production'dan, bilgi amaçlı)
$fProfit = ($edit && $edit['category'] === 'tasima') ? $repo->tasimaProfit($editId, $month) : null;

$eyebrow = 'Müşteri yönetimi';
$pageTitle = 'Müşteriler';
$active = 'musteriler';
require __DIR__ . '/partials/header.php';
?>
      <?php if ($flash): ?><div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= Helpers::e($flash) ?></div><?php endif; ?>
      <?php if (($_GET['m'] ?? '') !== ''): ?><div class="flash ok"><?= Helpers::e((string) $_GET['m']) ?></div><?php endif; ?>

<?php
// ══════════════ fable-092: AYLIK SAYIM PANELİ ══════════════
// Ömer (28 Ağu): "müşteri isimlerinin yanına aylık sayı (haftalık ayrımlarla) görebileceğim
// bir ikon ekle; orada sayı değiştirdiğimde kaydettiğimde faturaya da senkronize olsun...
// boş güne yemek sayısı ekleyeceğim."
// Senkron ayrı bir iş DEĞİL: fatura zaten production'dan doğuyor, buraya yazılan sayı
// doğrudan faturanın kaynağı olur. Haftalık ayrım = FATURA dönemleri (1-7/8-14/15-21/22-28/29-son).
$sayimMusteri = $sayimId ? $repo->customer($sayimId) : null;
if ($sayimMusteri):
    $sGrid = $repo->aylikSayimGrid($sayimId, $month);
    $sBirim = (float) $repo->priceFor($sayimId, $month)['unit_price'];
    // Öğün sütunları: müşteri o ay hangi öğünleri kullanıyorsa o sütun görünür (sade kalsın).
    $ogunVar = ['ogle' => true, 'aksam' => false, 'kumanya' => false];
    foreach ($sGrid as $g) {
        if ($g['aksam'] > 0) { $ogunVar['aksam'] = true; }
        if ($g['kumanya'] > 0) { $ogunVar['kumanya'] = true; }
    }
    $sonrakiAy = date('Y-m', strtotime($month . '-01 +1 month'));
    $oncekiAy = date('Y-m', strtotime($month . '-01 -1 month'));
    // Ömer (28 Ağu): "üretimi yazma, CANTAŞ özetinde karışıyor — fatura tutarları olsa iyi."
    // Özetler yalnız FATURA kişisi + tutar gösterir; üretim sayısı gün satırındaki öğün
    // kutularında zaten duruyor (orası düzenlenebilir alan, özet değil).
    $ayFatura = 0;
    foreach ($sGrid as $g) { $ayFatura += $g['fatura_kisi']; }
    // fable-093 (Ömer): "CANTAŞ özelinde sayıları FATURA BAZLI göreyim; 3 firmanın ayrı toplamı
    // özette yazsın." Marmara da 2 firmalı — kırılım alt firması olan HER müşteride görünür.
    $sFirmalar = $repo->altFirmalar($sayimId);
    $sKirilim = $sFirmalar ? $repo->altFirmaGunGun($sayimId, $month . '-01', date('Y-m-t', strtotime($month . '-01'))) : [];
    $sFirmaToplam = [];
    foreach ($sFirmalar as $f) { $sFirmaToplam[$f['kod']] = 0; }
    foreach ($sKirilim as $pay) {
        foreach ($pay as $kod => $adet) { $sFirmaToplam[$kod] = ($sFirmaToplam[$kod] ?? 0) + (int) $adet; }
    }
?>
      <div class="cardx card-pad">
        <div class="head-row">
          <h2><?= Helpers::e((string) $sayimMusteri['name']) ?> — aylık sayım</h2>
          <a class="btn-action btn-ghost" href="musteriler.php">Müşterilere dön</a>
        </div>
        <div class="ftr-kisayol" style="margin-bottom:10px">
          <a class="chip" href="musteriler.php?sayim=<?= $sayimId ?>&ay=<?= $oncekiAy ?>">‹ <?= Helpers::e(ay_label_tr($oncekiAy)) ?></a>
          <span class="chip active"><?= Helpers::e(ay_label_tr($month)) ?></span>
          <a class="chip" href="musteriler.php?sayim=<?= $sayimId ?>&ay=<?= $sonrakiAy ?>"><?= Helpers::e(ay_label_tr($sonrakiAy)) ?> ›</a>
        </div>
        <p class="row-meta" style="margin:0 0 10px">
          Kişi başı <strong>₺<?= Helpers::money($sBirim) ?></strong> ·
          Ay toplamı <strong><?= $ayFatura ?></strong> kişi ·
          <strong>₺<?= Helpers::money($ayFatura * $sBirim) ?></strong>
          <br>Boş güne sayı girip kaydedebilirsin — <strong>fatura bu sayılardan kesilir</strong>.
          İrsaliyesi/faturası kesilmiş günler kilitlidir (belge ile kayıt ayrışmasın).
        </p>

        <?php if ($sFirmalar): ?>
          <?php // fable-093: FATURA BAZLI özet — bu müşteriye ayın sonunda kaç ayrı e-Fatura
                // kesileceği ve her birinin kişi/tutarı. Kırılım desenden hesaplanır (hafta içi
                // sabit kota + kalan varsayılana), faturanın kullandığı kaynağın ta kendisidir. ?>
          <div style="border:1px solid var(--line);border-radius:10px;padding:10px;margin-bottom:12px">
            <div class="head-row" style="margin-bottom:6px">
              <strong>Fatura bazlı aylık toplam</strong>
              <span class="row-meta"><?= count($sFirmalar) ?> ayrı e-Fatura kesilecek · <?= Helpers::e(ay_label_tr($month)) ?></span>
            </div>
            <table class="tbl">
              <thead><tr><th>Firma</th><th class="num">Kişi</th><th class="num">Tutar</th></tr></thead>
              <tbody>
              <?php $fTopKisi = 0; $fTopTutar = 0.0; foreach ($sFirmalar as $f):
                  $fk = (int) ($sFirmaToplam[$f['kod']] ?? 0);
                  $ft = $fk * $sBirim;
                  $fTopKisi += $fk; $fTopTutar += $ft;
              ?>
                <tr>
                  <td><?= Helpers::e((string) $f['ad']) ?><?= $f['varsayilan'] ? ' <span class="row-meta">(kalan buraya)</span>' : '' ?></td>
                  <td class="num"><strong><?= $fk ?></strong></td>
                  <td class="num">₺<?= Helpers::money($ft) ?></td>
                </tr>
              <?php endforeach; ?>
                <tr style="border-top:2px solid var(--line)">
                  <td><strong>TOPLAM</strong></td>
                  <td class="num"><strong><?= $fTopKisi ?></strong></td>
                  <td class="num"><strong>₺<?= Helpers::money($fTopTutar) ?></strong></td>
                </tr>
              </tbody>
            </table>
            <?php if ($fTopKisi !== $ayFatura): ?>
              <p class="row-meta" style="margin:6px 0 0;color:var(--danger)">
                ⚠ Kırılım toplamı (<?= $fTopKisi ?>) ay fatura kişisinden (<?= $ayFatura ?>) farklı — kontrol edin.
              </p>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <p style="margin:0 0 12px">
          <a class="btn-action btn-ghost" href="musteriler.php?sayim=<?= $sayimId ?>&ay=<?= Helpers::e($month) ?>&xlsx=1">
            <i class="bi bi-file-earmark-excel"></i> Excel indir
          </a>
        </p>

        <form method="post">
          <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
          <input type="hidden" name="action" value="sayim_kaydet">
          <input type="hidden" name="id" value="<?= $sayimId ?>">
          <input type="hidden" name="ay" value="<?= Helpers::e($month) ?>">
          <?php for ($dn = 1; $dn <= 5; $dn++):
              $grup = array_values(array_filter($sGrid, static fn(array $x): bool => $x['donem'] === $dn));
              if (!$grup) { continue; }
              $gF = 0;
              foreach ($grup as $g) { $gF += $g['fatura_kisi']; }
          ?>
          <div style="border:1px solid var(--line);border-radius:10px;padding:10px;margin-bottom:12px">
            <div class="head-row" style="margin-bottom:6px">
              <strong><?= Helpers::e(Repo::donemEtiketi($dn, $month)) ?> <?= Helpers::e(ay_label_tr($month)) ?></strong>
              <span class="row-meta"><strong><?= $gF ?></strong> kişi · <strong>₺<?= Helpers::money($gF * $sBirim) ?></strong></span>
            </div>
            <div style="overflow-x:auto">
            <table class="tbl">
              <thead><tr>
                <th>Gün</th>
                <?php // fable-093b (Ömer): "ekranda hâlâ 50-70 yazıyor; istediğim HC şu, İç-Dış şu,
                      // Bakır şu FATURA SAYILARI." Bölüşümlü müşteride asıl rakam firma kırılımıdır;
                      // öğün kutuları veri GİRİŞİ olduğu için duruyor ama geri plana alındı. ?>
                <?php foreach ($sFirmalar as $f):
                    // Başlıkta müşteri adını tekrarlama — tabloyu taşırıyor ve zaten o müşterinin
                    // panelindeyiz: "CANTAŞ İç-Dış" → "İç-Dış". Tam ad title'da duruyor.
                    $fKisa = trim((string) preg_replace('/^' . preg_quote((string) $sayimMusteri['name'], '/') . '\s*/iu', '', (string) $f['ad']));
                    if ($fKisa === '') { $fKisa = (string) $f['ad']; }
                ?>
                  <th class="num" style="white-space:nowrap" title="<?= Helpers::e((string) $f['ad']) ?> — desenden hesaplanır, fatura bu kırılımdan kesilir"><?= Helpers::e($fKisa) ?></th>
                <?php endforeach; ?>
                <?php if ($sFirmalar): ?>
                  <th class="num">Fatura sayısı</th>
                <?php else: ?>
                  <th class="num">Öğle</th>
                  <?php if ($ogunVar['aksam']): ?><th class="num">Akşam</th><?php endif; ?>
                  <?php if ($ogunVar['kumanya']): ?><th class="num">Kumanya</th><?php endif; ?>
                  <th class="num">Fatura kişisi</th>
                <?php endif; ?>
                <th>Durum</th>
              </tr></thead>
              <tbody>
              <?php foreach ($grup as $g):
                  $kilitli = $g['kilit'] !== '';
                  $ro = $kilitli ? ' readonly tabindex="-1"' : '';
                  $st = 'width:70px;font-size:16px;text-align:right' . ($kilitli ? ';opacity:.55' : '');
              ?>
                <tr<?= $g['haftasonu'] ? ' style="background:var(--bg-soft)"' : '' ?>>
                  <td><?= sprintf('%02d', $g['gun_no']) ?> <span class="row-meta"><?= $g['gun_adi'] ?></span></td>
                  <?php $kirToplam = 0; foreach ($sFirmalar as $f):
                      $fk = (int) ($sKirilim[$g['gun']][$f['kod']] ?? 0);
                      $kirToplam += $fk;
                  ?>
                    <td class="num" style="font-size:15px<?= $fk > 0 ? ';font-weight:700' : ';color:var(--muted)' ?>"><?= $fk > 0 ? $fk : '—' ?></td>
                  <?php endforeach; ?>
                  <?php if ($sFirmalar): ?>
                    <?php // Ömer (28 Ağu): "üretim toplamı 50'yi istemiyorum, FATURA istiyorum —
                          // Temmuz Excel'imdeki desene bak." O tabloda üretim sütunu yok; her
                          // firmanın fatura sayısı var. Bu yüzden bölüşümlü müşteride TEK giriş
                          // vardır ve o FATURA sayısıdır; firma kırılımı ondan doğar.
                          // Üretim (persons) kaydı korunur — kişi başı gıda maliyeti ona bölünüyor. ?>
                    <td class="num"><input type="number" min="0" name="fatura[<?= $g['gun'] ?>]" value="<?= $g['fatura_kisi'] ?: '' ?>"
                        title="Faturalanacak kişi — firma kırılımı bundan hesaplanır"
                        style="width:80px;font-size:16px;text-align:right<?= $kilitli ? ';opacity:.55' : '' ?>"<?= $ro ?>></td>
                  <?php else: ?>
                    <td class="num"><input type="number" min="0" name="ogle[<?= $g['gun'] ?>]" value="<?= $g['ogle'] ?: '' ?>" style="<?= $st ?>"<?= $ro ?>></td>
                    <?php if ($ogunVar['aksam']): ?>
                      <td class="num"><input type="number" min="0" name="aksam[<?= $g['gun'] ?>]" value="<?= $g['aksam'] ?: '' ?>" style="<?= $st ?>"<?= $ro ?>></td>
                    <?php endif; ?>
                    <?php if ($ogunVar['kumanya']): ?>
                      <td class="num"><input type="number" min="0" name="kumanya[<?= $g['gun'] ?>]" value="<?= $g['kumanya'] ?: '' ?>" style="<?= $st ?>"<?= $ro ?>></td>
                    <?php endif; ?>
                    <td class="num"><input type="number" min="0" name="fatura[<?= $g['gun'] ?>]" value="<?= $g['fatura_kisi'] ?: '' ?>"
                        placeholder="=üretim" title="Boş bırakılırsa üretim toplamıyla aynı"
                        style="width:80px;font-size:16px;text-align:right<?= $kilitli ? ';opacity:.55' : '' ?><?= $g['fatura_kisi'] !== $g['uretim'] ? ';font-weight:700' : '' ?>"<?= $ro ?>></td>
                  <?php endif; ?>
                  <td class="row-meta">
                    <?php if ($kilitli): ?>
                      🔒 <?= Helpers::e($g['kilit']) ?><?= $g['irsaliye_no'] !== '' ? ' · ' . Helpers::e($g['irsaliye_no']) : '' ?>
                    <?php elseif ($g['uretim'] === 0): ?>
                      <span style="color:var(--muted)">boş</span>
                    <?php else: ?>
                      açık
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            </div>
          </div>
          <?php endfor; ?>
          <button type="submit" class="btn btn-primary">Kaydet — fatura bu sayılardan kesilir</button>
        </form>
      </div>
<?php require __DIR__ . '/partials/footer.php'; ?>
<?php exit; ?>
<?php endif; ?>


      <?php
      // aksiyon-faz6: KARAR RAKAMI + AKILLI DURUM. Fiyatı girilmemiş müşteri sessizce
      // "₺0,00 kişi başı" yazıyordu; o müşterinin cirosu 0 hesaplanır, faturası boş çıkar.
      // Artık ekranın en üstünde sayısıyla söyleniyor ve satırında da işaretli.
      $fiyatsizlar = [];
      foreach (array_merge($uretim, $tasima) as $c) {
          if ((float) $repo->priceFor((int) $c['id'], $month)['unit_price'] <= 0) {
              $fiyatsizlar[] = $c;
          }
      }
      $aktifSayi = count($uretim) + count($tasima);
      ?>
      <?php if (!$formOpen && !$edit): ?>
      <div class="cardx card-pad gt-nabiz-sm">
        <div class="gt-pulse">
          <div class="gt-pulse-n"><?= $aktifSayi ?></div>
          <?php // Taşıma tarafında "ciro" değil KÂR tutuluyor (tasimaProfit); ikisini toplamak
                // farklı iki şeyi toplamak olurdu. Etiket ne gösterdiğini açıkça yazar. ?>
          <div class="gt-pulse-l">aktif müşteri · <?= Helpers::e(ay_label_tr($month)) ?> üretim cirosu ₺<?= number_format(round($uretimCiro), 0, ',', '.') ?></div>
        </div>
      </div>
      <?php if ($fiyatsizlar): ?>
      <div class="cardx card-pad akilli-durum">
        <div class="ad-metin">
          <b><?= count($fiyatsizlar) ?> müşterinin <?= Helpers::e(ay_label_tr($month)) ?> fiyatı girilmemiş</b>
          <span class="ad-not">fiyatsız müşteride ciro 0 hesaplanır, fatura boş çıkar</span>
        </div>
        <a class="btn-action btn-primaryx" href="musteriler.php?edit=<?= (int) $fiyatsizlar[0]['id'] ?>&ay=<?= Helpers::e($month) ?>">Fiyat gir</a>
      </div>
      <?php endif; ?>
      <?php endif; ?>

      <?php if (!$formOpen): ?>
        <a class="btn-action btn-primaryx btn-full" href="musteriler.php?yeni=1"><i class="bi bi-person-plus"></i> Müşteri ekle</a>
      <?php endif; ?>

      <?php // aksiyon-faz6: müşteri detayında KARAR RAKAMI (bu ayki tutar) ve tek akıllı uyarı.
            // Paraşüt carisi bağlı değilse fatura o müşterinin karnesine düşmez — sebebi burada
            // yazılı (kokpit-parasut-cari-bagi: bağ YALNIZ contact_id ile kurulur, ad eşleşmesi yok). ?>
      <?php if ($edit): $mAy = $musteriAy[(int) $edit['id']] ?? null; ?>
      <div class="cardx card-pad gt-nabiz-sm">
        <div class="gt-pulse">
          <div class="gt-pulse-n">₺<?= number_format(round($mAy['ciro'] ?? 0), 0, ',', '.') ?></div>
          <div class="gt-pulse-l"><?= Helpers::e(ay_label_tr($month)) ?> · <?= number_format($mAy['kisi'] ?? 0, 0, ',', '.') ?> kişi</div>
        </div>
      </div>
      <?php if (($edit['parasut_id'] ?? '') === '' || $edit['parasut_id'] === null): ?>
      <div class="cardx card-pad akilli-durum">
        <div class="ad-metin">
          <b>Paraşüt carisi bağlı değil</b>
          <span class="ad-not">bu müşterinin faturası kâr karnesine düşmez</span>
        </div>
        <a class="btn-action btn-secondaryx" href="parasut.php">Cariyi bağla</a>
      </div>
      <?php endif; ?>
      <?php endif; ?>

      <div class="fab-sheet" id="musteri-form" style="<?= $formOpen ? '' : 'display:none' ?>">
        <div class="gt-h"><i class="bi bi-person-plus"></i> <?= $edit ? 'MÜŞTERİ DÜZENLE' : 'YENİ MÜŞTERİ' ?></div>
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
          <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
          <input type="hidden" name="ay" value="<?= Helpers::e($month) ?>">

          <div class="field"><label>Müşteri adı</label>
            <input class="inputx" name="name" value="<?= Helpers::e($fName) ?>" required autocapitalize="words">
          </div>

          <div class="field"><label>Yetkili kişi</label>
            <input class="inputx" name="contact" value="<?= Helpers::e($fContact) ?>" autocapitalize="words" placeholder="ör. Ahmet Yılmaz">
          </div>
          <div class="field"><label>Telefon</label>
            <input class="inputx" type="tel" name="phone" value="<?= Helpers::e($fPhone) ?>" inputmode="tel" placeholder="05xx xxx xx xx">
          </div>
          <div class="field"><label>E-posta</label>
            <input class="inputx" type="email" name="email" value="<?= Helpers::e($fEmail) ?>" inputmode="email" autocapitalize="none" placeholder="ornek@firma.com">
          </div>

          <div class="field"><label>Kategori</label>
            <div class="segmented">
              <button class="chip <?= $fCat === 'uretim' ? 'active' : '' ?>" type="button" data-cat="uretim" onclick="setCat(this,'uretim')">Üretim</button>
              <button class="chip <?= $fCat === 'tasima' ? 'active' : '' ?>" type="button" data-cat="tasima" onclick="setCat(this,'tasima')">Taşıma</button>
            </div>
            <input type="hidden" name="category" id="cat-input" value="<?= Helpers::e($fCat) ?>">
          </div>

          <div class="field"><label id="lbl-price"><span id="lbl-price-txt"><?= $fCat === 'tasima' ? 'Birim fiyat — SATIŞ (₺ / adet)' : 'Birim fiyat (₺ / kişi)' ?></span></label>
            <input class="inputx" name="unit_price" id="f-satis" inputmode="decimal" value="<?= $fPrice > 0 ? Helpers::money($fPrice) : '' ?>" placeholder="0,00" oninput="calcKar()">
            <p class="text-muted" style="font-size:11px;margin:4px 0 0"><strong><?= Helpers::e(ay_label_tr($month)) ?></strong> fiyatı — değiştirirsen bu aydan itibaren geçerli olur; geçmiş ayları aşağıdaki <em>Aylık fiyat</em> bölümünden düzelt.</p>
          </div>

          <!-- fable-040: fatura kişisi (hafta içi sabit) — üretim müşterisi; boş = üretimle aynı (kural yok) -->
          <div class="field" id="uretim-fatura-field" style="<?= $fCat === 'tasima' ? 'display:none' : '' ?>">
            <label>Fatura kişisi — hafta içi (opsiyonel)</label>
            <input class="inputx" name="fatura_kisi_haftaici" id="f-fatura-kisi" type="number" min="0" inputmode="numeric" value="<?= $fFaturaKisi === '' ? '' : (int) $fFaturaKisi ?>" placeholder="boş = üretimle aynı">
            <p class="text-muted" style="font-size:11px;margin:4px 0 0">Üretim sayısı ≠ fatura ise (ör. <strong>CANTAŞ 50 üretim · 70 fatura</strong>): hafta içi ciro/fatura bu kişiden hesaplanır; üretim maliyeti gerçek sayıdan kalır. Cumartesi/pazar uygulanmaz.</p>
          </div>

          <div id="tasima-fields" style="display:<?= $fCat === 'tasima' ? 'grid' : 'none' ?>; gap:11px;">
            <div class="text-muted" style="font-size:12px;font-weight:600">Taşıma kartı · aylık kâr = aydaki satış adedi × birim kâr − sabit gider</div>
            <div class="field"><label>Maliyet birim fiyat (₺ — alış / tedarik)</label>
              <input class="inputx" name="maliyet_birim" id="f-alis" inputmode="decimal" value="<?= $fAlis > 0 ? Helpers::money($fAlis) : '' ?>" placeholder="0,00" oninput="calcKar()">
            </div>
            <div class="field"><label>Aylık sabit gider (₺ — opsiyonel)</label>
              <input class="inputx" name="sabit_gider" id="f-gider" inputmode="decimal" value="<?= $fGider > 0 ? Helpers::money($fGider) : '' ?>" placeholder="0,00">
            </div>
            <div class="field"><label>Not (opsiyonel)</label>
              <input class="inputx" name="note" value="<?= Helpers::e($fNote) ?>" placeholder="ör. 2 araç, şoför dahil">
            </div>
            <div class="summary-grid">
              <div class="summary-card tint-blue"><p class="label">Birim satış</p><p class="metric small" id="satis-live">₺ <?= Helpers::money($fPrice) ?></p></div>
              <div class="summary-card tint-blue"><p class="label">Birim alış</p><p class="metric small" id="alis-live">₺ <?= Helpers::money($fAlis) ?></p></div>
              <div class="summary-card tint-orange"><p class="label">Birim kâr (satış − alış)</p><p class="metric small" id="birimkar-live">₺ <?= Helpers::money($fBirimKar) ?></p></div>
              <?php if ($fProfit): ?>
              <div class="summary-card tint-green"><p class="label"><?= Helpers::e(ay_label_tr($month)) ?> · <?= number_format((float) $fProfit['adet'], 0, ',', '.') ?> adet → net kâr</p><p class="metric small">₺ <?= Helpers::money((float) $fProfit['net']) ?></p></div>
              <?php endif; ?>
            </div>
            <p class="text-muted" style="font-size:11px">Adet buraya girilmez — <strong>Bugün</strong> ekranındaki günlük sayımlardan (o ayın toplamı) otomatik gelir.</p>
          </div>

          <div class="actions-row">
            <a class="btn-action btn-ghost flex-fill" href="musteriler.php">Vazgeç</a>
            <button class="btn-action btn-primaryx flex-fill" type="submit"><i class="bi bi-check2"></i> Kaydet</button>
          </div>
        </form>
      </div>

      <?php if ($edit): ?>
      <!-- opus-017: AYLIK FİYAT — o ayın fiyatını gör/düzenle; kaydedince o ay her yerde güncellenir -->
      <div class="cardx card-pad" id="aylik-fiyat">
        <div class="gt-h"><i class="bi bi-tag"></i> AYLIK FİYAT</div>
        <p class="text-muted" style="font-size:12px">
          Fiyatlar aya göre değişir (zam). Bir ayın fiyatını değiştirince <strong>o ayın</strong>
          cirosu, kâr analizi ve carisi her yerde güncellenir; diğer aylar sabit kalır.
        </p>
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
          <input type="hidden" name="action" value="aylik_fiyat">
          <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
          <div class="field"><label>Ay</label>
            <input class="inputx" type="month" name="fiyat_ay" value="<?= Helpers::e($month) ?>"
                   onchange="window.location='musteriler.php?edit=<?= (int) $edit['id'] ?>&ay='+this.value">
          </div>
          <div class="field">
            <label><?= $fCat === 'tasima' ? 'Birim fiyat — SATIŞ (₺ / adet)' : 'Birim fiyat (₺ / kişi)' ?></label>
            <input class="inputx" name="ay_unit_price" inputmode="decimal"
                   value="<?= Helpers::money((float) $ayFiyat['unit_price']) ?>" placeholder="0,00">
          </div>
          <?php if ($fCat === 'tasima'): ?>
          <div class="field"><label>Maliyet birim (₺ — alış)</label>
            <input class="inputx" name="ay_maliyet_birim" inputmode="decimal"
                   value="<?= Helpers::money((float) $ayFiyat['maliyet_birim']) ?>" placeholder="0,00">
          </div>
          <div class="field"><label>Aylık sabit gider (₺ — opsiyonel)</label>
            <input class="inputx" name="ay_sabit_gider" inputmode="decimal"
                   value="<?= Helpers::money((float) $ayFiyat['tasima_sabit_gider']) ?>" placeholder="0,00">
          </div>
          <?php endif; ?>
          <div class="hint-card" style="margin:0">
            Bu fiyat değişince <strong><?= Helpers::e(ay_label_tr($month)) ?></strong> her yerde güncellenir.
          </div>
          <div class="actions-row">
            <button class="btn-action btn-primaryx flex-fill" type="submit"><i class="bi bi-check2"></i> <?= Helpers::e(ay_label_tr($month)) ?> fiyatını kaydet</button>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <?php if ($edit):
        // fable-076 (Ömer, 14 Ağu): "yeni müşteri eklediğimde otomatik senkron olsun."
        // Satış faturası müşteriye YALNIZ Paraşüt cari id'siyle bağlanır. Bağ yoksa fatura
        // "eşleşmemiş gelir" olur → müşteri kâr/zarar karnesinde çıkmaz. Burası o bağın
        // GÖRÜNÜR halidir: bağlıysa hangi cari, değilse uyarı + seçim.
        $pcId = (int) $edit['id'];
        $pcBagli = $repo->parasutBagliMi($pcId);
        $pcMevcut = trim((string) ($edit['parasut_id'] ?? ''));
        $pcListe = \Uysa\Parasut::configured()
            ? $repo->parasutCariListesi(static fn(): array => \Uysa\Parasut::contacts(),
                ($_GET['cari_tazele'] ?? '') === '1')
            : [];
        $pcSahipli = $repo->parasutSahipliCariler();
        $pcAdaylar = $pcBagli ? [] : $repo->parasutCariAdaylari((string) $edit['name'], $pcListe, $pcSahipli, $pcId);
        $pcAdayId = array_column($pcAdaylar, 'parasut_id');
        $pcAltAdet = 0;
        foreach ($pcSahipli as $k => $v) { if ($v === $pcId && $k !== $pcMevcut) { $pcAltAdet++; } }
      ?>
      <div class="cardx card-pad" id="parasut-cari">
        <div class="gt-h"><i class="bi bi-link-45deg"></i> PARAŞÜT CARİSİ</div>
        <?php if (!$pcListe): ?>
          <div class="empty-state">Paraşüt cari listesi okunamadı — bağ değiştirilemez (mevcut bağ korunuyor).</div>
        <?php else: ?>
          <?php if ($pcBagli): ?>
            <p class="row-meta" style="margin-bottom:8px"><i class="bi bi-check-circle" style="color:var(--green)"></i>
              Bağlı — bu müşterinin faturaları kâr/zarara <strong>düşüyor</strong>.
              <?php if ($pcAltAdet > 0): ?>(<?= $pcAltAdet ?> cari alt firmalardan geliyor)<?php endif; ?></p>
          <?php else: ?>
            <p class="row-meta" style="margin-bottom:8px"><i class="bi bi-exclamation-triangle" style="color:var(--red)"></i>
              <strong>Bağlı değil</strong> — bu müşteriye kesilen faturalar kâr/zararda
              "eşleşmemiş gelir" olarak kalır, karnede görünmez.
              <?= $pcAdaylar ? 'Aşağıdaki ' . count($pcAdaylar) . ' aday işaretlendi; doğru olanı seç.' : '' ?></p>
          <?php endif; ?>
          <form method="post" class="af-row">
            <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
            <input type="hidden" name="action" value="parasut_cari">
            <input type="hidden" name="id" value="<?= $pcId ?>">
            <label class="field"><span>Cari</span>
              <select class="inputx" name="pc_id">
                <option value="">— bağlı değil —</option>
                <?php foreach ($pcListe as $c):
                  $cid2 = (string) $c['parasut_id'];
                  $sahip = $pcSahipli[$cid2] ?? 0;
                  $baskasi = $sahip > 0 && $sahip !== $pcId;
                  $etiket = $c['name'] . ($c['tax_number'] ? ' · ' . $c['tax_number'] : '');
                  if (in_array($cid2, $pcAdayId, true)) { $etiket = '★ ' . $etiket; }
                  if ($baskasi) { $etiket .= '  (başka müşteride)'; }
                ?>
                  <option value="<?= Helpers::e($cid2) ?>"<?= $cid2 === $pcMevcut ? ' selected' : '' ?><?= $baskasi ? ' disabled' : '' ?>><?= Helpers::e($etiket) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <div class="row-actions" style="margin-top:8px">
              <button class="btn-action btn-primaryx" type="submit"><i class="bi bi-link"></i> Cariyi bağla</button>
              <a class="btn-action btn-ghost" href="musteriler.php?edit=<?= $pcId ?>&ay=<?= Helpers::e($month) ?>&cari_tazele=1#parasut-cari"><i class="bi bi-arrow-clockwise"></i> Listeyi tazele</a>
            </div>
          </form>
          <p class="text-muted" style="font-size:12px;margin-top:6px">
            Faturası <strong>birden çok şirkete</strong> kesiliyorsa (ör. CANTAŞ) ek carileri
            <a href="#alt-firmalar">Alt firmalar</a> bölümünden ekle — oradaki cariler de bu müşteriye sayılır.</p>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($edit && $fCat !== 'tasima'):
        // fable-051: ALT FİRMALAR — faturası birden çok şirkete kesilen müşteri (CANTAŞ).
        // Ay sonu bölüşümü bu desenden gün gün hesaplanır; Fatura Kes penceresine DOLU gelir.
        $altF = $repo->altFirmalar($editId, false);
        $ozet = $altF ? $repo->aylikAltFirmaOzet($editId, $month) : [];
      ?>
      <div class="cardx card-pad" id="alt-firmalar">
        <div class="gt-h"><i class="bi bi-diagram-3"></i> ALT FİRMALAR (fatura bölüşümü)</div>
        <p class="text-muted" style="font-size:12px">
          Faturası <strong>birden çok şirkete</strong> kesilen müşteri için. Ay sonu bölüşümü şu desenden
          <strong>gün gün</strong> hesaplanır: hafta içi sabit kotalar sırayla dağıtılır, <strong>kalan varsayılan
          firmaya</strong>; cumartesi/pazar <strong>tamamı varsayılan firmaya</strong>. Hesap fatura kişisinden yapılır.
          Kod, müşterinin fatura bölüşümü ayarındaki anahtarla aynı olmalı (ör. <code>fatura_cantas_hc</code>).
        </p>
        <?php if (!$altF): ?>
          <div class="empty-state">Alt firma tanımlı değil — bölüşüm eskisi gibi son ayın oranlarından gelir.</div>
        <?php else: foreach ($altF as $af): $o = $ozet[$af['kod']] ?? null; ?>
          <form method="post" class="af-row<?= $af['aktif'] ? '' : ' af-pasif' ?>">
            <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
            <input type="hidden" name="action" value="altfirma">
            <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
            <input type="hidden" name="af_id" value="<?= (int) $af['id'] ?>">
            <div class="af-grid">
              <label class="field"><span>Ad</span><input class="inputx" name="af_ad" value="<?= Helpers::e($af['ad']) ?>" required></label>
              <label class="field"><span>Kod</span><input class="inputx" name="af_kod" value="<?= Helpers::e($af['kod']) ?>" autocapitalize="none" required></label>
              <label class="field"><span>Paraşüt cari id</span><input class="inputx" name="af_contact" value="<?= Helpers::e((string) $af['contact_id']) ?>" inputmode="numeric" placeholder="boş = ayardan"></label>
              <label class="field"><span>Hafta içi sabit</span><input class="inputx" name="af_sabit" type="number" min="0" inputmode="numeric" value="<?= $af['haftaici_sabit'] === null ? '' : (int) $af['haftaici_sabit'] ?>" placeholder="boş = kalanı alır"></label>
              <label class="field"><span>Sıra</span><input class="inputx" name="af_sira" type="number" min="0" inputmode="numeric" value="<?= (int) $af['sira'] ?>"></label>
            </div>
            <label class="af-check"><input type="checkbox" name="af_varsayilan" value="1"<?= $af['varsayilan'] ? ' checked' : '' ?>>
              <span>Varsayılan (kalan + cumartesi/pazar bu firmaya)</span></label>
            <p class="row-meta">
              <?php if ($o !== null): ?><?= Helpers::e(ay_label_tr($month)) ?>: <strong><?= (int) $o['kisi'] ?></strong> kişi · ₺<?= Helpers::money((float) $o['tutar']) ?><?php endif; ?>
              <?= $af['aktif'] ? '' : ' · PASİF' ?>
            </p>
            <div class="actions-row">
              <button class="btn-action btn-primaryx" type="submit"><i class="bi bi-check2"></i> Kaydet</button>
              <?php if ($af['aktif']): ?>
              <button class="btn-action btn-ghost" type="submit" name="action" value="altfirma_pasif"
                      formnovalidate onclick="return confirm('Bu alt firma pasifleştirilsin mi? (kayıt silinmez)');">
                <i class="bi bi-archive"></i> Pasifleştir</button>
              <?php endif; ?>
            </div>
          </form>
        <?php endforeach; endif; ?>

        <form method="post" class="af-row af-yeni">
          <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
          <input type="hidden" name="action" value="altfirma">
          <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
          <div class="gt-h" style="margin:0;font-size:12px">YENİ ALT FİRMA</div>
          <div class="af-grid">
            <label class="field"><span>Ad</span><input class="inputx" name="af_ad" placeholder="ör. HC Isıtma" required></label>
            <label class="field"><span>Kod</span><input class="inputx" name="af_kod" placeholder="ör. fatura_cantas_hc" autocapitalize="none" required></label>
            <label class="field"><span>Paraşüt cari id</span><input class="inputx" name="af_contact" inputmode="numeric" placeholder="boş = ayardan"></label>
            <label class="field"><span>Hafta içi sabit</span><input class="inputx" name="af_sabit" type="number" min="0" inputmode="numeric" placeholder="boş = kalanı alır"></label>
            <label class="field"><span>Sıra</span><input class="inputx" name="af_sira" type="number" min="0" value="<?= count($altF) + 1 ?>"></label>
          </div>
          <label class="af-check"><input type="checkbox" name="af_varsayilan" value="1"><span>Varsayılan (kalan + cumartesi/pazar bu firmaya)</span></label>
          <div class="actions-row">
            <button class="btn-action btn-primaryx" type="submit"><i class="bi bi-plus-lg"></i> Alt firma ekle</button>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <?php if ($edit && $fCat !== 'tasima'):
        // fable-065: SABİT AYLIK KALEMLER — yemek faturasından AYRI, üretimden BAĞIMSIZ,
        // her ay AYNI tutarda kesilen kalem (BOMİ → PERSONEL HİZMET; kira/hizmet bedeli de olabilir).
        $sabitK = $repo->sabitFaturaKalemleri($editId, false);
      ?>
      <div class="cardx card-pad" id="sabit-kalemler">
        <div class="gt-h"><i class="bi bi-receipt-cutoff"></i> SABİT AYLIK KALEMLER</div>
        <p class="text-muted" style="font-size:12px">
          Yemek faturasından <strong>ayrı</strong>, üretimden <strong>bağımsız</strong>, her ay
          <strong>aynı tutarda</strong> kesilen kalem (ör. personel hizmeti, kira). Ay kapanınca
          <strong>Fatura Kes</strong> ekranında <em>SABİT</em> rozetli ayrı satır olarak çıkar;
          aynı ay iki kez kesilemez. KDV kalemin kendi oranından (hizmet <strong>%20</strong> —
          yemek %10 değil). Paraşüt cari boş bırakılırsa müşterinin kendi carisi kullanılır.
        </p>
        <?php if (!$sabitK): ?>
          <div class="empty-state">Sabit kalem tanımlı değil — bu müşteride hiçbir davranış değişmez.</div>
        <?php else: foreach ($sabitK as $sk):
            $skHesap = Repo::sabitFaturaHesap($sk['birim_fiyat'], $sk['kdv_orani']);
            $skKesim = $repo->sabitFaturaKesim($sk['id'], $month);
        ?>
          <form method="post" class="af-row<?= $sk['aktif'] ? '' : ' af-pasif' ?>">
            <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
            <input type="hidden" name="action" value="sabit_kalem">
            <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
            <input type="hidden" name="sk_id" value="<?= (int) $sk['id'] ?>">
            <div class="af-grid">
              <label class="field"><span>Kalem adı</span><input class="inputx" name="sk_ad" value="<?= Helpers::e($sk['ad']) ?>" required></label>
              <label class="field"><span>Birim fiyat (₺ · KDV hariç)</span><input class="inputx" name="sk_fiyat" inputmode="decimal" value="<?= Helpers::money($sk['birim_fiyat']) ?>" required></label>
              <label class="field"><span>KDV %</span><input class="inputx" name="sk_kdv" type="number" min="0" max="100" step="0.01" value="<?= rtrim(rtrim(number_format($sk['kdv_orani'], 2, '.', ''), '0'), '.') ?>"></label>
              <label class="field"><span>Paraşüt ürün id</span><input class="inputx" name="sk_urun" value="<?= Helpers::e($sk['parasut_product_id']) ?>" inputmode="numeric" placeholder="ör. 1066391424"></label>
              <label class="field"><span>Paraşüt cari id</span><input class="inputx" name="sk_contact" value="<?= Helpers::e($sk['parasut_contact_id']) ?>" inputmode="numeric" placeholder="boş = müşterinin carisi"></label>
              <label class="field"><span>Açıklama</span><input class="inputx" name="sk_aciklama" value="<?= Helpers::e($sk['aciklama']) ?>" placeholder="opsiyonel"></label>
            </div>
            <p class="row-meta">
              1 adet × ₺<?= Helpers::money($sk['birim_fiyat']) ?>
              + KDV %<?= rtrim(rtrim(number_format($sk['kdv_orani'], 2), '0'), '.') ?>
              = <strong>₺<?= Helpers::money($skHesap['net']) ?></strong>
              <?php if ($skKesim !== null): ?>
                · <?= Helpers::e(ay_label_tr($month)) ?>: <strong>kesildi<?= $skKesim['fatura_no'] !== '' ? ' (' . Helpers::e($skKesim['fatura_no']) . ')' : '' ?></strong>
              <?php else: ?>
                · <?= Helpers::e(ay_label_tr($month)) ?>: henüz kesilmedi
              <?php endif; ?>
              <?= $sk['aktif'] ? '' : ' · PASİF' ?>
            </p>
            <div class="actions-row">
              <button class="btn-action btn-primaryx" type="submit"><i class="bi bi-check2"></i> Kaydet</button>
              <?php if ($sk['aktif']): ?>
              <button class="btn-action btn-ghost" type="submit" name="action" value="sabit_kalem_pasif"
                      formnovalidate onclick="return confirm('Bu sabit kalem pasifleştirilsin mi? (kayıt silinmez)');">
                <i class="bi bi-archive"></i> Pasifleştir</button>
              <?php endif; ?>
            </div>
          </form>
        <?php endforeach; endif; ?>

        <form method="post" class="af-row af-yeni">
          <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
          <input type="hidden" name="action" value="sabit_kalem">
          <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
          <div class="gt-h" style="margin:0;font-size:12px">YENİ SABİT KALEM</div>
          <div class="af-grid">
            <label class="field"><span>Kalem adı</span><input class="inputx" name="sk_ad" placeholder="ör. Personel hizmeti" required></label>
            <label class="field"><span>Birim fiyat (₺ · KDV hariç)</span><input class="inputx" name="sk_fiyat" inputmode="decimal" placeholder="0,00" required></label>
            <label class="field"><span>KDV %</span><input class="inputx" name="sk_kdv" type="number" min="0" max="100" step="0.01" value="20"></label>
            <label class="field"><span>Paraşüt ürün id</span><input class="inputx" name="sk_urun" inputmode="numeric" placeholder="ör. 1066391424"></label>
            <label class="field"><span>Paraşüt cari id</span><input class="inputx" name="sk_contact" inputmode="numeric" placeholder="boş = müşterinin carisi"></label>
            <label class="field"><span>Açıklama</span><input class="inputx" name="sk_aciklama" placeholder="opsiyonel"></label>
          </div>
          <div class="actions-row">
            <button class="btn-action btn-primaryx" type="submit"><i class="bi bi-plus-lg"></i> Sabit kalem ekle</button>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <!-- ÜRETİM müşterileri — fable-046: önce özet, tıklayınca liste (native details/summary) -->
      <div class="cardx card-pad">
        <details class="gt-katman"<?= $acikBolum === 'uretim' ? ' open' : '' ?>>
          <summary>
            <div class="gt-katman-top">
              <div class="gt-h" style="margin:0"><i class="bi bi-people-fill"></i> ÜRETİM MÜŞTERİLERİ</div>
              <span class="gt-katman-chev"><span class="ac">listeyi aç</span><span class="kap">kapat</span><i class="bi bi-chevron-down"></i></span>
            </div>
            <div class="gt-mini">
              <div><div class="gt-mn"><?= count($uretim) ?></div><div class="gt-ml">Firma</div></div>
              <div><div class="gt-mn"><?= number_format($uretimKisi, 0, ',', '.') ?></div><div class="gt-ml"><?= Helpers::e(ay_label_tr($month)) ?> kişi</div></div>
              <div><div class="gt-mn">₺<?= number_format(round($uretimCiro), 0, ',', '.') ?></div><div class="gt-ml"><?= Helpers::e(ay_label_tr($month)) ?> ciro</div></div>
            </div>
          </summary>
          <div class="gt-katman-liste">
        <?php if (!$uretim): ?>
          <div class="empty-state">Üretim müşterisi yok.</div>
        <?php else: foreach ($uretim as $c): ?>
          <div class="customer-row">
            <div>
              <div class="row-title"><span class="status-dot"></span><strong><?= Helpers::e($c['name']) ?></strong>
                <?php if (($c['parasut_bakiye'] ?? null) !== null): ?><span class="badge-soft <?= (float) $c['parasut_bakiye'] < 0 ? 'badge-neg' : 'badge-ok' ?>" title="Paraşüt muhasebe bakiyesi">Paraşüt ₺ <?= Helpers::money((float) $c['parasut_bakiye']) ?></span><?php endif; ?>
              </div>
              <?php // aksiyon-faz6: fiyatsız müşteri "₺0,00" diye sessizce geçmesin — satırda
                    // sebebi ve tek dokunuşluk çıkış yolu yazılı. ?>
              <?php $satirFiyat = (float) $repo->priceFor((int) $c['id'], $month)['unit_price']; ?>
              <p class="row-meta"><?php if ($satirFiyat > 0): ?>₺ <?= Helpers::money($satirFiyat) ?> kişi başı · <?= Helpers::e(ay_label_tr($month)) ?><?php else: ?><span class="fiyat-yok">fiyat girilmemiş</span> · <a href="musteriler.php?edit=<?= (int) $c['id'] ?>&ay=<?= Helpers::e($month) ?>" class="fiyat-gir">Fiyat gir</a><?php endif; ?></p>
            </div>
            <div class="actions-row" style="justify-content:flex-end">
              <a class="icon-btn" href="musteriler.php?sayim=<?= (int) $c['id'] ?>&ay=<?= Helpers::e($month) ?>" aria-label="Aylık sayım" title="Aylık sayı (haftalık ayrımlı)"><i class="bi bi-calendar3"></i></a>
              <a class="icon-btn" href="musteriler.php?edit=<?= (int) $c['id'] ?>" aria-label="Düzenle"><i class="bi bi-pencil"></i></a>
              <form method="post" onsubmit="return confirm('Bu müşteri pasifleştirilsin mi?');" style="display:inline">
                <input type="hidden" name="csrf" value="<?= Helpers::e(Helpers::csrfToken()) ?>">
                <input type="hidden" name="action" value="pasif">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <button class="icon-btn" type="submit" aria-label="Pasifleştir"><i class="bi bi-archive"></i></button>
              </form>
            </div>
          </div>
        <?php endforeach; endif; ?>
          </div>
        </details>
      </div>

      <!-- TAŞIMA müşterileri — fable-046: önce özet, tıklayınca liste -->
      <div class="cardx card-pad">
        <details class="gt-katman"<?= $acikBolum === 'tasima' ? ' open' : '' ?>>
          <summary>
            <div class="gt-katman-top">
              <div class="gt-h" style="margin:0"><i class="bi bi-truck"></i> TAŞIMA MÜŞTERİLERİ</div>
              <span class="gt-katman-chev"><span class="ac">listeyi aç</span><span class="kap">kapat</span><i class="bi bi-chevron-down"></i></span>
            </div>
            <div class="gt-mini">
              <div><div class="gt-mn"><?= count($tasima) ?></div><div class="gt-ml">Firma</div></div>
              <div><div class="gt-mn"><?= number_format($tasimaAdet, 0, ',', '.') ?></div><div class="gt-ml"><?= Helpers::e(ay_label_tr($month)) ?> adet</div></div>
              <div><div class="gt-mn <?= $tasimaNet < 0 ? 'bad' : 'ok' ?>">₺<?= number_format(round($tasimaNet), 0, ',', '.') ?></div><div class="gt-ml"><?= Helpers::e(ay_label_tr($month)) ?> kâr</div></div>
            </div>
          </summary>
          <div class="gt-katman-liste">
        <?php if (!$tasima): ?>
          <div class="empty-state">Taşıma müşterisi yok.</div>
        <?php else: foreach ($tasima as $c):
            $t = $tasimaKar[(int) $c['id']];
            $adet = (float) $t['adet'];
            $kar = (float) $t['net']; ?>
          <div class="customer-row">
            <div>
              <div class="row-title"><span class="status-dot"></span><strong><?= Helpers::e($c['name']) ?></strong>
                <?php if ($adet > 0): ?><span class="badge-soft <?= $kar >= 0 ? 'badge-ok' : 'badge-neg' ?>">₺ <?= Helpers::money($kar) ?> kâr</span><?php endif; ?>
              </div>
              <p class="row-meta">
                Satış ₺ <?= Helpers::money((float) $t['satis']) ?> · Alış ₺ <?= Helpers::money((float) $t['alis']) ?> / adet
                <?php if ($adet > 0): ?>· <?= number_format($adet, 0, ',', '.') ?> adet (bu ay)<?php else: ?>· bu ay sayım yok<?php endif; ?>
              </p>
            </div>
            <div class="actions-row" style="justify-content:flex-end">
              <a class="icon-btn" href="rapor.php?musteri=<?= (int) $c['id'] ?>&ay=<?= $month ?>" aria-label="Rapor"><i class="bi bi-graph-up-arrow"></i></a>
              <a class="icon-btn" href="musteriler.php?sayim=<?= (int) $c['id'] ?>&ay=<?= Helpers::e($month) ?>" aria-label="Aylık sayım" title="Aylık sayı (haftalık ayrımlı)"><i class="bi bi-calendar3"></i></a>
              <a class="icon-btn" href="musteriler.php?edit=<?= (int) $c['id'] ?>&ay=<?= $month ?>" aria-label="Düzenle"><i class="bi bi-pencil"></i></a>
            </div>
          </div>
        <?php endforeach; endif; ?>
          </div>
        </details>
      </div>

      <script>
        function setCat(btn, cat){
          document.getElementById('cat-input').value = cat;
          btn.parentNode.querySelectorAll('.chip').forEach(function(c){c.classList.remove('active');});
          btn.classList.add('active');
          document.getElementById('tasima-fields').style.display = (cat === 'tasima') ? 'grid' : 'none';
          var uf = document.getElementById('uretim-fatura-field'); // fable-040: fatura kişisi yalnız üretim
          if (uf) uf.style.display = (cat === 'tasima') ? 'none' : '';
          document.getElementById('lbl-price-txt').textContent =
            (cat === 'tasima') ? 'Birim fiyat — SATIŞ (₺ / adet)' : 'Birim fiyat (₺ / kişi)';
          calcKar();
        }
        function parseTL(v){ return parseFloat(String(v).replace(/\./g,'').replace(',', '.')) || 0; }
        function tl(n){ return '₺ ' + n.toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}); }
        function calcKar(){
          var satis = parseTL(document.getElementById('f-satis').value);
          var alis = parseTL(document.getElementById('f-alis').value);
          document.getElementById('satis-live').textContent = tl(satis);
          document.getElementById('alis-live').textContent = tl(alis);
          document.getElementById('birimkar-live').textContent = tl(satis - alis);
        }
      </script>
<?php require __DIR__ . '/partials/footer.php'; ?>
