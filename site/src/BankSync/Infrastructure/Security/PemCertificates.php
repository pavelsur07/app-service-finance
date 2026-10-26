<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\Security;

/**
 * Чтение PEM-файла сертификатов (клиентский с цепочкой, набор CA) без утечки пути в
 * диагностику PHP: не файл, не читается, оборванный или неразборный блок — null.
 */
final class PemCertificates
{
    private const BEGIN = '-----BEGIN CERTIFICATE-----';
    private const END = '-----END CERTIFICATE-----';

    /**
     * Содержимое обычного файла; папка, нечитаемый или исчезнувший файл — null.
     */
    public static function readFile(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        // @ — диагностика PHP содержит путь (к ключу в том числе); ответ false проверяется.
        $contents = @file_get_contents($path);

        return false === $contents ? null : $contents;
    }

    /**
     * Все сертификаты файла по порядку (первый — лист). Null, если сертификатов нет,
     * число начал и концов блоков не совпадает (обрезанная цепочка) или блок не разбирается.
     *
     * @return non-empty-list<\OpenSSLCertificate>|null
     */
    public static function parse(string $pem): ?array
    {
        $begins = substr_count($pem, self::BEGIN);
        if (0 === $begins || $begins !== substr_count($pem, self::END)) {
            return null;
        }
        if ($begins !== preg_match_all('/'.preg_quote(self::BEGIN, '/').'.+?'.preg_quote(self::END, '/').'/s', $pem, $blocks)) {
            return null;
        }

        $certificates = [];
        foreach ($blocks[0] as $block) {
            // @ — openssl пишет warning о неразобранном блоке; false проверяется.
            $certificate = @openssl_x509_read($block);
            if (false === $certificate) {
                return null;
            }
            $certificates[] = $certificate;
        }

        return [] === $certificates ? null : $certificates;
    }
}
