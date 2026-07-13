<?php
declare(strict_types=1);

namespace Uysa;

final class Helpers
{
    /** Fuzzy müşteri eşleşmesi minimum güven eşiği. Altı → eşleşmedi. */
    public const MATCH_MIN_SCORE = 0.72;

    /** Çift-kodlanmış UTF-8 (CP1252 üzerinden) mojibake onarımı. Onarılamazsa aynen döner. */
    public static function unmojibake(string $s): string
    {
        if ($s === '' || !preg_match('/[\x{80}-\x{FF}]/u', $s)) {
            // Tipik mojibake işaretleri yoksa (Ã, Ä, Å, â) dokunma
        }
        // Sadece klasik mojibake belirtileri varsa dene
        if (!preg_match('/Ã|Ä|Å|â‚|â€| Å|Ã¼/u', $s)) {
            return $s;
        }
        $r = @iconv('UTF-8', 'CP1252//IGNORE', $s);
        if ($r !== false && $r !== '' && mb_check_encoding($r, 'UTF-8')) {
            return $r;
        }
        return $s;
    }

    /** ₺ formatı: 1234.5 -> "1.234,50" */
    public static function money(float $n): string
    {
        return number_format($n, 2, ',', '.');
    }

    /**
     * Türkçe para girdisini float'a çevir: "180.000,00" -> 180000.0, "1.500,50" -> 1500.5,
     * "250" -> 250.0. Binlik '.' atılır, ondalık ',' noktaya çevrilir (₺/boşluk temizlenir).
     */
    public static function parseMoney(string $s): float
    {
        $s = str_replace(['₺', ' ', "\u{00A0}"], '', trim($s));
        $s = str_replace('.', '', $s);   // binlik ayıracı
        $s = str_replace(',', '.', $s);  // ondalık ayıracı
        return (float) $s;
    }

    /** Türkçe-duyarlı normalize: küçült, aksan/İ-ı düzelt, sadece a-z0-9 bırak. Fuzzy eşleşme için. */
    public static function normalizeName(string $s): string
    {
        $s = self::unmojibake($s);
        $map = [
            'ç' => 'c', 'Ç' => 'c', 'ğ' => 'g', 'Ğ' => 'g', 'ı' => 'i', 'İ' => 'i',
            'ö' => 'o', 'Ö' => 'o', 'ş' => 's', 'Ş' => 's', 'ü' => 'u', 'Ü' => 'u',
        ];
        $s = strtr($s, $map);
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^a-z0-9]+/u', '', $s) ?? '';
        return $s;
    }

    /** Levenshtein (ASCII-normalize sonrası). */
    private static function lev(string $a, string $b): int
    {
        return levenshtein($a, $b);
    }

    /**
     * Fuzzy müşteri adı eşleşme. $candidates: [id => name].
     * Dönüş: ['id'=>int|null, 'name'=>string|null, 'score'=>float, 'reason'=>string].
     * Strateji: normalize eşitlik > prefix/substring > levenshtein oranı (>=0.72).
     */
    public static function matchCustomer(string $query, array $candidates): array
    {
        $q = self::normalizeName($query);
        if ($q === '') {
            return ['id' => null, 'name' => null, 'score' => 0.0, 'reason' => 'bos_sorgu'];
        }
        $best = ['id' => null, 'name' => null, 'score' => 0.0, 'reason' => 'eslesme_yok'];
        foreach ($candidates as $id => $name) {
            $n = self::normalizeName((string) $name);
            if ($n === '') {
                continue;
            }
            $score = 0.0;
            $reason = '';
            if ($n === $q) {
                $score = 1.0;
                $reason = 'tam';
            } elseif (str_starts_with($n, $q) || str_starts_with($q, $n)) {
                // Önek: en az 3 karakterlik anlamlı önek gerekir ("e"/"op" belirsiz → atla).
                // Gerçek önek eşik üstünde kabul edilir (kısa marka adı "talay" gibi).
                $shorter = min(strlen($n), strlen($q));
                $longer = max(strlen($n), strlen($q));
                if ($shorter >= 3) {
                    $score = max(self::MATCH_MIN_SCORE + 0.02, 0.90 * ($shorter / $longer));
                    $reason = 'onek';
                }
            } elseif (str_contains($n, $q) || str_contains($q, $n)) {
                if (min(strlen($n), strlen($q)) >= 4) {
                    $score = 0.80;
                    $reason = 'icinde';
                }
            } else {
                // Levenshtein oranı — skoru her zaman ata; eşik kontrolü sondaki kapıda.
                $dist = self::lev($q, $n);
                $maxLen = max(strlen($q), strlen($n));
                $score = $maxLen > 0 ? 1 - ($dist / $maxLen) : 0.0;
                $reason = 'levenshtein';
            }
            if ($score > $best['score']) {
                $best = ['id' => (int) $id, 'name' => $name, 'score' => $score, 'reason' => $reason];
            }
        }
        // Minimum skor kapısı: eşik altı → eşleşme YOK (yanlış müşteriye yazmayı önle).
        // En yakın aday tanı için 'aday'/'score'te korunur ama id=null döner.
        if ($best['id'] !== null && $best['score'] < self::MATCH_MIN_SCORE) {
            return [
                'id' => null, 'name' => null, 'score' => $best['score'],
                'reason' => 'dusuk_skor', 'aday' => $best['name'],
            ];
        }
        return $best;
    }

    public static function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    public static function csrfCheck(?string $token): bool
    {
        return session_status() === PHP_SESSION_ACTIVE
            && !empty($_SESSION['csrf'])
            && is_string($token)
            && hash_equals($_SESSION['csrf'], $token);
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function json(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** ISO tarih doğrula (YYYY-MM-DD). */
    public static function isDate(string $d): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    }

    public static function today(): string
    {
        return date('Y-m-d');
    }

    /** Sipariş değişiklik son saati (order_date'ten bir gün önce). fable-001: 16:00 (Ömer istedi). */
    public const ORDER_CUTOFF_HOUR = 16;
    public const ORDER_CUTOFF_MIN = 0;

    /** İnsan-okur son saat etiketi, ör. "15:30". */
    public static function orderCutoffLabel(): string
    {
        return sprintf('%02d:%02d', self::ORDER_CUTOFF_HOUR, self::ORDER_CUTOFF_MIN);
    }

    /**
     * Sipariş son değişiklik zamanı: order_date'ten bir gün önce saat 15:30 (müşteri bazlı ayar
     * ileride). Bu andan sonra müşteri o günün siparişini değiştiremez (üretim planı kilitlenir).
     */
    public static function orderDeadline(string $date): int
    {
        $prev = date('Y-m-d', strtotime($date . ' -1 day'));
        return (int) strtotime($prev . ' ' . sprintf('%02d:%02d:00', self::ORDER_CUTOFF_HOUR, self::ORDER_CUTOFF_MIN));
    }

    /** Müşteri bu tarihi hâlâ değiştirebilir mi? (bir gün önce 15:30 geçmedi). $now test için. */
    public static function orderEditable(string $date, ?int $now = null): bool
    {
        return ($now ?? time()) <= self::orderDeadline($date);
    }
}
