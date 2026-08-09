<?php

declare(strict_types=1);

namespace App\Cash\Form\Transfer;

use App\Cash\DTO\CashTransferFormData;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Company\Entity\Company;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CashTransferType extends AbstractType
{
    public function __construct(private readonly MoneyAccountRepository $accountRepository)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Company $company */
        $company = $options['company'];
        $accounts = array_values(array_filter(
            $this->accountRepository->findByFilters(
                $company,
                null,
                FiatCurrency::values(),
                true,
                null,
                ['name' => 'ASC'],
            ),
            static fn (MoneyAccount $account): bool => MoneyAccountType::CRYPTO_WALLET !== $account->getType(),
        ));
        $accountOptions = [
            'choices' => $accounts,
            'choice_label' => static fn (MoneyAccount $account): string => sprintf('%s — %s', $account->getName(), $account->getCurrency()),
            'choice_value' => 'id',
            'choice_attr' => static fn (MoneyAccount $account): array => ['data-currency' => $account->getCurrency()],
            'placeholder' => 'Выберите счёт',
        ];
        $amountOptions = [
            'attr' => [
                'inputmode' => 'decimal',
                'pattern' => '\d+(?:[\.,]\d{1,2})?',
                'placeholder' => '0.00',
                'autocomplete' => 'off',
            ],
        ];

        $builder
            ->add('occurredAt', DateType::class, [
                'widget' => 'single_text',
                'label' => 'Дата перевода',
            ])
            ->add('sourceAccount', ChoiceType::class, $accountOptions + ['label' => 'Счёт списания'])
            ->add('sourceAmount', TextType::class, $amountOptions + ['label' => 'Сумма списания'])
            ->add('targetAccount', ChoiceType::class, $accountOptions + ['label' => 'Счёт поступления'])
            ->add('targetAmount', TextType::class, $amountOptions + ['label' => 'Сумма поступления'])
            ->add('description', TextareaType::class, [
                'required' => false,
                'label' => 'Примечание',
            ])
            ->add('idempotencyKey', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CashTransferFormData::class]);
        $resolver->setRequired('company');
        $resolver->setAllowedTypes('company', Company::class);
    }
}
