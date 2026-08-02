<?php

declare(strict_types=1);

namespace App\Cash\Form\Transaction;

use App\Cash\Application\DTO\CashTransactionSplitsInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CashTransactionSplitsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('rows', CollectionType::class, [
            'entry_type' => CashTransactionSplitRowType::class,
            'entry_options' => ['categories' => $options['categories']],
            'allow_add' => true,
            'allow_delete' => true,
            'by_reference' => false,
            'label' => false,
            'prototype' => true,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CashTransactionSplitsInput::class,
        ]);
        $resolver->setRequired('categories');
        $resolver->setAllowedTypes('categories', 'array');
    }
}
