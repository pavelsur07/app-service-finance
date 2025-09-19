<?php

namespace App\Form;

use App\Entity\MoneyAccount;
use App\Enum\MoneyAccountType as MoneyAccountTypeEnum;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MoneyAccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'choices' => [
                    'Bank' => MoneyAccountTypeEnum::BANK,
                    'Cash' => MoneyAccountTypeEnum::CASH,
                    'E-wallet' => MoneyAccountTypeEnum::EWALLET,
                ],
                'label' => 'Тип',
            ])
            ->add('name', TextType::class, [
                'label' => 'Название',
            ])
            ->add('currency', TextType::class, [
                'label' => 'Валюта',
            ])
            ->add('openingBalance', MoneyType::class, [
                'label' => 'Стартовый остаток',
                'required' => false,
                'currency' => false,
            ])
            ->add('openingBalanceDate', DateType::class, [
                'label' => 'Дата ввода',
                'widget' => 'single_text',
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
        ]);
    }
}
