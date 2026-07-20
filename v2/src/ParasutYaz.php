<?php
declare(strict_types=1);

namespace Uysa;

/**
 * fable-023b — Paraşüt'e e-İrsaliye KESME (TEK yazma ucu).
 *
 * 🔒 ALTIN KURAL: Bu sınıf Paraşüt muhasebesine YAZAN tek yerdir ve iki bağımsız kapıdan geçer:
 *   1) ANA ŞALTER  — env PARASUT_IRSALIYE_AKTIF != 1 ise HİÇBİR HTTP çağrısı yapılmaz.
 *      (UI'da da ayrı kapı var; biri unutulursa diğeri tutar.)
 *   2) ONAY İMZASI — $ctx['onay'] sunucudaki tek kullanımlık token ile eşleşmezse çağrı yok.
 *      Yani "onaysız yazma" kod seviyesinde imkânsızdır.
 *
 * ⚠️ Kesilen belge GİB'e giden RESMİ e-İrsaliye'dir (canlı keşif: ~4 sn sonra legalized).
 *    Bu yüzden burada DELETE/UPDATE metodu YOKTUR — yanlış kesim insan kararıyla düzeltilir.
 *
 * Ağ katmanı ENJEKTE EDİLEBİLİR ($http): testler "şalter kapalıyken hiç çağrılmadı"yı ispatlar.
 * $http imzası: fn(string $method, string $path, ?array $body): array
 *   dönüş: ['net'=>'ok'|'timeout'|'connect', 'status'=>int, 'data'=>array, 'error'=>string]
 *   'timeout' = istek gitti, cevap gelmedi (belge kesilmiş OLABİLİR) → ASLA yeniden deneme.
 *   'connect' = bağlantı hiç kurulamadı (istek gitmedi)             → 1 kez yeniden denenebilir.
 */
final class ParasutYaz
{
    /** Öğün → ayar anahtarı (Paraşüt ürün id'si koda GÖMÜLMEZ). */
    public const URUN_AYAR = [
        'ogle'    => 'irsaliye_urun_ogle',
        'aksam'   => 'irsaliye_urun_aksam',
        'kumanya' => 'irsaliye_urun_kumanya',
    ];

    public const OGUN_ETIKET = ['ogle' => 'Öğlen', 'aksam' => 'Akşam', 'kumanya' => 'Kumanya'];

    /**
     * Taşıyıcı alan adları canlı belgede görüldü ama RESMİ SPEC'TE YOK → create'te kabul
     * edildiği KANITLANMADI. Yanlış ad çıkarsa deploy beklemeden düzeltilebilsin diye
     * ayardan geçersiz kılınabilir (irsaliye_alan_plaka / irsaliye_alan_sofor).
     */
    public const ALAN_PLAKA_VARSAYILAN = 'plate_number';
    public const ALAN_SOFOR_VARSAYILAN = 'drivers_info';

    /** Paraşüt hız sınırı (art arda sorguda 429) — istekler arası küçük bekleme, ms. */
    private const ISTEK_ARASI_MS = 350;

    /** @var callable */
    private $http;

    /** Gerçek ağ mı kullanılıyor? (enjekte edilmiş katmanda hız-sınırı beklemesi anlamsız) */
    private bool $gercekAg;

    /**
     * @param Repo         $repo
     * @param string|null  $onayToken sunucu tarafındaki beklenen onay imzası (session'dan)
     * @param callable|null $http     ağ katmanı (test/kuru deneme için enjekte edilir)
     */
    public function __construct(
        private Repo $repo,
        private ?string $onayToken = null,
        ?callable $http = null
    ) {
        $this->gercekAg = $http === null;
        $this->http = $http ?? self::gercekHttp(...);
    }

    /** Ana şalter: env PARASUT_IRSALIYE_AKTIF. 1/true/on/yes dışında her şey KAPALI sayılır. */
    public static function aktif(): bool
    {
        $v = strtolower(trim((string) Env::get('PARASUT_IRSALIYE_AKTIF', '0')));
        return in_array($v, ['1', 'true', 'on', 'yes'], true);
    }

