<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Entity;

use App\Ingestion\Entity\NormalizationIssue;
use App\Ingestion\Enum\NormalizationIssueKind;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class NormalizationIssueTest extends TestCase
{
    public function testMarkResolvedIsIdempotent(): void
    {
        $issue = new NormalizationIssue(
            companyId: Uuid::uuid7()->toString(),
            rawRecordId: Uuid::uuid7()->toString(),
            operationGroupId: Uuid::uuid7()->toString(),
            kind: NormalizationIssueKind::SUM_MISMATCH,
            details: ['expected' => 100, 'actual' => 99],
        );
        $resolvedAt = new \DateTimeImmutable('2026-06-18 12:00:00');

        $issue->markResolved($resolvedAt);
        $issue->markResolved(new \DateTimeImmutable('2026-06-19 12:00:00'));

        self::assertSame($resolvedAt, $issue->getResolvedAt());
        self::assertSame(['expected' => 100, 'actual' => 99], $issue->getDetails());
    }
}
