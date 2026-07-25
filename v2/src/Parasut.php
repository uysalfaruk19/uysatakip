<?php
declare(strict_types=1);

namespace Uysa;

/**
 * Paraşüt SALT-OKUMA istemcisi (opus-012).
 *
 * 🔒 ALTIN KURAL (BAĞLAYICI — vault/projeler/parasut-entegrasyon):
 *   Bu sınıf Paraşüt VERİ API'sine (/v4/{company}/...) YALNIZCA GET yapar.
 *   Fatura/cari YAZMA (POST/PUT/DELETE) YOK — o iş opus-013, emsal→öneri→Ömer onayı akışıyla.
 *   Buradaki TEK POST çağrısı OAuth2 token endpoint'inedir (/oauth/token); bu kimlik
 *   doğrulamadır, muhasebe verisine yazma DEĞİL. Veri uçlarına sadece get()/contacts() erişir.
 *
 * Kredensiyaller koda GÖMÜLMEZ — Env::get ile .env(.v2)'den okunur:
 *   PARASUT_CLIENT_ID, PARASUT_CLIENT_SECRET, PARASUT_USERNAME, PARASUT_PASSWORD, PARASUT_COMPANY_ID
 */
final class Parasut
{
    private const OAUTH_URL = 'https://api.parasut.com/oauth/token';
    private const API_BASE  = 'https://api.parasut.com/v4';
    private const REDIRECT  = 'urn:ietf:wg:oauth:2.0:oob';
    private const TIMEOUT   = 20;
    // Canlı API sayfa boyutu üst sınırı 25 — 100 istenirse HTTP 422 (fable-023 keşfinde yakalandı).
    private const PAGE_SIZE = 25;
    private const MAX_PAGES = 100; // güvenlik freni (sonsuz sayfalamayı önle)

    /** @var array<string,mixed>|null bellek-içi token cache (istek boyunca) */
    private static $memToken = null;

    /**
     * fable-023: Kredensiyaller VPS'te ŞİFRELİ durur (Ömer kararı, 2026-07-20).
     * PARASUT_ENC_FILE (AES-256-CBC, "base64(iv):base64(ciphertext)") anahtarı AYRI yoldaki
     * PARASUT_KEY_FILE'dan okunur (chmod 400, repoya/yedeğe girmez). Çözülen değerler yalnız
     * sürecin belleğine (putenv) alınır; diske yazılmaz, log'a basılmaz.
     * Dürüst sınır: sunucuya root erişen anahtarı da okur — bu yöntem dosya/yedek sızıntısını kapatır.
     * Env'de düz PARASUT_* varsa (yerel geliştirme) ona dokunulmaz.
     */
    public static function loadEncryptedCreds(): bool
    {
        if ((string) Env::get('PARASUT_CLIENT_ID', '') !== '') {
            return true; // zaten yüklü (env veya önceki çağrı)
        }
        $encFile = (string) Env::get('PARASUT_ENC_FILE', '');
        $keyFile = (string) Env::get('PARASUT_KEY_FILE', '');
        if ($encFile === '' || $keyFile === '' || !is_readable($encFile) || !is_readable($keyFile)) {
            return false;
        }
        $blob = trim((string) @file_get_contents($encFile));
        $key  = base64_decode(trim((string) @file_get_contents($keyFile)), true);
        if ($blob === '' || $key === false || strlen($key) !== 32 || !str_contains($blob, ':')) {
            return false;
        }
        [$ivB64, $ctB64] = explode(':', $blob, 2);
        $iv = base64_decode($ivB64, true);
        $ct = base64_decode($ctB64, true);
        if ($iv === false || $ct === false || strlen($iv) !== 16) {
            return false;
        }
        $json = openssl_decrypt($ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data)) {
            return false;
        }
        foreach (['PARASUT_CLIENT_ID', 'PARASUT_CLIENT_SECRET', 'PARASUT_USERNAME', 'PARASUT_PASSWORD', 'PARASUT_COMPANY_ID'] as $k) {
            $v = (string) ($data[$k] ?? '');
            if ($v === '') {
                return false;
            }
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
        return true;
    }

