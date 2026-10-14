<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company;

use App\Company\Domain\Service\CounterpartyNameNormalizer;
use App\Company\Enum\CounterpartyDisplayNameSource;
use App\Tests\Builders\Company\CounterpartyBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Отображаемое название — производное поле: правила пишут его без updatedAt, ручное главнее
 * правил, переименование отправляет авто-название на пересчёт.
 */
final class CounterpartyDisplayNameEntityTest extends TestCase
{
    public function testNewCounterpartyNeedsAutoDisplayName(): void
    {
        $counterparty = CounterpartyBuilder::aCounterparty()->build();

        self::assertNull($counterparty->getDisplayName());
        self::assertNull($counterparty->getDisplayNameSource());
        self::assertTrue($counterparty->needsAutoDisplayName(1));
    }

    public function testApplyAutoDisplayNameKeepsUpdatedAtAndRecordsVersion(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()->withName('ООО "Ромашка"')->build();
        $updatedAt = $counterparty->getUpdatedAt();

        // When
        $counterparty->applyAutoDisplayName('ООО «Ромашка»', 1);

        // Then
        self::assertSame('ООО «Ромашка»', $counterparty->getDisplayName());
        self::assertSame(CounterpartyDisplayNameSource::AUTO, $counterparty->getDisplayNameSource());
        self::assertSame(1, $counterparty->getDisplayRulesVersion());
        self::assertSame($updatedAt, $counterparty->getUpdatedAt());
        self::assertFalse($counterparty->needsAutoDisplayName(1));
        self::assertTrue($counterparty->needsAutoDisplayName(2));
    }

    public function testUnrecognisedIsRecordedAsProcessed(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()->withName('Ozon')->build();

        // When: правила не уверены
        $counterparty->applyAutoDisplayName(null, 1);

        // Then: показывать name, но повторно этой версией не обрабатывать
        self::assertNull($counterparty->getDisplayName());
        self::assertFalse($counterparty->needsAutoDisplayName(1));
    }

    public function testManualDisplayNameIsNeverRecalculated(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()->build();
        $counterparty->setManualDisplayName('ООО «Ромашка и партнёры»');

        // Then
        self::assertSame(CounterpartyDisplayNameSource::MANUAL, $counterparty->getDisplayNameSource());
        self::assertFalse($counterparty->needsAutoDisplayName(1));
        $this->expectException(\LogicException::class);
        $counterparty->applyAutoDisplayName('ООО «Ромашка»', 1);
    }

    public function testRenameSendsAutoDisplayNameToRecalculation(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()->withName('ООО "Ромашка"')->build();
        $counterparty->applyAutoDisplayName('ООО «Ромашка»', 1);

        // When
        $counterparty->rename((new CounterpartyNameNormalizer())->normalize('ООО "Лютик"'));

        // Then: старое авто-название посчитано от другого сырого — сброшено
        self::assertNull($counterparty->getDisplayName());
        self::assertNull($counterparty->getDisplayNameSource());
        self::assertTrue($counterparty->needsAutoDisplayName(1));
    }

    public function testRenameKeepsManualDisplayName(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()->withName('ООО "Ромашка"')->build();
        $counterparty->setManualDisplayName('Ромашка (наш поставщик)');

        // When
        $counterparty->rename((new CounterpartyNameNormalizer())->normalize('ООО "Лютик"'));

        // Then
        self::assertSame('Ромашка (наш поставщик)', $counterparty->getDisplayName());
        self::assertSame(CounterpartyDisplayNameSource::MANUAL, $counterparty->getDisplayNameSource());
    }

    public function testManualDisplayNameMustNotBeBlank(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CounterpartyBuilder::aCounterparty()->build()->setManualDisplayName('   ');
    }
}
