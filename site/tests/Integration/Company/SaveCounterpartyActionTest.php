<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Application\DTO\CounterpartyFormData;
use App\Company\Application\RefreshCounterpartyDisplayNamesAction;
use App\Company\Application\SaveCounterpartyAction;
use App\Company\Domain\Service\CounterpartyDisplayNameFormatter;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Enum\CounterpartyType;
use App\Company\Exception\CounterpartyInnAlreadyExistsException;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class SaveCounterpartyActionTest extends IntegrationTestCase
{
    private SaveCounterpartyAction $save;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var SaveCounterpartyAction $save */
        $save = self::getContainer()->get(SaveCounterpartyAction::class);
        $this->save = $save;

        $owner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000000010')->withEmail('owner-save@example.com')->build();
        $this->company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->flush();
    }

    public function testCreateWritesNameAndDerivedFieldsTogether(): void
    {
        // When
        $counterparty = ($this->save)($this->company, $this->formData('ООО "Ромашка"', '7707083893', '770701001'));

        // Then
        self::assertSame('ООО "Ромашка"', $counterparty->getName());
        self::assertSame('РОМАШКА', $counterparty->getNameCore());
        self::assertSame('ООО', $counterparty->getLegalFormHint());
        self::assertSame('7707083893', $counterparty->getInn());
        self::assertSame('770701001', $counterparty->getKpp());
    }

    /**
     * Внешнее ревью этапа 2: форма загрузила карточку → пересчёт записал авто-название →
     * пользователь переименовал. В снимке Doctrine поля были пустыми, flush их не трогал, и в БД
     * оставалось название от старого name с текущей версией — пересчёт его больше не брал.
     */
    public function testRenameAfterConcurrentRefreshResetsAutoDisplayNameInDatabase(): void
    {
        // Given: карточка загружена до пересчёта
        $counterparty = ($this->save)($this->company, $this->formData('ООО "Ромашка"', null, null));
        $id = $counterparty->getId();
        $this->connection->executeStatement(
            'UPDATE "counterparty" SET display_name = :name, display_name_source = :source, display_rules_version = :version WHERE id = :id',
            ['name' => 'ООО «Ромашка»', 'source' => 'auto', 'version' => CounterpartyDisplayNameFormatter::RULES_VERSION, 'id' => $id],
        );

        // When: пользователь переименовал ту же загруженную сущность
        ($this->save)($this->company, $this->formData('ООО "Лютик"', null, null), $counterparty);

        // Then: авто-название сброшено — пересчёт возьмёт новое name
        self::assertSame([null, null, null], $this->displayRow($id));
        $result = ($this->refresh())(false);
        self::assertSame(1, $result->processed);
        self::assertSame('ООО «Лютик»', $this->displayRow($id)[0]);
    }

    public function testSavingWithoutRenameKeepsAutoDisplayName(): void
    {
        // Given
        $counterparty = ($this->save)($this->company, $this->formData('ООО "Ромашка"', null, null));
        $id = $counterparty->getId();
        $this->connection->executeStatement(
            'UPDATE "counterparty" SET display_name = :name, display_name_source = :source, display_rules_version = :version WHERE id = :id',
            ['name' => 'ООО «Ромашка»', 'source' => 'auto', 'version' => CounterpartyDisplayNameFormatter::RULES_VERSION, 'id' => $id],
        );

        // When: правка ИНН, название то же
        ($this->save)($this->company, $this->formData('ООО "Ромашка"', '7707083893', null), $counterparty);

        // Then
        self::assertSame(['ООО «Ромашка»', 'auto', CounterpartyDisplayNameFormatter::RULES_VERSION], $this->displayRow($id));
    }

    public function testRenameKeepsManualDisplayNameInDatabase(): void
    {
        // Given
        $counterparty = ($this->save)($this->company, $this->formData('ООО "Ромашка"', null, null));
        $id = $counterparty->getId();
        $this->connection->executeStatement(
            'UPDATE "counterparty" SET display_name = :name, display_name_source = :source WHERE id = :id',
            ['name' => 'Ромашка (наш поставщик)', 'source' => 'manual', 'id' => $id],
        );

        // When
        ($this->save)($this->company, $this->formData('ООО "Лютик"', null, null), $counterparty);

        // Then
        self::assertSame(['Ромашка (наш поставщик)', 'manual', null], $this->displayRow($id));
    }

    public function testCreateCollapsesWhitespaceInName(): void
    {
        // When
        $counterparty = ($this->save)($this->company, $this->formData('ООО   "Ромашка"', null, null));

        // Then
        self::assertSame('ООО "Ромашка"', $counterparty->getName());
        self::assertSame('РОМАШКА', $counterparty->getNameCore());
    }

    public function testEmptyInnAndKppAreStoredAsNull(): void
    {
        // When
        $counterparty = ($this->save)($this->company, $this->formData('ООО "Ромашка"', '', ''));

        // Then
        self::assertNull($counterparty->getInn());
        self::assertNull($counterparty->getKpp());
    }

    public function testUpdateRenamesAndKeepsCreatedAt(): void
    {
        // Given
        $counterparty = ($this->save)($this->company, $this->formData('ООО "Ромашка"', '7707083893', null));
        $createdAt = $counterparty->getCreatedAt();

        // When
        ($this->save)($this->company, $this->formData('"Ромашка" ООО', '7707083893', null), $counterparty);

        // Then
        self::assertSame('"Ромашка" ООО', $counterparty->getName());
        self::assertSame('РОМАШКА', $counterparty->getNameCore());
        self::assertSame($createdAt, $counterparty->getCreatedAt());
    }

    public function testDuplicateInnThrows(): void
    {
        // Given
        ($this->save)($this->company, $this->formData('ООО "Ромашка"', '7707083893', null));

        // Then
        $this->expectException(CounterpartyInnAlreadyExistsException::class);

        // When
        ($this->save)($this->company, $this->formData('ООО "Василёк"', '7707083893', null));
    }

    public function testUpdateWithOwnInnDoesNotThrow(): void
    {
        // Given
        $counterparty = ($this->save)($this->company, $this->formData('ООО "Ромашка"', '7707083893', null));

        // When
        $updated = ($this->save)($this->company, $this->formData('ООО "Ромашка Плюс"', '7707083893', null), $counterparty);

        // Then
        self::assertSame('ООО "Ромашка Плюс"', $updated->getName());
    }

    public function testSameInnInAnotherCompanyIsAllowed(): void
    {
        // Given
        $otherOwner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000000011')->withEmail('owner-save-2@example.com')->build();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($otherOwner)->build();
        $this->em->persist($otherOwner);
        $this->em->persist($otherCompany);
        $this->em->flush();

        ($this->save)($otherCompany, $this->formData('ООО "Ромашка"', '7707083893', null));

        // When
        $counterparty = ($this->save)($this->company, $this->formData('ООО "Ромашка"', '7707083893', null));

        // Then
        self::assertTrue($counterparty->belongsToCompany($this->company->getId()));
    }

    /**
     * «ИП» при 10-значном ИНН — ошибка разбора названия. Подсказка сбрасывается,
     * само название не меняется.
     */
    public function testInconsistentLegalFormHintIsDropped(): void
    {
        // When
        $counterparty = ($this->save)($this->company, $this->formData('ИП Кулешова Анастасия Владимировна', '7707083893', null));

        // Then
        self::assertNull($counterparty->getLegalFormHint());
        self::assertSame('ИП Кулешова Анастасия Владимировна', $counterparty->getName());
        self::assertSame('КУЛЕШОВА АНАСТАСИЯ ВЛАДИМИРОВНА', $counterparty->getNameCore());
    }

    public function testConsistentIpHintIsKeptForTwelveDigitInn(): void
    {
        // When
        $counterparty = ($this->save)($this->company, $this->formData('ИП Кулешова Анастасия Владимировна', '503200000010', null));

        // Then
        self::assertSame('ИП', $counterparty->getLegalFormHint());
    }

    /**
     * Название из одних кавычек не должно приводить к 500: нормализатор total.
     */
    public function testQuotesOnlyNameDoesNotBreakSave(): void
    {
        // When
        $counterparty = ($this->save)($this->company, $this->formData('""', null, null));

        // Then
        self::assertNotSame('', $counterparty->getNameCore());
        self::assertNull($counterparty->getLegalFormHint());
    }

    public function testPersistedRowIsReadableAfterClear(): void
    {
        // Given
        $counterparty = ($this->save)($this->company, $this->formData('ООО "Ромашка"', '7707083893', null));
        $id = $counterparty->getId();
        $this->em->clear();

        // When
        $reloaded = $this->em->find(Counterparty::class, $id);

        // Then
        self::assertInstanceOf(Counterparty::class, $reloaded);
        self::assertSame('РОМАШКА', $reloaded->getNameCore());
    }

    public function testExistingCounterpartyIsNotDuplicatedOnUpdate(): void
    {
        // Given
        $existing = CounterpartyBuilder::aCounterparty()
            ->withId('33333333-3333-3333-3333-000000000050')
            ->withCompany($this->company)
            ->withName('ООО "Ромашка"')
            ->withInn(null)
            ->build();
        $this->em->persist($existing);
        $this->em->flush();

        // When
        ($this->save)($this->company, $this->formData('ООО "Ромашка"', '7707083893', null), $existing);

        // Then
        $count = (int) $this->connection->fetchOne(
            'SELECT count(*) FROM "counterparty" WHERE company_id = :companyId',
            ['companyId' => $this->company->getId()],
        );
        self::assertSame(1, $count);
    }

    private function refresh(): RefreshCounterpartyDisplayNamesAction
    {
        /** @var RefreshCounterpartyDisplayNamesAction $refresh */
        $refresh = self::getContainer()->get(RefreshCounterpartyDisplayNamesAction::class);

        return $refresh;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?int}
     */
    private function displayRow(string $id): array
    {
        /** @var array{display_name: ?string, display_name_source: ?string, display_rules_version: int|string|null} $row */
        $row = $this->connection->fetchAssociative(
            'SELECT display_name, display_name_source, display_rules_version FROM "counterparty" WHERE id = :id',
            ['id' => $id],
        );

        return [$row['display_name'], $row['display_name_source'], null === $row['display_rules_version'] ? null : (int) $row['display_rules_version']];
    }

    private function formData(string $name, ?string $inn, ?string $kpp): CounterpartyFormData
    {
        $data = new CounterpartyFormData();
        $data->name = $name;
        $data->inn = $inn;
        $data->kpp = $kpp;
        $data->type = CounterpartyType::LEGAL_ENTITY;

        return $data;
    }
}
