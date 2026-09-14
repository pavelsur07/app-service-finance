<?php

declare(strict_types=1);

namespace App\Balance\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CurrencyType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array<string, mixed>> */
final class BalanceSetupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('currency', CurrencyType::class, ['label' => 'Валюта учета', 'constraints' => [new NotBlank()]])
            ->add('startDate', DateType::class, ['label' => 'Дата начала учета', 'widget' => 'single_text', 'input' => 'string', 'constraints' => [new NotBlank()]]);
    }
}
