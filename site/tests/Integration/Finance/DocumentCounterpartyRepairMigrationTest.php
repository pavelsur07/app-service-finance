<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use DoctrineMigrations\Version20261029120000;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

require_once dirname(__DIR__, 3).'/migrations/Version20261029120000.php';

/**
 * Починка «один документ ОПиУ — один контрагент» (docs/tasks/pnl-document-single-counterparty, Stage 3).
 * Расхождения строятся сырым SQL: через сущность их больше не создать.
 */
final class DocumentCounterpartyRepairMigrationTest extends IntegrationTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        // Тестовая БД уже прошла миграции — таблица бэкапа есть; в транзакции теста её убираем.
        $this->connection->executeStatement('DROP TABLE IF EXISTS document_counterparty_repair_backup');

        $owner = UserBuilder::aUser()->withIndex(2910)->build();
        $this->company = CompanyBuilder::aCompany()->withIndex(2910)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($this->company);
    }

    public function testRepairsMismatchesAndDownRestoresThem(): void
    {
        $bank = $this->counterparty('Банк');
        $lessor = $this->counterparty('Лизинг');

        // Шапка пуста, у операции контрагент — поднимается в шапку; вторая операция получает его же.
        $promote = $this->document(null, 2);
        $this->setOperationCounterparty($promote, 0, $lessor);
        // Шапка задана, операция пуста — получает контрагента шапки.
        $fill = $this->document($bank, 1);
        $this->setOperationCounterparty($fill, 0, null);
        // Шапка задана, у операции другой — побеждает шапка.
        $override = $this->document($bank, 1);
        $this->setOperationCounterparty($override, 0, $lessor);
        // Согласованные — не трогаются и в бэкап не попадают.
        $consistent = $this->document($bank, 2);
        $empty = $this->document(null, 1);
        $before = $this->state();

        $this->migrate('up');

        self::assertSame([$lessor->getId(), [$lessor->getId(), $lessor->getId()]], $this->documentState($promote));
        self::assertSame([$bank->getId(), [$bank->getId()]], $this->documentState($fill));
        self::assertSame([$bank->getId(), [$bank->getId()]], $this->documentState($override));
        self::assertSame($before[$consistent], $this->documentState($consistent));
        self::assertSame($before[$empty], $this->documentState($empty));
        self::assertSame(4, (int) $this->connection->fetchOne('SELECT count(*) FROM document_counterparty_repair_backup'));
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT count(*) FROM document_operations o JOIN documents d ON d.id = o.document_id WHERE o.counterparty_id IS DISTINCT FROM d.counterparty_id',
        ));

        $this->migrate('down');

        self::assertSame($before, $this->state());
        self::assertFalse((bool) $this->connection->fetchOne("SELECT to_regclass('document_counterparty_repair_backup') IS NOT NULL"));
    }

    public function testAbortsWithoutChangesWhenHeaderlessDocumentHasSeveralCounterparties(): void
    {
        $document = $this->document(null, 2);
        $this->setOperationCounterparty($document, 0, $this->counterparty('Первый'));
        $this->setOperationCounterparty($document, 1, $this->counterparty('Второй'));
        $before = $this->state();

        try {
            $this->migrate('up');
            self::fail('Неоднозначный документ не остановил миграцию.');
        } catch (AbortMigration $exception) {
            self::assertStringContainsString('1 P&L document(s)', $exception->getMessage());
        }

        self::assertSame($before, $this->state());
    }

    public function testAbortsWhenOperationCounterpartyBelongsToAnotherCompany(): void
    {
        $foreignCompany = CompanyBuilder::aCompany()->withIndex(2911)->withOwner($this->company->getUser() ?? throw new \LogicException('Нет владельца.'))->build();
        $this->em->persist($foreignCompany);
        $foreign = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid4()->toString())->withCompany($foreignCompany)->withName('Чужой')->build();
        $this->em->persist($foreign);
        $document = $this->document(null, 1);
        $this->setOperationCounterparty($document, 0, $foreign);

        $this->expectException(AbortMigration::class);
        $this->migrate('up');
    }

    private function migrate(string $direction): void
    {
        $migration = new Version20261029120000($this->connection, new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function counterparty(string $name): Counterparty
    {
        $counterparty = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid4()->toString())->withCompany($this->company)->withName($name)->build();
        $this->em->persist($counterparty);
        $this->em->flush();

        return $counterparty;
    }

    private function document(?Counterparty $counterparty, int $operations): string
    {
        $document = (new Document(Uuid::uuid4()->toString(), $this->company))->setCounterparty($counterparty);
        for ($i = 0; $i < $operations; ++$i) {
            $document->addOperation((new DocumentOperation())->setAmount(sprintf('%d.00', 100 + $i))->setComment((string) $i));
        }
        $this->em->persist($document);
        $this->em->flush();

        return (string) $document->getId();
    }

    private function setOperationCounterparty(string $documentId, int $index, ?Counterparty $counterparty): void
    {
        $this->connection->executeStatement(
            'UPDATE document_operations SET counterparty_id = :cp WHERE document_id = :doc AND comment = :i',
            ['cp' => $counterparty?->getId(), 'doc' => $documentId, 'i' => (string) $index],
        );
    }

    /**
     * @return array{0: string|null, 1: list<string|null>}
     */
    private function documentState(string $documentId): array
    {
        $header = $this->connection->fetchOne('SELECT counterparty_id FROM documents WHERE id = :id', ['id' => $documentId]);
        /** @var list<string|null> $operations */
        $operations = $this->connection->fetchFirstColumn(
            'SELECT counterparty_id FROM document_operations WHERE document_id = :id ORDER BY comment',
            ['id' => $documentId],
        );

        return [false === $header ? null : $header, $operations];
    }

    /**
     * @return array<string, array{0: string|null, 1: list<string|null>}>
     */
    private function state(): array
    {
        $state = [];
        foreach ($this->connection->fetchFirstColumn('SELECT id FROM documents ORDER BY id') as $id) {
            $state[(string) $id] = $this->documentState((string) $id);
        }

        return $state;
    }
}
