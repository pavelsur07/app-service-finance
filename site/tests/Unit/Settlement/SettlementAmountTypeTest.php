<?php

declare(strict_types=1);

namespace App\Tests\Unit\Settlement;

use App\Settlement\Form\SettlementAmountType;
use App\Shared\Domain\ValueObject\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Form\Test\TypeTestCase;

final class SettlementAmountTypeTest extends TypeTestCase
{
    public function testExactDecimalInputBecomesMoney(): void
    {
        $form = $this->factory->create(SettlementAmountType::class);
        $form->submit('1 234,56');
        self::assertTrue($form->isSynchronized());
        $amount = $form->getData();
        self::assertInstanceOf(Money::class, $amount);
        self::assertSame(123456, $amount->amountMinor());
        self::assertSame('RUB', $amount->currency());
    }

    /** @return iterable<string,array{string}> */
    public static function invalidAmounts(): iterable
    {
        foreach (['1.001', '0.001', '0', '-1.00', 'NaN', '1e3', '92233720368547758.08'] as $raw) {
            yield 'amount '.$raw => [$raw];
        }
    }

    #[DataProvider('invalidAmounts')]
    public function testInvalidAmountsAreRejectedWithoutRounding(string $raw): void
    {
        $form = $this->factory->create(SettlementAmountType::class);
        $form->submit($raw);
        self::assertFalse($form->isSynchronized());
    }
}
