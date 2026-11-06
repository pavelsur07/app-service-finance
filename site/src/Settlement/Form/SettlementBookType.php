<?php

declare(strict_types=1);

namespace App\Settlement\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array<string,mixed>> */
final class SettlementBookType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('startDate', DateType::class, ['label' => 'Дата начала учёта', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'constraints' => [new NotBlank()]]);
    }
}
