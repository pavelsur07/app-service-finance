<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Repository;

use App\Marketplace\Entity\MarketplaceListingTag;
use App\Marketplace\Repository\MarketplaceListingTagRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class MarketplaceListingTagRepositoryInsertIfAbsentTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000985';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-000000000986';

    public function testInsertsNewTag(): void
    {
        $repository = $this->repository();

        $repository->insertIfAbsent($this->tag(self::COMPANY_ID, 'Зима'));

        self::assertSame('Зима', $repository->findBySlug(self::COMPANY_ID, 'зима')?->getName());
    }

    public function testKeepsExistingTagWithSameSlugWithoutError(): void
    {
        $repository = $this->repository();
        $first = $this->tag(self::COMPANY_ID, 'Зима');
        $repository->insertIfAbsent($first);

        $repository->insertIfAbsent($this->tag(self::COMPANY_ID, ' ЗИМА '));

        self::assertSame(1, $this->countTags(self::COMPANY_ID));
        self::assertSame($first->getId(), $repository->findBySlug(self::COMPANY_ID, 'зима')?->getId());
    }

    public function testSameSlugInAnotherCompanyIsNotAConflict(): void
    {
        $repository = $this->repository();
        $repository->insertIfAbsent($this->tag(self::OTHER_COMPANY_ID, 'Зима'));

        $repository->insertIfAbsent($this->tag(self::COMPANY_ID, 'Зима'));

        self::assertSame(1, $this->countTags(self::COMPANY_ID));
    }

    private function tag(string $companyId, string $name): MarketplaceListingTag
    {
        return new MarketplaceListingTag(Uuid::uuid7()->toString(), $companyId, $name);
    }

    private function countTags(string $companyId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_listing_tags WHERE company_id = ?',
            [$companyId],
        );
    }

    private function repository(): MarketplaceListingTagRepository
    {
        return self::getContainer()->get(MarketplaceListingTagRepository::class);
    }
}
