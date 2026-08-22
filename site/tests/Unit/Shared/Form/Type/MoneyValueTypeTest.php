<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Form\Type;

use App\Shared\Domain\ValueObject\Money;
use App\Shared\Form\Type\MoneyValueType;
use Symfony\Component\Form\Test\TypeTestCase;

final class MoneyValueTypeTest extends TypeTestCase
{
    public function testMapsSubmittedDecimalAndCurrencyToMoney(): void
    {
        $form = $this->factory->create(MoneyValueType::class, Money::fromMinor(0, 'RUB'));

        $form->submit(['amount' => '1 500,25', 'currency' => 'USD']);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertInstanceOf(Money::class, $form->getData());
        self::assertTrue($form->getData()->equals(Money::fromMinor(150025, 'USD')));
    }

    public function testRejectsNegativeAmount(): void
    {
        $form = $this->factory->create(MoneyValueType::class, Money::fromMinor(0, 'RUB'));

        $form->submit(['amount' => '-0.01', 'currency' => 'RUB']);

        self::assertFalse($form->isSynchronized());
    }

    public function testRejectsAmountOutsideSupportedRange(): void
    {
        $form = $this->factory->create(MoneyValueType::class, Money::fromMinor(0, 'RUB'));

        $form->submit(['amount' => '999999999999999999999999.99', 'currency' => 'RUB']);

        self::assertFalse($form->isSynchronized());
    }

    public function testRejectsNonScalarAmountWithoutWarning(): void
    {
        $form = $this->factory->create(MoneyValueType::class, Money::fromMinor(0, 'RUB'));

        $form->submit(['amount' => ['10.00'], 'currency' => 'RUB']);

        self::assertFalse($form->get('amount')->isSynchronized());
        self::assertFalse($form->isValid());
    }
}
