<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Application\ApplyCounterpartyDisplayNameAction;
use App\Company\Application\ResetCounterpartyDisplayNameAction;
use App\Company\Entity\Company;
use App\Company\Exception\CounterpartyDisplayNameNotApplicableException;
use App\Company\Exception\CounterpartyNotFoundException;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class ApplyCounterpartyDisplayNameActionTest extends IntegrationTestCase
{
    private ApplyCounterpartyDisplayNameAction $apply;
    private ResetCounterpartyDisplayNameAction $reset;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApplyCounterpartyDisplayNameAction $apply */
        $apply = self::getContainer()->get(ApplyCounterpartyDisplayNameAction::class);
        $this->apply = $apply;
        /** @var ResetCounterpartyDisplayNameAction $reset */
        $reset = self::getContainer()->get(ResetCounterpartyDisplayNameAction::class);
        $this->reset = $reset;

        $owner = UserBuilder::aUser()->withEmail('owner-apply-display@example.com')->build();
        $this->company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($this->company);
    }

    public function testAppliesServerSuggestionAsManualWithoutTouchingRawName(): void
    {
        // Given
        $id = $this->persist($this->company, 'АО "АЛЬФА-БАНК" Р/С 30233810714000000919');
        $before = $this->row($id);

        // When
        $applied = ($this->apply)($this->companyId(), $id);

        // Then
        self::assertSame('АО «АЛЬФА-БАНК»', $applied);
        $after = $this->row($id);
        self::assertSame('АО «АЛЬФА-БАНК»', $after['display_name']);
        self::assertSame('manual', $after['display_name_source']);
        self::assertNull($after['display_rules_version']);
        self::assertSame($before['name'], $after['name']);
        self::assertNotSame($before['updated_at'], $after['updated_at']);
    }

    public function testRepeatedApplyIsNoOp(): void
    {
        // Given
        $id = $this->persist($this->company, 'ООО "Ромашка"');
        ($this->apply)($this->companyId(), $id);
        $this->em->clear();

        // When
        $applied = ($this->apply)($this->companyId(), $id);

        // Then
        self::assertSame('ООО «Ромашка»', $applied);
        self::assertSame('manual', $this->row($id)['display_name_source']);
    }

    public function testNoSuggestionIsRejected(): void
    {
        $id = $this->persist($this->company, 'ФНС России');

        $this->expectException(CounterpartyDisplayNameNotApplicableException::class);
        $this->expectExceptionMessage('Предложение больше не актуально.');
        ($this->apply)($this->companyId(), $id);
    }

    public function testAlreadyNormalNameIsRejected(): void
    {
        $id = $this->persist($this->company, 'ООО «Ромашка»');

        $this->expectException(CounterpartyDisplayNameNotApplicableException::class);
        ($this->apply)($this->companyId(), $id);
    }

    public function testOtherManualNameIsNotOverwritten(): void
    {
        // Given: пользователь задал своё название
        $id = $this->persist($this->company, 'ООО "Ромашка"');
        $this->connection->executeStatement(
            'UPDATE "counterparty" SET display_name = :name, display_name_source = :source WHERE id = :id',
            ['name' => 'Ромашка (поставщик)', 'source' => 'manual', 'id' => $id],
        );
        $this->em->clear();

        // When
        try {
            ($this->apply)($this->companyId(), $id);
            self::fail('Ручное название не должно перезаписываться.');
        } catch (CounterpartyDisplayNameNotApplicableException $exception) {
            self::assertStringContainsString('задано вручную', $exception->getMessage());
        }

        // Then
        self::assertSame('Ромашка (поставщик)', $this->row($id)['display_name']);
    }

    public function testArchivedIsRejected(): void
    {
        $id = $this->persist($this->company, 'ООО "Ромашка"', archived: true);

        $this->expectException(CounterpartyDisplayNameNotApplicableException::class);
        ($this->apply)($this->companyId(), $id);
    }

    public function testOtherCompanyIsNotFound(): void
    {
        // Given
        $otherOwner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000000021')->withEmail('owner-apply-display-2@example.com')->build();
        $other = CompanyBuilder::aCompany()->withIndex(2)->withOwner($otherOwner)->build();
        $this->em->persist($otherOwner);
        $this->em->persist($other);
        $foreignId = $this->persist($other, 'ООО "Чужая"');

        // When / Then
        try {
            ($this->apply)($this->companyId(), $foreignId);
            self::fail('Чужой контрагент должен быть не найден.');
        } catch (CounterpartyNotFoundException) {
        }
        self::assertNull($this->row($foreignId)['display_name']);
    }

    public function testInvalidIdIsNotFound(): void
    {
        $this->expectException(CounterpartyNotFoundException::class);
        ($this->apply)($this->companyId(), 'not-a-uuid');
    }

    public function testResetReturnsSuggestionAndIsIdempotent(): void
    {
        // Given
        $id = $this->persist($this->company, 'ООО "Ромашка"');
        ($this->apply)($this->companyId(), $id);
        $this->em->clear();

        // When
        ($this->reset)($this->companyId(), $id);
        $this->em->clear();
        ($this->reset)($this->companyId(), $id);

        // Then: исходное показывается, предложение снова доступно
        self::assertSame(['display_name' => null, 'display_name_source' => null, 'display_rules_version' => null], array_intersect_key($this->row($id), array_flip(['display_name', 'display_name_source', 'display_rules_version'])));
        $this->em->clear();
        self::assertSame('ООО «Ромашка»', ($this->apply)($this->companyId(), $id));
    }

    public function testResetDoesNotTouchAutoName(): void
    {
        // Given: название поставил пересчёт правил
        $id = $this->persist($this->company, 'ООО "Ромашка"');
        $this->connection->executeStatement(
            'UPDATE "counterparty" SET display_name = :name, display_name_source = :source, display_rules_version = 2 WHERE id = :id',
            ['name' => 'ООО «Ромашка»', 'source' => 'auto', 'id' => $id],
        );
        $this->em->clear();

        // When / Then
        try {
            ($this->reset)($this->companyId(), $id);
            self::fail('Авто-название «Вернуть исходное» не снимает.');
        } catch (CounterpartyDisplayNameNotApplicableException) {
        }
        self::assertSame('auto', $this->row($id)['display_name_source']);
    }

    private function companyId(): string
    {
        return (string) $this->company->getId();
    }

    private function persist(Company $company, string $name, bool $archived = false): string
    {
        $builder = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid7()->toString())->withCompany($company)->withName($name)->withInn(null);
        if ($archived) {
            $builder = $builder->asArchived();
        }
        $counterparty = $builder->build();
        $this->em->persist($counterparty);
        $this->em->flush();

        return $counterparty->getId();
    }

    /**
     * @return array{name: string, display_name: ?string, display_name_source: ?string, display_rules_version: int|string|null, updated_at: string}
     */
    private function row(string $id): array
    {
        /** @var array{name: string, display_name: ?string, display_name_source: ?string, display_rules_version: int|string|null, updated_at: string} $row */
        $row = $this->connection->fetchAssociative(
            'SELECT name, display_name, display_name_source, display_rules_version, updated_at FROM "counterparty" WHERE id = :id',
            ['id' => $id],
        );

        return $row;
    }
}
