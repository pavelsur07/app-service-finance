<?php

declare(strict_types=1);

namespace App\Company\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class FinancialResponsibilityCenterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Наименование',
                'trim' => true,
                'disabled' => $options['system'],
                'constraints' => [
                    new NotBlank(message: 'Укажите наименование.'),
                    new Length(max: 255),
                ],
            ])
            ->add('sort', IntegerType::class, [
                'label' => 'Порядок сортировки',
            ])
            ->add('version', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'system' => false,
        ]);
        $resolver->setAllowedTypes('system', 'bool');
    }
}
