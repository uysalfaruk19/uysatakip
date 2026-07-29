<?php
declare(strict_types=1);

namespace Uysa;

/**
 * fable-052 — UYSA'nın KENDİ SMTP'sinden mail gönderimi (ham SMTP, harici kütüphane YOK).
 *
 * NEDEN: Paraşüt'ün `POST /sharings` ucu müşteriye ZIP (PDF + imzalı UBL zarfı) yolluyor;
 * ne API'de ne arayüzde ek formatını seçtiren seçenek var (TALAY/PENDORYA bu yüzden ZIP aldı).
 * Çözüm: belgenin PDF'ini Paraşüt'ten indir (ParasutPdf) → burada TEK PDF olarak gönder.
 * Uçtan uca kanıtlandı (29 Tem): 32.887 baytlık fatura PDF'i → 250 OK.
 *
 * 🔒 Kredensiyal koda GÖMÜLMEZ — Env::get ile okunur:
 *   SMTP_HOST · SMTP_PORT (varsayılan 465) · SMTP_USER · SMTP_PASS · SMTP_FROM (yoksa USER)
 *   SMTP_FROM_AD (yoksa FROM_AD, yoksa 'UYSA Yemek Hizmetleri')
 *
 * 🔒 ASLA exception fırlatmaz: fatura/irsaliye kesimi mail yüzünden ÇÖKMEZ. Her yol
 *    ['ok'=>bool,'mesaj'=>string] döner; hata logda/kuyrukta görünür.
 *
 * Test: tasiyiciAta() ile SMTP konuşması enjekte edilir → testler ağa ÇIKMAZ, mail GÖNDERMEZ.
 */
final class Mail
{
    private const ZAMAN_ASIMI = 20;

    /** SMTP taşıyıcısı (test/kuru deneme için enjekte edilir). fn(array $z): array{ok,mesaj} */
    private static $tasiyici = null;

    /** Taşıyıcıyı değiştir (null = gerçek SMTP). Testler bunu kullanır. */
    public static function tasiyiciAta(?callable $tasiyici): void
    {
        self::$tasiyici = $tasiyici;
    }

