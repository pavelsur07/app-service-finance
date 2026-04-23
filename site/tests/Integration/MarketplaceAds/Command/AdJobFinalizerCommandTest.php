<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Command;

use App\Company\Entity\Company;
use App\MarketplaceAds\Entity\AdLoadJob;
use App\MarketplaceAds\Enum\AdLoadJobStatus;
use App\MarketplaceAds\Enum\AdScheduledBatchState;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\MarketplaceAds\Repository\AdScheduledBatchRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use App\Tests\Builders\MarketplaceAds\AdScheduledBatchBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end тесты {@see \App\MarketplaceAds\Command\AdJobFinalizerCommand}
 * с реальным Postgres.
 *
 * Acceptance кейс Task-11.7: 2 jobs (один full OK, один mixed) → оба
 * финализированы с правильными статусами (completed / partial_success).
 */
final class AdJobFinalizerCommandTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000001';
    private const OWNER_ID = '22222222-2222-2222-2222-000000000001';

    private AdLoadJobRepository $jobRepo;
    private AdScheduledBatchRepository $batchRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jobRepo = self::getContainer()->get(AdLoadJobRepository::class);
        $this->batchRepo = self::getContainer()->get(AdScheduledBatchRepository::class);
    }

    public function testEmptyDbExitsSuccess(): void
    {
        $tester = $this->makeCommandTester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('No RUNNING jobs', $tester->getDisplay());
    }

    public function testFullyOkJobIsMarkedCompleted(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        $job = $this->persistRunningJob(1);
        $this->persistBatch($job, 0, AdScheduledBatchState::OK);
        $this->persistBatch($job, 1, AdScheduledBatchState::OK);
        $this->persistBatch($job, 2, AdScheduledBatchState::OK);

        $tester = $this->makeCommandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $this->em->clear();
        $reloaded = $this->jobRepo->find($job->getId());
        self::assertNotNull($reloaded);
        self::assertSame(AdLoadJobStatus::COMPLETED, $reloaded->getStatus());
        self::assertNotNull($reloaded->getFinishedAt());
    }

    public function testAllFailedJobIsMarkedFailed(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        $job = $this->persistRunningJob(1);
        $this->persistBatch($job, 0, AdScheduledBatchState::FAILED);
        $this->persistBatch($job, 1, AdScheduledBatchState::ABANDONED);

        $tester = $this->makeCommandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $this->em->clear();
        $reloaded = $this->jobRepo->find($job->getId());
        self::assertNotNull($reloaded);
        self::assertSame(AdLoadJobStatus::FAILED, $reloaded->getStatus());
        self::assertSame('All 2 batches failed', $reloaded->getFailureReason());
        self::assertNotNull($reloaded->getFinishedAt());
    }

    public function testMixedJobIsMarkedPartialSuccess(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        $job = $this->persistRunningJob(1);
        $this->persistBatch($job, 0, AdScheduledBatchState::OK);
        $this->persistBatch($job, 1, AdScheduledBatchState::OK);
        $this->persistBatch($job, 2, AdScheduledBatchState::OK);
        $this->persistBatch($job, 3, AdScheduledBatchState::OK);
        $this->persistBatch($job, 4, AdScheduledBatchState::OK);
        $this->persistBatch($job, 5, AdScheduledBatchState::FAILED);
        $this->persistBatch($job, 6, AdScheduledBatchState::FAILED);
        $this->persistBatch($job, 7, AdScheduledBatchState::ABANDONED);

        $tester = $this->makeCommandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $this->em->clear();
        $reloaded = $this->jobRepo->find($job->getId());
        self::assertNotNull($reloaded);
        self::assertSame(AdLoadJobStatus::PARTIAL_SUCCESS, $reloaded->getStatus());
        self::assertSame('3 of 8 batches failed', $reloaded->getFailureReason());
        self::assertNotNull($reloaded->getFinishedAt());
    }

    public function testJobWithPlannedBatchIsNotFinalized(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        $job = $this->persistRunningJob(1);
        $this->persistBatch($job, 0, AdScheduledBatchState::OK);
        $this->persistBatch($job, 1, AdScheduledBatchState::PLANNED);

        $tester = $this->makeCommandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $this->em->clear();
        $reloaded = $this->jobRepo->find($job->getId());
        self::assertNotNull($reloaded);
        self::assertSame(AdLoadJobStatus::RUNNING, $reloaded->getStatus(), 'Ещё есть PLANNED — рано финализировать');
        self::assertNull($reloaded->getFinishedAt());
    }

    public function testAcceptanceCaseTwoJobsOkAndMixed(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        $okJob = $this->persistRunningJob(1);
        $this->persistBatch($okJob, 0, AdScheduledBatchState::OK);
        $this->persistBatch($okJob, 1, AdScheduledBatchState::OK);

        $mixJob = $this->persistRunningJob(2);
        $this->persistBatch($mixJob, 0, AdScheduledBatchState::OK);
        $this->persistBatch($mixJob, 1, AdScheduledBatchState::FAILED);

        $tester = $this->makeCommandTester();
        $exit = $tester->execute([]);
        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('finalized=2', $tester->getDisplay());

        $this->em->clear();

        $okReloaded = $this->jobRepo->find($okJob->getId());
        self::assertNotNull($okReloaded);
        self::assertSame(AdLoadJobStatus::COMPLETED, $okReloaded->getStatus());

        $mixReloaded = $this->jobRepo->find($mixJob->getId());
        self::assertNotNull($mixReloaded);
        self::assertSame(AdLoadJobStatus::PARTIAL_SUCCESS, $mixReloaded->getStatus());
        self::assertSame('1 of 2 batches failed', $mixReloaded->getFailureReason());
    }

    public function testJobWithoutBatchesLogsWarningAndStaysRunning(): void
    {
        $this->seedCompany(self::COMPANY_ID, self::OWNER_ID, 'a@example.test');
        $this->em->flush();

        $job = $this->persistRunningJob(1);
        // Батчи не создаём — аномалия (Planner должен был).

        $tester = $this->makeCommandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('still_running=1', $tester->getDisplay());

        $this->em->clear();
        $reloaded = $this->jobRepo->find($job->getId());
        self::assertNotNull($reloaded);
        self::assertSame(AdLoadJobStatus::RUNNING, $reloaded->getStatus());
    }

    private function makeCommandTester(): CommandTester
    {
        $app = new Application(self::$kernel);
        $command = $app->find('app:marketplace-ads:finalizer');

        return new CommandTester($command);
    }

    private function persistRunningJob(int $index): AdLoadJob
    {
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex($index)
            ->asRunning()
            ->build();

        $this->jobRepo->save($job);
        $this->em->flush();

        return $job;
    }

    private function persistBatch(AdLoadJob $job, int $batchIndex, AdScheduledBatchState $state): void
    {
        $batch = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex($batchIndex)
            ->withState($state)
            ->build();

        $this->batchRepo->save($batch);
        $this->em->flush();
    }

    private function seedCompany(string $companyId, string $ownerId, string $email): Company
    {
        $owner = UserBuilder::aUser()
            ->withId($ownerId)
            ->withEmail($email)
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId($companyId)
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }
}
