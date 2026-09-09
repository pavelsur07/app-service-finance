<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Application\RecordConnectionAuthResultAction;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Сломанный ключ виден в кабинете.
 *
 * До этой задачи он не был виден нигде: страница подключений показывала ошибку
 * только у выключенного подключения, а у подключения с отвергнутым ключом
 * `is_active` остаётся истинным. Пользователь видел «Активно» и дату последней
 * синхронизации, пока данные молча не грузились.
 */
final class BrokenConnectionVisibilityTest extends WebTestCaseBase
{
    public function testConnectionsPageShowsRejectedKeyAndOffersToUpdateIt(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company, $connection] = $this->seedBrokenConnection();
        $this->loginWithActiveCompany($client, $user, $company);

        $crawler = $client->request('GET', '/marketplace/connections');

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString('Ключ не принимается', $html);
        self::assertStringContainsString('Загрузка данных остановлена', $html);
        self::assertStringContainsString('Обновить ключ', $html);
        self::assertStringNotContainsString('>Активно<', $html);
    }

    /**
     * Ручная синхронизация по мёртвому ключу упадёт так же, как автоматическая.
     * Оставить кнопку рабочей значило бы предложить действие, заведомо
     * заканчивающееся ошибкой.
     */
    public function testManualSyncIsDisabledForRejectedKey(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBrokenConnection();
        $this->loginWithActiveCompany($client, $user, $company);

        $crawler = $client->request('GET', '/marketplace/connections');

        $disabled = $crawler->filter('button.btn-primary[disabled]')->count();
        self::assertGreaterThan(0, $disabled, 'Кнопка ручной синхронизации обязана быть недоступна');
    }

    public function testHealthyConnectionShowsNoWarning(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedConnection(false);
        $this->loginWithActiveCompany($client, $user, $company);

        $crawler = $client->request('GET', '/marketplace/connections');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Ключ не принимается', $crawler->html());
    }

    public function testDashboardWarnsAboutRejectedKey(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBrokenConnection();
        $this->loginWithActiveCompany($client, $user, $company);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString('Загрузка данных остановлена', $html);
        self::assertStringContainsString('ключ не принимается', $html);
        self::assertStringContainsString('/marketplace/connections', $html);
    }

    public function testDashboardStaysCleanWhenEveryKeyWorks(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedConnection(false);
        $this->loginWithActiveCompany($client, $user, $company);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Загрузка данных остановлена', $crawler->html());
    }

    /**
     * Кнопки в разметке недостаточно: страница могла быть открыта до того, как
     * ключ отвергли, и её CSRF-токен всё ещё действителен. Запрет обязан
     * держать сервер, иначе ручной запуск по мёртвому ключу продолжает
     * порождать заведомо падающие задания.
     */
    public function testManualSyncEndpointRefusesRejectedKeyEvenWithValidToken(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company, $connection] = $this->seedBrokenConnection();
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', sprintf('/marketplace/connection/%s/sync', $connection->getId()), [
            '_token' => $this->csrfToken($client, 'sync'.$connection->getId()),
        ]);

        self::assertResponseRedirects('/marketplace/connections');
        $client->followRedirect();
        self::assertStringContainsString(
            'маркетплейс не принимает ключ',
            $client->getResponse()->getContent() ?: '',
        );
    }

    /**
     * Интерфейс не показывает ни кода ответа маркетплейса, ни куска ключа:
     * первое пользователю ничего не говорит, второе — утечка секрета в разметку.
     */
    public function testWarningLeaksNeitherHttpStatusNorKeyMaterial(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company, $connection] = $this->seedBrokenConnection();
        $this->loginWithActiveCompany($client, $user, $company);

        $crawler = $client->request('GET', '/dashboard');

        // Проверяется текст самого предупреждения, а не вся страница: в
        // разметке есть посторонние URL и числа, и по ним ассерт падал бы
        // ложно, ничего не говоря о содержании сообщения.
        $alert = $crawler->filter('.alert-danger');
        self::assertCount(1, $alert);
        $text = $alert->text();

        self::assertStringNotContainsStringIgnoringCase('http', $text);
        self::assertStringNotContainsString('403', $text);
        self::assertStringNotContainsString('401', $text);
        self::assertStringNotContainsString($connection->getApiKey(), $text);
        self::assertStringNotContainsString(substr($connection->getApiKey(), 0, 8), $text);
    }

    /**
     * @return array{0: User, 1: Company, 2: MarketplaceConnection}
     */
    private function seedBrokenConnection(): array
    {
        [$user, $company, $connection] = $this->seedConnection(true);

        return [$user, $company, $connection];
    }

    /**
     * @return array{0: User, 1: Company, 2: MarketplaceConnection}
     */
    private function seedConnection(bool $broken): array
    {
        $user = UserBuilder::aUser()->withEmail('broken-connection-visibility@test.local')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();

        $connection = new MarketplaceConnection(
            Uuid::uuid4()->toString(),
            $company,
            MarketplaceType::OZON,
            MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('secret-ozon-api-key-value');
        $connection->setClientId('ozon-client-id');

        $this->em()->persist($user);
        $this->em()->persist($company);
        $this->em()->persist($connection);
        $this->em()->flush();

        if ($broken) {
            $recordAuthResult = static::getContainer()->get(RecordConnectionAuthResultAction::class);
            for ($i = 0; $i < RecordConnectionAuthResultAction::AUTH_FAILURE_THRESHOLD; ++$i) {
                $recordAuthResult->recordFailure((string) $company->getId(), $connection->getId());
            }
            $this->em()->refresh($connection);
        }

        return [$user, $company, $connection];
    }

    private function loginWithActiveCompany(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }
}
