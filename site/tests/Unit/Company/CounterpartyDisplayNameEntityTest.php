<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company;

use App\Company\Domain\Service\CounterpartyNameNormalizer;
use App\Company\Entity\Counterparty;
use App\Company\Enum\CounterpartyDisplayNameSource;
use App\Tests\Builders\Company\CounterpartyBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Отображаемое название в сущности: ручное главнее правил, переименование отправляет
 * авто-название на пересчёт. Запись авто-названия — условный UPDATE пересчёта
 * (CounterpartyDisplayNameWriterTest), сущность здесь не участвует.
 */
final class CounterpartyDisplayNameEntityTest extends TestCase
{
    public function testNewCounterpartyHasNoDisplayName(): void
    {
        $counterparty = CounterpartyBuilder::aCounterparty()->build();

        self::assertNull($counterparty->getDisplayName());
        self::assertNull($counterparty->getDisplayNameSource());
        self::assertNull($counterparty->getDisplayRulesVersion());
    }

    public function testRenameSendsAutoDisplayNameToRecalculation(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()->withName('ООО "Ромашка"')->build();
        self::loadAutoDisplayName($counterparty, 'ООО «Ромашка»');

        // When
        $counterparty->rename((new CounterpartyNameNormalizer())->normalize('ООО "Лютик"'));

        // Then: старое авто-название посчитано от другого сырого — сброшено
        self::assertNull($counterparty->getDisplayName());
        self::assertNull($counterparty->getDisplayNameSource());
        self::assertNull($counterparty->getDisplayRulesVersion());
    }

    public function testSavingSameNameKeepsAutoDisplayName(): void
    {
        // Given: форма сохраняет карточку с тем же названием (правка ИНН или типа)
        $counterparty = CounterpartyBuilder::aCounterparty()->withName('ООО "Ромашка"')->build();
        self::loadAutoDisplayName($counterparty, 'ООО «Ромашка»');

        // When
        $counterparty->rename((new CounterpartyNameNormalizer())->normalize('ООО "Ромашка"'));

        // Then
        self::assertSame('ООО «Ромашка»', $counterparty->getDisplayName());
        self::assertSame(CounterpartyDisplayNameSource::AUTO, $counterparty->getDisplayNameSource());
        self::assertSame(1, $counterparty->getDisplayRulesVersion());
    }

    public function testManualDisplayNameSurvivesRename(): void
    {
        // Given
        $counterparty = CounterpartyBuilder::aCounterparty()->withName('ООО "Ромашка"')->build();
        $counterparty->setManualDisplayName('  Ромашка   (наш поставщик) ');

        // When
        $counterparty->rename((new CounterpartyNameNormalizer())->normalize('ООО "Лютик"'));

        // Then: пробелы схлопнуты, ручное не сброшено
        self::assertSame('Ромашка (наш поставщик)', $counterparty->getDisplayName());
        self::assertSame(CounterpartyDisplayNameSource::MANUAL, $counterparty->getDisplayNameSource());
        self::assertNull($counterparty->getDisplayRulesVersion());
    }

    public function testManualDisplayNameTouchesUpdatedAt(): void
    {
        $counterparty = CounterpartyBuilder::aCounterparty()->withUpdatedAt(new \DateTimeImmutable('2026-01-01'))->build();

        $counterparty->setManualDisplayName('Ромашка');

        self::assertGreaterThan(new \DateTimeImmutable('2026-01-01'), $counterparty->getUpdatedAt());
    }

    public function testManualDisplayNameMustNotBeBlank(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CounterpartyBuilder::aCounterparty()->build()->setManualDisplayName('   ');
    }

    /** Состояние после пересчёта: его пишет условный UPDATE, а не метод сущности. */
    private static function loadAutoDisplayName(Counterparty $counterparty, string $displayName): void
    {
        $set = \Closure::bind(static function (Counterparty $c) use ($displayName): void {
            $c->displayName = $displayName;
            $c->displayNameSource = CounterpartyDisplayNameSource::AUTO;
            $c->displayRulesVersion = 1;
        }, null, Counterparty::class);
        $set($counterparty);
    }
}
