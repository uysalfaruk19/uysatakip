<?php

declare(strict_types=1);

/**
 * fable-052 — BELGE MAİLİ KUYRUĞU İŞLEYİCİSİ (cron).
 *
 * Paraşüt'ün paylaşım ucu müşteriye ZIP yolluyor (kanıtlı, seçenek yok). Kokpit bunun yerine
 * belgenin PDF'ini Paraşüt'ten indirip UYSA'nın kendi SMTP'sinden TEK PDF olarak gönderir.
 * PDF belge resmileşmeden hazır OLMAYABİLİR → gönderim kuyruğa alınır, bu script tekrar dener.
 *
 * Ömer'in şartı: "sistem senden bağımsız çalışacak" — hiçbir adımda insan müdahalesi YOK.
 *
 * Kullanım:  php tools/mail_kuyruk.php [limit=20]
 * Cron:      *_/5 * * * * docker exec uysatakip-v2 php /var/www/html/tools/mail_kuyruk.php
 * Kill-switch: ayar `paylasim_yontemi` = 'parasut' (kuyruk dolmaz) veya cron satırını sil.
 *
 * Çıkış kodu: 0 = normal (gönderilecek iş olmasa da), 2 = yapılandırma/DB hatası.
 * ⚠️ Kalıcı hataya düşen satır (deneme ≥ 8) 'hata' olur ve BİR DAHA denenmez — ekranda
 *    (Belge maili ayarları) görünür; sessiz kalmaz.
 */

require_once __DIR__ . '/../src/bootstrap.php';

use Uysa\Db;
use Uysa\Mail;
use Uysa\ParasutPdf;
use Uysa\Repo;

$limit = isset($argv[1]) ? max(1, min(200, (int) $argv[1])) : 20;
$damga = date('Y-m-d H:i');

try {
    $repo = new Repo(Db::pdo());
} catch (\Throwable $e) {
    fwrite(STDERR, "[$damga] DB bağlantısı yok: " . $e->getMessage() . "\n");
    exit(2);
}

try {
    $ozet = $repo->mailKuyrukOzet();
} catch (\Throwable $e) {
    // mail_kuyruk tablosu yoksa (migration uygulanmadı) — sessizce ölme, SEBEBİ söyle.
    fwrite(STDERR, "[$damga] mail_kuyruk okunamadı (migrate_049.sql uygulandı mı?): " . $e->getMessage() . "\n");
    exit(2);
}

if ($ozet['bekliyor'] === 0) {
    echo "[$damga] Kuyrukta bekleyen yok (gönderildi: {$ozet['gonderildi']}, hata: {$ozet['hata']}).\n";
    exit(0);
}

if (!ParasutPdf::yapilandirilmis()) {
    fwrite(STDERR, "[$damga] Paraşüt kredensiyali çözülemedi — {$ozet['bekliyor']} satır BEKLİYOR (deneme artmadı).\n");
    exit(2);
}
if (!Mail::yapilandirilmis()) {
    fwrite(STDERR, "[$damga] SMTP yapılandırılmamış (SMTP_HOST/SMTP_USER/SMTP_PASS) — "
        . "{$ozet['bekliyor']} satır BEKLİYOR (deneme artmadı).\n");
    exit(2);
}

$r = $repo->mailKuyrukIsle($limit);
if (($r['atlandi'] ?? '') !== '') {
    fwrite(STDERR, "[$damga] Atlandı: {$r['atlandi']}\n");
    exit(2);
}

printf(
    "[%s] işlenen %d · gönderildi %d · tekrar denenecek %d · kalıcı hata %d\n",
    $damga,
    $r['islenen'],
    $r['gonderildi'],
    $r['bekleyen'],
    $r['hata']
);

// Kalıcı hataya düşen varsa log'da GÖRÜNSÜN (sessiz başarısızlık yok).
if ($r['hata'] > 0) {
    foreach ($repo->mailKuyrukSon(50) as $s) {
        if ((string) $s['durum'] === 'hata') {
            printf(
                "  ! %s #%s (%s) → %s | %s\n",
                (string) $s['tur'],
                (string) $s['kaynak_id'],
                (string) ($s['musteri'] ?? '?'),
                (string) $s['alici'],
                (string) ($s['son_hata'] ?? '')
            );
        }
    }
}

exit(0);
