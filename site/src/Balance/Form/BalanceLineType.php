<?php

declare(strict_types=1);

namespace App\Balance\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array<string, mixed>> */
final class BalanceLineType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('accountId', ChoiceType::class, ['label' => 'Счет', 'choices' => $options['account_choices'], 'choice_attr' => $options['account_attributes'], 'placeholder' => 'Выберите счет', 'constraints' => [new NotBlank()]])
            ->add('direction', ChoiceType::class, ['label' => 'Изменение', 'choices' => ['Увеличение' => 'increase', 'Уменьшение' => 'decrease']])
            ->add('amount', TextType::class, ['label' => 'Сумма', 'attr' => ['inputmode' => 'decimal'], 'constraints' => [new NotBlank()]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['account_choices' => [], 'account_attributes' => []]);
        $resolver->setAllowedTypes('account_choices', 'array');
        $resolver->setAllowedTypes('account_attributes', 'array');
    }
}
