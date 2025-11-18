<?php

declare(strict_types=1);

namespace App\Loan\Form;

use App\Loan\Entity\Loan;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LoanType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Название займа',
            ])
            ->add('lenderName', TextType::class, [
                'label' => 'Кредитор',
                'required' => false,
            ])
            ->add('principalAmount', NumberType::class, [
                'label' => 'Сумма займа',
                'scale' => 2,
            ])
            ->add('remainingPrincipal', NumberType::class, [
                'label' => 'Остаток долга',
                'scale' => 2,
            ])
            ->add('interestRate', NumberType::class, [
                'label' => 'Ставка, %',
                'required' => false,
                'scale' => 4,
            ])
            ->add('startDate', DateType::class, [
                'label' => 'Дата начала',
                'widget' => 'single_text',
            ])
            ->add('endDate', DateType::class, [
                'label' => 'Дата окончания',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('paymentDayOfMonth', IntegerType::class, [
                'label' => 'День платежа в месяц',
                'required' => false,
                'attr' => [
                    'min' => 1,
                    'max' => 31,
                ],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Статус',
                'choices' => [
                    'Активен' => 'active',
                    'Закрыт' => 'closed',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Loan::class,
        ]);
    }
}
