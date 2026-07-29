<?php
declare(strict_types=1);

namespace Uysa;

/**
 * fable-052 — Belgenin PDF'ini Paraşüt'ten indirir (SALT-OKUMA; yalnız GET).
 *
 * Neden gerekli: Paraşüt'ün paylaşım ucu müşteriye ZIP yolluyor. Biz PDF'i indirip
 * UYSA'nın kendi SMTP'sinden TEK PDF olarak gönderiyoruz (bkz. Mail).
 *
 * ⚠️ PDF GECİKMESİ GERÇEK: belge resmileşmeden (legalize) PDF hazır olmayabilir.
 *    Bu sınıf o durumda null döner — kuyruk (Repo::mailKuyrukIsle) tekrar dener.
 *
 * ⚠️ %PDF başlığı doğrulanmadan ASLA veri döndürülmez (hata sayfası/boş cevap ek olarak gitmesin).
 *
 * Test: agAta() ile hem API hem indirme enjekte edilir → testler ağa ÇIKMAZ.
 */
final class ParasutPdf
{
    private const INDIRME_ZAMAN_ASIMI = 60;

    /** @var null|callable fn(string $path, array $query): ?array */
    private static $get = null;
    /** @var null|callable fn(string $url): ?string */
    private static $indir = null;

    /** Ağ katmanlarını değiştir (null,null = gerçek ağ). Testler bunu kullanır. */
    public static function agAta(?callable $get, ?callable $indir = null): void
    {
        self::$get = $get;
        self::$indir = $indir;
    }

    /** Paraşüt kredensiyalleri çözülebiliyor mu? (yoksa hiç ağ denenmez) */
    public static function yapilandirilmis(): bool
    {
        if (self::$get !== null) {
            return true; // enjekte edilmiş katman
        }
        return Parasut::configured();
    }

    /**
     * Satış faturasının e-belge PDF'i. e_invoices (e-Fatura) veya e_archives (e-Arşiv)
     * olabilir — hangisi olduğu `active_e_document` ilişkisinden okunur.
     * @return string|null ham PDF; belge henüz hazır değilse null
     */
    public static function faturaPdf(string $salesInvoiceId): ?string
    {
        $salesInvoiceId = trim($salesInvoiceId);
        if ($salesInvoiceId === '') {
            return null;
        }
        $r = self::get('sales_invoices/' . rawurlencode($salesInvoiceId), ['include' => 'active_e_document']);
        if ($r === null) {
            return null;
        }
        [$eid, $tip] = self::eBelge($r);
        if ($eid === '' || $tip === '') {
            return null; // henüz resmileşmemiş → kuyruk tekrar dener
        }
        $p = self::get($tip . '/' . rawurlencode($eid) . '/pdf', []);
        return self::urldenIndir(self::pdfUrl($p));
    }

    /** e-İrsaliye PDF'i (kanıtlı uç: GET /shipment_documents/{id}/pdf). */
    public static function irsaliyePdf(string $shipmentDocId): ?string
    {
        $shipmentDocId = trim($shipmentDocId);
        if ($shipmentDocId === '') {
            return null;
        }
        $p = self::get('shipment_documents/' . rawurlencode($shipmentDocId) . '/pdf', []);
        return self::urldenIndir(self::pdfUrl($p));
    }

    /** tur ('fatura'|'irsaliye') → ilgili PDF. Kuyruk bu kapıyı kullanır. */
    public static function belgePdf(string $tur, string $kaynakId): ?string
    {
        return $tur === 'fatura' ? self::faturaPdf($kaynakId) : self::irsaliyePdf($kaynakId);
    }

    /**
     * active_e_document ilişkisinden [id, tip] çıkar. Tip 'e_invoices' | 'e_archives';
     * beklenmedik tip gelirse boş döner (yanlış uca istek atılmaz).
     * @return array{0:string,1:string}
     */
    private static function eBelge(array $r): array
    {
        $rel = $r['data']['relationships']['active_e_document']['data'] ?? null;
        $id  = is_array($rel) ? (string) ($rel['id'] ?? '') : '';
        $tip = is_array($rel) ? (string) ($rel['type'] ?? '') : '';
        if ($id === '' || $tip === '') {
            foreach ((array) ($r['included'] ?? []) as $inc) {
                $t = (string) ($inc['type'] ?? '');
                if ($t === 'e_invoices' || $t === 'e_archives') {
                    $id = (string) ($inc['id'] ?? '');
                    $tip = $t;
                    break;
                }
            }
        }
        return in_array($tip, ['e_invoices', 'e_archives'], true) ? [$id, $tip] : ['', ''];
    }

    private static function pdfUrl(?array $r): string
    {
        return $r === null ? '' : trim((string) ($r['data']['attributes']['url'] ?? ''));
    }

    /** @return array<string,mixed>|null */
    private static function get(string $path, array $query): ?array
    {
        try {
            if (self::$get !== null) {
                $r = (self::$get)($path, $query);
                return is_array($r) ? $r : null;
            }
            if (!Parasut::configured()) {
                return null;
            }
            return Parasut::get($path, $query);
        } catch (\Throwable $e) {
            error_log('[UYSA v2 fable-052] Paraşüt PDF sorgusu başarısız (' . $path . '): ' . $e->getMessage());
            return null;
        }
    }

    /** İmzalı S3 url'ini indir + %PDF doğrula. Doğrulanmayan veri ASLA dönmez. */
    private static function urldenIndir(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        try {
            if (self::$indir !== null) {
                $ham = (self::$indir)($url);
            } else {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT        => self::INDIRME_ZAMAN_ASIMI,
                ]);
                $ham = curl_exec($ch);
                $ham = $ham === false ? null : (string) $ham;
                curl_close($ch);
            }
        } catch (\Throwable $e) {
            error_log('[UYSA v2 fable-052] PDF indirilemedi: ' . $e->getMessage());
            return null;
        }
        $ham = is_string($ham) ? $ham : '';
        return substr($ham, 0, 4) === '%PDF' ? $ham : null;
    }
}
