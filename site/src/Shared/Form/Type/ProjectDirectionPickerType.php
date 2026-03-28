<?php

namespace App\Shared\Form\Type;

use App\Company\Entity\ProjectDirection;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProjectDirectionPickerType extends EntityType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'class' => ProjectDirection::class,
            'choice_label' => 'name',
            'choice_value' => 'id',
            'required' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'project_direction_picker';
    }
}
