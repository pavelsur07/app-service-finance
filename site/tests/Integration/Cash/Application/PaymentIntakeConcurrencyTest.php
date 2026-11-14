<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\PaymentIntake\PaymentIntakeDraft;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Repository\PaymentIntake\PaymentIntakeDraftRepository;
use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\Process;

final class PaymentIntakeConcurrencyTest extends IntegrationTestCase
{
    public function testTwoSimultaneousConfirmationsReturnOneStableTransactionSet(): void
    {
        $this->race('confirm');
    }

    public function testAmendAndConfirmCannotBothApplyTheSameRevision(): void
    {
        $this->race('amend');
    }

    private function race(string $secondOperation): void
    {
        // Separate physical connection commits the fixture: an uncommitted DAMA
        // INSERT would itself block readers and falsely prove the application lock.
        $seed = DriverManager::getConnection($this->connection->getParams());
        $manager = new EntityManager($seed, $this->em->getConfiguration(), new EventManager());
        $user = new User(Uuid::uuid7()->toString());
        $user->setEmail('intake-race-'.Uuid::uuid7()->toString().'@example.test');
        $user->setPassword('test');
        $company = new Company(Uuid::uuid7()->toString(), $user);
        $company->setName('Intake race');
        $companyId = (string) $company->getId();
        $account = new MoneyAccount(Uuid::uuid7()->toString(), $company, MoneyAccountType::BANK, 'Race', 'RUB');
        $account->setOpeningBalance('0');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('today'));
        $project = new ProjectDirection(Uuid::uuid7()->toString(), $company, 'Общий', ProjectDirection::CODE_GENERAL);
        $center = new FinancialResponsibilityCenter($companyId, FinancialResponsibilityCenter::CODE_GENERAL, FinancialResponsibilityCenter::NAME_GENERAL);
        $draft = new PaymentIntakeDraft($companyId, (string) $user->getId(), (string) $account->getId(), 'race/'.Uuid::uuid7()->toString(), 'original', 'оплата 100', new \DateTimeImmutable('today'), 'Europe/Moscow', 'RUB');
        $taskId = Uuid::uuid7()->toString();
        $draft->queue($taskId);
        $draft->acceptResult($taskId, ['intent' => 'payments', 'items' => [['ref' => 'p1', 'amount' => '100.00', 'currency' => 'RUB', 'date' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'direction' => 'outflow', 'execution_status' => 'paid', 'description' => 'Race', 'text_evidence' => 'оплата 100', 'amount_evidence' => '100', 'category_id' => null, 'project_id' => null, 'cfo_id' => null, 'questions' => []]], 'questions' => [], 'target_ref' => null]);
        $itemId = $draft->getItems()[0]['id'];
        $draft->amend(1, [$itemId => ['paid_confirmed' => true]]);
        foreach ([$user, $company, $account, $project, $center, new FinancialResponsibilityCenterProject($companyId, $project, $center), $draft] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();
        $processes = [];
        $locked = false;
        try {
            $this->connection->beginTransaction();
            $locked = true;
            self::getContainer()->get(PaymentIntakeDraftRepository::class)->getForCompany($companyId, $draft->getId(), true);
            foreach (['confirm', $secondOperation] as $index => $operation) {
                $name = 'intake_race_'.Uuid::uuid7()->toString();
                $process = new Process([\PHP_BINARY, 'tests/Fixtures/Cash/payment-intake-race.php'], (string) self::getContainer()->getParameter('kernel.project_dir'), ['INTAKE_REVISION' => '2', 'INTAKE_RACE_NAME' => $name, 'INTAKE_RACE_OPERATION' => $operation, 'INTAKE_COMPANY' => $companyId, 'INTAKE_ACTOR' => (string) $user->getId(), 'INTAKE_DRAFT' => $draft->getId(), 'INTAKE_ITEM' => $itemId], null, 30);
                $process->start();
                $processes[] = $process;
                self::assertTrue($process->waitUntil(static fn (string $type, string $out): bool => str_contains($out, "ready\n")), $process->getErrorOutput());
                $deadline = microtime(true) + 5;
                do {
                    $this->connection->executeQuery('SELECT pg_stat_clear_snapshot()');
                    $waiting = (bool) $this->connection->fetchOne("SELECT 1 FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'", [$name]);
                    if (!$waiting) {
                        usleep(10000);
                    }
                } while (!$waiting && $process->isRunning() && microtime(true) < $deadline);
                self::assertTrue($waiting, 'Both callbacks must wait for the committed draft row lock.');
            }
            $this->connection->rollBack();
            $locked = false;
            $outputs = [];
            foreach ($processes as $process) {
                self::assertSame(0, $process->wait(), $process->getErrorOutput());
                $lines = explode("\n", trim($process->getOutput()));
                $outputs[] = json_decode(end($lines), true, 512, \JSON_THROW_ON_ERROR);
            }
            $count = (int) $seed->fetchOne('SELECT count(*) FROM cash_transaction WHERE company_id=?', [$companyId]);
            if ('confirm' === $secondOperation) {
                self::assertSame('confirmed', $outputs[0]['status']);
                self::assertSame($outputs[0], $outputs[1]);
                self::assertSame(1, $count);
            } else {
                $statuses = array_column($outputs, 'status');
                self::assertContains('rejected', $statuses);
                self::assertCount(1, array_filter($statuses, static fn (string $status): bool => 'rejected' !== $status));
                self::assertSame(\in_array('confirmed', $statuses, true) ? 1 : 0, $count);
            }
        } finally {
            if ($locked) {
                $this->connection->rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
            $this->cleanup($seed, $companyId, (string) $user->getId());
            $seed->close();
        }
    }

    private function cleanup(Connection $connection, string $companyId, string $userId): void
    {
        foreach (['cash_transaction_split', 'cash_transaction', 'money_account_daily_balance', 'cash_payment_intake_draft', 'financial_responsibility_center_projects', 'project_directions', 'financial_responsibility_centers', 'money_account'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table.' WHERE company_id=?', [$companyId]);
        }
        $connection->executeStatement('DELETE FROM companies WHERE id=?', [$companyId]);
        $connection->executeStatement('DELETE FROM "user" WHERE id=?', [$userId]);
    }
}