    /**
     * Env'de tüm Paraşüt kredensiyalleri tanımlı mı? (parasut.php bunu kontrol edip
     * eksikse kullanıcıya "creds .env.v2'de yok" der; ağ çağrısı denemez.)
     */
    public static function configured(): bool
    {
        self::loadEncryptedCreds();
        foreach (['PARASUT_CLIENT_ID', 'PARASUT_CLIENT_SECRET', 'PARASUT_USERNAME', 'PARASUT_PASSWORD', 'PARASUT_COMPANY_ID'] as $k) {
            if ((string) Env::get($k, '') === '') {
                return false;
            }
        }
        return true;
    }

    /** Token cache dosya yolu (kalıcı; uploads ile aynı mantık). */
    private static function tokenCachePath(): string
    {
        $dir = (string) Env::get('UPLOAD_DIR', '');
        if ($dir === '' || !is_dir($dir)) {
            $dir = dirname(__DIR__) . '/storage';
            if (!is_dir($dir)) {
                @mkdir($dir, 0770, true);
            }
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            // Son çare: sistem temp (yazılabilir olduğu garanti).
            $dir = sys_get_temp_dir();
        }
        return rtrim($dir, '/\\') . '/parasut_token.json';
    }

    /**
     * Geçerli Bearer access_token döndür (cache + gerekirse yenile/al).
     * Sıra: bellek → dosya cache (süresi dolmadıysa) → refresh_token → password grant.
     */
    public static function token(): string
    {
        self::loadEncryptedCreds();
        if (is_array(self::$memToken) && self::tokenFresh(self::$memToken)) {
            return (string) self::$memToken['access_token'];
        }

        $cache = self::readTokenCache();
        if (is_array($cache) && self::tokenFresh($cache)) {
            self::$memToken = $cache;
            return (string) $cache['access_token'];
        }

        // Süresi geçmiş ama refresh_token varsa → yenile.
        if (is_array($cache) && !empty($cache['refresh_token'])) {
            $refreshed = self::requestToken([
                'grant_type'    => 'refresh_token',
                'client_id'     => (string) Env::get('PARASUT_CLIENT_ID', ''),
                'client_secret' => (string) Env::get('PARASUT_CLIENT_SECRET', ''),
                'refresh_token' => (string) $cache['refresh_token'],
            ]);
            if ($refreshed !== null) {
                return (string) $refreshed['access_token'];
            }
        }

        // İlk kez / refresh başarısız → password grant.
        $fresh = self::requestToken([
            'grant_type'    => 'password',
            'client_id'     => (string) Env::get('PARASUT_CLIENT_ID', ''),
            'client_secret' => (string) Env::get('PARASUT_CLIENT_SECRET', ''),
            'username'      => (string) Env::get('PARASUT_USERNAME', ''),
            'password'      => (string) Env::get('PARASUT_PASSWORD', ''),
            'redirect_uri'  => self::REDIRECT,
        ]);
        if ($fresh === null) {
            throw new \RuntimeException('Paraşüt kimlik doğrulama başarısız (creds/ağ).');
        }
        return (string) $fresh['access_token'];
    }

    /**
     * Bearer'lı GET — JSON:API gövdesini dizi olarak döndürür.
     * $path örn: "/contacts". $query örn: ['filter[account_type]' => 'customer'].
     * Ağ hatası/401/timeout → RuntimeException (çağıran yakalar, çökme yok).
     */
    public static function get(string $path, array $query = []): array
    {
        $company = (string) Env::get('PARASUT_COMPANY_ID', '');
        if ($company === '') {
            throw new \RuntimeException('PARASUT_COMPANY_ID tanımlı değil.');
        }
        $url = self::API_BASE . '/' . rawurlencode($company) . '/' . ltrim($path, '/');
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        $token = self::token();

        [$status, $body] = self::httpGet($url, ['Authorization: Bearer ' . $token, 'Accept: application/json']);

        if ($status === 401) {
            // Token bayatlamış olabilir → cache'i temizle, bir kez daha dene.
            self::$memToken = null;
            @unlink(self::tokenCachePath());
            $token = self::token();
            [$status, $body] = self::httpGet($url, ['Authorization: Bearer ' . $token, 'Accept: application/json']);
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Paraşüt GET hatası (HTTP ' . $status . ').');
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Paraşüt yanıtı çözümlenemedi.');
        }
        return $data;
    }

