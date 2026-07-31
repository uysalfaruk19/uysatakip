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

    /** fable-052: kuyruk işleyici (kesim anında tek deneme). @var callable */
    private $mailIsle;

    /**
     * @param Repo         $repo
     * @param string|null  $onayToken sunucu tarafındaki beklenen onay imzası (session'dan)
     * @param callable|null $http     ağ katmanı (test/kuru deneme için enjekte edilir)
     * @param callable|null $mailIsle fable-052 mail kuyruğu işleyici fn(int $id): array
     */
    public function __construct(
        private Repo $repo,
        private ?string $onayToken = null,
        ?callable $http = null,
        ?callable $mailIsle = null
    ) {
        $this->gercekAg = $http === null;
        $this->http = $http ?? self::gercekHttp(...);
        $this->mailIsle = $mailIsle ?? fn(int $id): array => $this->repo->mailKuyrukIsle(1, null, null, $id);
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
            $mesaj .= ' Mail gönderildi' . ($mailMesaj !== '' ? " ($mailMesaj)" : '') . '.';
        } elseif ($mail === 'sirada') {
            // fable-052: PDF belge resmileşene kadar hazır olmayabilir → kuyruk 5 dk'da bir dener.
            $mesaj .= ' Mail SIRADA' . ($mailMesaj !== '' ? " ($mailMesaj)" : '') . ' — PDF hazır olunca gönderilecek.';
        } elseif ($mail === 'hata') {
            $mesaj .= ' Mail BAŞARISIZ — Belge maili ayarlarından kontrol edin.';
        }
        if (!$tasiyiciOk) {
            $mesaj .= ' Ayrıca taşıyıcı bilgisi (plaka/şoför) işlenmedi — Paraşüt\'ten kontrol edin.';
        }
        if (!$sorguYapildi) {
            // Kalkanlardan biri çalışmadı: gizleme, söyle (yerel kilit + onay hâlâ devrede).
            $mesaj .= ' (Not: Paraşüt mükerrer sorgusu yapılamadı — elle kesilmiş bir belge varsa çift olabilir.)';
        }
        return self::sonuc(true, 'kesildi', $mesaj, $docId, $despatch, $tasiyiciOk, $parasutAd, $toplam, $mail);
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
        // fable-023g (PENDORYA/TSKB dersi, 21 Tem): alıcı GİB'de e-İrsaliye kayıtlıysa 'to'
        // ile, KAYITLI DEĞİLSE 'to' OLMADAN gönderilir ("Alıcı etiketi boş bırakılmalı").
        // Hangisi olduğu önceden bilinemeyebilir → alias'la dene, o hatada alias'sız tekrar dene
        // (job error = belge resmileşmedi, tekrar legalize güvenli — CEOTHERM/PENDORYA kanıtı).
        $alias = trim((string) ($musteri['edespatch_alias'] ?? ''));
        $attr = $alias !== '' ? ['to' => $alias] : new \stdClass();
        $sonHata = '';
        for ($deneme = 0; $deneme < 2; $deneme++) {
            $r = $this->cagir('POST', '/shipment_documents/' . rawurlencode($docId) . '/legalize',
                ['data' => ['type' => 'shipment_documents', 'attributes' => $attr]]);
            if ($r['net'] !== 'ok' || $r['status'] < 200 || $r['status'] >= 300) {
                return ['hata', 'gönderim isteği kabul edilmedi (HTTP ' . $r['status'] . ') — Paraşüt\'ten elle gönderin.', null];
            }
            // trackable_job takibi (kısa: ~16 sn). Sonuçsuz kalırsa belge durumundan okunur.
            $jobId = (string) ($r['data']['data']['id'] ?? '');
            $sonHata = '';
            for ($i = 0; $i < 4 && $jobId !== ''; $i++) {
                $this->bekle(4);
                $j = $this->cagir('GET', '/trackable_jobs/' . rawurlencode($jobId), null);
                $st = (string) ($j['data']['data']['attributes']['status'] ?? '');
                if ($st === 'done') {
                    break;
                }
                if ($st === 'error') {
                    $err = $j['data']['data']['attributes']['errors'] ?? [];
                    $sonHata = mb_substr(json_encode($err, JSON_UNESCAPED_UNICODE), 0, 160);
                    break;
                }
            }
            if ($sonHata === '') {
                break; // job done → doğrulamaya geç
            }
            if ($deneme === 0 && $alias !== '' && mb_stripos($sonHata, 'etiketi boş') !== false) {
                $attr = new \stdClass(); // alıcı e-İrsaliye kayıtlı değil → etiketsiz tekrar dene
                continue;
            }
            return ['hata', 'GİB gönderimi reddetti: ' . $sonHata . ' — Paraşüt\'ten elle gönderin.', null];
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
     * fable-023e: Belgeyi müşterinin mail adres(ler)ine paylaş.
     *
     * fable-052 ŞALTERİ (ayar `paylasim_yontemi`):
     *   'uysa_mail' (VARSAYILAN) → Paraşüt sharings ÇAĞRILMAZ; belge mail kuyruğuna yazılır,
     *                              PDF indirilip UYSA SMTP'sinden TEK PDF olarak gider.
     *   'parasut'                → aşağıdaki ESKİ davranış (rollback yolu; kod SİLİNMEDİ).
     *                              ⚠️ Paraşüt paylaşımı müşteriye ZIP yollar (kanıtlı).
     *
     * Eski akışın canlı kanıtı (21 Tem): POST /sharings, 'portal' parametresi ZORUNLU (yoksa 400),
     * başarıda email_status='sent'. Adres yoksa hiç çağrı yapılmaz.
     * @return array{0:string,1:string} [mail(gonderildi|sirada|hata|yok), mesaj(adresler)]
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
        if ($this->paylasimYontemi() === 'uysa_mail') {
            return $this->uysaMailKuyruguna('irsaliye', $customerId, $docId, $adresler, $despatch, $gun);
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
        ?string $despatch = null, bool $tasiyiciOk = false, ?string $parasutAd = null, int $toplam = 0,
        string $mail = 'yok'): array
    {
        return [
            'ok' => $ok, 'durum' => $durum, 'mesaj' => $mesaj, 'doc_id' => $docId,
            'despatch_no' => $despatch, 'tasiyici_ok' => $tasiyiciOk,
            'parasut_ad' => $parasutAd, 'toplam' => $toplam,
            // fable-052: ekran mail göstergesi kuyruk durumunu yansıtsın (gonderildi|sirada|hata|yok)
            'mail' => $mail,
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

    // ══════════════════════════════════════════════════════════════════════════════════
    // fable-024 — SATIŞ FATURASI + e-FATURA (aynı disiplin: şalter + onay imzası + timeout)
    //
    // İki akış:
    //   (1) irsaliyeli (haftalık): dönem irsaliye loglarının öğün toplamı → tek fatura, öğün başına
    //       kalem, tevkifatlıysa (PENDORYA 604/%50) kalem oranı + e-Fatura vat_withholding_params.
    //   (2) aylık: irsaliye_aktif=0 müşteri → dönem üretim toplamı × birim, tek kalem, tevkifat YOK,
    //       irsaliye bağı YOK. CANTAŞ bölüşümde her alt-firma için AYRI fatura (createMonthlyInvoice).
    //
    // ⚠️ shipment_documents ilişkisi RESMİ SPEC'te YOK (canlı faturada görüldü) — create'te kabul
    //    edildiği KANITLANMADI. 422 riskine karşı ayardan kapatılabilir (fatura_irsaliye_bagla=0).
    //    "Faturalandı → listede görünmesin" bizim fatura_log_id işaretimizle çalışır; Paraşüt-yanı
    //    has_invoice bağı BONUS'tur, ona güvenilmez.
    // ══════════════════════════════════════════════════════════════════════════════════

    /** Fatura şalteri: env PARASUT_FATURA_AKTIF. 1/true/on/yes dışında her şey KAPALI. */
    public static function faturaAktif(): bool
    {
        $v = strtolower(trim((string) Env::get('PARASUT_FATURA_AKTIF', '0')));
        return in_array($v, ['1', 'true', 'on', 'yes'], true);
    }

    /** Yemek KDV oranı (parametrik; ayardan değişebilir). */
    private function faturaKdvOrani(): float
    {
        return (float) str_replace(',', '.', (string) $this->repo->ayar('fatura_kdv_orani', '10'));
    }

    /**
     * Tutar matematiği (PÜR — kuruş bazında, float taşması yok).
     * brüt = Σ(miktar×birim) · KDV = brüt×oran · tevkifat = KDV×tevkifatOran · net = brüt+KDV−tevkifat.
     * @param array<int,array{miktar:int,birim:float}> $kalemler
     * @return array{brut:float,kdv:float,tevkifat:float,net:float,brut_kurus:int,kdv_kurus:int,tevkifat_kurus:int,net_kurus:int}
     */
    public static function faturaHesap(array $kalemler, float $vatRate, ?float $tevkifatOran): array
    {
        $brutKurus = 0;
        foreach ($kalemler as $k) {
            $brutKurus += (int) round(((int) $k['miktar']) * ((float) $k['birim']) * 100);
        }
        $kdvKurus = (int) round($brutKurus * $vatRate / 100);
        $tevkifatKurus = $tevkifatOran !== null && $tevkifatOran > 0
            ? (int) round($kdvKurus * $tevkifatOran / 100) : 0;
        $netKurus = $brutKurus + $kdvKurus - $tevkifatKurus;
        return [
            'brut' => (float) ($brutKurus / 100), 'kdv' => (float) ($kdvKurus / 100),
            'tevkifat' => (float) ($tevkifatKurus / 100), 'net' => (float) ($netKurus / 100),
            'brut_kurus' => $brutKurus, 'kdv_kurus' => $kdvKurus,
            'tevkifat_kurus' => $tevkifatKurus, 'net_kurus' => $netKurus,
        ];
    }

    /**
     * Dönemin FATURALANMAMIŞ irsaliyelerinden öğün kalemlerini kur (PÜR — ağ yok).
     * @return array{kalemler:array<int,array{ogun:string,urun_id:string,miktar:int,birim:float}>,
     *   irsaliye_ids:array<int,int>,doc_ids:array<int,string>,despatch_nolar:array<int,string>,
     *   toplam_kisi:int,eksik:array<int,string>,birim:float,parasut_id:string,
     *   tevkifat_kodu:string,tevkifat_oran:?float,vade_gun:int}
     */
    public function faturaKalemler(int $customerId, string $bas, string $son): array
    {
        $c = $this->repo->customer($customerId);
        $bos = ['kalemler' => [], 'irsaliye_ids' => [], 'doc_ids' => [], 'despatch_nolar' => [],
            'toplam_kisi' => 0, 'eksik' => [], 'birim' => 0.0, 'parasut_id' => '',
            'tevkifat_kodu' => '', 'tevkifat_oran' => null, 'vade_gun' => 1];
        if ($c === null) {
            return $bos;
        }
        $rows = $this->repo->faturaAdayIrsaliyeler($customerId, $bas, $son);
        $sums = ['ogle' => 0, 'aksam' => 0, 'kumanya' => 0];
        $irsaliyeIds = $docIds = $nolar = [];
        foreach ($rows as $r) {
            $irsaliyeIds[] = (int) $r['id'];
            if (($r['parasut_doc_id'] ?? '') !== '') {
                $docIds[] = (string) $r['parasut_doc_id'];
            }
            if (($r['despatch_no'] ?? '') !== '') {
                $nolar[] = (string) $r['despatch_no'];
            }
            foreach (json_decode((string) ($r['kalemler'] ?? '[]'), true) ?: [] as $k) {
                $og = (string) ($k['ogun'] ?? '');
                if (isset($sums[$og])) {
                    $sums[$og] += (int) ($k['miktar'] ?? 0);
                }
            }
        }
        $birim = $this->repo->priceFor($customerId, substr($son, 0, 7))['unit_price'];
        $kalemler = [];
        $eksik = [];
        $toplam = 0;
        foreach (self::URUN_AYAR as $ogun => $ayarKey) {
            $m = (int) $sums[$ogun];
            if ($m <= 0) {
                continue;
            }
            $urunId = trim((string) $this->repo->ayar($ayarKey, ''));
            if ($urunId === '') {
                $eksik[] = $ogun;
                continue;
            }
            $kalemler[] = ['ogun' => $ogun, 'urun_id' => $urunId, 'miktar' => $m, 'birim' => $birim];
            $toplam += $m;
        }
        return [
            'kalemler' => $kalemler, 'irsaliye_ids' => $irsaliyeIds, 'doc_ids' => $docIds,
            'despatch_nolar' => $nolar, 'toplam_kisi' => $toplam, 'eksik' => $eksik, 'birim' => $birim,
            'parasut_id' => trim((string) ($c['parasut_id'] ?? '')),
            'tevkifat_kodu' => trim((string) ($c['tevkifat_kodu'] ?? '')),
            'tevkifat_oran' => $c['tevkifat_oran'] !== null ? (float) $c['tevkifat_oran'] : null,
            'vade_gun' => (int) ($c['fatura_vade_gun'] ?? 1),
        ];
    }

    /**
     * Satış faturası JSON:API gövdesi (PÜR — ağ yok; kuru deneme ekranı bunu gösterir).
     * @param array<int,array{ogun:string,urun_id:string,miktar:int,birim:float}> $kalemler
     * @param array<int,string> $docIds dönemin irsaliye belge id'leri (bağ; ayarla kapatılabilir)
     */
    public function faturaGovde(string $parasutId, string $bas, string $son, array $kalemler,
        int $vadeGun, ?string $tevkifatKodu, ?float $tevkifatOran, array $docIds = []): array
    {
        $vat = $this->faturaKdvOrani();
        $vade = date('Y-m-d', strtotime($son . ' +' . max(0, $vadeGun) . ' day'));
        $details = [];
        foreach ($kalemler as $k) {
            $attr = [
                'quantity'    => (int) $k['miktar'],
                'unit_price'  => (float) $k['birim'],
                'vat_rate'    => $vat,
                'description' => self::OGUN_ETIKET[$k['ogun']] ?? $k['ogun'],
            ];
            if ($tevkifatKodu !== null && $tevkifatKodu !== '' && $tevkifatOran !== null && $tevkifatOran > 0) {
                $attr['vat_withholding_rate'] = $tevkifatOran;
            }
            $details[] = [
                'type'          => 'sales_invoice_details',
                'attributes'    => $attr,
                'relationships' => [
                    'product' => ['data' => ['id' => (string) $k['urun_id'], 'type' => 'products']],
                ],
            ];
        }
        $rel = [
            'contact' => ['data' => ['id' => $parasutId, 'type' => 'contacts']],
            'details' => ['data' => $details],
        ];
        // shipment_documents bağı: ayardan açıksa ve belge id'leri varsa gönder (best-effort, spec dışı).
        $bagla = trim((string) $this->repo->ayar('fatura_irsaliye_bagla', '1'));
        if ($bagla !== '' && $bagla !== '0' && $docIds) {
            $rel['shipment_documents'] = ['data' => array_map(
                static fn(string $id): array => ['id' => $id, 'type' => 'shipment_documents'],
                array_values(array_unique($docIds))
            )];
        }
        return ['data' => [
            'type'       => 'sales_invoices',
            'attributes' => [
                'item_type'         => 'invoice',
                'issue_date'        => $son,
                'due_date'          => $vade,
                'shipment_included' => true,
                'description'       => date('d.m.Y', strtotime($bas)) . ' - ' . date('d.m.Y', strtotime($son))
                    . ' dönemi yemek hizmet bedeli',
                'print_note'        => $this->faturaNotu(),
            ],
            'relationships' => $rel,
        ]];
    }

    /**
     * fable-061 (Ömer, 31 Tem): "faturada IBAN görünsün." Paraşüt'te banka hesabı kayıtlı
     * olmasına rağmen e-Fatura PDF'ine basılmıyordu (TALAY UY02026000000132 PDF'inde IBAN YOK
     * — kanıtlandı). Çözüm: fatura gövdesine `print_note` — PDF'te "Not" alanında görünür.
     * Metin `ayar.fatura_notu`'ndan gelir (koda gömülü DEĞİL; Ömer ayarlardan değiştirebilir).
     */
    private function faturaNotu(): string
    {
        return trim((string) $this->repo->ayar('fatura_notu', ''));
    }

    /** Aylık tek-kalem fatura gövdesi (tevkifat YOK, irsaliye bağı YOK). */
    public function aylikGovde(string $parasutId, string $son, int $adet, float $birim, int $vadeGun, string $etiket = 'Yemek hizmet bedeli'): array
    {
        $vat = $this->faturaKdvOrani();
        $vade = date('Y-m-d', strtotime($son . ' +' . max(0, $vadeGun) . ' day'));
        $detail = [
            'type'       => 'sales_invoice_details',
            'attributes' => [
                'quantity'    => $adet,
                'unit_price'  => $birim,
                'vat_rate'    => $vat,
                'description' => $etiket,
            ],
        ];
        $urun = trim((string) $this->repo->ayar('irsaliye_urun_ogle', ''));
        if ($urun !== '') {
            $detail['relationships'] = ['product' => ['data' => ['id' => $urun, 'type' => 'products']]];
        }
        return ['data' => [
            'type'       => 'sales_invoices',
            'attributes' => [
                'item_type'   => 'invoice',
                'issue_date'  => $son,
                'due_date'    => $vade,
                'description' => date('m.Y', strtotime($son)) . ' dönemi yemek hizmet bedeli',
                'print_note'  => $this->faturaNotu(),
            ],
            'relationships' => [
                'contact' => ['data' => ['id' => $parasutId, 'type' => 'contacts']],
                'details' => ['data' => [$detail]],
            ],
        ]];
    }

    /**
     * fable-065: SABİT kalem fatura gövdesi (tek kalem, 1 adet, kalemin KENDİ KDV oranı).
     * Tevkifat YOK, irsaliye bağı YOK. issue_date = ayın SON günü, due_date = +vade.
     * `print_note` = ayar.fatura_notu (IBAN — fable-061).
     */
    public function sabitGovde(string $contactId, string $son, string $urunId, float $birim,
        float $kdvOrani, int $vadeGun, string $ad): array
    {
        $vade = date('Y-m-d', strtotime($son . ' +' . max(0, $vadeGun) . ' day'));
        $detail = [
            'type'       => 'sales_invoice_details',
            'attributes' => [
                'quantity'    => 1,
                'unit_price'  => $birim,
                'vat_rate'    => $kdvOrani,
                'description' => $ad,
            ],
        ];
        if ($urunId !== '') {
            $detail['relationships'] = ['product' => ['data' => ['id' => $urunId, 'type' => 'products']]];
        }
        return ['data' => [
            'type'       => 'sales_invoices',
            'attributes' => [
                'item_type'   => 'invoice',
                'issue_date'  => $son,
                'due_date'    => $vade,
                'description' => date('m.Y', strtotime($son)) . ' dönemi ' . $ad,
                'print_note'  => $this->faturaNotu(),
            ],
            'relationships' => [
                'contact' => ['data' => ['id' => $contactId, 'type' => 'contacts']],
                'details' => ['data' => [$detail]],
            ],
        ]];
    }

    /**
     * e-Fatura resmileştirme gövdesi. Tevkifatlıysa her kalem için vat_withholding_params.
     * @param array<int,int> $detailIds fatura kalem id'leri (create yanıtından)
     */
    public function eFaturaGovde(string $faturaId, string $alias, array $detailIds, ?string $tevkifatKodu): array
    {
        $attr = ['scenario' => 'commercial'];
        if ($alias !== '') {
            $attr['to'] = $alias;
        }
        if ($tevkifatKodu !== null && $tevkifatKodu !== '') {
            $params = [];
            foreach ($detailIds as $did) {
                $params[] = ['detail_id' => (int) $did, 'vat_withholding_code' => $tevkifatKodu];
            }
            if ($params) {
                $attr['vat_withholding_params'] = $params;
            }
        }
        return ['data' => [
            'type'          => 'e_invoices',
            'attributes'    => $attr,
            'relationships' => ['invoice' => ['data' => ['id' => $faturaId, 'type' => 'sales_invoices']]],
        ]];
    }

    /**
     * KURU DENEME (irsaliyeli) — hiçbir ağ çağrısı yapmadan gövde + tutar + engelleri hesaplar.
     * @return array{ok:bool,durum:string,mesaj:string,kalemler:array,hesap:array,toplam:int,
     *   despatch_nolar:array,vade:string,govde:?array}
     */
    public function faturaOnizleme(int $customerId, string $bas, string $son): array
    {
        $on = $this->faturaOnKontrol($customerId, $bas, $son);
        if (!$on['ok']) {
            return $on + ['kalemler' => [], 'hesap' => self::faturaHesap([], $this->faturaKdvOrani(), null),
                'toplam' => 0, 'despatch_nolar' => [], 'vade' => '', 'govde' => null];
        }
        $k = $on['kalem'];
        $hesap = self::faturaHesap($k['kalemler'], $this->faturaKdvOrani(), $k['tevkifat_oran']);
        $vade = date('Y-m-d', strtotime($son . ' +' . max(0, $k['vade_gun']) . ' day'));
        return [
            'ok' => true, 'durum' => 'hazir', 'mesaj' => '',
            'kalemler' => $k['kalemler'], 'hesap' => $hesap, 'toplam' => $k['toplam_kisi'],
            'despatch_nolar' => $k['despatch_nolar'], 'vade' => $vade,
            'govde' => $this->faturaGovde($k['parasut_id'], $bas, $son, $k['kalemler'],
                $k['vade_gun'], $k['tevkifat_kodu'], $k['tevkifat_oran'], $k['doc_ids']),
        ];
    }

    /**
     * 🔒 SATIŞ FATURASI KES (irsaliyeli). Sıra: şalter → onay imzası → yerel kalkan →
     *    CLAIM (irsaliyeleri bu faturaya kilitle, atomik) → POST → e-Fatura → mail → log + audit.
     * @param array{onay?:string,actor?:string} $ctx
     * @return array{ok:bool,durum:string,mesaj:string,fatura_id:?string,fatura_no:?string,
     *   resmilestirme:string,mail:string,net:float,toplam:int}
     */
    public function createSalesInvoice(int $customerId, string $bas, string $son, array $ctx): array
    {
        if (!self::faturaAktif()) {
            return self::faturaSonuc(false, 'kapali', 'Fatura kesimi kapalı — Ömer onayı bekleniyor (PARASUT_FATURA_AKTIF=0).');
        }
        $imza = (string) ($ctx['onay'] ?? '');
        if ($this->onayToken === null || $this->onayToken === '' || $imza === ''
            || !hash_equals($this->onayToken, $imza)) {
            return self::faturaSonuc(false, 'onaysiz', 'Onay imzası geçersiz — fatura kesilmedi.');
        }
        if (!Helpers::isDate($bas) || !Helpers::isDate($son) || $bas > $son) {
            return self::faturaSonuc(false, 'hata', 'Geçersiz dönem.');
        }
        $on = $this->faturaOnKontrol($customerId, $bas, $son);
        if (!$on['ok']) {
            return self::faturaSonuc(false, $on['durum'], $on['mesaj']);
        }
        $k = $on['kalem'];
        $actor = (string) ($ctx['actor'] ?? '');
        $hesap = self::faturaHesap($k['kalemler'], $this->faturaKdvOrani(), $k['tevkifat_oran']);
        $kalemlerLog = array_map(static fn(array $x): array => [
            'ogun' => $x['ogun'], 'urun_id' => $x['urun_id'], 'miktar' => $x['miktar'], 'birim_fiyat' => $x['birim'],
        ], $k['kalemler']);

        // ── CLAIM FIRST: fatura kaydını 'bilinmiyor' (güvenli varsayılan) aç + irsaliyeleri kilitle ──
        $faturaLogId = $this->repo->faturaLogEkle([
            'customer_id' => $customerId, 'donem_bas' => $bas, 'donem_son' => $son, 'tip' => 'irsaliye',
            'parasut_contact_id' => $k['parasut_id'], 'kalemler' => $kalemlerLog,
            'toplam_kisi' => $k['toplam_kisi'], 'toplam_tutar' => $hesap['net'],
            'durum' => 'bilinmiyor', 'entered_by' => $actor,
        ]);
        $claimed = $this->repo->irsaliyeleriFaturayaBagla($k['irsaliye_ids'], $faturaLogId);
        if ($claimed !== count($k['irsaliye_ids'])) {
            $this->repo->irsaliyeleriFaturadanCoz($k['irsaliye_ids'], $faturaLogId);
            $this->repo->faturaLogGuncelle($faturaLogId, ['durum' => 'hata',
                'hata_mesaj' => 'Çakışma — irsaliyeler eşzamanlı başka faturaya bağlandı; kesim yapılmadı.']);
            return self::faturaSonuc(false, 'cakisma',
                'Bu irsaliyeler eşzamanlı başka bir fatura tarafından alındı — baştan seçin.');
        }

        // ── POST /sales_invoices (timeout'ta RETRY YOK; connect'te 1 tekrar) ──
        $govde = $this->faturaGovde($k['parasut_id'], $bas, $son, $k['kalemler'],
            $k['vade_gun'], $k['tevkifat_kodu'], $k['tevkifat_oran'], $k['doc_ids']);
        $r = $this->cagir('POST', '/sales_invoices?include=details', $govde);
        if ($r['net'] === 'connect') {
            $r = $this->cagir('POST', '/sales_invoices?include=details', $govde);
        }
        if ($r['net'] === 'timeout') {
            // Belge kesilmiş OLABİLİR → claim KALIR (kilitli), durum bilinmiyor, retry YOK.
            $this->repo->faturaLogGuncelle($faturaLogId, ['durum' => 'bilinmiyor',
                'hata_mesaj' => 'Zaman aşımı — fatura kesilmiş OLABİLİR. Yeniden denenmedi.']);
            uysa_audit('parasut_fatura', $actor, $son, json_encode([
                'customer_id' => $customerId, 'durum' => 'bilinmiyor', 'fatura_log_id' => $faturaLogId,
            ], JSON_UNESCAPED_UNICODE), '');
            return self::faturaSonuc(false, 'bilinmiyor',
                'Zaman aşımı — fatura kesilmiş olabilir. TEKRAR DENEMEYİN, Paraşüt\'ten kontrol edin.',
                null, null, 'yok', 'yok', $hesap['net'], $k['toplam_kisi']);
        }
        if ($r['net'] !== 'ok' || $r['status'] < 200 || $r['status'] >= 300) {
            // Kalıcı hata → claim GERİ AL (irsaliyeler tekrar aday olsun) + durum hata.
            $this->repo->irsaliyeleriFaturadanCoz($k['irsaliye_ids'], $faturaLogId);
            $mesaj = 'Paraşüt reddetti (HTTP ' . $r['status'] . ')' . ($r['error'] !== '' ? ': ' . $r['error'] : '')
                . self::apiHata($r['data']);
            $this->repo->faturaLogGuncelle($faturaLogId, ['durum' => 'hata', 'hata_mesaj' => $mesaj]);
            uysa_audit('parasut_fatura', $actor, $son, json_encode([
                'customer_id' => $customerId, 'durum' => 'hata', 'fatura_log_id' => $faturaLogId,
            ], JSON_UNESCAPED_UNICODE), '');
            return self::faturaSonuc(false, 'hata', $mesaj, null, null, 'yok', 'yok', $hesap['net'], $k['toplam_kisi']);
        }

        $doc = $r['data']['data'] ?? [];
        $faturaId = (string) ($doc['id'] ?? '');
        $faturaNo = trim((string) ($doc['attributes']['invoice_no'] ?? '')) ?: null;
        $detailIds = self::faturaDetailIds($r['data']);

        // ── e-Fatura resmileştirme (edespatch_alias varsa; tevkifat params) ──
        [$resm, $resmMsg] = $this->faturaResmilestir($customerId, $faturaId, $detailIds, $k['tevkifat_kodu']);
        // ── mail (fatura_mail dolu + resmileşti ise) ──
        [$mail, $mailMsg] = $resm === 'gonderildi'
            ? $this->faturaMailPaylas($customerId, $faturaId, $faturaNo)
            : ['yok', ''];

        $this->repo->faturaLogGuncelle($faturaLogId, [
            'parasut_fatura_id' => $faturaId !== '' ? $faturaId : null,
            'fatura_no' => $faturaNo, 'durum' => 'kesildi',
            'resmilestirme' => $resm, 'mail' => $mail,
            'hata_mesaj' => $resm !== 'gonderildi' && $resmMsg !== '' ? $resmMsg : null,
        ]);
        uysa_audit('parasut_fatura', $actor, $son, json_encode([
            'customer_id' => $customerId, 'fatura_id' => $faturaId, 'fatura_no' => $faturaNo,
            'toplam_kisi' => $k['toplam_kisi'], 'net' => $hesap['net'],
            'resmilestirme' => $resm, 'mail' => $mail, 'fatura_log_id' => $faturaLogId,
        ], JSON_UNESCAPED_UNICODE), '');

        $mesaj = 'Fatura kesildi' . ($faturaNo !== null ? " ($faturaNo)" : '') . '; '
            . ($resm === 'gonderildi' ? 'e-Fatura resmileşti.' : 'e-Fatura resmileşmedi — ' . $resmMsg);
        if ($mail === 'gonderildi') {
            $mesaj .= ' Mail gönderildi' . ($mailMsg !== '' ? " ($mailMsg)" : '') . '.';
        } elseif ($mail === 'sirada') {
            $mesaj .= ' Mail SIRADA' . ($mailMsg !== '' ? " ($mailMsg)" : '') . ' — PDF hazır olunca gönderilecek.';
        } elseif ($mail === 'hata') {
            $mesaj .= ' Mail BAŞARISIZ — Belge maili ayarlarından kontrol edin.';
        }
        return self::faturaSonuc(true, 'kesildi', $mesaj, $faturaId, $faturaNo, $resm, $mail, $hesap['net'], $k['toplam_kisi']);
    }

    /**
     * 🔒 AYLIK FATURA KES (tek contact). CANTAŞ bölüşümde UI her alt-firma için AYRI çağırır.
     * Tevkifat YOK, irsaliye bağı YOK. Mükerrer: onay imzası + aday-aşaması 'kesildi' kilidi.
     * @param array{contact_id:string,ad?:string,kisi:int} $part
     * @param array{onay?:string,actor?:string} $ctx
     */
    public function createMonthlyInvoice(int $customerId, string $bas, string $son, array $part, array $ctx): array
    {
        if (!self::faturaAktif()) {
            return self::faturaSonuc(false, 'kapali', 'Fatura kesimi kapalı — Ömer onayı bekleniyor (PARASUT_FATURA_AKTIF=0).');
        }
        $imza = (string) ($ctx['onay'] ?? '');
        if ($this->onayToken === null || $this->onayToken === '' || $imza === ''
            || !hash_equals($this->onayToken, $imza)) {
            return self::faturaSonuc(false, 'onaysiz', 'Onay imzası geçersiz — fatura kesilmedi.');
        }
        if (!Helpers::isDate($bas) || !Helpers::isDate($son) || $bas > $son) {
            return self::faturaSonuc(false, 'hata', 'Geçersiz dönem.');
        }
        $c = $this->repo->customer($customerId);
        if ($c === null) {
            return self::faturaSonuc(false, 'hata', 'Müşteri bulunamadı.');
        }
        $contactId = trim((string) ($part['contact_id'] ?? ''));
        if ($contactId === '') {
            return self::faturaSonuc(false, 'eslesme_yok', 'Paraşüt cari eşleşmesi yok — fatura kesilemez.');
        }
        $kisi = Repo::normalizePersons($part['kisi'] ?? 0);
        if ($kisi <= 0) {
            return self::faturaSonuc(false, 'kalem_yok', 'Kişi sayısı 0 — fatura kesilmez.');
        }
        $altAd = trim((string) ($part['ad'] ?? $c['name']));
        $birim = $this->repo->priceFor($customerId, substr($son, 0, 7))['unit_price'];
        $vadeGun = (int) ($c['fatura_vade_gun'] ?? 1);
        $hesap = self::faturaHesap([['miktar' => $kisi, 'birim' => $birim]], $this->faturaKdvOrani(), null);
        $actor = (string) ($ctx['actor'] ?? '');

        $faturaLogId = $this->repo->faturaLogEkle([
            'customer_id' => $customerId, 'donem_bas' => $bas, 'donem_son' => $son, 'tip' => 'aylik',
            'parasut_contact_id' => $contactId, 'alt_ad' => $altAd,
            'kalemler' => [['ogun' => 'aylik', 'urun_id' => trim((string) $this->repo->ayar('irsaliye_urun_ogle', '')), 'miktar' => $kisi, 'birim_fiyat' => $birim]],
            'toplam_kisi' => $kisi, 'toplam_tutar' => $hesap['net'],
            'durum' => 'bilinmiyor', 'entered_by' => $actor,
        ]);

        $govde = $this->aylikGovde($contactId, $son, $kisi, $birim, $vadeGun, $altAd . ' — yemek hizmet bedeli');
        $r = $this->cagir('POST', '/sales_invoices', $govde);
        if ($r['net'] === 'connect') {
            $r = $this->cagir('POST', '/sales_invoices', $govde);
        }
        if ($r['net'] === 'timeout') {
            $this->repo->faturaLogGuncelle($faturaLogId, ['durum' => 'bilinmiyor',
                'hata_mesaj' => 'Zaman aşımı — fatura kesilmiş OLABİLİR. Yeniden denenmedi.']);
            uysa_audit('parasut_fatura', $actor, $son, json_encode([
                'customer_id' => $customerId, 'alt_ad' => $altAd, 'durum' => 'bilinmiyor',
            ], JSON_UNESCAPED_UNICODE), '');
            return self::faturaSonuc(false, 'bilinmiyor',
                $altAd . ': zaman aşımı — fatura kesilmiş olabilir. TEKRAR DENEMEYİN, Paraşüt\'ten kontrol edin.',
                null, null, 'yok', 'yok', $hesap['net'], $kisi);
        }
        if ($r['net'] !== 'ok' || $r['status'] < 200 || $r['status'] >= 300) {
            $mesaj = $altAd . ': Paraşüt reddetti (HTTP ' . $r['status'] . ')' . self::apiHata($r['data']);
            $this->repo->faturaLogGuncelle($faturaLogId, ['durum' => 'hata', 'hata_mesaj' => $mesaj]);
            return self::faturaSonuc(false, 'hata', $mesaj, null, null, 'yok', 'yok', $hesap['net'], $kisi);
        }
        $doc = $r['data']['data'] ?? [];
        $faturaId = (string) ($doc['id'] ?? '');
        $faturaNo = trim((string) ($doc['attributes']['invoice_no'] ?? '')) ?: null;
        // fable-062: alt firma carisinden alias ÇÖZÜLÜP e-Fatura OTOMATİK resmileştirilir.
        // Çözülemezse (mükellef değil / API hatası) eski davranış: elle resmileştirme uyarısı.
        $altAlias = Parasut::contactAlias((string) ($part['contact_id'] ?? ''));
        $resm = 'yok';
        $resmMesaj = 'Aylık fatura — e-Fatura resmileştirme Paraşüt\'ten elle yapılır (alias tanımlı değil).';
        if ($altAlias !== null && $faturaId !== '') {
            [$resm, $resmMesaj] = $this->faturaResmilestir(
                $customerId, $faturaId, self::faturaDetailIds($r['data']), null, $altAlias
            );
        }
        $this->repo->faturaLogGuncelle($faturaLogId, [
            'parasut_fatura_id' => $faturaId !== '' ? $faturaId : null, 'fatura_no' => $faturaNo,
            'durum' => 'kesildi', 'resmilestirme' => $resm,
            'hata_mesaj' => $resm === 'gonderildi' ? null : $resmMesaj,
        ]);
        uysa_audit('parasut_fatura', $actor, $son, json_encode([
            'customer_id' => $customerId, 'alt_ad' => $altAd, 'fatura_id' => $faturaId,
            'fatura_no' => $faturaNo, 'kisi' => $kisi, 'net' => $hesap['net'], 'durum' => 'kesildi',
        ], JSON_UNESCAPED_UNICODE), '');
        return self::faturaSonuc(true, 'kesildi',
            $altAd . ': fatura kesildi' . ($faturaNo !== null ? " ($faturaNo)" : '')
            . ($resm === 'gonderildi' ? ' — e-Fatura gönderildi.' : ' — ' . $resmMesaj),
            $faturaId, $faturaNo, $resm, 'yok', $hesap['net'], $kisi);
    }

    /**
     * 🔒 fable-065 — SABİT AYLIK KALEM FATURASI KES (BOMİ personel hizmeti gibi).
     * createMonthlyInvoice deseninin BİREBİR aynısı; farkı: kalem üretimden değil
     * `musteri_sabit_fatura`'dan gelir, KDV kalemin KENDİ oranıdır (hizmet %20 · yemek %10),
     * miktar daima 1, issue_date ayın SON günüdür.
     *
     * Kapılar (sırayla, hiçbiri atlanmaz): şalter → onay imzası → ay kapandı mı →
     * kalem/cari/ürün doğrulaması → MÜKERRER KALKANI (o ayın log kaydı) → CLAIM (log) → POST.
     * Kalkana takılırsa HİÇBİR HTTP çağrısı yapılmaz (Temmuz elle kesildi: UY02026000000145).
     *
     * @param string $ay 'YYYY-MM'
     * @param array{onay?:string,actor?:string} $ctx
     */
    public function createFixedInvoice(int $kalemId, string $ay, array $ctx): array
    {
        if (!self::faturaAktif()) {
            return self::faturaSonuc(false, 'kapali', 'Fatura kesimi kapalı — Ömer onayı bekleniyor (PARASUT_FATURA_AKTIF=0).');
        }
        $imza = (string) ($ctx['onay'] ?? '');
        if ($this->onayToken === null || $this->onayToken === '' || $imza === ''
            || !hash_equals($this->onayToken, $imza)) {
            return self::faturaSonuc(false, 'onaysiz', 'Onay imzası geçersiz — fatura kesilmedi.');
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $ay)) {
            return self::faturaSonuc(false, 'hata', 'Geçersiz dönem.');
        }
        $bas = $ay . '-01';
        $son = date('Y-m-t', strtotime($bas));
        $kalem = $this->repo->sabitFaturaKalem($kalemId);
        if ($kalem === null) {
            return self::faturaSonuc(false, 'hata', 'Sabit kalem bulunamadı.');
        }
        if (!$kalem['aktif']) {
            return self::faturaSonuc(false, 'kapsam_disi', $kalem['ad'] . ': kalem pasif — fatura kesilmez.');
        }
        $customerId = $kalem['customer_id'];
        $c = $this->repo->customer($customerId);
        if ($c === null) {
            return self::faturaSonuc(false, 'hata', 'Müşteri bulunamadı.');
        }
        // fable-056 kuralı burada da geçerli: ay KAPANMADAN kesilmez (UI'yi atlayan istek de durur).
        if (Helpers::today() < $son) {
            return self::faturaSonuc(false, 'ay_kapanmadi', 'Aylık fatura ay kapanınca kesilir — '
                . date('d.m.Y', strtotime($son)) . ' tarihinde açılır.');
        }
        $musteriCari = trim((string) ($c['parasut_id'] ?? ''));
        $contactId = $kalem['parasut_contact_id'] !== '' ? $kalem['parasut_contact_id'] : $musteriCari;
        if ($contactId === '') {
            return self::faturaSonuc(false, 'eslesme_yok', 'Paraşüt cari eşleşmesi yok — fatura kesilemez.');
        }
        if ($kalem['parasut_product_id'] === '') {
            return self::faturaSonuc(false, 'hata', 'Paraşüt ürün eşleşmesi yok — müşteri kartından ürün id girin.');
        }
        if ($kalem['birim_fiyat'] <= 0) {
            return self::faturaSonuc(false, 'kalem_yok', 'Birim fiyat girilmemiş — fatura kesilmez.');
        }
        // 🔒 MÜKERRER KALKANI — ağa çıkmadan ÖNCE.
        $onceki = $this->repo->sabitFaturaKesim($kalemId, $ay);
        if ($onceki !== null) {
            return self::faturaSonuc(false, $onceki['durum'] === 'bilinmiyor' ? 'bilinmiyor' : 'zaten_kesildi',
                $onceki['durum'] === 'bilinmiyor'
                    ? $kalem['ad'] . ': önceki fatura denemesi belirsiz — Paraşüt\'ten kontrol edin, TEKRAR DENEMEYİN.'
                    : $kalem['ad'] . ': bu ay zaten kesildi'
                        . ($onceki['fatura_no'] !== '' ? ' (' . $onceki['fatura_no'] . ')' : '') . ' — mükerrer fatura kesilmedi.');
        }

        $ad = $kalem['ad'];
        $birim = $kalem['birim_fiyat'];
        $kdv = $kalem['kdv_orani'];
        $vadeGun = (int) ($c['fatura_vade_gun'] ?? 1);
        // Tutarın TEK kaynağı Repo::sabitFaturaHesap — aday ekranı da kesim de aynı hesabı okur.
        $hesap = Repo::sabitFaturaHesap($birim, $kdv);
        $actor = (string) ($ctx['actor'] ?? '');

        $faturaLogId = $this->repo->faturaLogEkle([
            'customer_id' => $customerId, 'donem_bas' => $bas, 'donem_son' => $son, 'tip' => 'sabit',
            'sabit_kalem_id' => $kalemId, 'parasut_contact_id' => $contactId, 'alt_ad' => $ad,
            'kalemler' => [['ogun' => 'sabit', 'urun_id' => $kalem['parasut_product_id'],
                'miktar' => 1, 'birim_fiyat' => $birim]],
            'toplam_kisi' => 1, 'toplam_tutar' => $hesap['net'],
            'durum' => 'bilinmiyor', 'entered_by' => $actor,
        ]);

        $govde = $this->sabitGovde($contactId, $son, $kalem['parasut_product_id'], $birim, $kdv, $vadeGun, $ad);
        $r = $this->cagir('POST', '/sales_invoices?include=details', $govde);
        if ($r['net'] === 'connect') {
            $r = $this->cagir('POST', '/sales_invoices?include=details', $govde);
        }
        if ($r['net'] === 'timeout') {
            // Belge kesilmiş OLABİLİR → kayıt 'bilinmiyor' KALIR (kalkan bir daha kesilmesini engeller).
            $this->repo->faturaLogGuncelle($faturaLogId, ['durum' => 'bilinmiyor',
                'hata_mesaj' => 'Zaman aşımı — fatura kesilmiş OLABİLİR. Yeniden denenmedi.']);
            uysa_audit('parasut_fatura', $actor, $son, json_encode([
                'customer_id' => $customerId, 'sabit_kalem_id' => $kalemId, 'alt_ad' => $ad,
                'durum' => 'bilinmiyor', 'fatura_log_id' => $faturaLogId,
            ], JSON_UNESCAPED_UNICODE), '');
            return self::faturaSonuc(false, 'bilinmiyor',
                $ad . ': zaman aşımı — fatura kesilmiş olabilir. TEKRAR DENEMEYİN, Paraşüt\'ten kontrol edin.',
                null, null, 'yok', 'yok', $hesap['net'], 1);
        }
        if ($r['net'] !== 'ok' || $r['status'] < 200 || $r['status'] >= 300) {
            $mesaj = $ad . ': Paraşüt reddetti (HTTP ' . $r['status'] . ')' . self::apiHata($r['data']);
            $this->repo->faturaLogGuncelle($faturaLogId, ['durum' => 'hata', 'hata_mesaj' => $mesaj]);
            uysa_audit('parasut_fatura', $actor, $son, json_encode([
                'customer_id' => $customerId, 'sabit_kalem_id' => $kalemId, 'durum' => 'hata',
                'fatura_log_id' => $faturaLogId,
            ], JSON_UNESCAPED_UNICODE), '');
            return self::faturaSonuc(false, 'hata', $mesaj, null, null, 'yok', 'yok', $hesap['net'], 1);
        }

        $doc = $r['data']['data'] ?? [];
        $faturaId = (string) ($doc['id'] ?? '');
        $faturaNo = trim((string) ($doc['attributes']['invoice_no'] ?? '')) ?: null;
        $detailIds = self::faturaDetailIds($r['data']);

        // e-Fatura resmileştirme. Kalem BAŞKA bir cariye kesiliyorsa alias o cariden ÇÖZÜLÜR
        // (fable-062); çözülemezse müşterinin alias'ına DÜŞÜLMEZ — yanlış kutuya e-Fatura gitmesin.
        if ($contactId !== $musteriCari) {
            $alias = Parasut::contactAlias($contactId);
            [$resm, $resmMesaj] = $alias === null
                ? ['yok', 'alıcı e-Fatura kutusu (alias) çözülemedi — Paraşüt\'ten elle resmileştirin.']
                : $this->faturaResmilestir($customerId, $faturaId, $detailIds, null, $alias);
        } else {
            [$resm, $resmMesaj] = $this->faturaResmilestir($customerId, $faturaId, $detailIds, null);
        }
        // Mail: resmileşmişse müşterinin fatura_mail adresine (kuyruk — fable-052). Boşsa girmez.
        [$mail, $mailMsg] = $resm === 'gonderildi'
            ? $this->faturaMailPaylas($customerId, $faturaId, $faturaNo)
            : ['yok', ''];

        $this->repo->faturaLogGuncelle($faturaLogId, [
            'parasut_fatura_id' => $faturaId !== '' ? $faturaId : null, 'fatura_no' => $faturaNo,
            'durum' => 'kesildi', 'resmilestirme' => $resm, 'mail' => $mail,
            'hata_mesaj' => $resm !== 'gonderildi' && $resmMesaj !== '' ? $resmMesaj : null,
        ]);
        uysa_audit('parasut_fatura', $actor, $son, json_encode([
            'customer_id' => $customerId, 'sabit_kalem_id' => $kalemId, 'alt_ad' => $ad,
            'fatura_id' => $faturaId, 'fatura_no' => $faturaNo, 'net' => $hesap['net'],
            'resmilestirme' => $resm, 'mail' => $mail, 'durum' => 'kesildi', 'fatura_log_id' => $faturaLogId,
        ], JSON_UNESCAPED_UNICODE), '');

        $mesaj = $ad . ': fatura kesildi' . ($faturaNo !== null ? " ($faturaNo)" : '') . '; '
            . ($resm === 'gonderildi' ? 'e-Fatura resmileşti.' : 'e-Fatura resmileşmedi — ' . $resmMesaj);
        if ($mail === 'gonderildi') {
            $mesaj .= ' Mail gönderildi' . ($mailMsg !== '' ? " ($mailMsg)" : '') . '.';
        } elseif ($mail === 'sirada') {
            $mesaj .= ' Mail SIRADA' . ($mailMsg !== '' ? " ($mailMsg)" : '') . ' — PDF hazır olunca gönderilecek.';
        } elseif ($mail === 'hata') {
            $mesaj .= ' Mail BAŞARISIZ — Belge maili ayarlarından kontrol edin.';
        }
        return self::faturaSonuc(true, 'kesildi', $mesaj, $faturaId, $faturaNo, $resm, $mail, $hesap['net'], 1);
    }

    /**
     * e-Fatura resmileştirme (POST /e_invoices → trackable_job). Fatura ZATEN kesilmiş — burada
     * ne olursa olsun fatura geri alınmaz; başarısızlıkta kullanıcı elle resmileştirir.
     * @param array<int,int> $detailIds
     * @return array{0:string,1:string} [resmilestirme(gonderildi|hata|yok), mesaj]
     */
    private function faturaResmilestir(int $customerId, string $faturaId, array $detailIds, ?string $tevkifatKodu, ?string $aliasOverride = null): array
    {
        if ($faturaId === '') {
            return ['hata', 'fatura id alınamadı — Paraşüt\'ten elle resmileştirin.'];
        }
        $musteri = null;
        try {
            $musteri = $this->repo->customer($customerId);
        } catch (\Throwable) {
        }
        // fable-062: alt firma faturasında alias müşteri kartından değil, ALT FİRMA carisinden
        // türetilir (Parasut::contactAlias) — alt firmalar customers'ta olmadığı için eskiden
        // alias bulunamıyor ve fatura TASLAKTA kalıyordu (Marmara/Gebze Palet 30 Tem dersi).
        $alias = $aliasOverride !== null ? trim($aliasOverride) : trim((string) ($musteri['edespatch_alias'] ?? ''));
        if ($alias === '') {
            return ['yok', 'alıcı e-Fatura kutusu (alias) tanımlı değil — Paraşüt\'ten elle resmileştirin.'];
        }
        $r = $this->cagir('POST', '/e_invoices', $this->eFaturaGovde($faturaId, $alias, $detailIds, $tevkifatKodu));
        if ($r['net'] !== 'ok' || $r['status'] < 200 || $r['status'] >= 300) {
            return ['hata', 'e-Fatura isteği kabul edilmedi (HTTP ' . $r['status'] . ')' . self::apiHata($r['data'])
                . ' — Paraşüt\'ten elle resmileştirin.'];
        }
        $jobId = (string) ($r['data']['data']['id'] ?? '');
        $sonHata = '';
        for ($i = 0; $i < 4 && $jobId !== ''; $i++) {
            $this->bekle(4);
            $j = $this->cagir('GET', '/trackable_jobs/' . rawurlencode($jobId), null);
            $st = (string) ($j['data']['data']['attributes']['status'] ?? '');
            if ($st === 'done') {
                break;
            }
            if ($st === 'error') {
                $err = $j['data']['data']['attributes']['errors'] ?? [];
                $sonHata = mb_substr(json_encode($err, JSON_UNESCAPED_UNICODE), 0, 160);
                break;
            }
        }
        if ($sonHata !== '') {
            return ['hata', 'GİB e-Fatura reddetti: ' . $sonHata . ' — Paraşüt\'ten elle resmileştirin.'];
        }
        // Doğrula: sales_invoice active_e_document oluştu mu (varsayım değil, ölçüm).
        $d = $this->cagir('GET', '/sales_invoices/' . rawurlencode($faturaId) . '?include=active_e_document', null);
        $rel = $d['data']['data']['relationships']['active_e_document']['data'] ?? null;
        if (is_array($rel) && ($rel['id'] ?? '') !== '') {
            return ['gonderildi', ''];
        }
        return ['hata', 'resmileştirme sonucu doğrulanamadı — Paraşüt\'ten kontrol edin.'];
    }

    /**
     * Faturayı müşterinin fatura_mail adres(ler)ine paylaş.
     * fable-052: `paylasim_yontemi` = 'uysa_mail' ise kuyruğa yazılır (tek PDF, UYSA SMTP);
     * 'parasut' ise aşağıdaki eski sharings akışı çalışır (ZIP gider — rollback yolu).
     * @return array{0:string,1:string} [mail(gonderildi|sirada|hata|yok), adresler]
     */
    private function faturaMailPaylas(int $customerId, string $faturaId, ?string $faturaNo): array
    {
        $musteri = null;
        try {
            $musteri = $this->repo->customer($customerId);
        } catch (\Throwable) {
        }
        $adresler = trim((string) ($musteri['fatura_mail'] ?? ''));
        if ($adresler === '' || $faturaId === '') {
            return ['yok', ''];
        }
        if ($this->paylasimYontemi() === 'uysa_mail') {
            return $this->uysaMailKuyruguna('fatura', $customerId, $faturaId, $adresler, $faturaNo, null);
        }
        $konu = 'Fatura' . ($faturaNo !== null ? ' ' . $faturaNo : '') . ' — UYSA YEMEK';
        $r = $this->cagir('POST', '/sharings', ['data' => [
            'type'       => 'sharing_forms',
            'attributes' => [
                'email'  => [
                    'addresses' => $adresler,
                    'subject'   => $konu,
                    'body'      => 'Faturanız ektedir. İyi çalışmalar dileriz.',
                ],
                'portal' => ['has_online_collection' => false, 'has_online_payment_reminder' => false, 'has_referral_link' => false],
            ],
            'relationships' => ['shareable' => ['data' => ['id' => $faturaId, 'type' => 'sales_invoices']]],
        ]]);
        if ($r['net'] !== 'ok' || $r['status'] < 200 || $r['status'] >= 300) {
            return ['hata', ''];
        }
        return ['gonderildi', $adresler];
    }

    /**
     * fable-052 şalteri. Ayar okunamazsa (migration henüz uygulanmadıysa) VARSAYILAN 'uysa_mail'
     * DEĞİL 'parasut' döner — yani tablo yoksa eski, çalışan akış korunur (sessiz veri kaybı yok).
     */
    private function paylasimYontemi(): string
    {
        try {
            return $this->repo->paylasimYontemi();
        } catch (\Throwable $e) {
            error_log('[UYSA v2 fable-052] paylasim_yontemi okunamadı, eski akışa düşüldü: ' . $e->getMessage());
            return 'parasut';
        }
    }

    /**
     * Belgeyi mail kuyruğuna al + kesim anında BİR kez göndermeyi dene (normal akışta gecikme
     * olmasın). PDF henüz hazır değilse 'sirada' döner; cron tekrar dener.
     * 🔒 Burada ne olursa olsun BELGE GERİ ALINMAZ — mail hatası kesimi çökertmez.
     * @return array{0:string,1:string} [mail durumu, adresler]
     */
    private function uysaMailKuyruguna(
        string $tur,
        int $customerId,
        string $kaynakId,
        string $adresler,
        ?string $belgeNo,
        ?string $gun
    ): array {
        try {
            $e = $this->repo->mailKuyrugaEkle($tur, $customerId, $kaynakId, $adresler, $belgeNo, $gun);
        } catch (\Throwable $ex) {
            error_log('[UYSA v2 fable-052] mail kuyruğuna yazılamadı: ' . $ex->getMessage());
            return ['hata', ''];
        }
        if (empty($e['ok'])) {
            return ['hata', ''];
        }
        if (($e['durum'] ?? '') === 'gonderildi') {
            return ['gonderildi', $adresler]; // mükerrer kalkanı: aynı belge ikinci kez maillenmez
        }
        try {
            $r = ($this->mailIsle)((int) $e['id']);
            if ((int) ($r['gonderildi'] ?? 0) > 0) {
                return ['gonderildi', $adresler];
            }
        } catch (\Throwable $ex) {
            error_log('[UYSA v2 fable-052] kuyruk ilk denemesi başarısız: ' . $ex->getMessage());
        }
        return ['sirada', $adresler];
    }

    /** create yanıtından fatura kalem (detail) id'lerini çıkar (e-Fatura tevkifat params için). */
    private static function faturaDetailIds(array $data): array
    {
        $ids = [];
        foreach (($data['data']['relationships']['details']['data'] ?? []) as $d) {
            $id = (int) ($d['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids) {
            return $ids;
        }
        foreach (($data['included'] ?? []) as $inc) {
            if (($inc['type'] ?? '') === 'sales_invoice_details') {
                $id = (int) ($inc['id'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        return $ids;
    }

    /**
     * Fatura (irsaliyeli) ağ öncesi tüm yerel engeller tek yerde (önizleme ve kesim AYNI kural).
     * @return array{ok:bool,durum:string,mesaj:string,kalem:array}
     */
    private function faturaOnKontrol(int $customerId, string $bas, string $son): array
    {
        $red = static fn(string $d, string $m): array => ['ok' => false, 'durum' => $d, 'mesaj' => $m, 'kalem' => []];
        if (!Helpers::isDate($bas) || !Helpers::isDate($son) || $bas > $son) {
            return $red('hata', 'Geçersiz dönem.');
        }
        $c = $this->repo->customer($customerId);
        if ($c === null) {
            return $red('hata', 'Müşteri bulunamadı.');
        }
        if ((int) ($c['irsaliye_aktif'] ?? 1) !== 1) {
            return $red('kapsam_disi', 'Bu müşteri aylık faturadan gidiyor (irsaliyeli değil).');
        }
        $k = $this->faturaKalemler($customerId, $bas, $son);
        if ($k['parasut_id'] === '') {
            return $red('eslesme_yok', 'Paraşüt eşleşmesi yok — fatura kesilemez.');
        }
        if ($k['eksik']) {
            $adlar = array_map(static fn($o) => self::OGUN_ETIKET[$o] ?? $o, $k['eksik']);
            return $red('hata', 'Ürün eşlemesi eksik: ' . implode(', ', $adlar) . ' (ayarlardan tanımlayın).');
        }
        if (!$k['kalemler'] || $k['toplam_kisi'] <= 0) {
            return $red('faturalanacak_yok', 'Bu dönemde faturalanmamış irsaliye yok.');
        }
        $kilit = $this->repo->faturaKilidi($customerId, $bas, $son);
        if ($kilit !== null) {
            return $red('bilinmiyor', $kilit);
        }
        return ['ok' => true, 'durum' => 'hazir', 'mesaj' => '', 'kalem' => $k];
    }

    private static function faturaSonuc(bool $ok, string $durum, string $mesaj, ?string $faturaId = null,
        ?string $faturaNo = null, string $resmilestirme = 'yok', string $mail = 'yok',
        float $net = 0.0, int $toplam = 0): array
    {
        return [
            'ok' => $ok, 'durum' => $durum, 'mesaj' => $mesaj, 'fatura_id' => $faturaId,
            'fatura_no' => $faturaNo, 'resmilestirme' => $resmilestirme, 'mail' => $mail,
            'net' => $net, 'toplam' => $toplam,
        ];
    }
}
