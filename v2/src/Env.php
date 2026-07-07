<?php
declare(strict_types=1);

namespace Uysa;

/**
 * Basit .env yükleyici (v1 deseni). Sırayla: gerçek env > .env dosyası > default.
 * Sır koda gömülmez; hepsi .env'den okunur.
 */
final class Env
{
    private static bool $loaded = false;

    public static function load(?string $file = null): void
    {
        if (self::$loaded) {
            return;
        }
        $file ??= dirname(__DIR__) . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v, " \t\n\r\0\x0B\"'");
                if (getenv($k) === false) {
                    putenv("$k=$v");
                    $_ENV[$k] = $v;
                }
            }
        }
        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = getenv($key);
        if ($v === false || $v === '') {
            return $_ENV[$key] ?? $default;
        }
        return $v;
    }

    public static function int(string $key, int $default): int
    {
        $v = self::get($key);
        return $v === null || $v === '' ? $default : (int) $v;
    }
}