    /** Onay imzası üret (UI onay ekranı açılırken; session'da tutulur, tek kullanımlık). */
    public static function onayImzaUret(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * fable-023c: Kokpit'te kayıtlı sevk adresi (customers.sevk_*). Kolon henüz yoksa boş döner
     * (migration uygulanmadan deploy edilirse ekran çökmesin).
     * @return array{address:string,city:string,district:string}
     */
    public function kayitliAdres(int $customerId): array
    {
        $bos = ['address' => '', 'city' => '', 'district' => ''];
        try {
            $c = $this->repo->customer($customerId);
        } catch (\Throwable) {
            return $bos;
        }
        if (!is_array($c)) {
            return $bos;
        }
        return [
            'address'  => trim((string) ($c['sevk_adres'] ?? '')),
            'city'     => trim((string) ($c['sevk_il'] ?? '')),
            'district' => trim((string) ($c['sevk_ilce'] ?? '')),
        ];
    }

    /** @return array{plaka:string,sofor_ad:string,sofor_tckn:string,alan_plaka:string,alan_sofor:string} */
    public function tasiyici(): array
    {
        return [
            'plaka'      => trim((string) $this->repo->ayar('irsaliye_plaka', '')),
            'sofor_ad'   => trim((string) $this->repo->ayar('irsaliye_sofor_ad', '')),
            'sofor_tckn' => trim((string) $this->repo->ayar('irsaliye_sofor_tckn', '')),
            'alan_plaka' => trim((string) $this->repo->ayar('irsaliye_alan_plaka', self::ALAN_PLAKA_VARSAYILAN)),
            'alan_sofor' => trim((string) $this->repo->ayar('irsaliye_alan_sofor', self::ALAN_SOFOR_VARSAYILAN)),
        ];
    }

    /**
     * Öğün kırılımını irsaliye kalemlerine çevir (PÜR — ağ yok).
     * 0/negatif öğün kalem üretmez; ürün id'si tanımsızsa o öğün ATLANMAZ, hata olarak döner.
     * @param array<string,int> $meals
     * @return array{kalemler:array<int,array{ogun:string,urun_id:string,miktar:int}>,eksik:array<int,string>}
     */
    public function kalemler(array $meals): array
    {
        $kalemler = [];
        $eksik = [];
        foreach (self::URUN_AYAR as $ogun => $ayarKey) {
            $miktar = Repo::normalizePersons($meals[$ogun] ?? 0);
            if ($miktar <= 0) {
                continue;
            }
            $urunId = trim((string) $this->repo->ayar($ayarKey, ''));
            if ($urunId === '') {
                $eksik[] = $ogun;
                continue;
            }
            $kalemler[] = ['ogun' => $ogun, 'urun_id' => $urunId, 'miktar' => $miktar];
        }
        return ['kalemler' => $kalemler, 'eksik' => $eksik];
    }

    /**
     * Paraşüt'e gidecek JSON:API gövdesi (PÜR — ağ yok; kuru deneme ekranı bunu gösterir).
     * @param array<int,array{ogun:string,urun_id:string,miktar:int}> $kalemler
     * @param array{address?:string,city?:string,district?:string} $adres
     */
    public function govde(string $parasutId, string $gun, array $kalemler, array $adres, ?string $sevkZamani = null): array
    {
        $t = $this->tasiyici();
        // fable-023d (ilk gönderim gecesi dersi): issue_datetime ZORUNLU ve formatı hassas —
        // ISO '+03:00' formatı Paraşüt'çe YOK SAYILIYOR, '.000Z' UTC formatı kabul ediliyor.
        // Saat boş (00:00) kalırsa GİB şeması 'IssueTime eksik' diye REDDEDİYOR.
        // Kural (Ömer): saat daima İstanbul'a göre — Paraşüt UTC saklar (09:30 İst kesim = 06:30Z
        // kanıtı), o yüzden İstanbul şimdisinin UTC karşılığı gönderilir; ekranda İstanbul görünür.
        $zaman = $sevkZamani ?? gmdate('Y-m-d\TH:i:s') . '.000Z';
        $attr = [
            'issue_date'     => $gun,
            'issue_datetime' => $zaman,
            'shipment_date'  => $zaman,
            'inflow'         => false, // çıkış irsaliyesi (bizden müşteriye)
            'address'        => (string) ($adres['address'] ?? ''),
            'city'           => (string) ($adres['city'] ?? ''),
            'district'       => (string) ($adres['district'] ?? ''),
        ];
        // fable-023c (ilk canlı kesim dersi, 21 Tem): teslim posta kodu + ÇIKIŞ (gönderici) adresi
        // de belgeye yazılmalı — ilk denemede boş gitti. Hepsi ayardan gelir, koda gömülmez.
        $sabit = [
            'postal_code'           => 'irsaliye_teslim_posta',
            'company_address'       => 'irsaliye_cikis_adres',
            'company_city'          => 'irsaliye_cikis_il',
            'company_district'      => 'irsaliye_cikis_ilce',
            'company_postal_code'   => 'irsaliye_cikis_posta',
        ];
        foreach ($sabit as $alan => $ayar) {
            $v = trim((string) $this->repo->ayar($ayar, ''));
            if ($v !== '') {
                $attr[$alan] = $v;
            }
        }
        // Ticari irsaliye (geçmiş belgelerin tamamında is_commercial=true).
        $ticari = trim((string) $this->repo->ayar('irsaliye_ticari', '1'));
        if ($ticari !== '' && $ticari !== '0') {
            $attr['is_commercial'] = true;
        }

        // ⚠️ Taşıyıcı alanları: canlı belgede var, spec'te yok — kabul edildiği kanıtlanmadı.
        // Yanıt doğrulanır ve işlenmediyse sonuç ekranında AÇIKÇA bildirilir (gizlenmez).
        if ($t['plaka'] !== '') {
            $attr[$t['alan_plaka']] = $t['plaka'];
        }
        if ($t['sofor_ad'] !== '' || $t['sofor_tckn'] !== '') {
            $attr[$t['alan_sofor']] = [[
                'tckn'      => $t['sofor_tckn'],
                'full_name' => $t['sofor_ad'],
            ]];
        }

        $hareketler = [];
        foreach ($kalemler as $k) {
            $hareketler[] = [
                'type'          => 'stock_movements',
                'attributes'    => ['quantity' => $k['miktar']],
                'relationships' => [
                    'product' => ['data' => ['id' => (string) $k['urun_id'], 'type' => 'products']],
                ],
            ];
        }

        return [
            'data' => [
                'type'          => 'shipment_documents',
                'attributes'    => $attr,
                'relationships' => [
                    'contact'         => ['data' => ['id' => $parasutId, 'type' => 'contacts']],
                    'stock_movements' => ['data' => $hareketler],
                ],
            ],
        ];
    }

    /**
     * KURU DENEME — hiçbir ağ çağrısı yapmadan gidecek gövdeyi ve engelleri hesaplar.
     * Sevk adresi kesim anında Paraşüt'ten okunur; burada yer tutucu görünür.
     * @param array<string,int> $meals
     * @return array{ok:bool,durum:string,mesaj:string,kalemler:array,toplam:int,govde:?array}
     */
    public function onizleme(int $customerId, string $gun, array $meals): array
    {
        $on = $this->onKontrol($customerId, $gun, $meals);
        if (!$on['ok']) {
            return $on + ['kalemler' => [], 'toplam' => 0, 'govde' => null];
        }
        $kalemler = $on['kalemler'];
        $toplam = 0;
        foreach ($kalemler as $k) {
            $toplam += $k['miktar'];
        }
        return [
            'ok'       => true,
            'durum'    => 'hazir',
            'mesaj'    => '',
            'kalemler' => $kalemler,
            'toplam'   => $toplam,
            'govde'    => $this->govde(
                $on['parasut_id'],
                $gun,
                $kalemler,
                // fable-023c: önizleme artık GERÇEK adresi gösterir (kayıtlı adres = gidecek veri).
                // Kayıtlı adres yoksa kesim anında Paraşüt cari kartından okunmaya çalışılır.
                $this->kayitliAdres($customerId)['address'] !== ''
                    ? $this->kayitliAdres($customerId)
                    : ['address' => '(kayıtlı adres yok — kesimde Paraşüt cari kartından okunur)', 'city' => '', 'district' => ''],
                $gun . 'T00:00:00+03:00' // önizlemede sabit: gövde karşılaştırması zamanla değişmesin
            ),
        ];
    }

    /**
     * 🔒 TEK YAZMA UCU — Paraşüt'te e-İrsaliye oluşturur.
     * Sıra: şalter → onay imzası → yerel kalkan → ön kontrol → adres oku → Paraşüt mükerrer
     *       sorgusu → POST → yanıt doğrula → log + audit.
     * @param array<string,int> $meals ['ogle'=>..,'aksam'=>..,'kumanya'=>..]
     * @param array{onay?:string,actor?:string} $ctx
     * @return array{ok:bool,durum:string,mesaj:string,doc_id:?string,despatch_no:?string,
     *   tasiyici_ok:bool,parasut_ad:?string,toplam:int}
     */
    public function createShipmentDocument(int $customerId, string $gun, array $meals, array $ctx): array
    {
        // ── KAPI 1: ana şalter. Burada dönersek HİÇBİR HTTP çağrısı yapılmamıştır. ──
        if (!self::aktif()) {
            return self::sonuc(false, 'kapali', 'Kesim kapalı — Ömer onayı bekleniyor (PARASUT_IRSALIYE_AKTIF=0).');
        }
        // ── KAPI 2: onay imzası (UI onay ekranından gelen tek kullanımlık token) ──
        $imza = (string) ($ctx['onay'] ?? '');
        if ($this->onayToken === null || $this->onayToken === '' || $imza === ''
            || !hash_equals($this->onayToken, $imza)) {
            return self::sonuc(false, 'onaysiz', 'Onay imzası geçersiz — kesim yapılmadı.');
        }
        if (!Helpers::isDate($gun)) {
            return self::sonuc(false, 'hata', 'Geçersiz tarih.');
        }

        $on = $this->onKontrol($customerId, $gun, $meals);
        if (!$on['ok']) {
            return self::sonuc(false, $on['durum'], $on['mesaj']);
        }
        $kalemler = $on['kalemler'];
        $parasutId = $on['parasut_id'];
        $toplam = 0;
        foreach ($kalemler as $k) {
            $toplam += $k['miktar'];
        }
        $actor = (string) ($ctx['actor'] ?? '');

        // ── Sevk adresi: ÖNCE Kokpit'teki kayıtlı adres (fable-023c) ──
        // Ders (ilk canlı deneme, 21 Tem): adres için her kesimde Paraşüt'e sormak gereksiz bir
        // kırılma noktasıydı — cari kart okuması HTTP 0 verince kesim hiç yapılamadı. Adres her
        // belgede AYNI (geçmiş irsaliyelerle birebir doğrulandı) → bizde durur, uzaktan sorulmaz.
        $adres = $this->kayitliAdres($customerId);
        $parasutAd = null;
        if ($adres['address'] === '') {
            // Kayıtlı adres yoksa Paraşüt cari kartını dene (ilk kurulum / yeni müşteri).
            $c = $this->cagir('GET', '/contacts/' . rawurlencode($parasutId), null);
            if ($c['net'] === 'ok' && $c['status'] >= 200 && $c['status'] < 300) {
                $cAttr = $c['data']['data']['attributes'] ?? [];
                $adres = [
                    'address'  => trim((string) ($cAttr['address'] ?? '')),
                    'city'     => trim((string) ($cAttr['city'] ?? '')),
                    'district' => trim((string) ($cAttr['district'] ?? '')),
                ];
                $parasutAd = trim((string) ($cAttr['name'] ?? '')) ?: null;
            }
        }
        if ($adres['address'] === '') {
            // Sessiz atlama YOK: sebebi kullanıcıya söylenir (müşteri kartına adres girilmeli).
            return $this->hataYaz($customerId, $gun, $kalemler, $toplam, $actor, 'hata',
                'Sevk adresi yok — müşteri kartına sevk adresi girin (Paraşüt cari kartı da okunamadı).');
        }

        // ── Dış kaynaklı mükerrer kalkanı: o gün + o cari için belge var mı? ──
        // NOT: resmi spec'te shipment_documents için issue_date/contact_id filtresi YOK
        // (yalnız flow_type/invoice_status/archived). Bu yüzden filtreye GÜVENMİYORUZ:
        // en yeni belgeler (sort=-issue_date, 25 kayıt) çekilir ve eşleşme YANITTA doğrulanır.
        // Sonuç: yanlış pozitif imkânsız (gün + cari birebir tutmalı), eksik kalabilir —
        // o yüzden yerel UNIQUE kilidi ve açık onay hâlâ asıl kalkanlardır.
        $q = $this->cagir('GET', '/shipment_documents?' . http_build_query([
            'filter[issue_date]' => $gun,
            'filter[contact_id]' => $parasutId,
            'sort'               => '-issue_date',
            'page[size]'         => 25,
        ]), null);
        $sorguYapildi = $q['net'] === 'ok' && $q['status'] >= 200 && $q['status'] < 300;
        if ($sorguYapildi) {
            $mevcut = self::ayniGunBelge($q['data'], $gun, $parasutId);
            if ($mevcut !== null) {
                $this->repo->irsaliyeLogKaydet($customerId, $gun, [
                    'parasut_doc_id' => (string) ($mevcut['id'] ?? ''),
                    'despatch_no'    => self::despatchNo($mevcut),
                    'kalemler'       => $kalemler,
                    'toplam_kisi'    => $toplam,
                    'durum'          => 'kesildi',
                    'hata_mesaj'     => 'Paraşüt\'te zaten vardı (elle kesilmiş olabilir) — yeni belge OLUŞTURULMADI.',
                    'entered_by'     => $actor,
                ]);
                return self::sonuc(true, 'zaten_var',
                    'Paraşüt\'te bu gün için belge zaten var — yeni irsaliye kesilmedi.',
                    (string) ($mevcut['id'] ?? ''), self::despatchNo($mevcut), false, $parasutAd, $toplam);
            }
        }
        // Sorgu başarısızsa kesime devam edilir: yerel UNIQUE + kullanıcının onayı hâlâ kalkan.

        // ── KESİM (tek POST). Timeout'ta ASLA yeniden deneme. ──
        $govde = $this->govde($parasutId, $gun, $kalemler, $adres);
        $r = $this->cagir('POST', '/shipment_documents', $govde);
        if ($r['net'] === 'connect') {
            // İstek hiç gitmedi → tek sefer yeniden dene (idempotency standardı: sadece bu dal).
            $r = $this->cagir('POST', '/shipment_documents', $govde);
        }
        if ($r['net'] === 'timeout') {
            $this->repo->irsaliyeLogKaydet($customerId, $gun, [
                'kalemler' => $kalemler, 'toplam_kisi' => $toplam, 'durum' => 'bilinmiyor',
                'hata_mesaj' => 'Zaman aşımı — belge kesilmiş OLABİLİR. Yeniden denenmedi.',
                'entered_by' => $actor,
            ]);
            uysa_audit('parasut_irsaliye', $actor, $gun,
                json_encode(['customer_id' => $customerId, 'durum' => 'bilinmiyor'], JSON_UNESCAPED_UNICODE), '');
            return self::sonuc(false, 'bilinmiyor',
                'Zaman aşımı — belge kesilmiş olabilir. TEKRAR DENEMEYİN, Paraşüt\'ten kontrol edin.',
                null, null, false, $parasutAd, $toplam);
        }
        if ($r['net'] !== 'ok' || $r['status'] < 200 || $r['status'] >= 300) {
            return $this->hataYaz($customerId, $gun, $kalemler, $toplam, $actor, 'hata',
                'Paraşüt reddetti (HTTP ' . $r['status'] . ')' . ($r['error'] !== '' ? ': ' . $r['error'] : '')
                . self::apiHata($r['data']), $parasutAd);
        }

        $doc = $r['data']['data'] ?? [];
        $docId = (string) ($doc['id'] ?? '');
        $despatch = self::despatchNo($doc);
        $tasiyiciOk = $this->tasiyiciIslendiMi($doc);

        // ── SAAT DÜZELTMESİ (fable-023f — CEOTHERM dersi, 21 Tem sabahı): Paraşüt
        // issue_datetime'ı CREATE sırasında YOK SAYIYOR (hangi formatta olursa olsun) —
        // yalnız oluşturma SONRASI PUT ile '.000Z' formatında yazılabiliyor. Saat 00:00
        // kalırsa GİB "IssueTime eksik" diye REDDEDİYOR. O yüzden kesimden hemen sonra
        // ayrı bir güncelleme adımı şart. (OPAK gece bu yüzden elle PUT'la kurtarılmıştı.)
        if ($docId !== '') {
            $utc = gmdate('Y-m-d\TH:i:s') . '.000Z';
            $this->cagir('PUT', '/shipment_documents/' . rawurlencode($docId),
                ['data' => ['id' => $docId, 'type' => 'shipment_documents',
                    'attributes' => ['issue_datetime' => $utc, 'shipment_date' => $utc]]]);
        }

        // ── GÖNDERİM (fable-023d): e-İrsaliye resmileştirme — undokümante /legalize ucu. ──
        // İlk gönderim gecesinde (21 Tem, OPAK UU02026000000590) kanıtlanan akış:
        //   POST {id}/legalize gövde {data:{type,attributes:{to:<GİB alıcı kutusu>}}} → 202 +
        //   trackable_job → job 'done' → belge legalized_at + despatch_no alır (status önce
        //   'waiting' = GİB kuyruğu, normal). Alias müşteri kartında (edespatch_alias);
        //   YOKSA gönderim DENENMEZ (yanlış kutuya resmi belge riski) — elle gönderilir.
        [$gonderim, $gonderimMesaj, $despatch2] = $this->gonder($customerId, $docId);
        if ($despatch2 !== null) {
            $despatch = $despatch2; // numara çoğu zaman gönderimden SONRA oluşur
        }

        // fable-023e: müşteri mail istiyorsa (irsaliye_mail dolu) belge maillenir —
        // SADECE resmileşmiş belge (gönderim başarısızsa müşteriye mail atılmaz).
        [$mail, $mailMesaj] = $gonderim === 'gonderildi'
            ? $this->mailPaylas($customerId, $docId, $despatch, $gun)
            : ['yok', ''];

        $this->repo->irsaliyeLogKaydet($customerId, $gun, [
            'parasut_doc_id' => $docId !== '' ? $docId : null,
            'despatch_no'    => $despatch,
            'kalemler'       => $kalemler,
            'toplam_kisi'    => $toplam,
            'durum'          => 'kesildi',
            'tasiyici_ok'    => $tasiyiciOk,
            'gonderim'       => $gonderim,
            'mail'           => $mail,
            // fable-023f: gönderim hatasının SEBEBİ de loglanır (CEOTHERM'de NULL kalmıştı,
            // teşhis için Paraşüt'e gitmek gerekti — sebep kayıttan okunabilmeli).
            'hata_mesaj'     => $gonderim !== 'gonderildi' && $gonderimMesaj !== '' ? mb_substr($gonderimMesaj, 0, 490) : null,
            'entered_by'     => $actor,
        ]);
        uysa_audit('parasut_irsaliye', $actor, $gun, json_encode([
            'customer_id' => $customerId, 'doc_id' => $docId, 'despatch_no' => $despatch,
            'toplam_kisi' => $toplam, 'tasiyici_ok' => $tasiyiciOk, 'gonderim' => $gonderim,
            'mail' => $mail,
        ], JSON_UNESCAPED_UNICODE), '');

        $mesaj = $gonderim === 'gonderildi'
            ? 'İrsaliye kesildi ve GÖNDERİLDİ' . ($despatch !== null ? " ($despatch)" : '') . '.'
            : 'İrsaliye kesildi; ' . $gonderimMesaj;
        if ($mail === 'gonderildi') {
            $mesaj .= ' Mail paylaşıldı' . ($mailMesaj !== '' ? " ($mailMesaj)" : '') . '.';
        } elseif ($mail === 'hata') {
            $mesaj .= ' Mail paylaşımı BAŞARISIZ — Paraşüt\'ten elle paylaşın.';
        }
        if (!$tasiyiciOk) {
            $mesaj .= ' Ayrıca taşıyıcı bilgisi (plaka/şoför) işlenmedi — Paraşüt\'ten kontrol edin.';
        }
        if (!$sorguYapildi) {
            // Kalkanlardan biri çalışmadı: gizleme, söyle (yerel kilit + onay hâlâ devrede).
            $mesaj .= ' (Not: Paraşüt mükerrer sorgusu yapılamadı — elle kesilmiş bir belge varsa çift olabilir.)';
        }
        return self::sonuc(true, 'kesildi', $mesaj, $docId, $despatch, $tasiyiciOk, $parasutAd, $toplam);
    }

    /**
     * e-İrsaliye gönderimi (resmileştirme). Belge ZATEN kesilmiş durumda çağrılır — burada ne
     * olursa olsun belge geri alınmaz; başarısızlıkta kullanıcı elle gönderir.
     * @return array{0:string,1:string,2:?string} [gonderim(gonderildi|hata|yok), mesaj, despatch_no]
     */
    private function gonder(int $customerId, string $docId): array
    {
        if ($docId === '') {
            return ['hata', 'belge id alınamadı — Paraşüt\'ten elle gönderin.', null];
        }
        $musteri = null;
        try {
            $musteri = $this->repo->customer($customerId);
        } catch (\Throwable) {
        }
        $alias = trim((string) ($musteri['edespatch_alias'] ?? ''));
        if ($alias === '') {
            return ['yok', 'GİB alıcı kutusu tanımlı değil — Paraşüt\'ten elle gönderin (müşteri kartına edespatch_alias girilebilir).', null];
        }
        $r = $this->cagir('POST', '/shipment_documents/' . rawurlencode($docId) . '/legalize',
            ['data' => ['type' => 'shipment_documents', 'attributes' => ['to' => $alias]]]);
        if ($r['net'] !== 'ok' || $r['status'] < 200 || $r['status'] >= 300) {
            return ['hata', 'gönderim isteği kabul edilmedi (HTTP ' . $r['status'] . ') — Paraşüt\'ten elle gönderin.', null];
        }
        // trackable_job takibi (kısa: ~16 sn). Sonuçsuz kalırsa belge durumundan okunur.
        $jobId = (string) ($r['data']['data']['id'] ?? '');
        for ($i = 0; $i < 4 && $jobId !== ''; $i++) {
            $this->bekle(4);
            $j = $this->cagir('GET', '/trackable_jobs/' . rawurlencode($jobId), null);
            $st = (string) ($j['data']['data']['attributes']['status'] ?? '');
            if ($st === 'done') {
                break;
            }
            if ($st === 'error') {
                $err = $j['data']['data']['attributes']['errors'] ?? [];
                return ['hata', 'GİB gönderimi reddetti: ' . mb_substr(json_encode($err, JSON_UNESCAPED_UNICODE), 0, 160)
                    . ' — Paraşüt\'ten elle gönderin.', null];
            }
        }
        // Belgeden doğrula: legalized_at oluştuysa gönderim başarılı (status 'waiting' = GİB kuyruğu, normal).
        $d = $this->cagir('GET', '/shipment_documents/' . rawurlencode($docId), null);
        $a = $d['data']['data']['attributes'] ?? [];
        $despatch = isset($a['despatch_no']) && $a['despatch_no'] !== null && $a['despatch_no'] !== ''
            ? (string) $a['despatch_no'] : null;
        if (!empty($a['legalized_at'])) {
            return ['gonderildi', '', $despatch];
        }
        return ['hata', 'gönderim sonucu doğrulanamadı — Paraşüt\'ten kontrol edin/elle gönderin.', $despatch];
    }

    /** Test edilebilir bekleme (mock ağda beklememek için gerçek ağ bayrağına bağlı). */
    private function bekle(int $sn): void
    {
        if ($this->gercekAg) {
            sleep($sn);
        }
    }

    /**
     * fable-023e: Belgeyi müşterinin mail adres(ler)ine paylaş (Paraşüt 'sharings' ucu).
     * Canlı kanıt (21 Tem): POST /sharings, 'portal' parametresi ZORUNLU (yoksa 400),
     * başarıda email_status='sent'. Adres yoksa hiç çağrı yapılmaz.
     * @return array{0:string,1:string} [mail(gonderildi|hata|yok), mesaj(adresler)]
     */
    private function mailPaylas(int $customerId, string $docId, ?string $despatch, string $gun): array
    {
        $musteri = null;
        try {
            $musteri = $this->repo->customer($customerId);
        } catch (\Throwable) {
        }
        $adresler = trim((string) ($musteri['irsaliye_mail'] ?? ''));
        if ($adresler === '' || $docId === '') {
            return ['yok', ''];
        }
        $tarih = date('d.m.Y', strtotime($gun));
        $konu = 'e-İrsaliye' . ($despatch !== null ? ' ' . $despatch : '') . ' — UYSA YEMEK';
        $r = $this->cagir('POST', '/sharings', ['data' => [
            'type'       => 'sharing_forms',
            'attributes' => [
                'email'  => [
                    'addresses' => $adresler,
                    'subject'   => $konu,
                    'body'      => $tarih . ' tarihli e-İrsaliyeniz ektedir. İyi çalışmalar dileriz.',
                ],
                // Zorunlu parametre (400 kanıtı); irsaliye mailinde portal özellikleri kapalı.
                'portal' => ['has_online_collection' => false, 'has_online_payment_reminder' => false, 'has_referral_link' => false],
            ],
            'relationships' => ['shareable' => ['data' => ['id' => $docId, 'type' => 'shipment_documents']]],
        ]]);
        if ($r['net'] !== 'ok' || $r['status'] < 200 || $r['status'] >= 300) {
            return ['hata', ''];
        }
        return ['gonderildi', $adresler];
    }

    // ── iç yardımcılar ───────────────────────────────────────────

    /**
     * Ağ öncesi tüm yerel engeller tek yerde (önizleme ve kesim AYNI kuralı kullanır).
     * @return array{ok:bool,durum:string,mesaj:string,kalemler:array,parasut_id:string}
     */
    private function onKontrol(int $customerId, string $gun, array $meals): array
    {
        $red = static fn(string $d, string $m): array
            => ['ok' => false, 'durum' => $d, 'mesaj' => $m, 'kalemler' => [], 'parasut_id' => ''];

        $c = $this->repo->customer($customerId);
        if ($c === null) {
            return $red('hata', 'Müşteri bulunamadı.');
        }
        if ((int) ($c['irsaliye_aktif'] ?? 1) !== 1) {
            return $red('kapsam_disi', 'İrsaliye kapsamı dışı (aylık faturadan gidiyor).');
        }
        $parasutId = trim((string) ($c['parasut_id'] ?? ''));
        if ($parasutId === '') {
            return $red('eslesme_yok', 'Paraşüt eşleşmesi yok — irsaliye kesilemez.');
        }
        $log = $this->repo->irsaliyeLog($customerId, $gun);
        if ($log !== null && (string) $log['durum'] === 'kesildi') {
            return $red('zaten_var', 'Bu gün için irsaliye zaten kesildi (' . (string) ($log['despatch_no'] ?? '—') . ').');
        }
        if ($log !== null && (string) $log['durum'] === 'bilinmiyor') {
            return $red('bilinmiyor', 'Önceki denemede durum bilinmiyor — önce Paraşüt\'ten kontrol edin.');
        }
        $k = $this->kalemler($meals);
        if ($k['eksik']) {
            $adlar = array_map(static fn($o) => self::OGUN_ETIKET[$o] ?? $o, $k['eksik']);
            return $red('hata', 'Ürün eşlemesi eksik: ' . implode(', ', $adlar) . ' (ayarlardan tanımlayın).');
        }
        if (!$k['kalemler']) {
            return $red('kalem_yok', 'Bu gün için kişi sayısı girilmemiş.');
        }
        return ['ok' => true, 'durum' => 'hazir', 'mesaj' => '', 'kalemler' => $k['kalemler'], 'parasut_id' => $parasutId];
    }

    private function hataYaz(int $customerId, string $gun, array $kalemler, int $toplam,
        string $actor, string $durum, string $mesaj, ?string $parasutAd = null): array
    {
        $this->repo->irsaliyeLogKaydet($customerId, $gun, [
            'kalemler' => $kalemler, 'toplam_kisi' => $toplam, 'durum' => $durum,
            'hata_mesaj' => mb_substr($mesaj, 0, 480), 'entered_by' => $actor,
        ]);
        uysa_audit('parasut_irsaliye', $actor, $gun, json_encode([
            'customer_id' => $customerId, 'durum' => $durum, 'mesaj' => $mesaj,
        ], JSON_UNESCAPED_UNICODE), '');
        return self::sonuc(false, $durum, $mesaj, null, null, false, $parasutAd, $toplam);
    }

    private static function sonuc(bool $ok, string $durum, string $mesaj, ?string $docId = null,
        ?string $despatch = null, bool $tasiyiciOk = false, ?string $parasutAd = null, int $toplam = 0): array
    {
        return [
            'ok' => $ok, 'durum' => $durum, 'mesaj' => $mesaj, 'doc_id' => $docId,
            'despatch_no' => $despatch, 'tasiyici_ok' => $tasiyiciOk,
            'parasut_ad' => $parasutAd, 'toplam' => $toplam,
        ];
    }

    /** Aynı gün + aynı cari belge var mı (filtre sunucuda tutmazsa diye yanıtta da doğrula). */
    private static function ayniGunBelge(array $resp, string $gun, string $parasutId): ?array
    {
        foreach (($resp['data'] ?? []) as $d) {
            if (!is_array($d)) {
                continue;
            }
            $issue = (string) ($d['attributes']['issue_date'] ?? '');
            $cid = (string) ($d['relationships']['contact']['data']['id'] ?? '');
            if (substr($issue, 0, 10) === $gun && ($cid === '' || $cid === $parasutId)) {
                return $d;
            }
        }
        return null;
    }

    private static function despatchNo(array $doc): ?string
    {
        $a = $doc['attributes'] ?? [];
        foreach (['despatch_no', 'procurement_number', 'invoice_no'] as $k) {
            $v = trim((string) ($a[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        return null;
    }

    /** Paraşüt hata gövdesinden okunur mesaj (varsa) — kullanıcı "neden olmadı"yı görsün. */
    private static function apiHata(array $data): string
    {
        $errs = $data['errors'] ?? null;
        if (!is_array($errs)) {
            return '';
        }
        $parts = [];
        foreach ($errs as $e) {
            $t = trim((string) (is_array($e) ? ($e['detail'] ?? $e['title'] ?? '') : $e));
            if ($t !== '') {
                $parts[] = $t;
            }
        }
        return $parts ? ' — ' . mb_substr(implode('; ', $parts), 0, 200) : '';
    }

    /** Dönen belgede taşıyıcı bilgisi gerçekten işlendi mi? (varsayım DEĞİL, ölçüm) */
    private function tasiyiciIslendiMi(array $doc): bool
    {
        $t = $this->tasiyici();
        if ($t['plaka'] === '' && $t['sofor_ad'] === '') {
            return true; // gönderilecek bilgi yoktu
        }
        $a = $doc['attributes'] ?? [];
        $json = json_encode($a, JSON_UNESCAPED_UNICODE) ?: '';
        $plakaOk = $t['plaka'] === '' || str_contains(mb_strtoupper($json, 'UTF-8'), mb_strtoupper($t['plaka'], 'UTF-8'));
        $soforOk = $t['sofor_ad'] === '' || str_contains(mb_strtoupper($json, 'UTF-8'), mb_strtoupper($t['sofor_ad'], 'UTF-8'));
        return $plakaOk && $soforOk;
    }

    /** Enjekte edilen ağ katmanını çağır (+ hız sınırı için istekler arası küçük bekleme). */
    private function cagir(string $method, string $path, ?array $body): array
    {
        $r = ($this->http)($method, $path, $body);
        if (($r['status'] ?? 0) === 429) {
            // Paraşüt hız sınırı: bir kez bekle-tekrar dene. POST'ta 429 = istek REDDEDİLDİ,
            // belge oluşmadı (mükerrer riski yok).
            if ($this->gercekAg) {
                usleep(1500 * 1000);
            }
            $r = ($this->http)($method, $path, $body);
        }
        if ($this->gercekAg) {
            usleep(self::ISTEK_ARASI_MS * 1000);
        }
        return [
            'net'    => (string) ($r['net'] ?? 'ok'),
            'status' => (int) ($r['status'] ?? 0),
            'data'   => is_array($r['data'] ?? null) ? $r['data'] : [],
            'error'  => (string) ($r['error'] ?? ''),
        ];
    }

    /**
     * GERÇEK ağ katmanı — yalnız şalter AÇIKKEN ve onay imzası geçerliyken buraya gelinir.
     * @return array{net:string,status:int,data:array,error:string}
     */
    private static function gercekHttp(string $method, string $path, ?array $body): array
    {
        // fable-023c: kredensiyaller VPS'te ŞİFRELİ dosyada — env'de düz durmuyorlar. Şirket
        // id'sini okumadan ÖNCE çözülmeleri şart; yoksa "PARASUT_COMPANY_ID yok" (HTTP 0) alınır.
        // (İlk canlı denemedeki iki hatanın da gerçek kökü buydu.)
        Parasut::loadEncryptedCreds();
        $company = (string) Env::get('PARASUT_COMPANY_ID', '');
        if ($company === '') {
            return ['net' => 'connect', 'status' => 0, 'data' => [],
                'error' => 'Paraşüt kredensiyali çözülemedi (PARASUT_COMPANY_ID yok)'];
        }
        try {
            $token = Parasut::token();
        } catch (\Throwable $e) {
            return ['net' => 'connect', 'status' => 0, 'data' => [], 'error' => 'kimlik doğrulama başarısız'];
        }
        $url = 'https://api.parasut.com/v4/' . rawurlencode($company) . '/' . ltrim($path, '/');

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            // 6/7 = bağlantı kurulamadı (istek GİTMEDİ) · 28 = timeout (gitmiş OLABİLİR)
            $net = in_array($errno, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT], true) ? 'connect' : 'timeout';
            return ['net' => $net, 'status' => 0, 'data' => [], 'error' => $err];
        }
        $data = json_decode((string) $raw, true);
        return [
            'net'    => 'ok',
            'status' => $status,
            'data'   => is_array($data) ? $data : [],
            'error'  => '',
        ];
    }
}
