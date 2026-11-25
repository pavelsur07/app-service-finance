<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\Report\BuildCashflowReconciliation;
use App\Cash\Application\Report\IntragroupDecisionAction;
use App\Cash\Application\Report\IntragroupGroupAccess;
use App\Cash\Entity\Transaction\CashIntragroupDecision;
use App\Cash\Enum\Transaction\IntragroupDecisionType;
use App\Company\Application\ConfigureConsolidationGroupAction;
use App\Tests\Fixtures\Cash\IntragroupReportFixture;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class IntragroupDecisionReportTest extends IntegrationTestCase
{
    use IntragroupReportFixture;

    public function testSwitchingHeadKeepsEachGroupsOwnDecisionForTheSameTransactions(): void
    {
        $this->createGroupFixture();
        $this->fillMissingInns();
        $this->addTransaction(1, '0001', '2026-11-12', '-100.00', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->addTransaction(2, '0003', '2026-11-10', '100.00', 'ООО "Альфа"', 'OP_IN_OTHER');
        $this->em->flush();
        $alpha = (string) $this->companies['Альфа']->getId();
        $beta = (string) $this->companies['Бета']->getId();
        $gamma = (string) $this->companies['Гамма']->getId();
        $owner = (string) $this->companies['Альфа']->getUser()?->getId();
        $out = self::transactionId(1);
        $in = self::transactionId(2);
        $oldDecision = Uuid::uuid7()->toString();
        $newDecision = Uuid::uuid7()->toString();
        $save = self::getContainer()->get(IntragroupDecisionAction::class);
        $configure = self::getContainer()->get(ConfigureConsolidationGroupAction::class);

        $save->save($beta, $owner, $oldDecision, IntragroupDecisionType::PAIR, $out, $in);
        self::assertSame([$out => $oldDecision], $this->report()->reconciliation->manualDecisionIds);

        $configure($alpha, $owner, false, []);
        $configure($gamma, $owner, true, [$alpha, $beta]);
        $newGroup = $this->report();
        self::assertSame($gamma, $newGroup->headCompanyId);
        self::assertSame([], $newGroup->reconciliation->manualDecisionIds);
        self::assertSame([], $newGroup->reconciliation->unpairDecisions);
        self::assertSame([], $newGroup->reconciliation->staleDecisions);
        self::assertEqualsCanonicalizing([$out, $in], array_keys($newGroup->reconciliation->unpaired));
        self::assertSame('0.00', array_column($newGroup->lines, 'elimination', 'key')['OP_IN_OTHER']);
        $save->save($beta, $owner, $newDecision, IntragroupDecisionType::PAIR, $out, $in);
        $paired = $this->report()->reconciliation;
        self::assertSame([$out => $newDecision], $paired->manualDecisionIds);
        self::assertSame([$out => $in], $paired->pairs);
        self::assertSame([], $paired->staleDecisions);

        $configure($gamma, $owner, false, []);
        $configure($alpha, $owner, true, [$beta, $gamma]);
        $restored = $this->report();
        self::assertSame($alpha, $restored->headCompanyId);
        self::assertSame([$out => $oldDecision], $restored->reconciliation->manualDecisionIds);
        self::assertSame([$out => $in], $restored->reconciliation->pairs);
        self::assertSame([], $restored->reconciliation->staleDecisions);
        self::assertSame('-100.00', array_column($restored->lines, 'elimination', 'key')['OP_IN_OTHER']);
        self::assertEquals([$alpha => $oldDecision, $gamma => $newDecision], $this->connection->fetchAllKeyValue(
            "SELECT company_id, id FROM cash_intragroup_decision WHERE outflow_transaction_id = ? AND inflow_transaction_id = ? AND type = 'PAIR'",
            [$out, $in],
        ));
    }

    public function testManualDecisionReservesItsLegOutsideWindowAndBecomesStaleWhenAmountChanges(): void
    {
        $this->createGroupFixture();
        $this->fillMissingInns();
        $this->addTransaction(1, '0001', '2026-12-20', '-10.00', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->addTransaction(2, '0003', '2026-11-10', '10.00', 'ООО "Альфа"', 'OP_IN_OTHER');
        $this->addTransaction(3, '0001', '2026-11-10', '-10.00', 'ООО "Бета"', 'OP_OUT_OTHER');
        $head = $this->companies['Альфа'];
        $decision = new CashIntragroupDecision(Uuid::uuid7()->toString(), (string) $head->getId(), IntragroupDecisionType::PAIR,
            self::transactionId(1), self::transactionId(2), (string) $head->getUser()?->getId(), new \DateTimeImmutable());
        $this->em->persist($decision);
        $this->em->flush();
        $rec = $this->report()->reconciliation;
        self::assertSame([self::transactionId(1) => self::transactionId(2)], $rec->inTransit);
        self::assertSame([], $rec->pairs);
        self::assertSame('10.00', $rec->transitNet);
        self::assertSame('-10.00', $rec->unpairedNet);
        self::assertTrue($rec->balanced);

        $this->connection->executeStatement('UPDATE cash_transaction SET amount = 11.00 WHERE company_id = ? AND id = ?', [$head->getId(), self::transactionId(1)]);
        $this->connection->executeStatement('UPDATE cash_transaction_split SET amount = 11.00 WHERE company_id = ? AND cash_transaction_id = ?', [$head->getId(), self::transactionId(1)]);
        $rec = $this->report()->reconciliation;
        self::assertArrayHasKey($decision->getId(), $rec->staleDecisions);
        self::assertSame([self::transactionId(3) => self::transactionId(2)], $rec->pairs);
        self::assertSame([], $rec->inTransit);
    }

    public function testManualChoicesUseOnlyFreeReciprocalEqualAmountsInFifteenDays(): void
    {
        $this->createGroupFixture();
        $this->fillMissingInns();
        $this->addTransaction(1, '0003', '2026-11-10', '100.00', 'ООО "Альфа"', 'OP_IN_OTHER');
        $this->addTransaction(2, '0001', '2026-11-25', '-100.00', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->addTransaction(3, '0001', '2026-11-26', '-100.00', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->addTransaction(4, '0001', '2026-11-16', '-100.01', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->addTransaction(5, '0001', '2026-11-12', '-100.00', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->addTransaction(6, '0003', '2026-11-13', '100.00', 'ООО "Альфа"', 'OP_IN_OTHER');
        $this->em->flush();

        $report = $this->report();
        $source = $report->reconciliation->unpaired[self::transactionId(1)];
        $members = array_keys($report->companies);
        self::assertNotEmpty($members);
        $choices = self::getContainer()->get(BuildCashflowReconciliation::class)->manualCandidates($members, $source);
        self::assertSame([self::transactionId(2)], array_keys($choices));

        // Старая пара с удалённой встречной ногой продолжает держать уникальный ключ до отмены.
        $head = $this->companies['Альфа'];
        $decision = new CashIntragroupDecision(Uuid::uuid7()->toString(), (string) $head->getId(), IntragroupDecisionType::PAIR,
            self::transactionId(2), self::transactionId(999), (string) $head->getUser()?->getId(), new \DateTimeImmutable());
        $this->em->persist($decision);
        $this->em->flush();
        $service = self::getContainer()->get(BuildCashflowReconciliation::class);
        self::assertSame([], $service->manualCandidates($members, $source));
        self::assertTrue($this->report('2026-11-01', '2026-11-30')->reconciliation->requiresDecisionRemoval(self::transactionId(2)));
    }

    public function testOwnerOfEveryCompanyUnderGroupLock(): void
    {
        $this->createGroupFixture();
        $head = $this->companies['Альфа'];
        $member = (string) $this->companies['Бета']->getId();
        $access = self::getContainer()->get(IntragroupGroupAccess::class);
        $this->em->wrapInTransaction(function () use ($head, $member, $access): void {
            $members = $access->lockedMembers($member, (string) $head->getUser()?->getId());
            self::assertSame($head->getId(), $members[0]);
            self::assertContains($member, $members);
        });
        $this->expectException(AccessDeniedException::class);
        $this->em->wrapInTransaction(fn () => $access->lockedMembers($member, Uuid::uuid7()->toString()));
    }
}
