<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class MarketplaceRawDocumentRepositoryTest extends IntegrationTestCase
{
    public function testFindMinPeriodFromForSuccessfulDocumentsUsesOnlyCompletedDocuments(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $this->em->persist($company);

        $completed = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withPeriod(new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-31'))
            ->build();
        $completed->setApiEndpoint('wildberries::reportDetailByPeriod');
        $completed->markCompleted();
        $this->em->persist($completed);

        $failed = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withPeriod(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'))
            ->build();
        $failed->setApiEndpoint('wildberries::reportDetailByPeriod');
        $failed->markStepFailed(\App\Marketplace\Enum\PipelineStep::SALES);
        $this->em->persist($failed);

        $pending = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withPeriod(new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-02-28'))
            ->build();
        $pending->setApiEndpoint('wildberries::reportDetailByPeriod');
        $pending->resetProcessingStatus();
        $this->em->persist($pending);

        $nullStatus = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withPeriod(new \DateTimeImmutable('2026-01-15'), new \DateTimeImmutable('2026-01-20'))
            ->build();
        $nullStatus->setApiEndpoint('wildberries::reportDetailByPeriod');
        $this->em->persist($nullStatus);

        $this->em->flush();

        /** @var MarketplaceRawDocumentRepository $repository */
        $repository = self::getContainer()->get(MarketplaceRawDocumentRepository::class);
        $result = $repository->findMinPeriodFromForSuccessfulDocuments(
            company: $company,
            marketplace: MarketplaceType::WILDBERRIES,
            documentType: 'sales_report',
            apiEndpoint: 'wildberries::reportDetailByPeriod',
            yearStart: new \DateTimeImmutable('2026-01-01'),
            yesterday: new \DateTimeImmutable('2026-05-01'),
        );

        self::assertNotNull($result);
        self::assertSame('2026-03-01', $result->format('Y-m-d'));
    }
}

