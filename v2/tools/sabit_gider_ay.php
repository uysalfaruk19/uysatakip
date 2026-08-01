<?php

declare(strict_types=1);

/**
 * fable-069 — SABİT AYLIK GİDER (Ömer: "50 binde sabit olarak yakıt ve sarf maliyeti ekle,
 * genel olarak"). Ayın son gününe tek kayıt açar; kâr/zararda genel havuza girer (ciro
 * oranında tüm müşterilere dağılır). Faturası/tedarikçisi yoktur → borç listesinde ÇIKMAZ.
 *
 * İDEMPOTENT: aynı ay ikinci kez çalışsa da tek kayıt kalır.
 * Kullanım: php sabit_gider_ay.php [YYYY-MM]   (boşsa içinde bulunulan ay)
 * Tutar `ayar.sabit_gider_yakit_sarf`'tan gelir — koda gömülü DEĞİL.
 */
require '/var/www/html/src/bootstrap.php';

use Uysa\Db;
use Uysa\Repo;

$pdo = Db::pdo();
$repo = new Repo($pdo);

$ay = $argv[1] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
    fwrite(STDERR, "ay=YYYY-MM bekleniyor\n");
    exit(1);
}
$tutar = (float) str_replace(',', '.', (string) $repo->ayar('sabit_gider_yakit_sarf', '0'));
if ($tutar <= 0) {
    echo "ayar.sabit_gider_yakit_sarf tanımlı değil ya da 0 — kayıt açılmadı.\n";
    exit(0);
}

$gun = date('Y-m-t', strtotime($ay . '-01'));
$aciklama = 'Yakıt ve sarf malzemesi — sabit aylık gider (' . $ay . ')';

$st = $pdo->prepare("SELECT id, amount FROM transactions
                     WHERE type='gider' AND category='Yakıt & Sarf' AND tx_date = ?");
$st->execute([$gun]);
$var = $st->fetch();

if ($var) {
    if (abs((float) $var['amount'] - $tutar) < 0.01) {
        printf("%s: zaten var (₺%s) — dokunulmadı.\n", $ay, number_format($tutar, 2, ',', '.'));
        exit(0);
    }
    $pdo->prepare('UPDATE transactions SET amount = ?, description = ? WHERE id = ?')
        ->execute([$tutar, $aciklama, (int) $var['id']]);
    uysa_audit('sabit_gider', 'fable', $ay, json_encode(['eski' => (float) $var['amount'], 'yeni' => $tutar]), 'local');
    printf("%s: tutar güncellendi → ₺%s\n", $ay, number_format($tutar, 2, ',', '.'));
    exit(0);
}

$pdo->prepare(
    "INSERT INTO transactions (type, tx_date, category, description, amount, source, alloc_type, created_at)
     VALUES ('gider', ?, 'Yakıt & Sarf', ?, ?, 'sabit', 'genel', NOW())"
)->execute([$gun, $aciklama, $tutar]);
uysa_audit('sabit_gider', 'fable', $ay, json_encode(['tutar' => $tutar], JSON_UNESCAPED_UNICODE), 'local');
printf("%s: sabit gider eklendi → ₺%s (%s)\n", $ay, number_format($tutar, 2, ',', '.'), $gun);
