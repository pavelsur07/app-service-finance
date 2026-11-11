<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\CreateDocumentFromTransactionAction;
use App\Cash\Application\DTO\CashTransactionDTO;
use App\Cash\Application\DTO\CashTransactionSplitInput;
use App\Cash\Application\DTO\CashTransactionSplitsInput;
use App\Cash\Application\SaveCashTransactionSplitsAction;
use App\Cash\Application\UpdateCashTransactionAction;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\PostgresResetTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\Process;

final class CashSplitSourceConcurrencyTest extends PostgresResetTestCase
{
    #[DataProvider('orders')]
    public function testWaitingMutationUsesCommittedSource(string $first, string $second): void
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid7()->toString())->forCompany($company)->build();
        $original = (new CashflowCategory(Uuid::uuid7()->toString(), $company))->setName('Original')->setAllowPlDocument(true);
        $a = (new CashflowCategory(Uuid::uuid7()->toString(), $company))->setName('A')->setAllowPlDocument(false);
        $b = (new CashflowCategory(Uuid::uuid7()->toString(), $company))->setName('B')->setAllowPlDocument(false);
        $tx = CashTransactionBuilder::aCashTransaction()->withId(Uuid::uuid7()->toString())->forCompany($company)->withMoneyAccount($account)->withAmount('1000.00')->withCashflowCategory($original)->build();
        foreach ([$owner, $company, $account, $original, $a, $b, $tx] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $name = 'cash_race_'.Uuid::uuid7();
        $process = new Process([\PHP_BINARY, 'tests/Fixtures/Cash/mutate-split-source.php'], (string) self::getContainer()->getParameter('kernel.project_dir'), [
            'CASH_RACE_NAME' => $name, 'CASH_RACE_TX' => $tx->getId(), 'CASH_RACE_COMPANY' => $company->getId(),
            'CASH_RACE_A' => $a->getId(), 'CASH_RACE_B' => $b->getId(), 'CASH_RACE_OPERATION' => $second,
        ], null, 90);
        $this->em->beginTransaction();
        try {
            if ('splits' === $first) {
                $input = new CashTransactionSplitsInput();
                foreach ([[$a->getId(), '600'], [$b->getId(), '400']] as [$id, $amount]) {
                    $row = new CashTransactionSplitInput();
                    $row->cashflowCategoryId = $id;
                    $row->amount = $amount;
                    $input->rows[] = $row;
                }
                self::getContainer()->get(SaveCashTransactionSplitsAction::class)($tx, $input);
            } elseif ('update' === $first) {
                $dto = new CashTransactionDTO();
                $dto->moneyAccountId = (string) $account->getId();
                $dto->currency = 'RUB';
                $dto->direction = $tx->getDirection();
                $dto->occurredAt = $tx->getOccurredAt();
                $dto->cashflowCategoryId = $original->getId();
                $dto->amount = '900';
                self::getContainer()->get(UpdateCashTransactionAction::class)($tx, $dto);
            } else {
                self::getContainer()->get(CreateDocumentFromTransactionAction::class)($tx, true);
            }
            $process->start();
            self::assertTrue($process->waitUntil(static fn (string $type, string $out): bool => str_contains($out, "ready\n")), $process->getErrorOutput());
            $deadline = microtime(true) + 5;
            do {
                $waiting = (bool) $this->connection->fetchOne("SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'", [$name]);
                if (!$waiting) {
                    usleep(10000);
                }
            } while (!$waiting && $process->isRunning() && microtime(true) < $deadline);
            self::assertTrue($waiting, 'Second action must wait for the source lock: '.$process->getErrorOutput());
            $this->em->commit();
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
            self::assertStringContainsString("rejected\n", $process->getOutput());
            $row = $this->connection->fetchAssociative('SELECT t.amount, (SELECT SUM(s.amount) FROM cash_transaction_split s WHERE s.cash_transaction_id = t.id) AS split_total, (SELECT COUNT(*) FROM documents d WHERE d.cash_transaction_id = t.id) AS documents FROM cash_transaction t WHERE t.id = ?', [$tx->getId()]);
            self::assertIsArray($row);
            if (null !== $row['split_total']) {
                self::assertSame($row['amount'], $row['split_total']);
            }
            self::assertSame('document' === $first ? 1 : 0, (int) $row['documents']);
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }

    /** @return iterable<array{string, string}> */
    public static function orders(): iterable
    {
        yield 'split then update' => ['splits', 'update'];
        yield 'update then split' => ['update', 'splits'];
        yield 'split then document' => ['splits', 'document'];
        yield 'document then split' => ['document', 'splits'];
    }
}
