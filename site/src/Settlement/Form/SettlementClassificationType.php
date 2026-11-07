<?php

declare(strict_types=1);

namespace App\Settlement\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<array<string,mixed>> */
final class SettlementClassificationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('isAdvance', ChoiceType::class, ['constraints' => [new Assert\NotNull()], 'label' => 'Классификация свободного остатка', 'choices' => ['Подтверждённый аванс' => true, 'Нераспределённая оплата' => false]]);
    }

    public function getParent(): string
    {
        return SettlementEventType::class;
    }
}
