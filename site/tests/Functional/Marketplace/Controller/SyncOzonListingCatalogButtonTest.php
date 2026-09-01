<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceJobLog;
use App\Marketplace\Enum\JobType;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\SyncOzonListingCatalogMessage;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class SyncOzonListingCatalogButtonTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000951';
    private const CONNECTION_ID = '66666666-6666-4666-8666-000000000951';

    public function testButtonIsRenderedOnListingsPage(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seed($client);

        $crawler = $client->request('GET', '/marketplace/listings');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $crawler->filter('form[action="/marketplace/listings/sync-ozon-catalog"]')->count(),
            'Кнопка запуска загрузки каталога Ozon отсутствует на странице листингов.',
        );
    }

    public function testPostDispatchesOneMessagePerActiveOzonSellerConnection(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seed($client);

        $crawler = $client->request('GET', '/marketplace/listings');
        $token = $crawler->filter('form[action="/marketplace/listings/sync-ozon-catalog"] input[name="_token"]')
            ->attr('value');

        $client->request('POST', '/marketplace/listings/sync-ozon-catalog', ['_token' => $token]);

        self::assertResponseRedirects('/marketplace/listings');

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async_sync');
        $messages = array_values(array_filter(
            array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent()),
            static fn ($message): bool => $message instanceof SyncOzonListingCatalogMessage,
        ));

        self::assertCount(1, $messages);
        self::assertSame(self::COMPANY_ID, $messages[0]->companyId);
        self::assertSame(self::CONNECTION_ID, $messages[0]->connectionId);
    }

    /**
     * Кнопка без обратной связи работает вслепую: нажал — и гадай. Страница
     * обязана показывать итог последнего прогона.
     */
    public function testLastRunResultIsShownNextToTheButton(): void
    {
        $client = static::createClient();
        $this->seed($client);

        $jobLog = new MarketplaceJobLog(
            '99999999-9999-4999-8999-000000000951',
            self::COMPANY_ID,
            JobType::LISTING_CATALOG_SYNC_OZON,
        );
        $jobLog->complete(['products_fetched' => 42, 'listings_upserted' => 44, 'raw_records_stored' => 2]);

        $em = $this->em();
        $em->persist($jobLog);
        $em->flush();

        $client->request('GET', '/marketplace/listings');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('42', (string) $client->getResponse()->getContent());
        self::assertSelectorExists('[data-testid="ozon-catalog-last-run"]');
    }

    public function testFailedRunIsVisibleOnThePage(): void
    {
        $client = static::createClient();
        $this->seed($client);

        $jobLog = new MarketplaceJobLog(
            '99999999-9999-4999-8999-000000000952',
            self::COMPANY_ID,
            JobType::LISTING_CATALOG_SYNC_OZON,
        );
        $jobLog->fail('RuntimeException');

        $em = $this->em();
        $em->persist($jobLog);
        $em->flush();

        $client->request('GET', '/marketplace/listings');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="ozon-catalog-last-run"]', 'ошибк');
    }

    /**
     * Без CSRF-токена запрос обязан быть отвергнут: иначе кнопку можно
     * нажать с чужой страницы.
     */
    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        $client = static::createClient();
        $this->seed($client);

        $client->request('POST', '/marketplace/listings/sync-ozon-catalog', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * CSRF — не единственная защита. Участник компании без права записи в
     * модуль «Маркетплейс» не должен запускать выгрузку: иначе регрессия
     * атрибута доступа осталась бы незамеченной.
     */
    public function testMemberWithoutWriteAccessIsRejected(): void
    {
        $client = static::createClient();
        [, $company] = $this->seed($client);

        $role = new CompanyRole(
            Uuid::uuid4()->toString(),
            'Только чтение маркетплейса',
            [Module::MARKETPLACE->value => AccessLevel::READ->value],
            $company,
        );
        $member = UserBuilder::aUser()
            ->withIndex(77)
            ->withEmail('ozon-catalog-readonly@example.test')
            ->withRoles(['ROLE_COMPANY_USER'])
            ->build();

        $em = $this->em();
        $em->persist($role);
        $em->persist($member);
        $em->persist(
            CompanyMemberBuilder::aMember()
                ->withId(Uuid::uuid4()->toString())
                ->withCompany($company)
                ->withUser($member)
                ->withRole(CompanyMember::ROLE_OPERATOR)
                ->withStatus(CompanyMember::STATUS_ACTIVE)
                ->withAccessRole($role)
                ->build(),
        );
        $em->flush();

        $client->loginUser($member);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        // Токен берём валидный: с неверным 403 пришёл бы от CSRF-проверки, и
        // тест не доказывал бы ничего про права.
        $crawler = $client->request('GET', '/marketplace/listings');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[action="/marketplace/listings/sync-ozon-catalog"] input[name="_token"]')
            ->attr('value');

        $client->request('POST', '/marketplace/listings/sync-ozon-catalog', ['_token' => $token]);

        self::assertResponseStatusCodeSame(403);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async_sync');
        self::assertSame([], array_values(array_filter(
            array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent()),
            static fn ($message): bool => $message instanceof SyncOzonListingCatalogMessage,
        )));
    }

    /**
     * @return array{0: \App\Company\Entity\User, 1: \App\Company\Entity\Company}
     */
    private function seed(KernelBrowser $client): array
    {
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail('ozon-catalog-button@example.test')->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->withName('Catalog Button Co')
            ->build();

        $connection = new MarketplaceConnection(
            self::CONNECTION_ID,
            $company,
            MarketplaceType::OZON,
            MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('test-key')->setClientId('test-client')->setIsActive(true);

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($connection);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        return [$owner, $company];
    }
}
