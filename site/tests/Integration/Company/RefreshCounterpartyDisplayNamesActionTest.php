<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Application\RefreshCounterpartyDisplayNamesAction;
use App\Company\Domain\Service\CounterpartyDisplayNameFormatter;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class RefreshCounterpartyDisplayNamesActionTest extends IntegrationTestCase
{
    private RefreshCounterpartyDisplayNamesAction $refresh;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var RefreshCounterpartyDisplayNamesAction $refresh */
        $refresh = self::getContainer()->get(RefreshCounterpartyDisplayNamesAction::class);
        $this->refresh = $refresh;

        $owner = UserBuilder::aUser()->withEmail('owner-display-name@example.com')->build();
        $this->company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($this->company);
    }

    public function testDryRunReportsAndWritesNothing(): void
    {
        // Given
        $id = $this->persist($this->company, 'ОБЩЕСТВО С ОГРАНИЧЕННОЙ ОТВЕТСТВЕННОСТЬЮ "РВБ"');
        $this->persist($this->company, 'Ozon');
        $this->em->flush();

        // When
        $result = ($this->refresh)(true);

        // Then
        self::assertSame(2, $result->processed);
        self::assertSame(1, $result->changed);
        self::assertSame(1, $result->unrecognised);
        self::assertSame('ООО «РВБ»', $result->changedSamples[0]['after']);
        self::assertSame('Ozon', $result->unrecognisedSamples[0]['raw']);
        self::assertSame(['display_name' => null, 'display_name_source' => null, 'display_rules_version' => null], $this->fetchDisplay($id));
    }

    public function testWritesDisplayNameSourceAndVersionWithoutTouchingRawOrUpdatedAt(): void
    {
        // Given
        $id = $this->persist($this->company, 'ИНДИВИДУАЛЬНЫЙ ПРЕДПРИНИМАТЕЛЬ ЛИЗУНОВА ОЛЬГА НИКОЛАЕВНА');
        $unrecognisedId = $this->persist($this->company, 'Ozon');
        $this->em->flush();
        $before = $this->fetchRow($id);

        // When
        ($this->refresh)(false);

        // Then
        self::assertSame([
            'display_name' => 'ИП Лизунова Ольга Николаевна',
            'display_name_source' => 'auto',
            'display_rules_version' => CounterpartyDisplayNameFormatter::RULES_VERSION,
        ], $this->fetchDisplay($id));
        $after = $this->fetchRow($id);
        self::assertSame($before['name'], $after['name']);
        self::assertSame($before['updated_at'], $after['updated_at']);
        // Нераспознанное обработано: null, но с версией — повторно не берётся.
        self::assertSame(['display_name' => null, 'display_name_source' => 'auto', 'display_rules_version' => CounterpartyDisplayNameFormatter::RULES_VERSION], $this->fetchDisplay($unrecognisedId));
    }

    public function testSecondRunFindsNothing(): void
    {
        // Given
        $this->persist($this->company, 'ООО "ИНТЕРНЕТ МАРКЕТИНГ"');
        $this->em->flush();
        ($this->refresh)(false);

        // When
        $result = ($this->refresh)(false);

        // Then
        self::assertSame(0, $result->processed);
    }

    public function testManualDisplayNameIsSkipped(): void
    {
        // Given
        $counterparty = $this->build($this->company, 'ООО "Ромашка"');
        $counterparty->setManualDisplayName('Ромашка (наш поставщик)');
        $this->em->persist($counterparty);
        $this->em->flush();

        // When
        $result = ($this->refresh)(false);

        // Then
        self::assertSame(0, $result->processed);
        self::assertSame('Ромашка (наш поставщик)', $this->fetchDisplay($counterparty->getId())['display_name']);
        self::assertSame('manual', $this->fetchDisplay($counterparty->getId())['display_name_source']);
    }

    public function testOlderRulesVersionIsRecalculated(): void
    {
        // Given: посчитано прошлой версией правил
        $id = $this->persist($this->company, 'ООО "Ромашка"');
        $this->em->flush();
        $this->connection->executeStatement(
            'UPDATE "counterparty" SET display_name = :name, display_name_source = :source, display_rules_version = :version WHERE id = :id',
            ['name' => 'устарело', 'source' => 'auto', 'version' => CounterpartyDisplayNameFormatter::RULES_VERSION - 1, 'id' => $id],
        );

        // When
        $result = ($this->refresh)(false);

        // Then
        self::assertSame(1, $result->changed);
        self::assertSame('ООО «Ромашка»', $this->fetchDisplay($id)['display_name']);
    }

    public function testAllCompaniesAcrossSeveralBatches(): void
    {
        // Given: больше одной порции (200) и две компании
        $otherOwner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000000007')->withEmail('owner-display-name-2@example.com')->build();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($otherOwner)->build();
        $this->em->persist($otherOwner);
        $this->em->persist($otherCompany);
        for ($i = 1; $i <= 205; ++$i) {
            $this->persist($this->company, sprintf('ООО "Поставщик %03d"', $i));
        }
        $this->persist($otherCompany, 'АО "Чужая"');
        $this->em->flush();

        // When
        $result = ($this->refresh)(false);

        // Then
        self::assertSame(206, $result->processed);
        self::assertSame(206, $result->changed);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM "counterparty" WHERE display_rules_version IS NULL'));
    }

    public function testDryRunAcrossSeveralBatchesTerminatesAndWritesNothing(): void
    {
        // Given: в dry-run строки остаются в очереди — завершение держится только на keyset
        for ($i = 1; $i <= 205; ++$i) {
            $this->persist($this->company, sprintf('ООО "Поставщик %03d"', $i));
        }
        $this->em->flush();

        // When
        $result = ($this->refresh)(true, 0);

        // Then
        self::assertSame(205, $result->processed);
        self::assertSame(205, $result->changed);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM "counterparty" WHERE display_rules_version IS NOT NULL'));
    }

    public function testRecognisedNameThatBecomesUnrecognisedIsCountedSeparately(): void
    {
        // Given: прошлая версия правил что-то показывала для «Ozon»
        $id = $this->persist($this->company, 'Ozon');
        $this->em->flush();
        $this->connection->executeStatement(
            'UPDATE "counterparty" SET display_name = :name, display_name_source = :source, display_rules_version = :version WHERE id = :id',
            ['name' => 'Озон', 'source' => 'auto', 'version' => CounterpartyDisplayNameFormatter::RULES_VERSION - 1, 'id' => $id],
        );

        // When
        $result = ($this->refresh)(false);

        // Then
        self::assertSame(1, $result->changed);
        self::assertSame(1, $result->becameUnrecognised);
        self::assertSame([], $result->changedSamples);
        self::assertNull($this->fetchDisplay($id)['display_name']);
    }

    private function build(Company $company, string $name): Counterparty
    {
        return CounterpartyBuilder::aCounterparty()
            ->withId(Uuid::uuid7()->toString())
            ->withCompany($company)
            ->withName($name)
            ->withInn(null)
            ->build();
    }

    private function persist(Company $company, string $name): string
    {
        $counterparty = $this->build($company, $name);
        $this->em->persist($counterparty);

        return $counterparty->getId();
    }

    /**
     * @return array{display_name: ?string, display_name_source: ?string, display_rules_version: ?int}
     */
    private function fetchDisplay(string $id): array
    {
        /** @var array{display_name: ?string, display_name_source: ?string, display_rules_version: int|string|null} $row */
        $row = $this->connection->fetchAssociative('SELECT display_name, display_name_source, display_rules_version FROM "counterparty" WHERE id = :id', ['id' => $id]);

        return [
            'display_name' => $row['display_name'],
            'display_name_source' => $row['display_name_source'],
            'display_rules_version' => null === $row['display_rules_version'] ? null : (int) $row['display_rules_version'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRow(string $id): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->connection->fetchAssociative('SELECT name, updated_at FROM "counterparty" WHERE id = :id', ['id' => $id]);

        return $row;
    }
}
