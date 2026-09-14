<?php

declare(strict_types=1);

namespace App\Balance\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array<string, mixed>> */
final class BalanceTargetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('date', DateType::class, ['label' => 'Дата корректировки', 'widget' => 'single_text', 'input' => 'string', 'constraints' => [new NotBlank()]])
            ->add('target', TextType::class, ['label' => 'Требуемый остаток на конец даты', 'attr' => ['inputmode' => 'decimal'], 'constraints' => [new NotBlank()]])
            ->add('reason', TextareaType::class, ['label' => 'Основание', 'constraints' => [new NotBlank()]])
            ->add('requestKey', HiddenType::class)
            ->add('journalVersion', HiddenType::class)
            ->add('documentVersion', HiddenType::class, ['required' => false])
            ->add('lines', CollectionType::class, ['entry_type' => BalanceLineType::class, 'entry_options' => ['account_choices' => $options['account_choices'], 'account_attributes' => $options['account_attributes']], 'allow_add' => true, 'allow_delete' => true, 'by_reference' => false, 'label' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['account_choices' => [], 'account_attributes' => []]);
        $resolver->setAllowedTypes('account_choices', 'array');
        $resolver->setAllowedTypes('account_attributes', 'array');
    }
}
