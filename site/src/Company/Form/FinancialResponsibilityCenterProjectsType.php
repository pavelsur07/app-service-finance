<?php

declare(strict_types=1);

namespace App\Company\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class FinancialResponsibilityCenterProjectsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('projectDirectionIds', ChoiceType::class, [
                'label' => 'Разрешённые проекты',
                'choices' => $options['project_choices'],
                'multiple' => true,
                'expanded' => true,
                'help' => 'Один проект можно разрешить для нескольких ЦФО.',
            ])
            ->add('version', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('project_choices');
        $resolver->setAllowedTypes('project_choices', 'array');
    }
}
