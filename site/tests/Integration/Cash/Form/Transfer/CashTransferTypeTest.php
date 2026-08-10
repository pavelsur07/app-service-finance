<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Form\Transfer;

use App\Cash\DTO\CashTransferFormData;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Form\Transfer\CashTransferType;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Form\FormFactoryInterface;

final class CashTransferTypeTest extends IntegrationTestCase
{
    public function testUsesOnlyActiveCompanyFiatAccountsAndPreservesExactInput(): void
    {
        $user = UserBuilder::aUser()->withEmail('transfer-form@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $otherUser = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail('transfer-form-other@example.test')
            ->build();
        $otherCompany = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($otherUser)
            ->build();
        $rubAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('RUB bank')
            ->withCurrency('RUB')
            ->build();
        $usdAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('USD wallet')
            ->withCurrency('USD')
            ->build();
        $inactiveAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('Inactive EUR')
            ->withCurrency('EUR')
            ->build()
            ->setIsActive(false);
        $cryptoAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('Crypto USD')
            ->withCurrency('USD')
            ->withType(MoneyAccountType::CRYPTO_WALLET)
            ->build();
        $foreignAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($otherCompany)
            ->withName('Foreign USD')
            ->withCurrency('USD')
            ->build();

        foreach ([$user, $company, $otherUser, $otherCompany, $rubAccount, $usdAccount, $inactiveAccount, $cryptoAccount, $foreignAccount] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $data = new CashTransferFormData('transfer-form-key');
        $form = self::getContainer()->get(FormFactoryInterface::class)->create(
            CashTransferType::class,
            $data,
            ['company' => $company, 'csrf_protection' => false],
        );
        $choices = $form->get('sourceAccount')->getConfig()->getOption('choices');
        self::assertSame([$rubAccount, $usdAccount], $choices);

        $form->submit([
            'occurredAt' => '2026-08-09',
            'sourceAccount' => $rubAccount->getId(),
            'sourceAmount' => '9500,25',
            'targetAccount' => $usdAccount->getId(),
            'targetAmount' => '100.00',
            'description' => 'Покупка валюты',
            'idempotencyKey' => 'transfer-form-key',
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame($rubAccount, $data->sourceAccount);
        self::assertSame($usdAccount, $data->targetAccount);
        self::assertSame('9500.25', $data->normalizedSourceAmount());
        self::assertSame('100.00', $data->normalizedTargetAmount());
        self::assertSame('transfer-form-key', $data->idempotencyKey);
    }

    public function testRejectsTamperedForeignAccountChoice(): void
    {
        $user = UserBuilder::aUser()->withEmail('transfer-form-scope@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $otherUser = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail('transfer-form-scope-other@example.test')
            ->build();
        $otherCompany = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($otherUser)
            ->build();
        $foreignAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($otherCompany)
            ->build();
        foreach ([$user, $company, $otherUser, $otherCompany, $foreignAccount] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $form = self::getContainer()->get(FormFactoryInterface::class)->create(
            CashTransferType::class,
            new CashTransferFormData('transfer-form-scope'),
            ['company' => $company, 'csrf_protection' => false],
        );
        $form->submit([
            'occurredAt' => '2026-08-09',
            'sourceAccount' => $foreignAccount->getId(),
            'sourceAmount' => '1.00',
            'targetAccount' => $foreignAccount->getId(),
            'targetAmount' => '1.00',
            'idempotencyKey' => 'transfer-form-scope',
        ]);

        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, $form->get('sourceAccount')->getErrors()->count());
        self::assertGreaterThan(0, $form->get('targetAccount')->getErrors()->count());
    }

    public function testMissingDateIsAFormErrorInsteadOfAMappingFailure(): void
    {
        $user = UserBuilder::aUser()->withEmail('transfer-form-date@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $source = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('Date source')
            ->build();
        $target = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('Date target')
            ->build();
        foreach ([$user, $company, $source, $target] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $form = self::getContainer()->get(FormFactoryInterface::class)->create(
            CashTransferType::class,
            new CashTransferFormData('transfer-form-date'),
            ['company' => $company, 'csrf_protection' => false],
        );
        $form->submit([
            'occurredAt' => '',
            'sourceAccount' => $source->getId(),
            'sourceAmount' => '1.00',
            'targetAccount' => $target->getId(),
            'targetAmount' => '1.00',
            'idempotencyKey' => 'transfer-form-date',
        ]);

        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, $form->get('occurredAt')->getErrors()->count());
    }
}
