<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company\Form;

use App\Company\Form\CompanyType;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Builders\Company\CompanyBuilder;
use Symfony\Component\Form\Test\TypeTestCase;

final class CompanyTypeTest extends TypeTestCase
{
    public function testMapsMinimumBalanceToCompany(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $form = $this->factory->create(CompanyType::class, $company);

        $form->submit([
            'minimumBalance' => ['amount' => '250000.50', 'currency' => 'EUR'],
        ], false);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertTrue($company->getMinimumBalance()->equals(Money::fromMinor(25000050, 'EUR')));
    }
}
