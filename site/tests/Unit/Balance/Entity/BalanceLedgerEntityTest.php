<?php

declare(strict_types=1);

namespace App\Tests\Unit\Balance\Entity;

use App\Balance\Entity\BalanceAccount;
use App\Balance\Entity\BalanceBook;
use App\Balance\Entity\BalanceOperationLine;
use App\Balance\Enum\BalanceDirection;
use App\Balance\Enum\BalanceOperationStatus;
use App\Tests\Builders\Balance\BalanceAccessGrantBuilder;
use App\Tests\Builders\Balance\BalanceAccountStateBuilder;
use App\Tests\Builders\Balance\BalanceAuditEventBuilder;
use App\Tests\Builders\Balance\BalanceOperationBuilder;
use App\Tests\Builders\Balance\BalancePeriodBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class BalanceLedgerEntityTest extends TestCase
{
    private const COMPANY_ID = '22222222-2222-4222-8222-222222222222';
    private const OBJECT_ID = '33333333-3333-4333-8333-333333333333';

    public function testNewDocumentIsUnposted(): void
    {
        $operation = BalanceOperationBuilder::aBalanceOperation()->build();

        self::assertSame(BalanceOperationStatus::DRAFT, $operation->getStatus());
        self::assertNull($operation->getPostedAt());
        self::assertNull($operation->getPostingSequence());
        self::assertSame(1, $operation->getVersion());
    }

    public function testNewStateStartsAtZero(): void
    {
        $state = BalanceAccountStateBuilder::aBalanceAccountState()->build();

        self::assertSame('0', $state->getBalance());
        self::assertSame(0, $state->getJournalVersion());
    }

    public function testNewGrantDoesNotBroadenPermissions(): void
    {
        $grant = BalanceAccessGrantBuilder::aBalanceAccessGrant()->build();

        self::assertFalse($grant->canPrepare());
        self::assertFalse($grant->canPost());
        self::assertFalse($grant->canManagePeriods());
    }

    public function testNewPeriodStartsOpen(): void
    {
        $period = BalancePeriodBuilder::aBalancePeriod()->build();

        self::assertFalse($period->isClosed());
        self::assertSame('01', $period->getMonth()->format('d'));
    }

    public function testSystemAuditDoesNotInventHumanAuthor(): void
    {
        $audit = BalanceAuditEventBuilder::aBalanceAuditEvent()->withAuthorId(null)->build();

        self::assertNull($audit->getAuthorId());
    }

    public function testNewBookHasNoConfirmedOpeningBalance(): void
    {
        $book = new BalanceBook(self::COMPANY_ID);

        self::assertSame(self::COMPANY_ID, $book->getCompanyId());
        self::assertFalse($book->isInitialized());
        self::assertNull($book->getCurrency());
        self::assertNull($book->getStartDate());
        self::assertSame(7, Uuid::fromString($book->getId())->getVersion());
    }

    public function testAccountDoesNotAllowNegativeBalancesByDefault(): void
    {
        $account = new BalanceAccount(self::COMPANY_ID, self::OBJECT_ID, 'BANK', 'Основной счет');

        self::assertSame(self::OBJECT_ID, $account->getArticleId());
        self::assertFalse($account->allowsNegative());
        self::assertFalse($account->isArchived());
    }

    public function testAccountRequiresACompanyUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BalanceAccount('another-company', self::OBJECT_ID, 'BANK', 'Основной счет');
    }

    public function testLinePreservesExactLargeAmount(): void
    {
        $line = new BalanceOperationLine(self::COMPANY_ID, self::OBJECT_ID, self::OBJECT_ID, BalanceDirection::INCREASE, '9223372036854775807');

        self::assertSame('9223372036854775807', $line->getAmount());
        self::assertSame(BalanceDirection::INCREASE, $line->getDirection());
    }

    public function testLineRejectsNegativeAmounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BalanceOperationLine(self::COMPANY_ID, self::OBJECT_ID, self::OBJECT_ID, BalanceDirection::DECREASE, '-1');
    }

    public function testLineRejectsOverflow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BalanceOperationLine(self::COMPANY_ID, self::OBJECT_ID, self::OBJECT_ID, BalanceDirection::INCREASE, '9223372036854775808');
    }
}
