<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company\Persistence;

use App\Company\Infrastructure\Persistence\CounterpartyDisplayNameWriter;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

/**
 * Пересчёт пишет авто-название условным UPDATE: правка пользователя между чтением порции
 * и записью не перезаписывается.
 */
final class CounterpartyDisplayNameWriterTest extends IntegrationTestCase
{
    private CounterpartyDisplayNameWriter $writer;
    private string $id;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var CounterpartyDisplayNameWriter $writer */
        $writer = self::getContainer()->get(CounterpartyDisplayNameWriter::class);
        $this->writer = $writer;

        $owner = UserBuilder::aUser()->withEmail('owner-display-writer@example.com')->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($owner)->build();
        $counterparty = CounterpartyBuilder::aCounterparty()->withCompany($company)->withName('ООО "Ромашка"')->withInn(null)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($counterparty);
        $this->em->flush();
        $this->id = $counterparty->getId();
    }

    public function testWritesWhenRowIsUnchanged(): void
    {
        self::assertTrue($this->writer->writeAutoIfUnchanged($this->id, 'ООО "Ромашка"', 'ООО «Ромашка»', 1));
        self::assertSame(['ООО «Ромашка»', 'auto', 1], $this->fetch());
    }

    public function testSkipsWhenRenamedDuringRun(): void
    {
        // Given: пользователь переименовал после чтения порции
        $this->connection->executeStatement('UPDATE "counterparty" SET name = :name WHERE id = :id', ['name' => 'ООО "Лютик"', 'id' => $this->id]);

        // When / Then: название от старого name не закрепляется
        self::assertFalse($this->writer->writeAutoIfUnchanged($this->id, 'ООО "Ромашка"', 'ООО «Ромашка»', 1));
        self::assertSame([null, null, null], $this->fetch());
    }

    public function testNeverOverwritesManual(): void
    {
        // Given: пользователь задал ручное название во время прогона
        $this->connection->executeStatement(
            'UPDATE "counterparty" SET display_name = :name, display_name_source = :source WHERE id = :id',
            ['name' => 'Ромашка (наш поставщик)', 'source' => 'manual', 'id' => $this->id],
        );

        // When / Then
        self::assertFalse($this->writer->writeAutoIfUnchanged($this->id, 'ООО "Ромашка"', 'ООО «Ромашка»', 1));
        self::assertSame(['Ромашка (наш поставщик)', 'manual', null], $this->fetch());
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?int}
     */
    private function fetch(): array
    {
        /** @var array{display_name: ?string, display_name_source: ?string, display_rules_version: int|string|null} $row */
        $row = $this->connection->fetchAssociative(
            'SELECT display_name, display_name_source, display_rules_version FROM "counterparty" WHERE id = :id',
            ['id' => $this->id],
        );

        return [$row['display_name'], $row['display_name_source'], null === $row['display_rules_version'] ? null : (int) $row['display_rules_version']];
    }
}
