<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Application\ProjectDirection\CopyProjectDirectionsAction;
use App\Company\Application\ProjectDirection\ProjectDirectionExchange;
use App\Company\Entity\ProjectDirection;
use App\Company\Repository\ProjectDirectionCopyMappingRepository;
use App\Company\Repository\ProjectDirectionRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\PostgresResetTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\Process;

final class ProjectDirectionCopyConcurrencyTest extends PostgresResetTestCase
{
    public function testWaitingConfirmationRejectsTheRevisionAlreadyUsedByTheWinner(): void
    {
        $owner = UserBuilder::aUser()->build();
        $source = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $target = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $project = new ProjectDirection(Uuid::uuid7()->toString(), $source, 'Проект');
        foreach ([$owner, $source, $target, $project] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $sourceId = (string) $source->getId();
        $targetId = (string) $target->getId();
        $projects = self::getContainer()->get(ProjectDirectionRepository::class);
        $mappings = new ProjectDirectionCopyMappingRepository($this->connection);
        $copy = new CopyProjectDirectionsAction($this->connection, $projects, $mappings, new ProjectDirectionExchange($projects));
        $preview = $copy->preview($sourceId, $targetId);
        $name = 'project_copy_race_'.Uuid::uuid7();
        $worker = new Process(
            [\PHP_BINARY, 'tests/Fixtures/Company/confirm-project-direction-copy.php'],
            (string) self::getContainer()->getParameter('kernel.project_dir'),
            input: json_encode([
                'name' => $name, 'source' => $sourceId, 'target' => $targetId,
                'sourceRevision' => $preview->sourceRevision, 'targetRevision' => $preview->targetRevision,
            ], \JSON_THROW_ON_ERROR),
            timeout: 20,
        );

        $this->connection->beginTransaction();
        try {
            $winner = $copy->confirm($sourceId, $targetId, $preview->sourceRevision, $preview->targetRevision, []);
            self::assertSame([], $winner->errors);
            $worker->start();
            self::assertTrue($worker->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "ready\n")), $worker->getErrorOutput());
            $deadline = microtime(true) + 5;
            do {
                $waiting = (bool) $this->connection->fetchOne("SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event = 'advisory'", [$name]);
                if (!$waiting) {
                    usleep(10000);
                }
            } while (!$waiting && $worker->isRunning() && microtime(true) < $deadline);
            self::assertTrue($waiting, 'Второе подтверждение должно ждать транзакцию первого.');

            $this->connection->commit();
            self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
            self::assertStringContainsString('изменился', $worker->getOutput());
            self::assertCount(1, $projects->readDirectory($targetId)['projects']);
            self::assertSame($winner->mappings, $mappings->findBetweenCompanies($sourceId, $targetId));
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop(0);
            }
        }
    }
}
