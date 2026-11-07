<?php

declare(strict_types=1);

namespace App\Settlement\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<array<string,mixed>> */
final class SettlementAllocationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('requestId', HiddenType::class, ['constraints' => [new Assert\NotBlank(), new Assert\Uuid()]])
            ->add('obligationItemId', ChoiceType::class, ['constraints' => [new Assert\NotBlank()], 'label' => 'Основание задолженности', 'choices' => $options['obligations']])
            ->add('paymentItemId', ChoiceType::class, ['constraints' => [new Assert\NotBlank()], 'label' => 'Оплата / начальный аванс', 'choices' => $options['payments']])
            ->add('amount', SettlementAmountType::class)
            ->add('effectiveDate', DateType::class, ['label' => 'Дата погашения', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'constraints' => [new Assert\NotBlank()]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['obligations', 'payments'])->setAllowedTypes('obligations', 'array')->setAllowedTypes('payments', 'array')->setDefaults(['data_class' => null]);
    }
}
