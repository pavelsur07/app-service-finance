<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Application\ProjectDirection\DeleteProjectDirectionAction;
use App\Company\Application\ProjectDirection\ImportProjectDirectionsAction;
use App\Company\Application\ProjectDirection\ProjectDirectionExchange;
use App\Company\Application\ProjectDirection\SaveProjectDirectionAction;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Repository\ProjectDirectionRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;

/** @phpstan-import-type ProjectRow from ProjectDirectionRepository */
final class ProjectDirectionImportTest extends IntegrationTestCase
{
    private const FIRST = '11111111-1111-4111-8111-111111111111';
    private const SECOND = '22222222-2222-4222-8222-22222222222a';
    private const THIRD = '33333333-3333-4333-8333-333333333333';
    private const FOURTH = '44444444-4444-4444-8444-444444444444';

    private ProjectDirectionExchange $exchange;
    private ImportProjectDirectionsAction $import;

    protected function setUp(): void
    {
        parent::setUp();
        $repository = static::getContainer()->get(ProjectDirectionRepository::class);
        $this->exchange = new ProjectDirectionExchange($repository);
        $this->import = new ImportProjectDirectionsAction($this->connection, $repository, $this->exchange);
    }

    public function testImportUpdatesByIdSwapsCodesAndKeepsSystemProjectAndOmittedRows(): void
    {
        $company = $this->createCompany();
        $first = $this->persistProject($company, self::FIRST, 'ALPHA', ProjectDirection::CODE_GENERAL);
        $this->persistProject($company, self::SECOND, 'BETA');
        $this->persistProject($company, self::FOURTH, null);
        $exchange = $this->exchange;
        $import = $this->import;

        $json = $this->file($company, [
            self::row(self::THIRD, ' gamma ', self::SECOND),
            self::row(self::FIRST, ' beta ', self::THIRD, "Описание\nпроекта"),
            array_replace(self::row(self::SECOND, 'alpha'), ['id' => strtoupper(self::SECOND)]),
        ]);
        $preview = $exchange->preview((string) $company->getId(), $json);
        self::assertSame([], $preview->errors);
        self::assertSame([1, 2, 0], [$preview->created, $preview->updated, $preview->unchanged]);
        self::assertSame('ALPHA', $first->getCode());
        self::assertSame(3, (int) $this->connection->fetchOne(
            'SELECT COUNT(id) FROM project_directions WHERE company_id = :companyId',
            ['companyId' => $company->getId()],
        ));

        $result = $import($company, $json, $preview->revision);
        self::assertSame([], $result->errors);
        $export = $exchange->export((string) $company->getId());
        self::assertSame(1, $export['version']);
        self::assertSame($company->getId(), $export['companyId']);
        self::assertCount(4, $export['projects']);
        self::assertEquals(
            [
                self::row(self::FIRST, 'BETA', self::THIRD, "Описание\nпроекта"),
                self::row(self::SECOND, 'ALPHA'),
                self::row(self::THIRD, 'GAMMA', self::SECOND),
            ],
            array_slice($export['projects'], 0, 3),
        );
        self::assertSame(ProjectDirection::CODE_GENERAL, $this->connection->fetchOne(
            'SELECT system_code FROM project_directions WHERE company_id = :companyId AND id = :id',
            ['companyId' => $company->getId(), 'id' => self::FIRST],
        ));

        $repeat = $exchange->preview((string) $company->getId(), $this->file($company, $export['projects']));
        self::assertSame([0, 0, 4], [$repeat->created, $repeat->updated, $repeat->unchanged]);
        self::assertSame([], $import($company, $this->file($company, $export['projects']), $repeat->revision)->errors);
        self::assertSame($export, $exchange->export((string) $company->getId()));
    }

