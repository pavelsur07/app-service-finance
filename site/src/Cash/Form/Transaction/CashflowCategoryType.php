<?php

namespace App\Cash\Form\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashflowCategoryStatus;
use App\Entity\PLCategory;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CashflowCategoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Наименование',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Описание',
                'required' => false,
            ])
            ->add('status', EnumType::class, [
                'class' => CashflowCategoryStatus::class,
                'label' => 'Статус',
            ])
            ->add('sort', IntegerType::class, [
                'label' => 'Сортировка',
            ])
            ->add('allowPlDocument', CheckboxType::class, [
                'label' => 'Разрешено создавать документы ОПиУ из этой категории',
                'required' => false,
            ])
            ->add('parent', EntityType::class, [
                'class' => CashflowCategory::class,
                'choices' => $options['parents'],
                'choice_label' => function (CashflowCategory $item) {
                    return str_repeat('—', $item->getLevel() - 1).' '.$item->getName();
                },
                'required' => false,
                'label' => 'Родитель',
            ])
            ->add('plCategory', EntityType::class, [
                'class' => PLCategory::class,
                'choices' => $options['plCategories'],
                'choice_label' => function (PLCategory $item) {
                    return str_repeat('—', $item->getLevel() - 1).' '.$item->getName();
                },
                'required' => false,
                'placeholder' => '—',
                'label' => 'Категория ОПиУ по умолчанию',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CashflowCategory::class,
            'parents' => [],
            'plCategories' => [],
        ]);
    }
}
