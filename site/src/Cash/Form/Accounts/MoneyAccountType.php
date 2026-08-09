<?php

declare(strict_types=1);

namespace App\Cash\Form\Accounts;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\Accounts\MoneyAccountType as MoneyAccountTypeEnum;
use App\Cash\Enum\FiatCurrency;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;

final class MoneyAccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'choices' => [
                    'Банк' => MoneyAccountTypeEnum::BANK,
                    'Касса' => MoneyAccountTypeEnum::CASH,
                    'Кошелёк' => MoneyAccountTypeEnum::EWALLET,
                    'Крипто-кошелёк' => MoneyAccountTypeEnum::CRYPTO_WALLET,
                ],
                'label' => 'Тип счёта',
                'expanded' => $options['expanded_type'],
                'choice_value' => $options['expanded_type']
                    ? static fn (?MoneyAccountTypeEnum $type): string => $type?->value ?? ''
                    : null,
            ])
            ->add('name', TextType::class, [
                'label' => 'Название',
            ]);

        $builder->add('currency', $options['currency_choices'] ? ChoiceType::class : TextType::class, [
            'label' => 'Валюта',
            'disabled' => $options['currency_disabled'],
            ...($options['currency_choices'] ? [
                'choices' => FiatCurrency::choices(),
            ] : []),
        ])
            ->add('openingBalance', MoneyType::class, [
                'label' => 'Стартовый остаток',
                'required' => false,
                'currency' => false,
            ])
            // Коментарий: Поле неснижаемого остатка (ватерлинии) для счёта.
            ->add('minimumSafeBalance', MoneyType::class, [
                'label' => 'Неснижаемый остаток',
                'required' => false,
                'currency' => false,
            ])
            ->add('openingBalanceDate', DateType::class, [
                'label' => 'Дата ввода',
                'widget' => 'single_text',
                'constraints' => [
                    new LessThanOrEqual('today', message: 'Дата ввода не может быть позже сегодняшнего дня.'),
                ],
            ])
            ->add('isDefault', CheckboxType::class, [
                'label' => 'По умолчанию',
                'required' => false,
            ])
            ->add('bankName', TextType::class, [
                'label' => 'Банк',
                'required' => false,
            ])
            ->add('accountNumber', TextType::class, [
                'label' => 'Расчетный счет',
                'required' => false,
            ])
            ->add('iban', TextType::class, [
                'label' => 'IBAN',
                'required' => false,
            ])
            ->add('bic', TextType::class, [
                'label' => 'BIC/SWIFT',
                'required' => false,
            ])
            ->add('corrAccount', TextType::class, [
                'label' => 'Корр. счет',
                'required' => false,
            ])
            ->add('location', TextType::class, [
                'label' => 'Место хранения',
                'required' => false,
            ])
            ->add('responsiblePerson', TextType::class, [
                'label' => 'Ответственный',
                'required' => false,
            ])
            ->add('provider', TextType::class, [
                'label' => 'Провайдер',
                'required' => false,
            ])
            ->add('walletId', TextType::class, [
                'label' => 'ID кошелька',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MoneyAccount::class,
            'expanded_type' => false,
            'currency_choices' => false,
            'currency_disabled' => false,
        ]);
        $resolver->setAllowedTypes('expanded_type', 'bool');
        $resolver->setAllowedTypes('currency_choices', 'bool');
        $resolver->setAllowedTypes('currency_disabled', 'bool');
    }
}
