<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application;

use App\Cash\Application\AutoRule\EnqueueCashAutoRulesAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleApplyMode;
use App\Cash\Message\EnqueueAutoRulesForRange;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class EnqueueCashAutoRulesActionTest extends TestCase
{
    public function testQueuesCurrentYearWithActorAndMode(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $today = new \DateTimeImmutable('today');
        $bus->expects(self::once())->method('dispatch')->with(self::callback(static fn (EnqueueAutoRulesForRange $message): bool => 'company' === $message->companyId && 'actor' === $message->initiatedByUserId && CashTransactionAutoRuleApplyMode::SAFE === $message->mode && $today == $message->to && $today->format('Y').'-01-01' === $message->from?->format('Y-m-d')))->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $period = (new EnqueueCashAutoRulesAction($bus))('company', CashTransactionAutoRuleApplyMode::SAFE, 'actor');
        self::assertEquals($today, $period['to']);
    }

    public function testUnsafeModeRequiresAnActorBeforeSending(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $this->expectException(\DomainException::class);
        (new EnqueueCashAutoRulesAction($bus))('company', CashTransactionAutoRuleApplyMode::REPLACE_AUTO_ASSIGNED, null);
    }
}
