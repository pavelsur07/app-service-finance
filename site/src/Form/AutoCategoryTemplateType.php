<?php

namespace App\Form;

use App\Entity\AutoCategoryTemplate;
use App\Enum\AutoTemplateDirection;
use App\Enum\MatchLogic;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AutoCategoryTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $opt): void
    {
        $b
            ->add('name', TextType::class, [
                'attr' => ['class' => 'form-control', 'placeholder' => 'Например: Связь (ключевые слова)'],
                'label' => 'Название шаблона',
            ])
            ->add('isActive', CheckboxType::class, [
                'required' => false,
                'label' => 'Активен',
            ])
            ->add('direction', ChoiceType::class, [
                'choices' => [
                    'Любое' => AutoTemplateDirection::ANY,
                    'Приток' => AutoTemplateDirection::INFLOW,
                    'Отток' => AutoTemplateDirection::OUTFLOW,
                ],
                'attr' => ['class' => 'form-select'],
                'label' => 'Направление',
            ])
            ->add('targetCategory', null, [
                'label' => 'Целевая статья ДДС',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('priority', IntegerType::class, [
                'label' => 'Приоритет (меньше — выше)',
                'empty_data' => '100',
                'attr' => ['class' => 'form-control', 'min' => 0],
            ])
            ->add('stopOnMatch', CheckboxType::class, [
                'required' => false,
                'label' => 'Остановиться после совпадения',
            ])
            ->add('matchLogic', ChoiceType::class, [
                'choices' => [
                    'Все условия (AND)' => MatchLogic::ALL,
                    'Любое условие (OR)' => MatchLogic::ANY,
                ],
                'attr' => ['class' => 'form-select'],
                'label' => 'Логика совпадения',
            ])
            ->add('conditions', CollectionType::class, [
                'entry_type' => AutoCategoryConditionType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => 'Условия',
                'prototype' => true,
                'entry_options' => ['label' => false],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AutoCategoryTemplate::class]);
    }
}
