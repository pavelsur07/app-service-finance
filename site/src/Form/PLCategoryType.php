<?php

namespace App\Form;

use App\Entity\Company;
use App\Entity\PLCategory;
use App\Enum\PLCategoryType as PLCategoryTypeEnum;
use App\Enum\PLValueFormat;
use App\Repository\PLCategoryRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PLCategoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Company $company */
        $company = $options['company'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'Название',
            ])
            ->add('code', TextType::class, [
                'label' => 'Код (уникален в компании)',
                'required' => false,
                'attr' => ['placeholder' => 'REV_WB, COGS, EBITDA ...'],
            ])
            ->add('parent', EntityType::class, [
                'class' => PLCategory::class,
                'label' => 'Родитель',
                'required' => false,
                'choice_label' => 'name',
                'query_builder' => fn (PLCategoryRepository $repo) => $repo->qbForCompany($company),
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
            ])
            ->add('sortOrder', IntegerType::class, [
                'label' => 'Порядок',
            ])
            ->add('isVisible', CheckboxType::class, [
                'label' => 'Показывать',
                'required' => false,
            ])
            ->add('formula', TextareaType::class, [
                'label' => 'Формула (KPI/особый итог)',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => 'REV_TOTAL - VAR_COSTS_TOTAL'],
            ])
            ->add('calcOrder', IntegerType::class, [
                'label' => 'Порядок расчёта',
                'required' => false,
            ])
            ->add('save', SubmitType::class, [
                'label' => 'Сохранить',
                'attr' => ['class' => 'btn btn-primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('company');
        $resolver->setDefaults([
            'data_class' => PLCategory::class,
        ]);
    }
}
