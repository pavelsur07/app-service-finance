<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\Bank\Alfa;

use App\BankSync\Application\DTO\BankConnectionFormData;
use App\BankSync\Exception\BankAuthorizationNotConfiguredException;
use App\BankSync\Infrastructure\Security\PemCertificates;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Пара приложения Alfa ID и сертификат проверки подписи токенов — по среде: у песочницы
 * и прода свои `client_id`, `client_secret` и ключ подписи (в песочнице `kid`
 * `alfa-test-jwt`, на проде — `cer.crt` «certificate-prod»). JWKS у банка нет: смена
 * ключа банком — новый файл сертификата без релиза.
 *
 * Среда без любой из трёх переменных не настроена: кнопки входа нет (прод до Stage 11).
 * `default::` — переменной может не быть в окружении контейнера вовсе.
 */
final readonly class AlfaIdConfig
{
    public const BANK_CODE = 'alfa';
    public const SANDBOX_CLIENT_ID_ENV = 'ALFA_SANDBOX_CLIENT_ID';
    public const SANDBOX_CLIENT_SECRET_ENV = 'ALFA_SANDBOX_CLIENT_SECRET';
    public const SANDBOX_SIGNING_CERT_PATH_ENV = 'ALFA_SANDBOX_ID_SIGNING_CERT_PATH';
    public const PROD_CLIENT_ID_ENV = 'ALFA_PROD_CLIENT_ID';
    public const PROD_CLIENT_SECRET_ENV = 'ALFA_PROD_CLIENT_SECRET';
    public const PROD_SIGNING_CERT_PATH_ENV = 'ALFA_PROD_ID_SIGNING_CERT_PATH';

    /**
     * Издатель `id_token`: документация банка даёт оба вида; точный — проверка в песочнице
     * (Stage 7), после неё лишнее убирается.
     */
    private const ENVIRONMENTS = [
        BankConnectionFormData::SANDBOX_URL => [
            'name' => 'sandbox',
            'authorize_url' => 'https://id-sandbox.alfabank.ru/oidc/authorize',
            'issuers' => ['https://id-sandbox.alfabank.ru/oidc', 'https://id-sandbox.alfabank.ru', 'https://id.alfabank.ru/oidc', 'https://id.alfabank.ru'],
            'env' => [self::SANDBOX_CLIENT_ID_ENV, self::SANDBOX_CLIENT_SECRET_ENV, self::SANDBOX_SIGNING_CERT_PATH_ENV],
        ],
        BankConnectionFormData::PRODUCTION_URL => [
            'name' => 'production',
            'authorize_url' => 'https://id.alfabank.ru/oidc/authorize',
            'issuers' => ['https://id.alfabank.ru/oidc', 'https://id.alfabank.ru'],
            'env' => [self::PROD_CLIENT_ID_ENV, self::PROD_CLIENT_SECRET_ENV, self::PROD_SIGNING_CERT_PATH_ENV],
        ],
    ];

    /** @var array<string, array{0: string, 1: string, 2: string}> client_id, secret, путь сертификата по базовому URL */
    private array $values;

    public function __construct(
        #[Autowire('%env(default::'.self::SANDBOX_CLIENT_ID_ENV.')%')]
        ?string $sandboxClientId,
        #[Autowire('%env(default::'.self::SANDBOX_CLIENT_SECRET_ENV.')%')]
        #[\SensitiveParameter]
        ?string $sandboxClientSecret,
        #[Autowire('%env(default::'.self::SANDBOX_SIGNING_CERT_PATH_ENV.')%')]
        ?string $sandboxSigningCertPath,
        #[Autowire('%env(default::'.self::PROD_CLIENT_ID_ENV.')%')]
        ?string $prodClientId,
        #[Autowire('%env(default::'.self::PROD_CLIENT_SECRET_ENV.')%')]
        #[\SensitiveParameter]
        ?string $prodClientSecret,
        #[Autowire('%env(default::'.self::PROD_SIGNING_CERT_PATH_ENV.')%')]
        ?string $prodSigningCertPath,
    ) {
        $this->values = [
            BankConnectionFormData::SANDBOX_URL => [trim($sandboxClientId ?? ''), $sandboxClientSecret ?? '', trim($sandboxSigningCertPath ?? '')],
            BankConnectionFormData::PRODUCTION_URL => [trim($prodClientId ?? ''), $prodClientSecret ?? '', trim($prodSigningCertPath ?? '')],
        ];
    }

    /**
     * Базовые URL сред, где заданы все три переменные (файл здесь не читается).
     *
     * @return list<string>
     */
    public function configuredBaseUrls(): array
    {
        $configured = [];
        foreach ($this->values as $baseUrl => [$clientId, $clientSecret, $certPath]) {
            if ('' !== $clientId && '' !== $clientSecret && '' !== $certPath) {
                $configured[] = $baseUrl;
            }
        }

        return $configured;
    }

    /**
     * Файлы сертификатов подписи по имени среды — для гейта сроков; незаданные — null.
     *
     * @return array<string, string|null>
     */
    public function signingCertificatePaths(): array
    {
        $paths = [];
        foreach (self::ENVIRONMENTS as $baseUrl => $environment) {
            $path = $this->values[$baseUrl][2];
            $paths[$environment['name']] = '' === $path ? null : $path;
        }

        return $paths;
    }

    /**
     * @throws BankAuthorizationNotConfiguredException среда неизвестна, переменная не задана,
     *                                                 файл сертификата не читается
     */
    public function environment(string $baseUrl): AlfaIdEnvironment
    {
        $environment = self::ENVIRONMENTS[$baseUrl] ?? throw new BankAuthorizationNotConfiguredException(self::BANK_CODE, 'unknown environment');
        [$clientId, $clientSecret, $certPath] = $this->values[$baseUrl];
        [$clientIdEnv, $clientSecretEnv, $certPathEnv] = $environment['env'];
        foreach ([$clientIdEnv => $clientId, $clientSecretEnv => $clientSecret, $certPathEnv => $certPath] as $env => $value) {
            if ('' === $value) {
                throw new BankAuthorizationNotConfiguredException(self::BANK_CODE, $env.' is not set');
            }
        }

        $certificates = PemCertificates::parse(PemCertificates::readFile($certPath) ?? '');
        if (null === $certificates) {
            throw new BankAuthorizationNotConfiguredException(self::BANK_CODE, sprintf('file from %s is not readable or not a complete PEM certificate set', $certPathEnv));
        }

        return new AlfaIdEnvironment(
            $environment['name'],
            $baseUrl,
            $environment['authorize_url'],
            $clientId,
            $clientSecret,
            $certificates,
            $environment['issuers'],
        );
    }
}
