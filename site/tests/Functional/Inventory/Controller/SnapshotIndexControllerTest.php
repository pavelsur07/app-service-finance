<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inventory\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Inventory\InventorySnapshotSessionBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class SnapshotIndexControllerTest extends WebTestCaseBase
{
    public function testPageIsAvailableForAuthorizedUserAndShowsOnlyOwnCompanyRows(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();

        $owner = UserBuilder::aUser()->withEmail('inventory-owner@example.test')->build();
        $activeCompany = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-111111111701')->withOwner($owner)->build();
        $otherCompany = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-111111111702')->withOwner($owner)->build();

        $ownSession = InventorySnapshotSessionBuilder::aSession()
            ->withCompanyId($activeCompany->getId())
            ->withSource(MarketplaceType::OZON)
            ->build();
        $ownSession->markCompleted();

        $foreignSession = InventorySnapshotSessionBuilder::aSession()
            ->withCompanyId($otherCompany->getId())
            ->withSource(MarketplaceType::WILDBERRIES)
            ->build();
        $foreignSession->markFailed('foreign');

        $em->persist($owner);
        $em->persist($activeCompany);
        $em->persist($otherCompany);
        $em->persist($ownSession);
        $em->persist($foreignSession);
        $em->flush();

        $this->loginWithActiveCompany($client, $owner, $activeCompany);

        $crawler = $client->request('GET', '/inventory/snapshots');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('table tbody tr')->count());
        self::assertStringContainsString('История загрузок', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('OZON', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('WILDBERRIES', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Получить остатки', (string) $client->getResponse()->getContent());
        self::assertGreaterThan(0, $crawler->filter('button[disabled]')->count());

        $headers = $crawler->filter('table thead th')->each(static fn ($node) => trim((string) $node->text()));
        self::assertSame(['Дата', 'Маркетплейс', 'Статус'], $headers);
    }

    public function testPaginationWorksWithThirtyItemsPerPage(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();
        $owner = UserBuilder::aUser()->withEmail('inventory-pagination@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-111111111703')->withOwner($owner)->build();

        $em->persist($owner);
        $em->persist($company);

        for ($i = 1; $i <= 31; ++$i) {
            $session = InventorySnapshotSessionBuilder::aSession()
                ->withCompanyId($company->getId())
                ->withCorrelationId(sprintf('33333333-3333-7333-8333-%012d', $i + 3000))
                ->build();
            if ($i % 2 === 0) {
                $session->markCompleted();
            } elseif ($i % 3 === 0) {
                $session->markPartial('partial');
            } else {
                $session->markFailed('failed');
            }
            $em->persist($session);
        }

        $em->flush();
        $this->loginWithActiveCompany($client, $owner, $company);

        $crawlerPage1 = $client->request('GET', '/inventory/snapshots?page=1');
        self::assertResponseIsSuccessful();
        self::assertSame(30, $crawlerPage1->filter('table tbody tr')->count());
        self::assertGreaterThan(0, $crawlerPage1->filter('.pagination')->count());

        $crawlerPage2 = $client->request('GET', '/inventory/snapshots?page=2');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawlerPage2->filter('table tbody tr')->count());
    }

    public function testEmptyStateIsShownWhenNoSessions(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();
        $owner = UserBuilder::aUser()->withEmail('inventory-empty@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-111111111704')->withOwner($owner)->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $this->loginWithActiveCompany($client, $owner, $company);
        $client->request('GET', '/inventory/snapshots');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Загрузок пока нет.', (string) $client->getResponse()->getContent());
    }

    private function loginWithActiveCompany(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $company->getId());
        $session->save();
    }
}
