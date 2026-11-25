<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\Report\IntragroupDecisionAction;
use App\Cash\Enum\Transaction\IntragroupDecisionType;
use App\Cash\Exception\IntragroupDecisionException;
use App\Tests\Fixtures\Cash\IntragroupReportFixture;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class IntragroupDecisionActionTest extends IntegrationTestCase
{
    use IntragroupReportFixture;

    private string $active;
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createGroupFixture();
        $this->loadNovemberTransactions();
        $this->fillMissingInns();
        $this->active = (string) $this->companies['Бета']->getId();
        $owner = $this->companies['Альфа']->getUser();
        self::assertNotNull($owner);
        $this->userId = (string) $owner->getId();
    }

    public function testPairsEarlierInflowAndARepeatedSubmissionPreservesTheOriginalDecision(): void
    {
        $this->addTransaction(34, '0001', '2026-11-13', '-2600000', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->addTransaction(35, '0002', '2026-11-19', '-150000', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->em->flush();
        $id = Uuid::uuid7()->toString();
        $before = $this->report('2026-11-01', '2026-11-30')->reconciliation;
        self::assertCount(2, $before->unpaired);
        $this->save($id, IntragroupDecisionType::PAIR, 35, 23);
        $this->save($id, IntragroupDecisionType::PAIR, 35, 23);

        $after = $this->report('2026-11-01', '2026-11-30')->reconciliation;
        self::assertSame(self::transactionId(23), $after->pairs[self::transactionId(35)]);
        self::assertSame([], $after->unpaired);
        self::assertTrue($after->balanced);
        $stored = $this->connection->fetchAllAssociative('SELECT company_id, created_by FROM cash_intragroup_decision WHERE id = ?', [$id]);
        self::assertSame([['company_id' => $this->companies['Альфа']->getId(), 'created_by' => $this->userId]], $stored);
    }

    public function testUnpairBansOneAutomaticPairUntilRemoval(): void
    {
        $id = Uuid::uuid7()->toString();
        $this->save($id, IntragroupDecisionType::UNPAIR, 24, 15);
        $this->save($id, IntragroupDecisionType::UNPAIR, 24, 15);
        $this->em->clear();
        $detached = $this->report()->reconciliation;
        self::assertArrayNotHasKey(self::transactionId(24), $detached->pairs);
        self::assertArrayHasKey(self::transactionId(15), $detached->unpaired);
        self::assertArrayHasKey(self::transactionId(24), $detached->unpaired);

        $action = self::getContainer()->get(IntragroupDecisionAction::class);
        $action->remove($this->active, $this->userId, $id);
        $action->remove($this->active, $this->userId, $id);
        self::assertSame(self::transactionId(15), $this->report()->reconciliation->pairs[self::transactionId(24)]);
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidLegs(): iterable
    {
        yield 'два списания' => [1, 4];
        yield 'перепутаны направления' => [19, 1];
        yield 'свой счёт и внешний клиент' => [14, 5];
        yield 'равная сумма внешнего клиента' => [24, 6];
        yield 'вне группы' => [13, 30];
        yield 'другая валюта' => [21, 12];
        yield 'нет встречного направления между теми же компаниями' => [13, 19];
    }

    #[DataProvider('invalidLegs')]
    public function testRejectsTransactionsThatCannotFormAReciprocalGroupPair(int $out, int $in): void
    {
        if (30 === $in) {
            $this->connection->delete('company_consolidation_member', ['company_id' => $this->companies['Гамма']->getId()]);
        } elseif (12 === $in) {
            $this->connection->update('cash_transaction', ['currency' => 'USD'], ['id' => self::transactionId(12)]);
        }
        $this->expectException(IntragroupDecisionException::class);
        $this->save(Uuid::uuid7()->toString(), IntragroupDecisionType::PAIR, $out, $in);
    }

    public function testOneKopeckMismatchIsRejectedWithTheTwoAmounts(): void
    {
        $this->addTransaction(35, '0002', '2026-11-19', '-150000.01', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->em->flush();

        $this->expectException(IntragroupDecisionException::class);
        $this->expectExceptionMessage('Суммы не совпадают: 150000,01 и 150000,00. Пара возможна только при равных суммах');
        $this->save(Uuid::uuid7()->toString(), IntragroupDecisionType::PAIR, 35, 23);
    }

    public function testSameDecisionIdCannotChangePayload(): void
    {
        $id = Uuid::uuid7()->toString();
        $this->save($id, IntragroupDecisionType::PAIR, 1, 19);
        $this->expectException(IntragroupDecisionException::class);
        $this->save($id, IntragroupDecisionType::PAIR, 4, 20);
    }

    public function testCannotBanAManualPair(): void
    {
        $this->save(Uuid::uuid7()->toString(), IntragroupDecisionType::PAIR, 1, 19);
        $this->expectException(IntragroupDecisionException::class);
        $this->save(Uuid::uuid7()->toString(), IntragroupDecisionType::UNPAIR, 1, 19);
    }

    public function testCannotBanANonexistentAutomaticPair(): void
    {
        $this->addTransaction(35, '0001', '2026-11-19', '-150000', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->em->flush();
        $this->expectException(IntragroupDecisionException::class);
        $this->save(Uuid::uuid7()->toString(), IntragroupDecisionType::UNPAIR, 35, 23);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAutoDates(): iterable
    {
        yield 'поступление раньше выплаты' => ['2026-11-18'];
        yield 'шестой день после выплаты' => ['2026-11-11'];
        yield 'десятилетия между операциями' => ['2000-01-01'];
    }

    public function testUnpairOnFifthCalendarDayStillChecksTheAutomaticPair(): void
    {
        $this->addTransaction(35, '0002', '2026-11-12', '-150000', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->em->flush();
        $this->save(Uuid::uuid7()->toString(), IntragroupDecisionType::UNPAIR, 35, 23);
        self::assertArrayHasKey(self::transactionId(23), $this->report()->reconciliation->unpaired);
    }

    #[DataProvider('invalidAutoDates')]
    public function testRefusesUnpairBeforeReadingTheDateRange(string $outflowDate): void
    {
        $existing = Uuid::uuid7()->toString();
        $this->save($existing, IntragroupDecisionType::PAIR, 1, 19);
        $this->addTransaction(35, '0002', $outflowDate, '-150000', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->em->flush();
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        $queries->reset();
        try {
            $this->save(Uuid::uuid7()->toString(), IntragroupDecisionType::UNPAIR, 35, 23);
            self::fail('Разъединение операций вне окна автопары должно быть отклонено.');
        } catch (IntragroupDecisionException) {
            foreach ($queries->getData()['default'] as $query) {
                if (str_contains($query['sql'], 't.occurred_at BETWEEN')) {
                    // Проверка двух заблокированных ног задаёт только ID. Запрос сверки привязал бы даты.
                    self::assertDoesNotMatchRegularExpression('/[0-9]{4}-[0-9]{2}-[0-9]{2}/', implode(',', $query['params']));
                }
            }
            self::assertSame([$existing], $this->connection->fetchFirstColumn('SELECT id FROM cash_intragroup_decision WHERE company_id = ?', [$this->companies['Альфа']->getId()]));
        }
    }

    public function testManualPairReadsALegBeyondTenDayWindow(): void
    {
        $this->addTransaction(35, '0002', '2026-12-21', '-150000', 'ООО "Бета"', 'OP_OUT_OTHER');
        $this->em->flush();
        $this->save(Uuid::uuid7()->toString(), IntragroupDecisionType::PAIR, 35, 23);
        $december = $this->report('2026-12-01', '2026-12-31')->reconciliation;
        self::assertSame([self::transactionId(35) => self::transactionId(23)], $december->inTransit);
        self::assertTrue($december->balanced);
        self::assertSame('-150000.00', $december->transitNet);
    }

    public function testOwnerCanRemoveStaleDecisionAfterAnOperationWasDeleted(): void
    {
        $id = Uuid::uuid7()->toString();
        $this->save($id, IntragroupDecisionType::PAIR, 1, 19);
        $this->connection->update('cash_transaction', ['deleted_at' => '2026-11-20'], ['id' => self::transactionId(19)]);
        self::assertArrayHasKey($id, $this->report()->reconciliation->staleDecisions);
        $this->addTransaction(35, '0003', '2026-11-18', '100000', 'ООО "Альфа"', 'OP_IN_OTHER');
        $this->em->flush();
        self::getContainer()->get(IntragroupDecisionAction::class)->remove($this->active, $this->userId, $id);
        $this->save(Uuid::uuid7()->toString(), IntragroupDecisionType::PAIR, 1, 35);
        self::assertSame([], $this->report()->reconciliation->staleDecisions);
        self::assertSame(self::transactionId(35), $this->report()->reconciliation->pairs[self::transactionId(1)]);
    }

    public function testMustDeleteStaleDecisionBeforeReusingItsSurvivingLeg(): void
    {
        $this->save(Uuid::uuid7()->toString(), IntragroupDecisionType::PAIR, 1, 19);
        $this->connection->update('cash_transaction', ['deleted_at' => '2026-11-20'], ['id' => self::transactionId(19)]);
        $this->addTransaction(35, '0003', '2026-11-18', '100000', 'ООО "Альфа"', 'OP_IN_OTHER');
        $this->em->flush();
        $this->expectException(IntragroupDecisionException::class);
        $this->expectExceptionMessage('удалите прежнее решение');
        $this->save(Uuid::uuid7()->toString(), IntragroupDecisionType::PAIR, 1, 35);
    }

    public function testForeignDecisionCannotBeDeletedOrReused(): void
    {
        $id = Uuid::uuid7()->toString();
        $foreignGroup = Uuid::uuid7()->toString();
        $this->connection->insert('cash_intragroup_decision', [
            'id' => $id, 'company_id' => $foreignGroup, 'type' => 'PAIR', 'created_by' => Uuid::uuid7()->toString(),
            'outflow_transaction_id' => Uuid::uuid7()->toString(), 'inflow_transaction_id' => Uuid::uuid7()->toString(),
            'created_at' => '2026-11-20 12:00:00',
        ]);
        self::getContainer()->get(IntragroupDecisionAction::class)->remove($this->active, $this->userId, $id);
        self::assertSame($foreignGroup, $this->connection->fetchOne('SELECT company_id FROM cash_intragroup_decision WHERE id = ?', [$id]));
        $this->expectException(IntragroupDecisionException::class);
        $this->expectExceptionMessage('Идентификатор решения или выбранные операции уже использованы.');
        $this->save($id, IntragroupDecisionType::PAIR, 1, 19);
    }

    public function testMemberWhoDoesNotOwnAllCompaniesCannotRemoveDecision(): void
    {
        $id = Uuid::uuid7()->toString();
        $this->save($id, IntragroupDecisionType::PAIR, 1, 19);
        $this->expectException(AccessDeniedException::class);
        self::getContainer()->get(IntragroupDecisionAction::class)->remove($this->active, Uuid::uuid7()->toString(), $id);
    }

    private function save(string $id, IntragroupDecisionType $type, int $out, int $in): void
    {
        self::getContainer()->get(IntragroupDecisionAction::class)->save($this->active, $this->userId, $id, $type, self::transactionId($out), self::transactionId($in));
    }
}