    /** SMTP kredensiyalleri tanımlı mı? (tanımlı değilse hiç bağlantı denenmez) */
    public static function yapilandirilmis(): bool
    {
        foreach (['SMTP_HOST', 'SMTP_USER', 'SMTP_PASS'] as $k) {
            if ((string) Env::get($k, '') === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Mail gönder. $to virgül/noktalı virgülle çoklu adres olabilir ("Ad <a@b>" biçimi de).
     * @param array<int,array{ad:string,tip?:string,veri:string}> $ekler
     * @return array{ok:bool,mesaj:string}
     */
    public static function gonder(string $to, string $konu, string $govde, array $ekler = []): array
    {
        try {
            $rcpts = self::adresAyristir($to);
            if (!$rcpts) {
                return ['ok' => false, 'mesaj' => 'Geçerli alıcı adresi yok'];
            }
            $host   = (string) Env::get('SMTP_HOST', '');
            $port   = Env::int('SMTP_PORT', 465);
            $user   = (string) Env::get('SMTP_USER', '');
            $pass   = (string) Env::get('SMTP_PASS', '');
            if ($host === '' || $user === '' || $pass === '') {
                return ['ok' => false, 'mesaj' => 'SMTP yapılandırılmamış (SMTP_HOST/SMTP_USER/SMTP_PASS)'];
            }
            $from   = (string) Env::get('SMTP_FROM', $user);
            $fromAd = (string) (Env::get('SMTP_FROM_AD', null) ?? Env::get('FROM_AD', 'UYSA Yemek Hizmetleri'));

            $mesaj = self::mime($fromAd, $from, $rcpts, $konu, $govde, $ekler);

            $tasiyici = self::$tasiyici ?? self::smtpKonus(...);
            $r = $tasiyici([
                'host' => $host, 'port' => $port, 'user' => $user, 'pass' => $pass,
                'from' => $from, 'rcpts' => $rcpts, 'mesaj' => $mesaj,
            ]);
            return ['ok' => (bool) ($r['ok'] ?? false), 'mesaj' => (string) ($r['mesaj'] ?? '')];
        } catch (\Throwable $e) {
            // Mail ASLA çağıranı çökertmez.
            return ['ok' => false, 'mesaj' => 'Mail hatası: ' . mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /**
     * "a@b.com, Ad Soyad <c@d.com>; e@f.com" → ['a@b.com','c@d.com','e@f.com']
     * Geçersizler atılır, mükerrerler (harf büyüklüğü fark etmeksizin) tekilleşir.
     * @return array<int,string>
     */
    public static function adresAyristir(string $s): array
    {
        $out = [];
        foreach (preg_split('/[,;]+/', $s) ?: [] as $p) {
            $p = trim($p);
            if ($p !== '' && preg_match('/<([^>]+)>/', $p, $m)) {
                $p = trim($m[1]);
            }
            if ($p === '' || filter_var($p, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $anahtar = mb_strtolower($p, 'UTF-8');
            if (!isset($out[$anahtar])) {
                $out[$anahtar] = $p; // ilk yazım kalır (mükerrer adres kalkanı)
            }
        }
        return array_values($out);
    }

    /**
     * RFC 2047 başlık kodlaması. Türkçe karakter ZORUNLU doğru görünmeli — saf ASCII
     * konu olduğu gibi geçer, aksi halde =?UTF-8?B?…?= parçalarına bölünür (satır ≤ 75).
     */
    public static function konuKodla(string $konu): string
    {
        $konu = trim((string) preg_replace('/[\r\n]+/', ' ', $konu));
        if ($konu === '') {
            return '';
        }
        if (preg_match('/^[\x20-\x7E]*$/', $konu) === 1) {
            return $konu;
        }
        $parcalar = [];
        $buf = '';
        foreach (preg_split('//u', $konu, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            // Kodlanmış parça 'sözcük'ü 75 karakteri aşmamalı: =?UTF-8?B? + base64 + ?=
            if ($buf !== '' && strlen(base64_encode($buf . $ch)) > 45) {
                $parcalar[] = $buf;
                $buf = '';
            }
            $buf .= $ch;
        }
        if ($buf !== '') {
            $parcalar[] = $buf;
        }
        $kodlu = array_map(static fn(string $p): string => '=?UTF-8?B?' . base64_encode($p) . '?=', $parcalar);
        return implode("\r\n ", $kodlu);
    }

    /**
     * MIME multipart/mixed zarfı (gövde ve ekler base64 → Türkçe karakter ve uzun satır
     * sorunu YOK, SMTP nokta-kaçışı riski YOK).
     * @param array<int,string> $rcpts
     * @param array<int,array{ad:string,tip?:string,veri:string}> $ekler
     */
    public static function mime(
        string $fromAd,
        string $from,
        array $rcpts,
        string $konu,
        string $govde,
        array $ekler = [],
        ?string $sinir = null
    ): string {
        $sinir ??= 'uysa_' . bin2hex(random_bytes(10));
        $govde = str_replace(["\r\n", "\r"], "\n", $govde);
        $govde = str_replace("\n", "\r\n", $govde);

        $bas = 'From: ' . self::konuKodla($fromAd) . ' <' . $from . ">\r\n"
            . 'To: ' . implode(', ', $rcpts) . "\r\n"
            . 'Subject: ' . self::konuKodla($konu) . "\r\n"
            . 'Date: ' . date('r') . "\r\n"
            . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@uysayemek.com.tr>' . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . 'Content-Type: multipart/mixed; boundary="' . $sinir . "\"\r\n\r\n";

        $govdeBlok = '--' . $sinir . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($govde), 76, "\r\n");

        $ekBlok = '';
        foreach ($ekler as $ek) {
            $ad  = self::dosyaAdiTemizle((string) ($ek['ad'] ?? 'ek.pdf'));
            $tip = (string) ($ek['tip'] ?? 'application/octet-stream');
            $tip = preg_replace('#[^a-zA-Z0-9/.+-]#', '', $tip) ?: 'application/octet-stream';
            $ekBlok .= '--' . $sinir . "\r\n"
                . 'Content-Type: ' . $tip . '; name="' . $ad . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . 'Content-Disposition: attachment; filename="' . $ad . "\"\r\n\r\n"
                . chunk_split(base64_encode((string) ($ek['veri'] ?? '')), 76, "\r\n");
        }

        return $bas . $govdeBlok . $ekBlok . '--' . $sinir . "--\r\n";
    }

    /** Ek dosya adı ASCII-güvenli olsun (Türkçe harf → ASCII karşılığı; tırnak/CRLF asla). */
    public static function dosyaAdiTemizle(string $ad): string
    {
        $tr = ['ı' => 'i', 'İ' => 'I', 'ş' => 's', 'Ş' => 'S', 'ğ' => 'g', 'Ğ' => 'G',
            'ü' => 'u', 'Ü' => 'U', 'ö' => 'o', 'Ö' => 'O', 'ç' => 'c', 'Ç' => 'C'];
        $ad = strtr($ad, $tr);
        $ad = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $ad);
        $ad = trim($ad, '-');
        return $ad !== '' ? mb_substr($ad, 0, 120) : 'ek.pdf';
    }

    // ── belge mail metni (Ömer'in birebir istediği kurumsal ton) ───────────

    /** Konu: 'İrsaliye 29.07.2026 — UYSA Yemek Hizmetleri' / 'Fatura UY0… — UYSA Yemek Hizmetleri' */
    public static function belgeKonusu(string $tur, ?string $belgeNo, ?string $gun): string
    {
        if ($tur === 'fatura') {
            $ek = ($belgeNo ?? '') !== '' ? ' ' . $belgeNo : '';
            return 'Fatura' . $ek . ' — UYSA Yemek Hizmetleri';
        }
        $ts = $gun !== null && $gun !== '' ? strtotime($gun) : false;
        $ek = $ts !== false ? ' ' . date('d.m.Y', $ts) : (($belgeNo ?? '') !== '' ? ' ' . $belgeNo : '');
        return 'İrsaliye' . $ek . ' — UYSA Yemek Hizmetleri';
    }

    public static function belgeGovdesi(string $tur): string
    {
        $ilk = $tur === 'fatura' ? 'Faturanız ektedir.' : 'Bugünün irsaliyesi ektedir.';
        return "Merhabalar,\n\n" . $ilk . "\nİyi çalışmalar dilerim.\n\n"
            . "UYSA Yemek Hizmetleri\nwww.uysayemek.com.tr\n0 (262) 744 8119\n";
    }

    /** 'UYSA-irsaliye-2026-07-29.pdf' / 'UYSA-fatura-UY02026000000132.pdf' */
    public static function belgeDosyaAdi(string $tur, ?string $belgeNo, ?string $gun): string
    {
        if ($tur === 'fatura') {
            $ek = ($belgeNo ?? '') !== '' ? (string) $belgeNo : date('Y-m-d');
            return self::dosyaAdiTemizle('UYSA-fatura-' . $ek . '.pdf');
        }
        $ek = ($gun ?? '') !== '' ? (string) $gun : (($belgeNo ?? '') !== '' ? (string) $belgeNo : date('Y-m-d'));
        return self::dosyaAdiTemizle('UYSA-irsaliye-' . $ek . '.pdf');
    }

    // ── gerçek SMTP konuşması ──────────────────────────────────────────────

    /**
     * Ham SMTP (kanıtlı akış: EHLO → AUTH LOGIN → MAIL FROM → RCPT TO → DATA → QUIT).
     * 465 = örtük TLS (ssl://); diğer portlarda sunucu STARTTLS sunuyorsa yükseltilir.
     * @param array{host:string,port:int,user:string,pass:string,from:string,rcpts:array<int,string>,mesaj:string} $z
     * @return array{ok:bool,mesaj:string}
     */
    private static function smtpKonus(array $z): array
    {
        $sema = ((int) $z['port']) === 465 ? 'ssl://' : 'tcp://';
        $fp = @stream_socket_client($sema . $z['host'] . ':' . $z['port'], $errno, $errstr, self::ZAMAN_ASIMI);
        if (!$fp) {
            return ['ok' => false, 'mesaj' => 'SMTP bağlantısı kurulamadı: ' . $errstr . ' (' . $errno . ')'];
        }
        stream_set_timeout($fp, self::ZAMAN_ASIMI);

        $oku = static function ($fp): string {
            $out = '';
            while (($line = fgets($fp, 2048)) !== false) {
                $out .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break;
                }
            }
            return $out;
        };
        $yaz = static function ($fp, string $cmd) use ($oku): string {
            fwrite($fp, $cmd . "\r\n");
            return $oku($fp);
        };
        $kod = static fn(string $y): int => (int) substr(trim($y), 0, 3);

        try {
            $oku($fp); // 220 karşılama
            $ehlo = $yaz($fp, 'EHLO uysatakip');
            if ($sema === 'tcp://' && stripos($ehlo, 'STARTTLS') !== false) {
                $yaz($fp, 'STARTTLS');
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    fclose($fp);
                    return ['ok' => false, 'mesaj' => 'STARTTLS başarısız'];
                }
                $yaz($fp, 'EHLO uysatakip');
            }
            $yaz($fp, 'AUTH LOGIN');
            $yaz($fp, base64_encode($z['user']));
            $auth = $yaz($fp, base64_encode($z['pass']));
            if ($kod($auth) !== 235) {
                $yaz($fp, 'QUIT');
                fclose($fp);
                return ['ok' => false, 'mesaj' => 'SMTP kimlik doğrulama başarısız: ' . trim(substr($auth, 0, 120))];
            }
            $mf = $yaz($fp, 'MAIL FROM:<' . $z['from'] . '>');
            if ($kod($mf) !== 250) {
                $yaz($fp, 'QUIT');
                fclose($fp);
                return ['ok' => false, 'mesaj' => 'MAIL FROM reddedildi: ' . trim(substr($mf, 0, 120))];
            }
            $kabul = [];
            $redSon = '';
            foreach ($z['rcpts'] as $r) {
                $y = $yaz($fp, 'RCPT TO:<' . $r . '>');
                if ($kod($y) === 250 || $kod($y) === 251) {
                    $kabul[] = $r;
                } else {
                    $redSon = $r . ': ' . trim(substr($y, 0, 80));
                }
            }
            if (!$kabul) {
                $yaz($fp, 'QUIT');
                fclose($fp);
                return ['ok' => false, 'mesaj' => 'Hiçbir alıcı kabul edilmedi. ' . $redSon];
            }
            $data = $yaz($fp, 'DATA');
            if ($kod($data) !== 354) {
                $yaz($fp, 'QUIT');
                fclose($fp);
                return ['ok' => false, 'mesaj' => 'DATA reddedildi: ' . trim(substr($data, 0, 120))];
            }
            // Nokta-kaçışı (satır başındaki '.' iki nokta olur) — gövde base64 olsa da garanti.
            $payload = (string) preg_replace("/\r\n\./", "\r\n..", "\r\n" . $z['mesaj']);
            $payload = substr($payload, 2);
            fwrite($fp, $payload . "\r\n.\r\n");
            $son = $oku($fp);
            $yaz($fp, 'QUIT');
            fclose($fp);
            if ($kod($son) !== 250) {
                return ['ok' => false, 'mesaj' => 'Gönderim reddedildi: ' . trim(substr($son, 0, 120))];
            }
            $not = $redSon !== '' ? ' (kabul edilmeyen: ' . $redSon . ')' : '';
            return ['ok' => true, 'mesaj' => implode(', ', $kabul) . $not];
        } catch (\Throwable $e) {
            @fclose($fp);
            return ['ok' => false, 'mesaj' => 'SMTP hatası: ' . mb_substr($e->getMessage(), 0, 160)];
        }
    }
}
