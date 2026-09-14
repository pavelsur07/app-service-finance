<?php

declare(strict_types=1);

namespace App\Balance\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<array<string, mixed>> */
final class BalanceGrantType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('userId', ChoiceType::class, ['label' => 'Участник компании', 'choices' => $options['user_choices'], 'constraints' => [new NotBlank()]])
            ->add('prepare', CheckboxType::class, ['label' => 'Подготовка черновиков', 'required' => false])
            ->add('post', CheckboxType::class, ['label' => 'Проведение и сторно', 'required' => false])
            ->add('managePeriods', CheckboxType::class, ['label' => 'Закрытие периодов', 'required' => false])
            ->add('reopenPeriods', CheckboxType::class, ['label' => 'Переоткрытие закрытых периодов', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['user_choices' => []]);
        $resolver->setAllowedTypes('user_choices', 'array');
    }
}
