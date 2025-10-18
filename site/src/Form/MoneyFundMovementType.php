<?php

namespace App\Form;

use App\Entity\MoneyFundMovement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MoneyFundMovementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currency = $options['currency'];

        $builder
            ->add('occurredAt', DateTimeType::class, [
                'label' => 'Дата и время',
                'widget' => 'single_text',
                'with_seconds' => false,
                'input' => 'datetime_immutable',
            ])
            ->add('amount', MoneyType::class, [
                'label' => 'Сумма',
                'currency' => $currency,
                'mapped' => false,
                'input' => 'string',
            ])
            ->add('note', TextareaType::class, [
                'label' => 'Комментарий',
                'required' => false,
                'attr' => ['rows' => 3],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MoneyFundMovement::class,
            'currency' => 'RUB',
        ]);
        $resolver->setAllowedTypes('currency', 'string');
    }
}
