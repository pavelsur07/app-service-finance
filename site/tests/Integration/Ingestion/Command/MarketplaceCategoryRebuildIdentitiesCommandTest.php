<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Ingestion\Application\Source\Ozon\OzonAccrualCategoryTaxonomyResolver;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\ExternalCategory;
use App\Ingestion\Enum\ExternalCategoryStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Repository\ExternalCategoryRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MarketplaceCategoryRebuildIdentitiesCommandTest extends IntegrationTestCase
{
    public function testRebuildsCodeLikeExternalNameIntoSemanticIdentity(): void
    {
        $category = new ExternalCategory(
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            scope: OzonAccrualCategoryTaxonomyResolver::SCOPE_NON_ITEM,
            normalizedKey: 'type:999',
            externalTypeId: '999',
            externalName: 'RfbsServiceFee',
            status: ExternalCategoryStatus::NEW,
        );
        $this->em->persist($category);
        $this->em->flush();

        $dryRun = $this->tester();
        self::assertSame(Command::SUCCESS, $dryRun->execute([]));
        $this->em->clear();
        self::assertNull($this->findCategory('code:rfbsservicefee'));

        $execute = $this->tester();
        self::assertSame(Command::SUCCESS, $execute->execute(['--execute' => true]));
        $this->em->clear();

        $rebuilt = $this->findCategory('code:rfbsservicefee');
        self::assertInstanceOf(ExternalCategory::class, $rebuilt);
        self::assertSame('RfbsServiceFee', $rebuilt->getExternalCode());
    }

    private function tester(): CommandTester
    {
        $app = new Application(self::$kernel);

        return new CommandTester($app->find('app:ingestion:marketplace-categories:rebuild-identities'));
    }

    private function findCategory(string $normalizedKey): ?ExternalCategory
    {
        /** @var ExternalCategoryRepository $repository */
        $repository = self::getContainer()->get(ExternalCategoryRepository::class);

        return $repository->findByIdentity(
            IngestSource::OZON,
            OzonResourceType::ACCRUAL_BY_DAY,
            OzonAccrualCategoryTaxonomyResolver::SCOPE_NON_ITEM,
            $normalizedKey,
        );
    }
}
