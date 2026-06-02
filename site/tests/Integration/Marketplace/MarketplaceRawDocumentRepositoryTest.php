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

    public function testFindActiveExactPeriodMethodsFilterByEndpointAndExcludeFailed(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $this->em->persist($company);

        $activeOlder = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withPeriod(new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-01'))
            ->withIndex(1)
            ->build();
        $activeOlder->setApiEndpoint('wildberries::reportDetailByPeriod');
        $this->em->persist($activeOlder);

        $activeNewest = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withPeriod(new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-01'))
            ->withIndex(2)
            ->build();
        $activeNewest->setApiEndpoint('wildberries::reportDetailByPeriod');
        $activeNewest->markCompleted();
        $this->em->persist($activeNewest);

        $failed = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withPeriod(new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-01'))
            ->withIndex(3)
            ->build();
        $failed->setApiEndpoint('wildberries::reportDetailByPeriod');
        $failed->markStepFailed(\App\Marketplace\Enum\PipelineStep::SALES);
        $this->em->persist($failed);

        $otherEndpoint = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withPeriod(new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-01'))
            ->withIndex(4)
            ->build();
        $otherEndpoint->setApiEndpoint('wildberries::supplierSales');
        $this->em->persist($otherEndpoint);

        $this->em->flush();

        /** @var MarketplaceRawDocumentRepository $repository */
        $repository = self::getContainer()->get(MarketplaceRawDocumentRepository::class);

        $documents = $repository->findActiveExactPeriodDocuments(
            company: $company,
            marketplace: MarketplaceType::WILDBERRIES,
            documentType: 'sales_report',
            apiEndpoint: 'wildberries::reportDetailByPeriod',
            periodFrom: new \DateTimeImmutable('2026-04-01'),
            periodTo: new \DateTimeImmutable('2026-04-01'),
        );

        self::assertCount(2, $documents);
        self::assertSame('22222222-2222-2222-2222-000000000002', $documents[0]->getId());
        self::assertSame('22222222-2222-2222-2222-000000000001', $documents[1]->getId());

        $single = $repository->findActiveExactPeriodDocument(
            company: $company,
            marketplace: MarketplaceType::WILDBERRIES,
            documentType: 'sales_report',
            apiEndpoint: 'wildberries::reportDetailByPeriod',
            periodFrom: new \DateTimeImmutable('2026-04-01'),
            periodTo: new \DateTimeImmutable('2026-04-01'),
        );

        self::assertNotNull($single);
        self::assertSame('22222222-2222-2222-2222-000000000002', $single->getId());
    }

    public function testFindActiveExactDayDocumentsIgnoresEndpointAndOrdersCompletedCanonicalFirst(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $this->em->persist($company);
        $day = new \DateTimeImmutable('2026-04-02');

        $pendingNewest = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withPeriod($day, $day)
            ->withApiEndpoint('wildberries::new-endpoint')
            ->withIndex(10)
            ->build();
        $pendingNewest->resetProcessingStatus();
        $this->forceDateTime($pendingNewest, 'syncedAt', new \DateTimeImmutable('2026-04-02 12:00:00'));
        $this->em->persist($pendingNewest);

        $completedOlder = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withPeriod($day, $day)
            ->withApiEndpoint('wildberries::old-endpoint')
            ->withIndex(11)
            ->build();
        $completedOlder->markCompleted();
        $this->forceDateTime($completedOlder, 'processedAt', new \DateTimeImmutable('2026-04-02 10:00:00'));
        $this->forceDateTime($completedOlder, 'syncedAt', new \DateTimeImmutable('2026-04-02 10:00:00'));
        $this->em->persist($completedOlder);

        $failed = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withPeriod($day, $day)
            ->withApiEndpoint('wildberries::failed-endpoint')
            ->withIndex(12)
            ->build();
        $failed->markFailed();
        $this->em->persist($failed);

        $this->em->flush();

        /** @var MarketplaceRawDocumentRepository $repository */
        $repository = self::getContainer()->get(MarketplaceRawDocumentRepository::class);
        $documents = $repository->findActiveExactDayDocuments(
            $company,
            MarketplaceType::WILDBERRIES,
            'sales_report',
            $day,
        );

        self::assertCount(2, $documents);
        self::assertSame($completedOlder->getId(), $documents[0]->getId());
        self::assertSame($pendingNewest->getId(), $documents[1]->getId());
    }

    private function forceDateTime(object $object, string $property, \DateTimeImmutable $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }

}

