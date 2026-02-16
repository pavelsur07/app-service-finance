<?php

namespace App\Admin\Form\Analytics;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PlRecalcFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('preset', ChoiceType::class, [
                'label' => 'Период (preset)',
                'required' => false,
                'placeholder' => 'Custom (через from/to)',
                'choices' => [
                    'Day' => 'day',
                    'Week' => 'week',
                    'Month' => 'month',
                ],
            ])
            ->add('from', DateType::class, [
                'label' => 'From',
                'widget' => 'single_text',
                'required' => false,
                'input' => 'datetime_immutable',
            ])
            ->add('to', DateType::class, [
                'label' => 'To',
                'widget' => 'single_text',
                'required' => false,
                'input' => 'datetime_immutable',
            ])
            ->add('recalcPl', CheckboxType::class, [
                'label' => 'Пересчитать PL регистр',
                'required' => false,
            ])
            ->add('warmupSnapshot', CheckboxType::class, [
                'label' => 'Прогреть snapshot',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PlRecalcFormData::class,
            'csrf_protection' => true,
        ]);
    }
}
