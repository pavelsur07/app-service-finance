<?php

declare(strict_types=1);

namespace App\Catalog\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotNull;

final class ProductPurchasePriceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('effectiveFrom', DateType::class, [
                'label' => 'Действует с',
                'widget' => 'single_text',
                'help' => 'Дата начала действия закупочной цены.',
                'constraints' => [new NotNull(message: 'Укажите дату начала действия.')],
            ])
            ->add('priceAmount', IntegerType::class, [
                'label' => 'Сумма закупочной цены',
                'help' => 'Укажите сумму в копейках.',
                'constraints' => [
                    new NotNull(message: 'Укажите сумму закупочной цены.'),
                    new GreaterThanOrEqual(0, message: 'Сумма не может быть отрицательной.'),
                ],
            ])
            ->add('currency', ChoiceType::class, [
                'label' => 'Валюта',
                'help' => 'Выберите валюту закупочной цены.',
                'choices' => [
                    'RUB' => 'RUB',
                    'USD' => 'USD',
                    'EUR' => 'EUR',
                ],
                'data' => 'RUB',
                'constraints' => [new Choice(['RUB', 'USD', 'EUR'])],
            ])
            ->add('note', TextType::class, [
                'label' => 'Комментарий',
                'help' => 'Необязательное пояснение к изменению цены.',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => true,
        ]);
    }
}
