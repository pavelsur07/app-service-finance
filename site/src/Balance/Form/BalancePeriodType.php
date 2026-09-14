<?php

declare(strict_types=1);

namespace App\Balance\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array<string, mixed>> */
final class BalancePeriodType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('month', DateType::class, ['label' => 'Месяц (первое число)', 'widget' => 'single_text', 'input' => 'string', 'constraints' => [new NotBlank()]])
            ->add('close', ChoiceType::class, ['label' => 'Действие', 'choices' => $options['action_choices']])
            ->add('reason', TextareaType::class, ['label' => 'Основание', 'constraints' => [new NotBlank()]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['action_choices' => []]);
        $resolver->setAllowedTypes('action_choices', 'array');
    }
}
