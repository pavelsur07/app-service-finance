<?php

namespace App\Form;

use App\Entity\PLCategory;
use App\Enum\PLCategoryType as PLCategoryTypeEnum;
use App\Enum\PLValueFormat;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PLCategoryFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Наименование',
            ])
            ->add('sortOrder', IntegerType::class, [
                'label' => 'Сортировка',
            ])
            ->add('parent', EntityType::class, [
                'class' => PLCategory::class,
                'choices' => $options['parents'],
                'choice_label' => function (PLCategory $item) {
                    return str_repeat('—', $item->getLevel() - 1).' '.$item->getName();
                },
                'required' => false,
                'label' => 'Родитель',
            ])
            ->add('code', TextType::class, [
                'label' => 'Код (уникален в компании)',
                'required' => false,
                'attr' => ['placeholder' => 'REV_WB, COGS, EBITDA ...'],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Тип строки',
                'choices' => [
                    'Лист (из фактов)' => PLCategoryTypeEnum::LEAF_INPUT,
                    'Итог (subtotal)' => PLCategoryTypeEnum::SUBTOTAL,
                    'Показатель (KPI)' => PLCategoryTypeEnum::KPI,
                ],
            ])
            ->add('format', ChoiceType::class, [
                'label' => 'Формат',
                'choices' => [
                    'Деньги' => PLValueFormat::MONEY,
                    '%' => PLValueFormat::PERCENT,
                    'Коэф.' => PLValueFormat::RATIO,
                    'Кол-во' => PLValueFormat::QTY,
                ],
            ])
            ->add('weightInParent', NumberType::class, [
                'label' => 'Вес в родителе',
                'html5' => true,
                'scale' => 4,
                'required' => false,
            ])
            ->add('isVisible', CheckboxType::class, [
                'label' => 'Показывать',
                'required' => false,
            ])
            ->add('formula', TextareaType::class, [
                'label' => 'Формула (для KPI/особых итогов)',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => 'Напр.: REV_TOTAL - VAR_COSTS_TOTAL'],
            ])
            ->add('calcOrder', IntegerType::class, [
                'label' => 'Порядок расчёта',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PLCategory::class,
            'parents' => [],
        ]);
    }
}
