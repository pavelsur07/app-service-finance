<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Form\Accounts;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\Accounts\MoneyAccountType as MoneyAccountTypeEnum;
use App\Cash\Form\Accounts\MoneyAccountType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Form\FormFactoryInterface;

final class MoneyAccountTypeTest extends IntegrationTestCase
{
    public function testRejectsFutureOpeningBalanceDate(): void
    {
        $user = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail(Uuid::uuid4().'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($user)->build();
        $account = new MoneyAccount(
            Uuid::uuid4()->toString(),
            $company,
            MoneyAccountTypeEnum::BANK,
            'Main account',
            'RUB',
        );

        $form = self::getContainer()->get(FormFactoryInterface::class)->create(MoneyAccountType::class, $account);
        $form->submit(['openingBalanceDate' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d')], false);

        self::assertFalse($form->isValid());
        self::assertSame(
            'Дата ввода не может быть позже сегодняшнего дня.',
            $form->get('openingBalanceDate')->getErrors()[0]->getMessage(),
        );
    }
}
