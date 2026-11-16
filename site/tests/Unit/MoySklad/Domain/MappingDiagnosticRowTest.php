<?php

declare(strict_types=1);

namespace App\Tests\Unit\MoySklad\Domain;

use App\Marketplace\DTO\ListingMetaDTO;
use App\MoySklad\Application\DTO\AutomaticMappingCandidate;
use App\MoySklad\Application\DTO\AutomaticMappingDecision;
use App\MoySklad\Application\DTO\CatalogTargetDTO;
use App\MoySklad\Application\DTO\MappingDiagnosticRow;
use App\MoySklad\Enum\AutomaticMappingDecisionStatus as Status;
use PHPUnit\Framework\TestCase;

final class MappingDiagnosticRowTest extends TestCase
{
    public function testPackageReasonIsKeptAlongsideAmbiguity(): void
    {
        $source = new ListingMetaDTO('33333333-3333-4333-8333-333333333333', 'Набор 3 шт', 'SKU', 'ozon', '001');
        $target = new CatalogTargetDTO('product', '44444444-4444-4444-8444-444444444444', null, 'Товар 1 шт', null, '001', [], true);
        $row = new MappingDiagnosticRow($source, new AutomaticMappingDecision(Status::AMBIGUOUS, candidates: [new AutomaticMappingCandidate($target)]));
        self::assertContains('duplicate', $row->reasons);
        self::assertContains('package_review', $row->reasons);
        self::assertTrue($row->manualReview);
    }

    public function testRecordedReasonsArePreservedWhenReadingCompletedReport(): void
    {
        $source = new ListingMetaDTO('33333333-3333-4333-8333-333333333333', 'Набор 3 шт', 'SKU', 'ozon', '001');
        $target = new CatalogTargetDTO('product', '44444444-4444-4444-8444-444444444444', null, 'Товар 1 шт', null, '001', [], true);
        $row = new MappingDiagnosticRow($source, new AutomaticMappingDecision(Status::AMBIGUOUS, candidates: [new AutomaticMappingCandidate($target)]));
        $recorded = json_decode(json_encode($row, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        // A completed report produced by an earlier classifier recorded only the ambiguity reason.
        $recorded['reasons'] = ['duplicate'];
        self::assertSame(['duplicate'], MappingDiagnosticRow::fromArray($recorded)->reasons);
        self::assertTrue(MappingDiagnosticRow::fromArray($recorded)->manualReview);
    }

    public function testAmbiguousSourceQuantityIsDiagnosedEvenWithoutMatches(): void
    {
        $row = new MappingDiagnosticRow(new ListingMetaDTO('33333333-3333-4333-8333-333333333333', 'Набор 2 шт + 3 шт', 'SKU', 'ozon', '001'), new AutomaticMappingDecision(Status::NO_MATCH));
        self::assertContains('no_match', $row->reasons);
        self::assertContains('package_review', $row->reasons);
    }
}
