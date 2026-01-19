<?php

namespace App\Form\Type;

use App\Entity\ProjectDirection;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProjectDirectionPickerType extends EntityType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'class' => ProjectDirection::class,
            'required' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'project_direction_picker';
    }
}
