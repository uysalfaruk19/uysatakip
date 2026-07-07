<?php
declare(strict_types=1);

namespace Uysa;

use PDO;

/**
 * "Beni hatırla" kalıcı girişi (selector:validator deseni) — app'te çıkış yapana kadar şifre sorulmaz.
 * Validator DB'de sha256 hash'li durur (DB sızsa bile cookie üretilemez); her kullanımda token döner (rotate).
 * Tarih karşılaştırmaları PHP tarafında — MySQL/sqlite dialekt farkı yok.
 */
final class Remember
{
    private const DAYS = 180;

    private function __construct(private PDO $pdo, private string $kind, private string $cookie)
    {
    }

    public static function forCustomer(PDO $pdo): self
    {
        return new self($pdo, 'customer', 'uysa_musteri_hatirla');
    }

    public static function forAdmin(PDO $pdo): self
    {
        return new self($pdo, 'admin', 'uysa_kokpit_hatirla');
    }

    /** Girişte çağrılır: yeni token üret, DB'ye hash'ini yaz, cookie'yi bas. */
    public function issue(int $userId): void
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $this->pdo->prepare(
            'INSERT INTO remember_tokens (kind, user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->kind, $userId, $selector, hash('sha256', $validator), date('Y-m-d H:i:s', time() + self::DAYS * 86400)]);
        $this->setCookie($selector . ':' . $validator, time() + self::DAYS * 86400);
    }

    /** Cookie geçerliyse user_id döner ve token'ı YENİLER; değilse temizler, null. */
    public function consume(): ?int
    {
        $raw = (string) ($_COOKIE[$this->cookie] ?? '');
        if (!preg_match('/^([0-9a-f]{24}):([0-9a-f]{64})$/', $raw, $m)) {
            return null;
        }
        $st = $this->pdo->prepare('SELECT * FROM remember_tokens WHERE selector = ? AND kind = ?');
        $st->execute([$m[1], $this->kind]);
        $row = $st->fetch();
        if (!$row || $row['expires_at'] < date('Y-m-d H:i:s') || !hash_equals($row['validator_hash'], hash('sha256', $m[2]))) {
            if ($row) {
                $this->pdo->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([$row['id']]);
            }
            $this->setCookie('', time() - 42000);
            return null;
        }
        // Rotate: kullanılan token ölür, yenisi verilir
        $this->pdo->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([$row['id']]);
        $userId = (int) $row['user_id'];
        $this->issue($userId);
        return $userId;
    }

    /** Çıkışta çağrılır: bu cihazın token'ını DB'den sil + cookie'yi düşür. */
    public function clear(): void
    {
        $raw = (string) ($_COOKIE[$this->cookie] ?? '');
        if (preg_match('/^([0-9a-f]{24}):/', $raw, $m)) {
            $this->pdo->prepare('DELETE FROM remember_tokens WHERE selector = ? AND kind = ?')->execute([$m[1], $this->kind]);
        }
        $this->setCookie('', time() - 42000);
    }

    private function setCookie(string $value, int $expires): void
    {
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        setcookie($this->cookie, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
