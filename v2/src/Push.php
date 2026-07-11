<?php
declare(strict_types=1);

namespace Uysa;

use PDO;

/**
 * Push orkestratörü — TEK KAPI (opus-021). Tüm push gönderimleri buradan geçer:
 * token seçimi + APNs gönderim + ölü token silme + push_log kaydı + sessiz saat.
 *
 * - Apns constructor'dan enjekte edilebilir (testte stub — gerçek APNs'e istek atılmaz).
 * - APNs yapılandırılmamışsa sessiz no-op: akış KIRILMAZ, yine de push_log'a yazılır.
 * - Sessiz saat (ayar: push_quiet_start/push_quiet_end, default 21:00–07:00): OLAY
 *   bildirimleri gönderilmez, log'a suppressed=1 yazılır. kind='manuel' (bildirim.php) MUAF.
 * - $data['url'] payload köküne girer (deep-link, opus-022 tüketir).
 */
class Push
{
    public const KINDS = ['menu', 'talep_cevap', 'talep_yeni', 'siparis', 'reminder', 'manuel'];
    public const DEAD_REASONS = ['BadDeviceToken', 'Unregistered', 'ExpiredToken'];

    private PDO $pdo;
    private Apns $apns;
    private int $now; // test için enjekte edilebilir zaman (sessiz saat + reminder günü)

    public function __construct(PDO $pdo, ?Apns $apns = null, ?int $now = null)
    {
        $this->pdo = $pdo;
        $this->apns = $apns ?? new Apns();
        $this->now = $now ?? time();
    }

    /** Manuel gönderim UI'ı için: APNs kullanılabilir mi? */
    public function configured(): bool
    {
        return $this->apns->configured();
    }

    /**
     * Bir müşterinin TÜM cihazlarına gönder. Ölü token'ı siler, sonucu push_log'a yazar.
     * @return array{sent:int,dead:int,failed:int,devices:int,suppressed:bool}
     */
    public function toCustomer(int $customerId, string $title, string $body, array $data = [], string $kind = 'manuel', ?string $ref = null): array
    {
        $st = $this->pdo->prepare('SELECT token FROM push_tokens WHERE customer_id = ?');
        $st->execute([$customerId]);
        $tokens = $st->fetchAll(PDO::FETCH_COLUMN);
        $badge = (new Repo($this->pdo))->badgeCountFor($customerId);
        if (in_array($kind, ['menu', 'talep_cevap'], true)) {
            // Bu olay henüz push_log'a yazılmadı; gönderilen badge'e mevcut olayı da kat.
            $badge = min(99, $badge + 1);
        }
        return $this->dispatch($tokens, $title, $body, $data, $kind, $customerId, null, $ref, $badge);
    }

    /**
     * Admin (users) cihazlarına gönder — yeni talep / yarının siparişi olayları.
     * @return array{sent:int,dead:int,failed:int,devices:int,suppressed:bool}
     */
    public function toAdmins(string $title, string $body, array $data = [], string $kind = 'manuel', ?string $ref = null): array
    {
        $tokens = $this->pdo->query('SELECT token FROM push_tokens WHERE user_id IS NOT NULL')
            ->fetchAll(PDO::FETCH_COLUMN);
        return $this->dispatch($tokens, $title, $body, $data, $kind, null, null, $ref);
    }

    /**
     * Tüm müşteri cihazlarına gönder (bildirim.php "Tüm müşteriler").
     * @return array{sent:int,dead:int,failed:int,devices:int,suppressed:bool}
     */
    public function toAll(string $title, string $body, array $data = [], string $kind = 'manuel'): array
    {
        $tokens = $this->pdo->query('SELECT token FROM push_tokens WHERE customer_id IS NOT NULL')
            ->fetchAll(PDO::FETCH_COLUMN);
        return $this->dispatch($tokens, $title, $body, $data, $kind, null, null, null);
    }

    /**
     * Olay: menü yayınlandı → hedef müşterilere "Menünüz yayınlandı".
     * MÜKERRER KORUMASI: push_log'da aynı menü+müşteri (ref='menu:ID') kaydı varsa atlanır
     * (tekrar yayınla → aynı müşteriye ikinci push YOK). Cihazı olmayan müşteri loglanmaz
     * (app kurunca sonraki yayında alabilsin).
     * @param array<int,int> $customerIds hedef müşteri id'leri
     * @return array{pushed:int,skipped:int,no_device:int}
     */
    public function menuYayinlandi(int $menuId, string $menuTitle, array $customerIds): array
    {
        $ref = 'menu:' . $menuId;
        $pushed = 0;
        $skipped = 0;
        $noDevice = 0;
        $seen = $this->pdo->prepare("SELECT COUNT(*) FROM push_log WHERE kind = 'menu' AND ref = ? AND customer_id = ?");
        $hasDev = $this->pdo->prepare('SELECT COUNT(*) FROM push_tokens WHERE customer_id = ?');
        foreach (array_unique(array_map('intval', $customerIds)) as $cid) {
            if ($cid <= 0) {
                continue;
            }
            $seen->execute([$ref, $cid]);
            if ((int) $seen->fetchColumn() > 0) {
                $skipped++;
                continue;
            }
            $hasDev->execute([$cid]);
            if ((int) $hasDev->fetchColumn() === 0) {
                $noDevice++;
                continue;
            }
            $this->toCustomer($cid, 'Menünüz yayınlandı', $menuTitle, ['url' => '/m/menu.php'], 'menu', $ref);
            $pushed++;
        }
        return ['pushed' => $pushed, 'skipped' => $skipped, 'no_device' => $noDevice];
    }

