<?php

declare(strict_types=1);

namespace App\Tests\Integration\BankSync;

use App\BankSync\Domain\BankAccess;
use App\BankSync\Enum\BankCode;
use App\BankSync\Exception\BankChannelNotConfiguredException;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaApiClient;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaTlsConfig;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Канал Альфы в контейнере: без сертификата в окружении (как в тестах и на проде до
 * Stage 11) приложение собирается, падает только запрос к Альфе — понятной ошибкой.
 */
final class AlfaChannelWiringTest extends KernelTestCase
{
    public function testAlfaClientWithoutCertificateFailsWithConfigurationError(): void
    {
        self::bootKernel();
        /** @var AlfaApiClient $client */
        $client = self::getContainer()->get(AlfaApiClient::class);

        try {
            $client->getAccounts(new BankAccess('connection-id', BankCode::ALFA, 'https://sandbox.alfabank.ru', 'token'));
            self::fail('request without client certificate must not reach the bank');
        } catch (BankChannelNotConfiguredException $exception) {
            self::assertStringContainsString(AlfaTlsConfig::CERT_PATH_ENV.' is not set', $exception->getMessage());
        }
    }

    public function testOtherClientsDoNotGetAlfaCertificate(): void
    {
        self::bootKernel();
        $moySklad = self::getContainer()->get('moysklad.http_client');

        $options = (new \ReflectionProperty($moySklad, 'defaultOptions'))->getValue($moySklad);
        self::assertIsArray($options);
        self::assertNull($options['local_cert']);
        self::assertNull($options['cafile']);
    }
}