    #[DataProvider('invalidFiles')]
    public function testInvalidImportLeavesDirectoryIntact(string $scenario, string $error): void
    {
        $company = $this->createCompany();
        $other = $this->createCompany();
        $first = $this->persistProject($company, self::FIRST, 'ALPHA');
        $this->persistProject($company, self::SECOND, null)->setParent($first);
        $this->persistProject($other, self::FOURTH, 'ALPHA');
        $this->em->flush();

        $exchange = $this->exchange;
        $import = $this->import;
        $before = $exchange->export((string) $company->getId());
        $json = match ($scenario) {
            'broken' => '[',
            'version' => $this->file($company, [], 2),
            'company' => $this->file($other, [self::row(self::THIRD)]),
            'rows' => $this->file($company, new \stdClass()),
            'uuid' => $this->file($company, [self::row(self::THIRD), self::row(self::THIRD)]),
            'foreign' => $this->file($company, [self::row(self::THIRD), self::row(self::FOURTH)]),
            'code file' => $this->file($company, [self::row(self::THIRD, 'a'), self::row(self::SECOND, ' A ')]),
            'code existing' => $this->file($company, [self::row(self::THIRD, ' alpha ')]),
            'parent' => $this->file($company, [self::row(self::THIRD, null, self::FOURTH)]),
            'cycle' => $this->file($company, [self::row(self::FIRST, null, self::SECOND)]),
            'sort' => $this->file($company, [array_replace(self::row(self::THIRD), ['sort' => 2147483648])]),
            'description' => $this->file($company, [self::row(self::THIRD, null, null, str_repeat('я', 301))]),
            'code format' => $this->file($company, [self::row(self::THIRD, '7FAIL')]),
            default => throw new \LogicException('Неизвестный тестовый сценарий.'),
        };

        $preview = $exchange->preview((string) $company->getId(), $json);
        self::assertStringContainsString($error, implode(' ', $preview->errors));
        self::assertSame($preview->errors, $import($company, $json, $preview->revision)->errors);
        self::assertSame($before, $exchange->export((string) $company->getId()));
    }

    /** @return iterable<array{string, string}> */
    public static function invalidFiles(): iterable
    {
        yield ['broken', 'Некорректный JSON'];
        yield ['version', 'версия'];
        yield ['company', 'другой компании'];
        yield ['rows', 'projects'];
        yield ['uuid', 'Запись 2: UUID'];
        yield ['foreign', 'Запись 2: UUID'];
        yield ['code file', 'Запись 2: код'];
        yield ['code existing', 'Запись 1: код'];
        yield ['parent', 'Запись 1: родитель'];
        yield ['cycle', 'Запись 1: цикл'];
        yield ['sort', 'Запись 1: сортировка'];
        yield ['description', 'Запись 1: описание'];
        yield ['code format', 'Запись 1: код'];
    }

    public function testPreviewBecomesStaleWhenOrdinaryEditsRestoreTheOriginalValues(): void
    {
        $company = $this->createCompany();
        $project = $this->persistProject($company, self::FIRST, null);
        $exchange = $this->exchange;
        $save = static::getContainer()->get(SaveProjectDirectionAction::class);
        $import = $this->import;
        $file = $this->file($company, [self::row(self::THIRD)]);

        $preview = $exchange->preview((string) $company->getId(), $file);
        $save((clone $project)->setName('Промежуточное имя'), false, $preview->revision);
        $save((clone $project)->setName('Исходный проект'), false, $exchange->preview((string) $company->getId(), $file)->revision);

        self::assertStringContainsString('изменился', implode(' ', $import($company, $file, $preview->revision)->errors));
        self::assertCount(1, $exchange->export((string) $company->getId())['projects']);
    }

    public function testStaleFormDoesNotOverwriteAnImportedChange(): void
    {
        $company = $this->createCompany();
        $project = $this->persistProject($company, self::FIRST, 'ALPHA');
        $draft = (clone $project)->setSort(50);
        $save = static::getContainer()->get(SaveProjectDirectionAction::class);
        $companyId = (string) $company->getId();
        $file = $this->file($company, [self::row(self::FIRST, 'BETA')]);
        $preview = $this->exchange->preview($companyId, $file);
        ($this->import)($company, $file, $preview->revision);

        $error = $save($draft, false, $preview->revision);

        self::assertNotNull($error);
        self::assertStringContainsString('изменился', $error['message']);
        self::assertSame('BETA', $this->exchange->export($companyId)['projects'][0]['code']);
    }

    public function testImportResolvesParentsAcrossWriteBatches(): void
    {
        $company = $this->createCompany();
        $companyId = (string) $company->getId();
        $rows = [];
        for ($i = 1; $i <= 501; ++$i) {
            $rows[] = self::row(sprintf('90000000-0000-4000-8000-%012d', $i));
        }
        $rows[0]['parentId'] = $rows[500]['id'];
        $file = $this->file($company, $rows);
        $preview = $this->exchange->preview($companyId, $file);

        $result = ($this->import)($company, $file, $preview->revision);

        self::assertSame([], $result->errors);
        self::assertSame($rows, $this->exchange->export($companyId)['projects']);
    }

