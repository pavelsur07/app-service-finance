<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\MoySklad\Application\Action\ChangeListingMappingAction;
use App\MoySklad\Application\Command\ChangeListingMappingCommand;
use App\MoySklad\Enum\ListingMappingOperation;
use App\MoySklad\Infrastructure\Query\MoySkladCatalogTargetsQuery;
use App\MoySklad\Infrastructure\Query\MoySkladListingMappingsQuery;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\PostgresResetTestCase;
use App\Tests\Support\MoySklad\MappingFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\Process;

final class ListingMappingConcurrencyTest extends PostgresResetTestCase
{
    use MappingFixtures;

    #[DataProvider('races')]
    public function testIndependentSessionsSerializeFirstCreationAndRequestIds(string $mode, int $successes, string $rejection): void
    {
        $data = $this->mappingCatalog($this->em);
        $application = 'mapping-race-'.Uuid::uuid7()->toString();
        $first = ['company' => $data['connection']->getCompanyId(), 'connection' => $data['connection']->getId(), 'listing' => $data['listing']->getId(),
            'product' => $data['product']->getExternalId(), 'request' => Uuid::uuid7()->toString(), 'application' => $application];
        $second = $first;
        if ('stale' === $mode) {
            $second['request'] = Uuid::uuid7()->toString();
        }
        if ('other-key' === $mode) {
            $listing = MarketplaceListingBuilder::aListing()->forCompany($data['company'])->withMarketplace(\App\Marketplace\Enum\MarketplaceType::OZON)->withIndex(2)->withMarketplaceSku('OZ-002')->build();
            $this->em->persist($listing);
            $this->em->flush();
            $second['listing'] = $listing->getId();
        }
        $processes = [];
        $this->connection->beginTransaction();
        try {
            foreach (array_unique([$first['listing'], $second['listing']]) as $listingId) {
                $this->connection->fetchOne('SELECT pg_advisory_xact_lock(hashtext(?),hashtext(?))', ['moysklad-listing-mapping', $first['company'].':'.$first['connection'].':'.$listingId]);
            }
            foreach ([$first, $second] as $input) {
                $processes[] = $this->startWorker($input);
            }
            $this->assertWaiting($application, 2);
            $this->connection->commit();
            $outputs = [];
            foreach ($processes as $process) {
                self::assertSame(0, $process->wait(), $process->getErrorOutput());
                $outputs[] = $process->getOutput();
            }
            self::assertCount($successes, array_filter($outputs, static fn (string $output): bool => str_contains($output, 'success:1')));
            if ('' !== $rejection) {
                self::assertCount(1, array_filter($outputs, static fn (string $output): bool => str_contains($output, 'rejected:'.$rejection)));
            }
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
        }
    }

    /** @return iterable<string,array{string,int,string}> */
    public static function races(): iterable
    {
        yield 'first creation CAS' => ['stale', 1, 'stale_mapping'];
        yield 'same request replay' => ['replay', 2, ''];
        yield 'same request different keys' => ['other-key', 1, 'idempotency_conflict'];
    }

    public function testProductLockBlocksConcurrentVariantInsertAndLaterImportRequiresReview(): void
    {
        $data = $this->mappingCatalog($this->em);
        $company = $data['connection']->getCompanyId();
        $connection = $data['connection']->getId();
        $listing = $data['listing']->getId();
        self::getContainer()->get(ChangeListingMappingAction::class)(new ChangeListingMappingCommand($company, $data['actor'], $connection, $listing,
            ListingMappingOperation::SET, 'product', $data['product']->getExternalId(), 0, Uuid::uuid7()->toString()));
        $application = 'mapping-variant-'.Uuid::uuid7()->toString();
        $process = null;
        $this->connection->beginTransaction();
        try {
            self::assertNotNull(self::getContainer()->get(MoySkladCatalogTargetsQuery::class)->lock($company, $connection, 'product', $data['product']->getExternalId()));
            $process = $this->startWorker(['company' => $company, 'connection' => $connection, 'listing' => $listing, 'product' => $data['product']->getExternalId(),
                'request' => Uuid::uuid7()->toString(), 'application' => $application, 'mode' => 'variant']);
            $this->assertWaiting($application, 1);
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_variants'));
            $this->connection->commit();
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
            $state = self::getContainer()->get(MoySkladListingMappingsQuery::class)->currentForListings($company, $connection, [$listing])[$listing];
            self::assertSame('needs_review', $state->status);
            self::assertSame(1, $state->revision);
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            if (null !== $process && $process->isRunning()) {
                $process->stop(0);
            }
        }
    }

    /** @param array<string,string> $input */
    private function startWorker(array $input): Process
    {
        $process = new Process([\PHP_BINARY, 'tests/Fixtures/MoySklad/mapping-worker.php'], (string) self::getContainer()->getParameter('kernel.project_dir'), input: json_encode($input, \JSON_THROW_ON_ERROR), timeout: 20);
        $process->start();
        self::assertTrue($process->waitUntil(static fn (string $type, string $buffer): bool => str_contains($buffer, "ready\n")), $process->getErrorOutput());

        return $process;
    }

    private function assertWaiting(string $application, int $expected): void
    {
        $deadline = microtime(true) + 5;
        do {
            $waiting = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'", [$application]);
            if ($expected === $waiting) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::assertSame($expected, $waiting, 'Independent DB sessions must actually contend on the lock.');
    }

    public function testVariantParentShareLockSerializesConcurrentArchive(): void
    {
        $data = $this->mappingCatalog($this->em);
        $variant = \App\Tests\Builders\MoySklad\MoySkladVariantBuilder::aVariant()->forProduct($data['product'])->build();
        $this->em->persist($variant);
        $this->em->flush();
        $application = 'mapping-archive-'.Uuid::uuid7()->toString();
        $process = null;
        $this->connection->beginTransaction();
        try {
            $target = self::getContainer()->get(MoySkladCatalogTargetsQuery::class)->lock($data['connection']->getCompanyId(), $data['connection']->getId(), 'variant', $variant->getExternalId());
            self::assertNotNull($target);
            self::assertTrue($target->selectable);
            $process = $this->startWorker(['company' => $data['connection']->getCompanyId(), 'connection' => $data['connection']->getId(),
                'listing' => $data['listing']->getId(), 'product' => $data['product']->getExternalId(), 'request' => Uuid::uuid7()->toString(), 'application' => $application, 'mode' => 'archive']);
            $this->assertWaiting($application, 1);
            self::assertFalse((bool) $this->connection->fetchOne('SELECT archived FROM moysklad_products WHERE company_id=? AND connection_id=? AND external_id=?', [$data['connection']->getCompanyId(), $data['connection']->getId(), $data['product']->getExternalId()]));
            $this->connection->commit();
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
            self::assertStringContainsString('success:archive', $process->getOutput());
            self::assertFalse(self::getContainer()->get(MoySkladCatalogTargetsQuery::class)->find($data['connection']->getCompanyId(), $data['connection']->getId(), 'variant', $variant->getExternalId())?->selectable);
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            if (null !== $process && $process->isRunning()) {
                $process->stop(0);
            }
        }
    }
}
