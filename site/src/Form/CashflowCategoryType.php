<?php

namespace App\Form;

use App\Entity\CashflowCategory;
use App\Enum\CashflowCategoryStatus;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
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
            ->add('parent', EntityType::class, [
                'class' => CashflowCategory::class,
                'choices' => $options['parents'],
                'choice_label' => function (CashflowCategory $item) {
                    return str_repeat('—', $item->getLevel() - 1).' '.$item->getName();
                },
                'required' => false,
                'label' => 'Родитель',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CashflowCategory::class,
            'parents' => [],
        ]);
    }
}
