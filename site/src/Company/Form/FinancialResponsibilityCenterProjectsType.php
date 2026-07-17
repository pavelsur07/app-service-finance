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
                'choices' => array_keys($options['project_labels']),
                'choice_label' => static fn (string $projectId): string => $options['project_labels'][$projectId],
                'multiple' => true,
                'expanded' => true,
                'help' => 'Один проект можно разрешить для нескольких ЦФО.',
            ])
            ->add('version', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('project_labels');
        $resolver->setAllowedTypes('project_labels', 'array');
    }
}
