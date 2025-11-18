<?php

declare(strict_types=1);

namespace App\Loan\Form;

use App\Loan\Entity\LoanPaymentSchedule;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LoanPaymentScheduleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('dueDate', DateType::class, [
                'label' => 'Дата платежа',
                'widget' => 'single_text',
            ])
            ->add('totalPaymentAmount', NumberType::class, [
                'label' => 'Общая сумма платежа',
                'scale' => 2,
            ])
            ->add('principalPart', NumberType::class, [
                'label' => 'Тело долга',
                'scale' => 2,
            ])
            ->add('interestPart', NumberType::class, [
                'label' => 'Проценты',
                'scale' => 2,
            ])
            ->add('feePart', NumberType::class, [
                'label' => 'Комиссия',
                'scale' => 2,
            ])
            ->add('isPaid', CheckboxType::class, [
                'label' => 'Оплачен',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LoanPaymentSchedule::class,
        ]);
    }
}
