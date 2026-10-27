<?php

declare(strict_types=1);

namespace App\Tests\Support\BankSync;

/**
 * Ключ подписи «банка» для тестов `id_token`: пара RSA и самоподписанный сертификат в
 * памяти, подпись JWS RS256.
 */
final class TestIdTokenSigner
{
    public readonly \OpenSSLCertificate $certificate;
    public readonly string $certificatePem;
    private \OpenSSLAsymmetricKey $key;

    public function __construct(int $days = 365)
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        if (!$key instanceof \OpenSSLAsymmetricKey) {
            throw self::failure();
        }
        $csr = openssl_csr_new(['commonName' => 'id-token-signer'], $key);
        if (!$csr instanceof \OpenSSLCertificateSigningRequest) {
            throw self::failure();
        }
        $certificate = openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256'], random_int(1, \PHP_INT_MAX));
        if (!$certificate instanceof \OpenSSLCertificate) {
            throw self::failure();
        }
        openssl_x509_export($certificate, $pem);

        $this->key = $key;
        $this->certificate = $certificate;
        $this->certificatePem = $pem;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $header
     */
    public function sign(array $payload, array $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'alfa-test-jwt']): string
    {
        $signingInput = self::encode($header).'.'.self::encode($payload);
        openssl_sign($signingInput, $signature, $this->key, \OPENSSL_ALGO_SHA256);

        return $signingInput.'.'.self::base64Url($signature);
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function encode(array $value): string
    {
        return self::base64Url(json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function failure(): \RuntimeException
    {
        return new \RuntimeException('OpenSSL test fixture failed: '.openssl_error_string());
    }
}
