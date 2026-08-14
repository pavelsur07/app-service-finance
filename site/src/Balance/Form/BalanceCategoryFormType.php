<?php

declare(strict_types=1);

namespace App\Balance\Form;

use App\Balance\Enum\BalanceCategoryType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class BalanceCategoryFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Наименование',
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Тип',
                'choices' => [
                    'Актив' => BalanceCategoryType::ASSET,
                    'Обязательство' => BalanceCategoryType::LIABILITY,
                    'Капитал' => BalanceCategoryType::EQUITY,
                ],
            ])
            ->add('parentId', ChoiceType::class, [
                'label' => 'Родитель',
                'choices' => $options['parent_choices'],
                'required' => false,
                'placeholder' => 'Корень',
            ])
            ->add('code', TextType::class, [
                'label' => 'Код (опционально)',
                'required' => false,
            ])
            ->add('isVisible', CheckboxType::class, [
                'label' => 'Показывать',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'parent_choices' => [],
        ]);
        $resolver->setRequired('parent_choices');
        $resolver->setAllowedTypes('parent_choices', 'array');
    }
}
