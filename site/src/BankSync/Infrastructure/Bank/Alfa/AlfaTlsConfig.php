<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\Bank\Alfa;

use App\BankSync\Exception\BankChannelNotConfiguredException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Канал до API Альфы: двусторонний TLS. Клиентский сертификат и ключ — файлы вне git,
 * пути и пароль ключа — из окружения; CA серверов банка (Минцифры) — набор в репозитории.
 *
 * `cafile` вместе с `capath` на ту же папку: один `cafile` curl дополняет системным
 * хранилищем, а клиент Альфы должен доверять только CA из набора.
 *
 * Конфигурация проверяется при создании клиента, а не на запросе: иначе curl отвечал бы
 * невнятной ошибкой транспорта, которую воркер ретраил бы как недоступность банка. В
 * сообщениях ошибок — только имена переменных, не пути и не пароль.
 */
final readonly class AlfaTlsConfig
{
    public const BANK_CODE = 'alfa';
    public const CERT_PATH_ENV = 'ALFA_TLS_CERT_PATH';
    public const KEY_PATH_ENV = 'ALFA_TLS_KEY_PATH';
    public const KEY_PASSPHRASE_ENV = 'ALFA_TLS_KEY_PASSPHRASE';

    private string $certPath;
    private string $keyPath;
    private string $keyPassphrase;

    /**
     * `default::` — переменной может не быть в окружении контейнера вовсе (прод до
     * Stage 11): это «канал не настроен», а не падение сборки сервиса.
     */
    public function __construct(
        #[Autowire('%env(default::'.self::CERT_PATH_ENV.')%')]
        ?string $certPath,
        #[Autowire('%env(default::'.self::KEY_PATH_ENV.')%')]
        ?string $keyPath,
        #[Autowire('%env(default::'.self::KEY_PASSPHRASE_ENV.')%')]
        #[\SensitiveParameter]
        ?string $keyPassphrase,
        #[Autowire('%kernel.project_dir%/config/bank_sync/alfa/ca-bundle.pem')]
        private string $caBundlePath,
    ) {
        $this->certPath = $certPath ?? '';
        $this->keyPath = $keyPath ?? '';
        $this->keyPassphrase = $keyPassphrase ?? '';
    }

    /**
     * Путь к файлу клиентского сертификата или null, если канал не настроен (прод до
     * выпуска своего сертификата).
     */
    public function clientCertificatePath(): ?string
    {
        return '' === trim($this->certPath) ? null : $this->certPath;
    }

    public function caBundlePath(): string
    {
        return $this->caBundlePath;
    }

    /**
     * @return array<string, mixed> опции Symfony HttpClient
     *
     * @throws BankChannelNotConfiguredException
     */
    public function httpOptions(): array
    {
        $certificate = $this->readCertificate();
        $this->assertKeyMatches($certificate);
        if (!is_readable($this->caBundlePath)) {
            throw new BankChannelNotConfiguredException(self::BANK_CODE, 'CA bundle config/bank_sync/alfa/ca-bundle.pem is not readable');
        }

        $options = [
            'local_cert' => $this->certPath,
            'local_pk' => $this->keyPath,
            'cafile' => $this->caBundlePath,
            'capath' => \dirname($this->caBundlePath),
            'verify_peer' => true,
            'verify_host' => true,
            // Минимум TLS 1.2, как требует банк; 1.3 сервер банка согласует сам.
            'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        ];
        if ('' !== $this->keyPassphrase) {
            $options['passphrase'] = $this->keyPassphrase;
        }

        return $options;
    }

    private function readCertificate(): \OpenSSLCertificate
    {
        if (null === $this->clientCertificatePath()) {
            throw new BankChannelNotConfiguredException(self::BANK_CODE, self::CERT_PATH_ENV.' is not set');
        }
        if ('' === trim($this->keyPath)) {
            throw new BankChannelNotConfiguredException(self::BANK_CODE, self::KEY_PATH_ENV.' is not set');
        }
        $pem = is_readable($this->certPath) ? file_get_contents($this->certPath) : false;
        if (false === $pem) {
            throw new BankChannelNotConfiguredException(self::BANK_CODE, 'file from '.self::CERT_PATH_ENV.' is not readable');
        }
        // @ — openssl пишет warning о неразобранных данных; ответ — false, он и проверяется.
        $certificate = @openssl_x509_read($pem);
        if (false === $certificate) {
            throw new BankChannelNotConfiguredException(self::BANK_CODE, 'file from '.self::CERT_PATH_ENV.' is not a PEM certificate');
        }

        return $certificate;
    }

    private function assertKeyMatches(\OpenSSLCertificate $certificate): void
    {
        $pem = is_readable($this->keyPath) ? file_get_contents($this->keyPath) : false;
        if (false === $pem) {
            throw new BankChannelNotConfiguredException(self::BANK_CODE, 'file from '.self::KEY_PATH_ENV.' is not readable');
        }
        $key = @openssl_pkey_get_private($pem, $this->keyPassphrase);
        if (false === $key) {
            throw new BankChannelNotConfiguredException(self::BANK_CODE, 'key from '.self::KEY_PATH_ENV.' cannot be opened, check '.self::KEY_PASSPHRASE_ENV);
        }
        if (!openssl_x509_check_private_key($certificate, $key)) {
            throw new BankChannelNotConfiguredException(self::BANK_CODE, 'key from '.self::KEY_PATH_ENV.' does not match the certificate');
        }
    }
}
