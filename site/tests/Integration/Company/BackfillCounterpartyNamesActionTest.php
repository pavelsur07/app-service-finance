<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Application\BackfillCounterpartyNamesAction;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class BackfillCounterpartyNamesActionTest extends IntegrationTestCase
{
    private BackfillCounterpartyNamesAction $backfill;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var BackfillCounterpartyNamesAction $backfill */
        $backfill = self::getContainer()->get(BackfillCounterpartyNamesAction::class);
        $this->backfill = $backfill;

        $owner = UserBuilder::aUser()->withEmail('owner-backfill@example.com')->build();
        $this->company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($this->company);
    }

    public function testFillsDerivedFieldsForLegacyRows(): void
    {
        // Given: строка, какой она приходит из БД до backfill
        $id = $this->persistLegacyRow('ООО "Ромашка"');

        // When
        $result = ($this->backfill)(false);

        // Then
        self::assertSame(1, $result->processed);
        self::assertSame(1, $result->updated);
        self::assertSame([
            'name_core' => 'РОМАШКА',
            'legal_form_hint' => 'ООО',
        ], $this->fetchDerived($id));
    }

    public function testDryRunWritesNothing(): void
    {
        // Given
        $id = $this->persistLegacyRow('ООО "Ромашка"');

        // When
        $result = ($this->backfill)(true);

        // Then
        self::assertSame(1, $result->updated);
        self::assertSame([
            'name_core' => null,
            'legal_form_hint' => null,
        ], $this->fetchDerived($id));
    }

    public function testSecondRunChangesNothing(): void
    {
        // Given
        $id = $this->persistLegacyRow('ООО "Ромашка"');
        ($this->backfill)(false);
        $before = $this->fetchRow($id);

        // When
        $result = ($this->backfill)(false);

        // Then
        self::assertSame(0, $result->updated);
        self::assertSame(1, $result->unchanged);
        self::assertSame($before, $this->fetchRow($id));
    }

    public function testUpdatedAtIsNotTouched(): void
    {
        // Given
        $id = $this->persistLegacyRow('ООО "Ромашка"');
        $before = $this->fetchRow($id)['updated_at'];

        // When
        ($this->backfill)(false);

        // Then
        self::assertSame($before, $this->fetchRow($id)['updated_at']);
    }

    /**
     * У исторических записей в названии встречаются двойные пробелы. Это не
     * переименование, и backfill не должен на них падать.
     */
    public function testNameWithExtraWhitespaceIsBackfilled(): void
    {
        // Given
        $id = $this->persistLegacyRow('ООО   "Ромашка"');

        // When
        $result = ($this->backfill)(false);

        // Then
        self::assertSame(1, $result->updated);
        self::assertSame([], $result->skipped);
        self::assertSame('РОМАШКА', $this->fetchDerived($id)['name_core']);
        // Само название не тронуто: backfill не переименовывает.
        self::assertSame('ООО   "Ромашка"', $this->fetchRow($id)['name']);
    }

    /**
     * Подсказка «ИП» при 10-значном ИНН сброшена при сохранении. Backfill не должен
     * её возвращать — иначе инвариант держится только до следующего прогона.
     */
    public function testInconsistentLegalFormHintIsNotRestored(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()
            ->withId('33333333-3333-3333-3333-000000000003')
            ->withCompany($this->company)
            ->withName('ИП Кулешова Анастасия Владимировна')
            ->withInn('7707083893')
            ->build();
        $this->em->persist($counterparty);
        $this->em->flush();
        $id = $counterparty->getId();

        $this->connection->executeStatement(
            'UPDATE "counterparty" SET name_core = NULL, legal_form_hint = NULL WHERE id = :id',
            ['id' => $id],
        );
        $this->em->clear();

        // When
        ($this->backfill)(false);

        // Then
        self::assertSame('КУЛЕШОВА АНАСТАСИЯ ВЛАДИМИРОВНА', $this->fetchDerived($id)['name_core']);
        self::assertNull($this->fetchDerived($id)['legal_form_hint']);

        // And: повторный прогон не считает это изменением
        self::assertSame(0, ($this->backfill)(false)->updated);
    }

    public function testEmptyNameIsSkippedNotFailed(): void
    {
        // Given: мусорная строка из легаси-данных
        $id = $this->persistLegacyRow('   ');

        // When
        $result = ($this->backfill)(false);

        // Then
        self::assertSame([$id], $result->skipped);
        self::assertSame(0, $result->updated);
        self::assertNull($this->fetchDerived($id)['name_core']);
    }

    /**
     * Строка создаётся через сущность, затем производные поля обнуляются SQL —
     * так выглядят записи, существовавшие до expand-миграции.
     */
    private function persistLegacyRow(string $name): string
    {
        $counterparty = CounterpartyBuilder::aCounterparty()
            ->withId('33333333-3333-3333-3333-000000000001')
            ->withCompany($this->company)
            ->withName('' === trim($name) ? 'Заглушка' : $name)
            ->withInn(null)
            ->build();

        $this->em->persist($counterparty);
        $this->em->flush();

        $id = $counterparty->getId();

        $this->connection->executeStatement(
            'UPDATE "counterparty" SET name = :name, name_core = NULL, legal_form_hint = NULL WHERE id = :id',
            ['name' => $name, 'id' => $id],
        );
        $this->em->clear();

        return $id;
    }

    /**
     * @return array{name_core: ?string, legal_form_hint: ?string}
     */
    private function fetchDerived(string $id): array
    {
        $row = $this->fetchRow($id);

        return [
            'name_core' => $row['name_core'],
            'legal_form_hint' => $row['legal_form_hint'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRow(string $id): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->connection->fetchAssociative(
            'SELECT name, name_core, legal_form_hint, inn, kpp, updated_at FROM "counterparty" WHERE id = :id',
            ['id' => $id],
        );

        return $row;
    }

    public function testCounterpartyEntityStaysLoadableWithNullDerivedFields(): void
    {
        // Given: гидрация строки, ещё не прошедшей backfill, не должна падать
        $id = $this->persistLegacyRow('ООО "Ромашка"');

        // When
        $counterparty = $this->em->find(Counterparty::class, $id);

        // Then
        self::assertInstanceOf(Counterparty::class, $counterparty);
        self::assertNull($counterparty->getNameCore());
    }
}
