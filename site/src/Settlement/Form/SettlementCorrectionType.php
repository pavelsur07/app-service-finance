<?php

declare(strict_types=1);

namespace App\Settlement\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<array<string,mixed>> */
final class SettlementCorrectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('itemId', ChoiceType::class, ['constraints' => [new Assert\NotBlank()], 'label' => 'Корректируемое основание', 'choices' => $options['items']])
            ->add('increase', ChoiceType::class, ['constraints' => [new Assert\NotNull()], 'label' => 'Изменение', 'choices' => ['Увеличить' => true, 'Уменьшить' => false]])
            ->add('amount', SettlementAmountType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('items')->setAllowedTypes('items', 'array');
    }

    public function getParent(): string
    {
        return SettlementEventType::class;
    }
}
