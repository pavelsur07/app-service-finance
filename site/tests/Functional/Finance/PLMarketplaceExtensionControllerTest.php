<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Enum\BusinessProfile;
use App\Finance\Application\Action\EnsureDefaultPLTreeAction;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class PLMarketplaceExtensionControllerTest extends WebTestCaseBase
{
    public function testUniversalCompanyPreviewsAndAddsMarketplaceLines(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->company(BusinessProfile::UNIVERSAL);
        $this->login($client, $user, $company);

        $crawler = $client->request('GET', '/pl-categories/import?source=marketplace');
        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-marketplace-extension]');
        self::assertStringContainsString('REV_SALES_TOTAL + REV_NOT_SPP_TOTAL', $card->text());
        self::assertStringContainsString('COGS_MP_COMMISSION', $card->text());

        $client->request('POST', '/pl-categories/import/marketplace-extension', ['_token' => $this->csrfToken($client, 'pl-marketplace-extension'.(string) $company->getId())]);
        self::assertResponseRedirects('/pl-categories/');
        self::assertSame(68, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pl_categories WHERE company_id = ?', [(string) $company->getId()]));

        $crawler = $client->request('GET', '/pl-categories/import?source=marketplace');
        self::assertStringContainsString('Статьи маркетплейса уже есть', $crawler->filter('[data-marketplace-extension]')->text());
    }

    public function testApplyRequiresCsrfToken(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->company(BusinessProfile::UNIVERSAL);
        $this->login($client, $user, $company);

        $client->request('POST', '/pl-categories/import/marketplace-extension', ['_token' => 'bad']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(44, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pl_categories WHERE company_id = ?', [(string) $company->getId()]));
    }

    public function testMarketplaceCompanyHasNoCardAndApplyIsRefused(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->company(BusinessProfile::MARKETPLACE);
        $this->login($client, $user, $company);

        $crawler = $client->request('GET', '/pl-categories/import');
        self::assertCount(0, $crawler->filter('[data-marketplace-extension]'));

        $client->request('POST', '/pl-categories/import/marketplace-extension', ['_token' => $this->csrfToken($client, 'pl-marketplace-extension'.(string) $company->getId())]);
        self::assertResponseRedirects('/pl-categories/import?source=marketplace');
        $client->followRedirect();
        self::assertSelectorTextContains('.toast.text-bg-danger', 'уже есть');
    }

    public function testConnectionsPageHintsUniversalCompanyWithoutMarketplaceLines(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->company(BusinessProfile::UNIVERSAL);
        $connection = new MarketplaceConnection(Uuid::uuid7()->toString(), $company, MarketplaceType::OZON);
        $connection->setApiKey('test-key');
        $this->em()->persist($connection);
        $this->em()->flush();
        $this->login($client, $user, $company);

        $crawler = $client->request('GET', '/marketplace/connections');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-pl-extension-hint] a[href="/pl-categories/import?source=marketplace"]'));

        $client->request('POST', '/pl-categories/import/marketplace-extension', ['_token' => $this->csrfToken($client, 'pl-marketplace-extension'.(string) $company->getId())]);
        $crawler = $client->request('GET', '/marketplace/connections');
        self::assertCount(0, $crawler->filter('[data-pl-extension-hint]'));
    }

    public function testConnectionsPageHasNoHintForMarketplaceCompany(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->company(BusinessProfile::MARKETPLACE);
        $connection = new MarketplaceConnection(Uuid::uuid7()->toString(), $company, MarketplaceType::OZON);
        $connection->setApiKey('test-key');
        $this->em()->persist($connection);
        $this->em()->flush();
        $this->login($client, $user, $company);

        $crawler = $client->request('GET', '/marketplace/connections');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-pl-extension-hint]'));
    }

    /**
     * @return array{User, Company}
     */
    private function company(BusinessProfile $profile): array
    {
        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $company->setBusinessProfile($profile);
        $this->em()->persist($user);
        $this->em()->persist($company);
        $this->em()->flush();
        (static::getContainer()->get(EnsureDefaultPLTreeAction::class))((string) $company->getId());

        return [$user, $company];
    }

    private function login(KernelBrowser $client, User $user, Company $company): void
    {
        $this->em()->clear();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }
}
