<?php

declare(strict_types=1);

namespace App\Settlement\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<array<string,mixed>> */
final class SettlementEventType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('requestId', HiddenType::class, ['constraints' => [new Assert\NotBlank(), new Assert\Uuid()]])
            ->add('effectiveDate', DateType::class, ['label' => 'Дата изменения', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'constraints' => [new Assert\NotBlank()]])
            ->add('reason', TextareaType::class, ['label' => 'Основание / причина', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 2000)]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
