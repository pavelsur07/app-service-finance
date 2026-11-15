<?php

declare(strict_types=1);

namespace App\MoySklad\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;

/** @extends AbstractType<array<string,mixed>> */
final class ApplyAutomaticMappingsType extends AbstractType
{
    public static function csrfId(string $previewId): string
    {
        return 'moysklad_automatic_apply'.$previewId;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string,string> $listings */
        $listings = $options['listings'];
        $builder->add('selectedIds', ChoiceType::class, ['label' => false, 'choices' => array_keys($listings), 'expanded' => true, 'multiple' => true,
            'choice_label' => static fn (string $id): string => $listings[$id], 'constraints' => [new Count(min: 1, max: 25)]]);
        $builder->add('_token', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_protection' => false, 'data_class' => null, 'listings' => []]);
        $resolver->setAllowedTypes('listings', 'array');
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
