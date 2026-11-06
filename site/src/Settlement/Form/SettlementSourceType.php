<?php

declare(strict_types=1);

namespace App\Settlement\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<array<string,mixed>> */
final class SettlementSourceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('requestId', HiddenType::class, ['constraints' => [new Assert\NotBlank(), new Assert\Uuid()]])
            ->add('effectiveDate', DateType::class, ['label' => 'Дата учёта', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'constraints' => [new Assert\NotBlank()]]);
        if ('cash' === $options['source_kind']) {
            $builder->add('isAdvance', CheckboxType::class, ['label' => 'Подтвердить свободную сумму как аванс', 'required' => false]);
        } else {
            $builder->add('amount', SettlementAmountType::class)
                ->add('bucket', ChoiceType::class, ['constraints' => [new Assert\NotBlank()], 'label' => 'Направление обязательства', 'choices' => ['К оплате' => 'payable', 'Нам должны' => 'receivable']])
                ->add('dueDate', DateType::class, ['label' => 'Срок оплаты', 'required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable'])
                ->add('reason', TextareaType::class, ['label' => 'Основание', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 2000)]]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('source_kind')->setAllowedValues('source_kind', ['cash', 'finance'])->setDefaults(['data_class' => null]);
    }
}