    /**
     * Tüm müşteri (account_type=customer) contact'ları — sayfalı çekilir, düzleştirilir.
     * @return array<int,array{parasut_id:string,name:string,balance:float,tax_number:string,tax_office:string,email:string,phone:string}>
     */
    public static function contacts(): array
    {
        $out = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $resp = self::get('/contacts', [
                'filter[account_type]' => 'customer',
                'page[size]'           => self::PAGE_SIZE,
                'page[number]'         => $page,
            ]);
            $batch = self::parseContacts($resp);
            foreach ($batch as $c) {
                $out[] = $c;
            }
            // Sayfa dolmadıysa son sayfadayız.
            if (count($resp['data'] ?? []) < self::PAGE_SIZE) {
                break;
            }
        }
        return $out;
    }

    /**
     * JSON:API contact yanıtını sade dizilere çevir (PÜR — ağ yok, test edilebilir).
     * $resp = ['data' => [ ['id'=>.., 'attributes'=>['name'=>.., 'balance'=>..]], ... ]].
     * @return array<int,array{parasut_id:string,name:string,balance:float,tax_number:string,tax_office:string,email:string,phone:string}>
     */
    public static function parseContacts(array $resp): array
    {
        $rows = $resp['data'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $a = $r['attributes'] ?? [];
            $a = is_array($a) ? $a : [];
            $out[] = [
                'parasut_id' => (string) ($r['id'] ?? ''),
                'name'       => trim((string) ($a['name'] ?? '')),
                'balance'    => (float) ($a['balance'] ?? 0),
                'tax_number' => trim((string) ($a['tax_number'] ?? '')),
                'tax_office' => trim((string) ($a['tax_office'] ?? '')),
                'email'      => trim((string) ($a['email'] ?? '')),
                'phone'      => trim((string) ($a['phone'] ?? '')),
            ];
        }
        return $out;
    }

    // ── fable-030: Paraşüt GİDER okuma (Hikari kanıtlı deseni; SALT-OKUMA) ──────────

    /**
     * İçeri alınmış alış faturaları (purchase_bills) — hedef ayın kayıtları.
     * net_total = KDV DAHİL ödenen para (Hikari canlı kanıtı: METRO gross 3.465,74 / net 3.921,97).
     * Sunucu filtresine güvenilmez; ay süzgeci istemcide.
     * @return array<int,array{parasut_id:string,gun:string,tutar:float,kategori_ad:string,tedarikci:string,fatura_no:string}>
     */
    public static function purchaseBillsForMonth(string $ym): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
            throw new \InvalidArgumentException('ay=YYYY-MM bekleniyor.');
        }
        $rows = [];
        $seen = 0;
        $total = null;
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $resp = self::get('/purchase_bills', [
                'include' => 'category,supplier', 'sort' => '-issue_date',
                'page[size]' => self::PAGE_SIZE, 'page[number]' => $page,
            ]);
            $data = $resp['data'] ?? [];
            if (!is_array($data) || $data === []) {
                break;
            }
            $names = [];
            foreach (($resp['included'] ?? []) as $i) {
                $names[($i['type'] ?? '') . ':' . ($i['id'] ?? '')] = (string) ($i['attributes']['name'] ?? '');
            }
            $eskiyeGecti = false;
            foreach ($data as $r) {
                $a = is_array($r['attributes'] ?? null) ? $r['attributes'] : [];
                $gun = (string) ($a['issue_date'] ?? '');
                if ($gun !== '' && $gun < $ym . '-01') {
                    $eskiyeGecti = true;
                    break;
                }
                if (!str_starts_with($gun, $ym)) {
                    continue;
                }
                $tutar = (float) ($a['net_total'] ?? 0);
                if ($tutar <= 0) {
                    $tutar = (float) ($a['gross_total'] ?? 0) + (float) ($a['total_vat'] ?? 0);
                }
                $catRef = $r['relationships']['category']['data'] ?? null;
                $supRef = $r['relationships']['supplier']['data'] ?? null;
                $rows[] = [
                    'parasut_id'  => (string) ($r['id'] ?? ''),
                    'gun'         => $gun,
                    'tutar'       => round($tutar, 2),
                    'kategori_ad' => is_array($catRef) ? (string) ($names[($catRef['type'] ?? 'item_categories') . ':' . ($catRef['id'] ?? '')] ?? '') : '',
                    'tedarikci'   => is_array($supRef) ? (string) ($names[($supRef['type'] ?? 'contacts') . ':' . ($supRef['id'] ?? '')] ?? '') : '',
                    'fatura_no'   => (string) ($a['invoice_no'] ?? ''),
                ];
            }
            $seen += count($data);
            $total ??= isset($resp['meta']['total_count']) ? (int) $resp['meta']['total_count'] : null;
            if ($eskiyeGecti || ($total !== null && $seen >= $total) || ($total === null && count($data) < self::PAGE_SIZE)) {
                break;
            }
        }
        return $rows;
    }

    /**
     * e-Fatura GELEN KUTUSU (inbound e_invoices) — Paraşüt'e "içeri alınmamış" faturalar da gider
     * toplamına girsin diye DOĞRUDAN okunur (Hikari dersi: 474 fatura kutuda bekliyordu).
     * Çift sayım köprüsü: içeri alınmış e-faturada relationships.invoice.data.id = purchase_bill
     * id'si → çağıran o kaydı atlar (fatura pb yolundan gelir). include=invoice ŞART.
     * İade faturaları alınmaz (sayısı raporlanır; negatif gider kaydı yok, Ömer elle düşer).
     * @return array{bills:array<int,array<string,mixed>>,iade:int}
     */
    public static function inboundEInvoicesForMonth(string $ym): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
            throw new \InvalidArgumentException('ay=YYYY-MM bekleniyor.');
        }
        $rows = [];
        $iade = 0;
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $resp = self::get('/e_invoices', [
                'sort' => '-issue_date', 'include' => 'invoice',
                'page[size]' => self::PAGE_SIZE, 'page[number]' => $page,
            ]);
            $data = $resp['data'] ?? [];
            if (!is_array($data) || $data === []) {
                break;
            }
            $eskiyeGecti = false;
            foreach ($data as $r) {
                $a = is_array($r['attributes'] ?? null) ? $r['attributes'] : [];
                $gun = (string) ($a['issue_date'] ?? '');
                if ($gun !== '' && $gun < $ym . '-01') {
                    $eskiyeGecti = true;
                    break;
                }
                if (!str_starts_with($gun, $ym) || (string) ($a['direction'] ?? '') !== 'inbound' || !empty($a['archived'])) {
                    continue;
                }
                if (!empty($a['refund_of_id']) || (string) ($a['item_type'] ?? 'invoice') !== 'invoice') {
                    $iade++;
                    continue;
                }
                $rows[] = [
                    'parasut_id'    => 'ei-' . (string) ($r['id'] ?? ''),
                    'gun'           => $gun,
                    'tutar'         => round((float) ($a['net_total'] ?? 0), 2),
                    'kategori_ad'   => '',
                    'tedarikci'     => (string) ($a['contact_name'] ?? ''),
                    'fatura_no'     => (string) ($a['external_id'] ?? ''),
                    'iceri_alinmis' => !empty($r['relationships']['invoice']['data']['id']),
                    'pb_ref'        => (string) ($r['relationships']['invoice']['data']['id'] ?? ''),
                ];
            }
            if ($eskiyeGecti || count($data) < self::PAGE_SIZE) {
                break;
            }
        }
        return ['bills' => $rows, 'iade' => $iade];
    }

    /**
     * fable-031b: Gelen e-faturanın SATIRLARI (miktar × birim) — API'de satır ucu yok,
     * imzalı UBL zip'i indirilip XML'den okunur (KIRMIZI 1 kanıtı: 568×175=99.400).
     * @return array{adet:float,net:float}|null toplam miktar + KDV-hariç toplam (okunamazsa null)
     */
    public static function eInvoiceLineTotals(string $eInvoiceId): ?array
    {
        $company = (string) Env::get('PARASUT_COMPANY_ID', '');
        if ($company === '' || !class_exists(\ZipArchive::class)) {
            return null;
        }
        $token = self::token();
        $ch = curl_init(self::API_BASE . '/' . rawurlencode($company) . '/e_invoices/' . rawurlencode($eInvoiceId) . '/signed_ubl');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token], CURLOPT_TIMEOUT => 40,
        ]);
        $bin = curl_exec($ch);
        $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($bin === false || $st !== 200 || strlen((string) $bin) < 100) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'ubl');
        file_put_contents($tmp, $bin);
        $xmlStr = null;
        $z = new \ZipArchive();
        if ($z->open($tmp) === true) {
            for ($i = 0; $i < $z->numFiles; $i++) {
                if (preg_match('/\.xml$/i', (string) $z->getNameIndex($i))) {
                    $xmlStr = $z->getFromIndex($i);
                    break;
                }
            }
            $z->close();
        }
        @unlink($tmp);
        if (!$xmlStr) {
            return null;
        }
        $sx = @simplexml_load_string($xmlStr);
        if (!$sx) {
            return null;
        }
        $sx->registerXPathNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $sx->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $adet = 0.0;
        $net = 0.0;
        foreach ($sx->xpath('//cac:InvoiceLine') as $L) {
            $L->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
            $adet += (float) (($L->xpath('./cbc:InvoicedQuantity')[0] ?? 0));
            $net += (float) (($L->xpath('./cbc:LineExtensionAmount')[0] ?? 0));
        }
        return $adet > 0 ? ['adet' => $adet, 'net' => round($net, 2)] : null;
    }

    /**
     * fable-044: Gelen e-faturanın SATIR SATIR DÖKÜMÜ (ürün · miktar · birim · birim fiyat · tutar).
     * eInvoiceLineTotals ile AYNI kaynak (signed_ubl zip → UBL XML) ama toplam yerine her satırı döner.
     * gider_kalem senkronu bunu kullanır. Okunamazsa (zip yok / XML bozuk / satır yok) null.
     * @return array<int,array{urun:string,miktar:?float,birim:?string,birim_fiyat:?float,tutar:float}>|null
     */
    public static function eInvoiceLines(string $eInvoiceId): ?array
    {
        $company = (string) Env::get('PARASUT_COMPANY_ID', '');
        if ($company === '' || !class_exists(\ZipArchive::class)) {
            return null;
        }
        $token = self::token();
        $ch = curl_init(self::API_BASE . '/' . rawurlencode($company) . '/e_invoices/' . rawurlencode($eInvoiceId) . '/signed_ubl');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token], CURLOPT_TIMEOUT => 40,
        ]);
        $bin = curl_exec($ch);
        $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($bin === false || $st !== 200 || strlen((string) $bin) < 100) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'ubl');
        file_put_contents($tmp, $bin);
        $xmlStr = null;
        $z = new \ZipArchive();
        if ($z->open($tmp) === true) {
            for ($i = 0; $i < $z->numFiles; $i++) {
                if (preg_match('/\.xml$/i', (string) $z->getNameIndex($i))) {
                    $xmlStr = $z->getFromIndex($i);
                    break;
                }
            }
            $z->close();
        }
        @unlink($tmp);
        if (!$xmlStr) {
            return null;
        }
        $lines = self::parseUblLines((string) $xmlStr);
        return $lines === [] ? null : $lines;
    }

    /**
     * fable-044: UBL InvoiceLine parse'ı — PÜR (ağ yok, test edilebilir; mock UBL ile doğrulanır).
     * InvoiceLine → Item/Name (ürün), InvoicedQuantity (+unitCode=birim), Price/PriceAmount
     * (birim fiyat), LineExtensionAmount (KDV hariç satır tutarı). Namespace'li XML (cac/cbc).
     * Ürün adı boş satır atlanır; tutarı olmayan satır 0 kabul edilir (dürüstlük: kapsanmayan sayılır).
     * @return array<int,array{urun:string,miktar:?float,birim:?string,birim_fiyat:?float,tutar:float}>
     */
    public static function parseUblLines(string $xmlStr): array
    {
        // DOMXPath (SimpleXML'in namespace re-register kaprisinden bağımsız, deterministik).
        if (trim($xmlStr) === '') {
            return [];
        }
        $dom = new \DOMDocument();
        try {
            if (!@$dom->loadXML($xmlStr)) {
                return [];
            }
        } catch (\ValueError $e) {
            return [];
        }
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $out = [];
        foreach ($xp->query('//cac:InvoiceLine') as $L) {
            $urun = trim((string) $xp->evaluate('string(.//cac:Item/cbc:Name)', $L));
            if ($urun === '') {
                continue; // adı olmayan satır (indirim/başlık) atlanır
            }
            $miktar = null;
            $birim = null;
            $qtyNode = $xp->query('./cbc:InvoicedQuantity', $L)->item(0);
            if ($qtyNode !== null) {
                $miktar = (float) $qtyNode->nodeValue;
                $uc = $qtyNode instanceof \DOMElement ? trim((string) $qtyNode->getAttribute('unitCode')) : '';
                $birim = $uc !== '' ? mb_substr($uc, 0, 20) : null;
            }
            $bfRaw = trim((string) $xp->evaluate('string(.//cac:Price/cbc:PriceAmount)', $L));
            $birimFiyat = ($bfRaw !== '' && is_numeric($bfRaw)) ? round((float) $bfRaw, 2) : null;
            $tutar = round((float) $xp->evaluate('string(./cbc:LineExtensionAmount)', $L), 2);
            $out[] = [
                'urun'        => mb_substr($urun, 0, 200),
                'miktar'      => ($miktar !== null && $miktar > 0) ? $miktar : null,
                'birim'       => $birim,
                'birim_fiyat' => $birimFiyat,
                'tutar'       => $tutar,
            ];
        }
        return $out;
    }

    // ── iç yardımcılar ───────────────────────────────────────────

    /** Token cache'i (istenleşen 5 dk emniyet payıyla) hâlâ taze mi? */
    private static function tokenFresh(array $tok): bool
    {
        return !empty($tok['access_token'])
            && isset($tok['expires_at'])
            && (int) $tok['expires_at'] - 300 > time();
    }

    private static function readTokenCache(): ?array
    {
        $path = self::tokenCachePath();
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : null;
    }

    private static function writeTokenCache(array $tok): void
    {
        $path = self::tokenCachePath();
        @file_put_contents($path, json_encode($tok), LOCK_EX);
        @chmod($path, 0600);
    }

    /**
     * OAuth token isteği (password / refresh_token grant). Başarılıysa cache'ler ve döndürür.
     * NOT: Bu tek POST kimlik doğrulama içindir; muhasebe verisine YAZMA değildir.
     */
    private static function requestToken(array $form): ?array
    {
        [$status, $body] = self::httpPostForm(self::OAUTH_URL, $form);
        if ($status < 200 || $status >= 300) {
            return null;
        }
        $d = json_decode($body, true);
        if (!is_array($d) || empty($d['access_token'])) {
            return null;
        }
        $expiresIn = (int) ($d['expires_in'] ?? 7200);
        $tok = [
            'access_token'  => (string) $d['access_token'],
            'refresh_token' => (string) ($d['refresh_token'] ?? ($form['refresh_token'] ?? '')),
            'expires_at'    => time() + max(60, $expiresIn),
        ];
        self::$memToken = $tok;
        self::writeTokenCache($tok);
        return $tok;
    }

    /**
     * @return array{0:int,1:string} [httpStatus, body]
     */
    private static function httpGet(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException('Paraşüt bağlantı hatası: ' . ($err !== '' ? $err : 'timeout'));
        }
        return [$status, (string) $body];
    }

    /**
     * OAuth için form-encoded POST (kimlik doğrulama).
     * @return array{0:int,1:string} [httpStatus, body]
     */
    private static function httpPostForm(string $url, array $form): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($form),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            return [0, ''];
        }
        return [$status, (string) $body];
    }
}
