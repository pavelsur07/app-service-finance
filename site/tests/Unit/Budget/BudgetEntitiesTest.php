<?php

declare(strict_types=1);

namespace App\Tests\Unit\Budget;

use App\Budget\Enum\BudgetLineSource;
use App\Budget\Enum\BudgetVersionStatus;
use App\Budget\Exception\InvalidBudgetVersionTransitionException;
use App\Tests\Builders\Budget\BudgetLineBuilder;
use App\Tests\Builders\Budget\BudgetVersionBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BudgetEntitiesTest extends TestCase
{
    public function testNewVersionIsEditableDraft(): void
    {
        $version = BudgetVersionBuilder::aVersion()->withYear(2027)->withName('  Базовый  ')->build();

        self::assertSame(BudgetVersionStatus::DRAFT, $version->getStatus());
        self::assertTrue($version->isEditable());
        self::assertSame(2027, $version->getYear());
        self::assertSame('Базовый', $version->getName());
    }

    public function testApproveReturnArchiveCycle(): void
    {
        $version = BudgetVersionBuilder::aVersion()->build();
        $now = new \DateTimeImmutable('2027-01-10 12:00:00');

        $version->approve($now);
        self::assertSame(BudgetVersionStatus::APPROVED, $version->getStatus());
        self::assertFalse($version->isEditable());
        self::assertSame($now, $version->getUpdatedAt());

        $version->returnToDraft($now);
        self::assertSame(BudgetVersionStatus::DRAFT, $version->getStatus());

        $version->archive($now);
        self::assertSame(BudgetVersionStatus::ARCHIVED, $version->getStatus());
        self::assertFalse($version->isEditable());

        $version->returnToDraft($now);
        self::assertSame(BudgetVersionStatus::DRAFT, $version->getStatus());
    }

    /**
     * @return iterable<string, array{BudgetVersionStatus, string}>
     */
    public static function forbiddenTransitions(): iterable
    {
        yield 'approve approved' => [BudgetVersionStatus::APPROVED, 'approve'];
        yield 'approve archived' => [BudgetVersionStatus::ARCHIVED, 'approve'];
        yield 'archive approved' => [BudgetVersionStatus::APPROVED, 'archive'];
        yield 'archive archived' => [BudgetVersionStatus::ARCHIVED, 'archive'];
        yield 'draft to draft' => [BudgetVersionStatus::DRAFT, 'returnToDraft'];
    }

    #[DataProvider('forbiddenTransitions')]
    public function testForbiddenTransitionIsRejected(BudgetVersionStatus $status, string $transition): void
    {
        $version = BudgetVersionBuilder::aVersion()->withStatus($status)->build();

        $this->expectException(InvalidBudgetVersionTransitionException::class);
        $version->{$transition}(new \DateTimeImmutable());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidYears(): iterable
    {
        yield 'too old' => [1999];
        yield 'too far' => [2101];
    }

    #[DataProvider('invalidYears')]
    public function testVersionYearIsBounded(int $year): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BudgetVersionBuilder::aVersion()->withYear($year)->build();
    }

    public function testVersionNameIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BudgetVersionBuilder::aVersion()->withName('   ')->build();
    }

    public function testLineTakesCompanyFromVersionAndKeepsMonthStart(): void
    {
        $version = BudgetVersionBuilder::aVersion()->withYear(2027)->build();
        $line = BudgetLineBuilder::aLine()->inVersion($version)->forMonth('2027-03')->withAmount('-60000.5')->build();

        self::assertSame($version->getCompanyId(), $line->getCompanyId());
        self::assertSame('2027-03-01', $line->getMonth()->format('Y-m-d'));
        self::assertSame('-60000.5', $line->getAmount());
        self::assertSame(BudgetLineSource::MANUAL, $line->getSource());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidLines(): iterable
    {
        yield 'month of another year' => ['2028-01', '100'];
        yield 'amount with comma' => ['2027-01', '100,5'];
        yield 'three decimals' => ['2027-01', '1.005'];
        yield 'empty amount' => ['2027-01', ''];
        yield 'too large' => ['2027-01', '12345678901234567'];
    }

    #[DataProvider('invalidLines')]
    public function testLineRejectsInvalidMonthOrAmount(string $month, string $amount): void
    {
        $version = BudgetVersionBuilder::aVersion()->withYear(2027)->build();

        $this->expectException(\InvalidArgumentException::class);
        BudgetLineBuilder::aLine()->inVersion($version)->forMonth($month)->withAmount($amount)->build();
    }

    public function testLineCommentIsTrimmedAndEmptyBecomesNull(): void
    {
        $line = BudgetLineBuilder::aLine()->withComment('   ')->build();
        self::assertNull($line->getComment());

        $line = BudgetLineBuilder::aLine()->withComment(' 2 менеджера × 80 ')->build();
        self::assertSame('2 менеджера × 80', $line->getComment());
    }
}
