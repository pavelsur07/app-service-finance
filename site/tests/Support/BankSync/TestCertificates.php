<?php

declare(strict_types=1);

namespace App\Tests\Support\BankSync;

/**
 * Самоподписанные сертификаты и ключи для тестов канала банка — во временной папке.
 */
final class TestCertificates
{
    private string $dir;

    public function __construct()
    {
        $this->dir = sys_get_temp_dir().'/bank-sync-tls-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }

    /**
     * @return array{cert: string, key: string, certPem: string}
     */
    public function issue(string $name, int $days, string $passphrase = ''): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        if (!$key instanceof \OpenSSLAsymmetricKey) {
            throw self::failure();
        }
        $csr = openssl_csr_new(['commonName' => $name], $key);
        if (!$csr instanceof \OpenSSLCertificateSigningRequest) {
            throw self::failure();
        }
        $certificate = openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256'], random_int(1, \PHP_INT_MAX));
        if (!$certificate instanceof \OpenSSLCertificate) {
            throw self::failure();
        }

        openssl_x509_export($certificate, $certPem);
        openssl_pkey_export($key, $keyPem, '' === $passphrase ? null : $passphrase);

        return [
            'cert' => $this->write($name.'.crt', $certPem),
            'key' => $this->write($name.'.key', $keyPem),
            'certPem' => $certPem,
        ];
    }

    public function write(string $file, string $contents): string
    {
        $path = $this->dir.'/'.$file;
        file_put_contents($path, $contents);

        return $path;
    }

    public function remove(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private static function failure(): \RuntimeException
    {
        return new \RuntimeException('OpenSSL test fixture failed: '.openssl_error_string());
    }
}
