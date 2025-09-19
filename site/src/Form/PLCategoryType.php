<?php

namespace App\Form;

use App\Entity\PLCategory;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PLCategoryType extends AbstractType
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
