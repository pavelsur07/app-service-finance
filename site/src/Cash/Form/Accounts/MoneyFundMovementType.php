<?php

declare(strict_types=1);

namespace App\Cash\Form\Accounts;

use App\Cash\Entity\Accounts\MoneyFundMovement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MoneyFundMovementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currency = $options['currency'];

        $builder
            ->add('occurredAt', DateTimeType::class, [
                'label' => 'Дата и время',
                'widget' => 'single_text',
                'with_seconds' => false,
                'input' => 'datetime_immutable',
            ])
            ->add('amount', MoneyType::class, [
                'label' => 'Сумма',
                'currency' => $currency,
                'mapped' => false,
                'input' => 'string',
            ])
            ->add('note', TextareaType::class, [
                'label' => 'Комментарий',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            // Ключ повтора = ID движения: повтор той же отправки возвращает уже созданное
            // движение (CreateMoneyFundMovementAction).
            ->add('idempotencyKey', HiddenType::class, [
                'mapped' => false,
                'data' => $builder->getData()?->getId(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MoneyFundMovement::class,
            'currency' => 'RUB',
        ]);
        $resolver->setAllowedTypes('currency', 'string');
    }
}
