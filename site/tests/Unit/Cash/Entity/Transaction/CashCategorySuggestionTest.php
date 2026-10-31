<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Entity\Transaction;

use App\Cash\Enum\Transaction\CashCategorySuggestionStatus as Status;
use App\Cash\Exception\CashCategorySuggestionStateException;
use App\Tests\Builders\Cash\CashCategorySuggestionBuilder;
use PHPUnit\Framework\TestCase;

final class CashCategorySuggestionTest extends TestCase
{
    private const CATEGORY = '0192f0a0-0000-7000-8000-0000000000aa';
    private const USER = '0192f0a0-0000-7000-8000-0000000000bb';

    public function testAnswerWithCategoryBecomesSuggestionThenAccepted(): void
    {
        $suggestion = CashCategorySuggestionBuilder::aSuggestion()->build();
        $suggestion->suggest(self::CATEGORY, 0.876543, 'accepted', 'stub/categorize', 'categorize_transactions/v1', new \DateTimeImmutable());

        self::assertSame(Status::SUGGESTED, $suggestion->getStatus());
        self::assertSame('0.8765', $suggestion->getConfidence());

        $suggestion->accept(self::USER, new \DateTimeImmutable());
        self::assertSame(Status::ACCEPTED, $suggestion->getStatus());
        self::assertSame(self::USER, $suggestion->getDecidedByUserId());
    }

    public function testAnswerWithoutCategoryIsNoAnswerAndCannotBeAccepted(): void
    {
        $suggestion = CashCategorySuggestionBuilder::aSuggestion()->build();
        $suggestion->suggest(null, 0.0, 'needs_review', null, null, new \DateTimeImmutable());

        self::assertSame(Status::NO_ANSWER, $suggestion->getStatus());
        $this->expectException(CashCategorySuggestionStateException::class);
        $suggestion->accept(self::USER, new \DateTimeImmutable());
    }

    public function testDecisionIsFinal(): void
    {
        $suggestion = CashCategorySuggestionBuilder::aSuggestion()->suggesting(self::CATEGORY, 0.9)->build();
        $suggestion->dismiss(self::USER, new \DateTimeImmutable());

        self::assertSame(Status::DISMISSED, $suggestion->getStatus());
        $this->expectException(CashCategorySuggestionStateException::class);
        $suggestion->markStale('categorized', new \DateTimeImmutable());
    }

    public function testFailedRequestKeepsErrorCodeAndAnswerIsNotAcceptedAfterwards(): void
    {
        $suggestion = CashCategorySuggestionBuilder::aSuggestion()->build();
        $suggestion->fail('lost', null, new \DateTimeImmutable());

        self::assertSame(Status::FAILED, $suggestion->getStatus());
        self::assertSame('lost', $suggestion->getErrorCode());
        $this->expectException(CashCategorySuggestionStateException::class);
        $suggestion->suggest(self::CATEGORY, 0.9, 'accepted', null, null, new \DateTimeImmutable());
    }

    public function testConfidenceOutsideRangeIsRejected(): void
    {
        $suggestion = CashCategorySuggestionBuilder::aSuggestion()->build();

        $this->expectException(\InvalidArgumentException::class);
        $suggestion->suggest(self::CATEGORY, 1.5, 'accepted', null, null, new \DateTimeImmutable());
    }

    public function testAutoApplyHasNoAuthorAndIsFinal(): void
    {
        $suggestion = CashCategorySuggestionBuilder::aSuggestion()->suggesting(self::CATEGORY, 0.95)->build();
        $suggestion->autoApply(new \DateTimeImmutable());

        self::assertSame(Status::AUTO_APPLIED, $suggestion->getStatus());
        self::assertNull($suggestion->getDecidedByUserId());
        $this->expectException(CashCategorySuggestionStateException::class);
        $suggestion->dismiss(self::USER, new \DateTimeImmutable());
    }

    public function testOnlyShownSuggestionIsAutoApplied(): void
    {
        $suggestion = CashCategorySuggestionBuilder::aSuggestion()->build();

        $this->expectException(CashCategorySuggestionStateException::class);
        $suggestion->autoApply(new \DateTimeImmutable());
    }
}
