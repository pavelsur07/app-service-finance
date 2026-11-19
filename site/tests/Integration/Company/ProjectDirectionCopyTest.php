<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Application\ProjectDirection\CopyProjectDirectionsAction;
use App\Company\Application\ProjectDirection\DeleteProjectDirectionAction;
use App\Company\Application\ProjectDirection\ProjectDirectionCopyPreview;
use App\Company\Application\ProjectDirection\ProjectDirectionExchange;
use App\Company\Application\ProjectDirection\SaveProjectDirectionAction;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Repository\ProjectDirectionCopyMappingRepository;
use App\Company\Repository\ProjectDirectionRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;

final class ProjectDirectionCopyTest extends IntegrationTestCase
{
    private CopyProjectDirectionsAction $copy;
    private ProjectDirectionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = static::getContainer()->get(ProjectDirectionRepository::class);
        $this->copy = new CopyProjectDirectionsAction(
            $this->connection,
            $this->repository,
            new ProjectDirectionCopyMappingRepository($this->connection),
            new ProjectDirectionExchange($this->repository),
        );
    }

    public function testCopiesTreeAndSystemParentAndPreservesLocalEditsOnRepeat(): void
    {
        $source = $this->company();
        $target = $this->company();
        $sourceSystem = $this->project($source, 'SYS_A', systemCode: ProjectDirection::CODE_GENERAL);
        $targetSystem = $this->project($target, 'SYS_B', systemCode: ProjectDirection::CODE_GENERAL);
        $parent = $this->project($source, 'PARENT', $sourceSystem);
        $child = $this->project($source, 'CHILD', $parent);
        $sourceId = (string) $source->getId();
        $targetId = (string) $target->getId();

        $preview = $this->copy->preview($sourceId, $targetId);
        self::assertSame([], $preview->errors);
        self::assertCount(2, $preview->creations);
        self::assertCount(1, $this->repository->readDirectory($targetId)['projects']);
        $result = $this->copy->confirm($sourceId, $targetId, $preview->sourceRevision, $preview->targetRevision, []);
        self::assertSame([], $result->errors);
        $projects = array_column($this->repository->readDirectory($targetId)['projects'], null, 'code');
        self::assertSame($targetSystem->getId(), $projects['PARENT']['parentId']);
        self::assertSame($projects['PARENT']['id'], $projects['CHILD']['parentId']);
        self::assertNotSame($child->getId(), $projects['CHILD']['id']);
        self::assertSame(7, Uuid::fromString($projects['CHILD']['id'])->getVersion());
        self::assertSame(['CHILD', "Описание\nпроекта", -7], [$projects['CHILD']['name'], $projects['CHILD']['description'], $projects['CHILD']['sort']]);

        $saved = $this->repository->findOneByIdAndCompanyId($projects['CHILD']['id'], $targetId);
        self::assertNotNull($saved);
        $saved->setName('Локальная правка')->setCode('LOCAL');
        $this->em->flush();
        $this->project($source, 'GRANDCHILD', $child);

        $repeat = $this->copyDirectory($source, $target);
        self::assertSame([], $repeat->errors);
        self::assertCount(1, $repeat->creations);
        $projects = array_column($this->repository->readDirectory($targetId)['projects'], null, 'code');
        self::assertSame('Локальная правка', $projects['LOCAL']['name']);
        self::assertSame($projects['LOCAL']['id'], $projects['GRANDCHILD']['parentId']);

        $delete = static::getContainer()->get(DeleteProjectDirectionAction::class);
        self::assertTrue($delete($targetId, $projects['LOCAL']['id']));
        $repeat = $this->copyDirectory($source, $target);
        self::assertSame([], $repeat->errors);
        self::assertSame(['CHILD'], array_column($repeat->creations, 'code'));
    }

    public function testMissingSystemProjectBlocksCopy(): void
    {
        $source = $this->company();
        $target = $this->company();
        $this->project($source, 'SYSTEM', systemCode: ProjectDirection::CODE_GENERAL);
        $this->project($source, 'REGULAR');
        $preview = $this->copy->preview((string) $source->getId(), (string) $target->getId());

        self::assertStringContainsString(ProjectDirection::CODE_GENERAL, implode(' ', $preview->errors));
        self::assertSame([], $this->repository->readDirectory((string) $target->getId())['projects']);
    }

    public function testConflictChoicesAndMappingsWithoutMappedAncestors(): void
    {
        $source = $this->company();
        $target = $this->company();
        $existingAncestor = $this->project($target, 'LOCAL');
        $existing = $this->project($target, 'COLLISION', $existingAncestor);
        $this->project($target, 'REPLACE');
        $this->project($target, 'SKIP');
        $link = $this->project($source, 'COLLISION');
        $replace = $this->project($source, 'REPLACE');
        $skip = $this->project($source, 'SKIP');
        $this->project($source, 'SKIPPED_CHILD', $skip);
        $linkedChild = $this->project($source, 'COPIED_CHILD', $link);
        $this->project($source, 'REPLACED_CHILD', $replace);

        $decisions = [
            (string) $link->getId() => ['mode' => 'link', 'code' => ''],
            (string) $replace->getId() => ['mode' => 'rename', 'code' => ' free '],
        ];
        $before = $this->repository->readDirectory((string) $target->getId());
        $result = $this->copyDirectory($source, $target, $decisions);
        self::assertSame([], $result->errors);
        $after = $this->repository->readDirectory((string) $target->getId());
        $projects = array_column($after['projects'], null, 'code');
        self::assertNotSame($before['revision'], $after['revision']);
        self::assertSame($before['projects'][(string) $existing->getId()], $projects['COLLISION']);
        self::assertSame($existing->getId(), $projects['COPIED_CHILD']['parentId']);
        self::assertSame($projects['FREE']['id'], $projects['REPLACED_CHILD']['parentId']);
        self::assertArrayNotHasKey('SKIPPED_CHILD', $projects);

        $linkedChild->setParent($skip);
        $this->em->flush();
        $this->project($source, 'NEW_GRANDCHILD', $linkedChild);
        $repeat = $this->copyDirectory($source, $target);
        self::assertSame([], $repeat->errors);
        self::assertSame([$projects['COPIED_CHILD']['id']], array_column($repeat->creations, 'parentId'));
    }

    public function testReplacementCodeMustBeFreeAcrossTheWholePlan(): void
    {
        $source = $this->company();
        $target = $this->company();
        $conflict = $this->project($source, 'TAKEN');
        $this->project($source, 'NEW');
        $this->project($target, 'TAKEN');
        $sourceId = (string) $source->getId();
        $targetId = (string) $target->getId();
        $decisions = [(string) $conflict->getId() => ['mode' => 'rename', 'code' => 'NEW']];
        $preview = $this->copy->preview($sourceId, $targetId, $decisions);

        self::assertStringContainsString('выбран один код NEW', implode(' ', $preview->errors));
        $result = $this->copy->confirm($sourceId, $targetId, $preview->sourceRevision, $preview->targetRevision, $decisions);
        self::assertSame($preview->errors, $result->errors);
        self::assertSame(['TAKEN'], array_column($this->repository->readDirectory($targetId)['projects'], 'code'));
    }

    public function testLinkOnlyAdvancesRevisionAndCannotBeConfirmedAgain(): void
    {
        $source = $this->company();
        $target = $this->company();
        $link = $this->project($source, 'SAME');
        $this->project($target, 'SAME');
        $sourceId = (string) $source->getId();
        $targetId = (string) $target->getId();
        $decisions = [(string) $link->getId() => ['mode' => 'link', 'code' => '']];
        $preview = $this->copy->preview($sourceId, $targetId, $decisions);
        $result = $this->copy->confirm($sourceId, $targetId, $preview->sourceRevision, $preview->targetRevision, $decisions);

        self::assertSame([], $result->errors);
        self::assertCount(1, $result->mappings);
        self::assertSame([], $result->creations);
        self::assertNotSame($preview->targetRevision, $this->repository->getDirectoryRevision($targetId));
        $repeat = $this->copy->confirm($sourceId, $targetId, $preview->sourceRevision, $preview->targetRevision, $decisions);
        self::assertStringContainsString('изменился', implode(' ', $repeat->errors));
    }

    #[DataProvider('changedDirectories')]
    public function testChangesInEitherDirectoryInvalidatePreview(bool $changeSource): void
    {
        $source = $this->company();
        $target = $this->company();
        $this->project($source, 'ORIGINAL');
        $sourceId = (string) $source->getId();
        $targetId = (string) $target->getId();
        $preview = $this->copy->preview($sourceId, $targetId);
        $company = $changeSource ? $source : $target;
        $save = static::getContainer()->get(SaveProjectDirectionAction::class);
        self::assertNull($save(new ProjectDirection(Uuid::uuid7()->toString(), $company, 'Новый проект'), true));

        $result = $this->copy->confirm($sourceId, $targetId, $preview->sourceRevision, $preview->targetRevision, []);

        self::assertStringContainsString('изменился', implode(' ', $result->errors));
        self::assertArrayNotHasKey('ORIGINAL', array_column($this->repository->readDirectory($targetId)['projects'], null, 'code'));
    }

    /** @return iterable<array{bool}> */
    public static function changedDirectories(): iterable
    {
        yield 'source' => [true];
        yield 'target' => [false];
    }

    #[DataProvider('changedDirectories')]
    public function testConfirmationWaitsForConcurrentWriterInEitherDirectory(bool $lockSource): void
    {
        $source = $this->company();
        $target = $this->company();
        $this->project($source, 'ORIGINAL');
        $sourceId = (string) $source->getId();
        $targetId = (string) $target->getId();
        $preview = $this->copy->preview($sourceId, $targetId);
        $writer = DriverManager::getConnection($this->connection->getParams());
        $writer->beginTransaction();
        try {
            $writer->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => 'project_directions:'.($lockSource ? $sourceId : $targetId)]);
            $this->connection->executeStatement("SET lock_timeout = '200ms'");
            $this->expectException(DriverException::class);
            $this->expectExceptionMessage('lock timeout');
            $this->copy->confirm($sourceId, $targetId, $preview->sourceRevision, $preview->targetRevision, []);
        } finally {
            $this->connection->executeStatement('SET lock_timeout = 0');
            $writer->rollBack();
            $writer->close();
        }
    }

    public function testFailureDuringCorrespondenceWriteRollsBackProjectsAndCorrespondences(): void
    {
        $source = $this->company();
        $target = $this->company();
        $this->project($source, 'NEW');
        $link = $this->project($source, 'LINK');
        $this->project($target, 'LINK');
        $sourceId = (string) $source->getId();
        $targetId = (string) $target->getId();
        $before = $this->repository->readDirectory($targetId);
        $decisions = [(string) $link->getId() => ['mode' => 'link', 'code' => '']];
        $this->connection->executeStatement(<<<'SQL'
            CREATE FUNCTION pg_temp.fail_copy_mapping_test() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE unique_violation USING MESSAGE = 'Ошибка записи соответствия';
            END;
            $$;
            CREATE TRIGGER fail_copy_mapping_test AFTER INSERT ON project_direction_copy_mappings
                FOR EACH STATEMENT EXECUTE FUNCTION pg_temp.fail_copy_mapping_test();
            SQL);

        try {
            $this->copyDirectory($source, $target, $decisions);
            self::fail('Запись должна откатиться.');
        } catch (UniqueConstraintViolationException $error) {
            self::assertStringContainsString('Ошибка записи соответствия', $error->getMessage());
        }

        self::assertSame($before, $this->repository->readDirectory($targetId));
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM project_direction_copy_mappings WHERE target_company_id = :companyId',
            ['companyId' => $targetId],
        ));
    }

    /** @param array<string, array{mode: string, code: string}> $decisions */
    private function copyDirectory(Company $source, Company $target, array $decisions = []): ProjectDirectionCopyPreview
    {
        $sourceId = (string) $source->getId();
        $targetId = (string) $target->getId();
        $preview = $this->copy->preview($sourceId, $targetId, $decisions);
        self::assertSame([], $preview->errors);

        return $this->copy->confirm($sourceId, $targetId, $preview->sourceRevision, $preview->targetRevision, $decisions);
    }

    private function company(): Company
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->build();
        $owner->setEmail(Uuid::uuid7()->toString().'@example.test');
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    private function project(Company $company, string $code, ?ProjectDirection $parent = null, ?string $systemCode = null): ProjectDirection
    {
        $project = (new ProjectDirection(Uuid::uuid7()->toString(), $company, $code, $systemCode))
            ->setCode($code)
            ->setDescription("Описание\nпроекта")
            ->setSort(-7)
            ->setParent($parent);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
