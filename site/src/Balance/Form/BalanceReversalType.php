<?php

declare(strict_types=1);

namespace App\Balance\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array<string, mixed>> */
final class BalanceReversalType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('date', DateType::class, ['label' => 'Дата сторно', 'widget' => 'single_text', 'input' => 'string', 'constraints' => [new NotBlank()]])
            ->add('reason', TextareaType::class, ['label' => 'Основание сторно', 'constraints' => [new NotBlank()]])
            ->add('requestKey', HiddenType::class);
    }
}