    public function testPreviewBecomesStaleWhenAnExistingProjectIsDeleted(): void
    {
        $company = $this->createCompany();
        $this->persistProject($company, self::FIRST, null);
        $companyId = (string) $company->getId();
        $delete = static::getContainer()->get(DeleteProjectDirectionAction::class);
        $file = $this->file($company, [self::row(self::THIRD)]);
        $preview = $this->exchange->preview($companyId, $file);

        self::assertTrue($delete($companyId, self::FIRST));
        self::assertStringContainsString('изменился', implode(' ', ($this->import)($company, $file, $preview->revision)->errors));
        self::assertSame([], $this->exchange->export($companyId)['projects']);
    }

    public function testInvalidRowsReturnABoundedErrorList(): void
    {
        $company = $this->createCompany();
        $file = $this->file($company, array_fill(0, 150, []));
        $preview = $this->exchange->preview((string) $company->getId(), $file);

        self::assertCount(101, $preview->errors);
        self::assertStringContainsString('Запись 100', $preview->errors[99]);
        self::assertStringContainsString('Показаны первые 100 ошибок', $preview->errors[100]);
    }

    public function testImportWaitsForTheCompanyWriterLock(): void
    {
        $company = $this->createCompany();
        $companyId = (string) $company->getId();
        $exchange = $this->exchange;
        $import = $this->import;
        $file = $this->file($company, [self::row(self::FIRST)]);
        $preview = $exchange->preview($companyId, $file);
        $writer = DriverManager::getConnection($this->connection->getParams());
        $writer->beginTransaction();
        try {
            $writer->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => 'project_directions:'.$companyId]);
            $this->connection->executeStatement("SET lock_timeout = '200ms'");
            try {
                $import($company, $file, $preview->revision);
                self::fail('Импорт должен ждать другую запись справочника.');
            } catch (DriverException $e) {
                self::assertSame('55P03', $e->getSQLState());
            }
        } finally {
            $this->connection->executeStatement('SET lock_timeout = 0');
            $writer->rollBack();
            $writer->close();
        }
        self::assertSame([], $exchange->export($companyId)['projects']);
    }

    public function testWriteFailureRollsBackPreviouslyFlushedRowsAndReleasedCodes(): void
    {
        $company = $this->createCompany();
        $this->persistProject($company, self::FIRST, 'ALPHA');
        $exchange = $this->exchange;
        $import = $this->import;
        $companyId = (string) $company->getId();
        $file = $this->file($company, [self::row(self::FIRST, 'BETA'), self::row(self::THIRD)]);
        $before = $exchange->export($companyId);
        $preview = $exchange->preview($companyId, $file);
        $this->connection->executeStatement(<<<'SQL'
            CREATE FUNCTION pg_temp.fail_project_direction_test() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE unique_violation USING MESSAGE = 'Ошибка записи проекта в тесте';
            END;
            $$;
            CREATE TRIGGER fail_project_direction_test BEFORE UPDATE OF code ON project_directions
                FOR EACH ROW WHEN (NEW.code = 'BETA') EXECUTE FUNCTION pg_temp.fail_project_direction_test();
            SQL);

        try {
            $import($company, $file, $preview->revision);
            self::fail('Импорт должен откатить предыдущие записи.');
        } catch (UniqueConstraintViolationException $e) {
            self::assertStringContainsString('Ошибка записи проекта в тесте', $e->getMessage());
        }

        self::assertSame($before, $exchange->export($companyId));
        self::assertSame($preview->revision, $exchange->preview($companyId, $file)->revision);
    }

    private function createCompany(): Company
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->build();
        $owner->setEmail(Uuid::uuid7()->toString().'@example.test');
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    private function persistProject(Company $company, string $id, ?string $code, ?string $systemCode = null): ProjectDirection
    {
        $project = (new ProjectDirection($id, $company, 'Исходный проект', $systemCode))->setCode($code);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    /** @param list<array<string, mixed>>|\stdClass $projects */
    private function file(Company $company, array|\stdClass $projects, int $version = 1): string
    {
        return json_encode(['version' => $version, 'companyId' => $company->getId(), 'projects' => $projects], \JSON_THROW_ON_ERROR);
    }

    /** @return ProjectRow */
    private static function row(string $id, ?string $code = null, ?string $parentId = null, ?string $description = null): array
    {
        return ['id' => $id, 'name' => 'Проект '.$id, 'code' => $code, 'description' => $description, 'sort' => -10, 'parentId' => $parentId];
    }
}
