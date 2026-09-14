<?php

declare(strict_types=1);

namespace App\Balance\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array<string, mixed>> */
final class BalanceDocumentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('kind', ChoiceType::class, ['label' => 'Вид документа', 'choices' => ['Начальные остатки' => 'opening', 'Операция' => 'operation', 'Корректировка' => 'correction']])
            ->add('date', DateType::class, ['label' => 'Учетная дата', 'widget' => 'single_text', 'input' => 'string', 'constraints' => [new NotBlank()]])
            ->add('reason', TextareaType::class, ['label' => 'Основание', 'required' => false, 'empty_data' => ''])
            ->add('requestKey', HiddenType::class)
            ->add('version', HiddenType::class, ['required' => false])
            ->add('lines', CollectionType::class, ['entry_type' => BalanceLineType::class, 'entry_options' => ['account_choices' => $options['account_choices'], 'account_attributes' => $options['account_attributes']], 'allow_add' => true, 'allow_delete' => true, 'by_reference' => false, 'label' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['account_choices' => [], 'account_attributes' => []]);
        $resolver->setAllowedTypes('account_choices', 'array');
        $resolver->setAllowedTypes('account_attributes', 'array');
    }
}