    /**
     * Olay: 14:30 sayı hatırlatması (tools/push_reminder.php cron'u çağırır). İDEMPOTENT:
     * - Sadece cihazı olan müşteriler değerlendirilir.
     * - Yarına sipariş VEYA üretim kaydı olan müşteri atlanır (sayısını girmiş).
     * - Aynı gün push_log'da reminder kaydı olan müşteriye TEKRAR atılmaz (günde 1).
     * @return array{pushed:int,skipped_entered:int,skipped_dup:int}
     */
    public function gunlukHatirlatma(): array
    {
        $today = date('Y-m-d', $this->now);
        $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
        $cutoff = Helpers::orderCutoffLabel();

        $cids = $this->pdo->query(
            'SELECT DISTINCT customer_id FROM push_tokens WHERE customer_id IS NOT NULL'
        )->fetchAll(PDO::FETCH_COLUMN);

        $entered = $this->pdo->prepare(
            "SELECT (SELECT COUNT(*) FROM orders WHERE customer_id = ? AND order_date = ? AND status <> 'reddedildi')
                  + (SELECT COUNT(*) FROM production WHERE customer_id = ? AND prod_date = ?)"
        );
        $dup = $this->pdo->prepare(
            "SELECT COUNT(*) FROM push_log WHERE kind = 'reminder' AND customer_id = ? AND DATE(created_at) = ?"
        );

        $pushed = 0;
        $skippedEntered = 0;
        $skippedDup = 0;
        foreach ($cids as $cid) {
            $cid = (int) $cid;
            $entered->execute([$cid, $tomorrow, $cid, $tomorrow]);
            if ((int) $entered->fetchColumn() > 0) {
                $skippedEntered++;
                continue;
            }
            $dup->execute([$cid, $today]);
            if ((int) $dup->fetchColumn() > 0) {
                $skippedDup++;
                continue;
            }
            $this->toCustomer(
                $cid,
                'Hatırlatma',
                "Yarının kişi sayısını girmeyi unutmayın ({$cutoff}'da kilitlenir)",
                ['url' => '/m/siparis.php'],
                'reminder'
            );
            $pushed++;
        }
        return ['pushed' => $pushed, 'skipped_entered' => $skippedEntered, 'skipped_dup' => $skippedDup];
    }

    /** Sessiz saatte miyiz? ayar: push_quiet_start/push_quiet_end (HH:MM, default 21:00–07:00). */
    public function inQuietHours(): bool
    {
        $start = $this->ayar('push_quiet_start', '21:00');
        $end = $this->ayar('push_quiet_end', '07:00');
        if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || $start === $end) {
            return false; // bozuk/eşit ayar → sessiz saat kapalı say
        }
        $t = date('H:i', $this->now);
        return $start > $end
            ? ($t >= $start || $t < $end)   // gece yarısını sarar: 21:00–07:00
            : ($t >= $start && $t < $end);  // aynı gün aralığı
    }

    /** Ortak gönderim: sessiz saat → suppressed log; APNs yoksa no-op log; yoksa gönder+temizle+log. */
    private function dispatch(array $tokens, string $title, string $body, array $data, string $kind, ?int $customerId, ?int $userId, ?string $ref, ?int $badge = 1): array
    {
        $res = ['sent' => 0, 'dead' => 0, 'failed' => 0, 'devices' => count($tokens), 'suppressed' => false];

        // Sessiz saat: OLAY bildirimleri bastırılır (manuel = bildirim.php muaf).
        if ($kind !== 'manuel' && $this->inQuietHours()) {
            $res['suppressed'] = true;
            $this->log($kind, $customerId, $userId, $ref, $title, $body, 0, 0, true);
            return $res;
        }

        if ($this->apns->configured()) {
            $del = $this->pdo->prepare('DELETE FROM push_tokens WHERE token = ?');
            foreach ($tokens as $t) {
                $r = $this->apns->send((string) $t, $title, $body, $data, $badge);
                if ($r['ok']) {
                    $res['sent']++;
                } elseif (in_array($r['reason'], self::DEAD_REASONS, true) || $r['status'] === 410) {
                    $del->execute([$t]); // ölü token: bir daha denenmesin
                    $res['dead']++;
                } else {
                    $res['failed']++;
                }
            }
        }
        // APNs yapılandırılmamışsa sessiz no-op (sent=0) — akış kırılmaz, yine de loglanır.
        $this->log($kind, $customerId, $userId, $ref, $title, $body, $res['sent'], $res['dead'], false);
        return $res;
    }

    private function log(string $kind, ?int $customerId, ?int $userId, ?string $ref, string $title, string $body, int $sent, int $dead, bool $suppressed): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            $kind = 'manuel';
        }
        // created_at PHP saatiyle yazılır (SQLite CURRENT_TIMESTAMP UTC — günlük dedupe local gün ister)
        $this->pdo->prepare(
            'INSERT INTO push_log (kind, customer_id, user_id, ref, title, body, sent, dead, suppressed, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$kind, $customerId, $userId, $ref, mb_substr($title, 0, 120), mb_substr($body, 0, 500), $sent, $dead, $suppressed ? 1 : 0, date('Y-m-d H:i:s', $this->now)]);
    }

    private function ayar(string $anahtar, string $default): string
    {
        try {
            $st = $this->pdo->prepare('SELECT deger FROM ayar WHERE anahtar = ?');
            $st->execute([$anahtar]);
            $v = $st->fetchColumn();
            return $v === false ? $default : (string) $v;
        } catch (\Throwable) {
            return $default;
        }
    }
}
