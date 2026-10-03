<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

/**
 * У «Реализации» нет статуса конвейера (`processing_status`), и колонка «Обработка» в истории документов была пустой
 * даже у применённых отчётов. Применена ли «Реализация», видно по созданным строкам.
 */
final class MarketplaceIndexRealizationBadgeTest extends WebTestCaseBase
{
    public function testRealizationDocumentsShowWhetherTheyWereApplied(): void
    {
        $client = static::createClient();

        $owner = UserBuilder::aUser()->withEmail('realization-badge@example.test')->withRoles(['ROLE_COMPANY_OWNER'])->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $applied = MarketplaceRawDocumentBuilder::aDocument()->forCompany($company)->withMarketplace(MarketplaceType::OZON)
            ->withDocumentType('realization')->withPeriod(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'))->build();
        $applied->setRecordsCount(100);
        $applied->setRecordsCreated(100);
        $notApplied = MarketplaceRawDocumentBuilder::aDocument()->forCompany($company)->withMarketplace(MarketplaceType::OZON)
            ->withDocumentType('realization')->withPeriod(new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-31'))->build();
        $notApplied->setRecordsCount(100);
        foreach ([$owner, $company, $applied, $notApplied] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/marketplace');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="realization-applied"]'));
        self::assertSame('Применена', trim($crawler->filter('[data-testid="realization-applied"]')->text()));
        self::assertCount(1, $crawler->filter('[data-testid="realization-not-applied"]'));
        self::assertSame('Не применена', trim($crawler->filter('[data-testid="realization-not-applied"]')->text()));
    }
}
