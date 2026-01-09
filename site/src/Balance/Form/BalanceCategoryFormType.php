<?php

namespace App\Balance\Form;

use App\Balance\Entity\BalanceCategory;
use App\Balance\Enum\BalanceCategoryType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BalanceCategoryFormType extends AbstractType
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
            ->add('parent', ChoiceType::class, [
                'label' => 'Родитель',
                'choices' => $options['parents'],
                'choice_value' => 'id',
                'choice_label' => function (BalanceCategory $item): string {
                    return str_repeat('—', max($item->getLevel() - 1, 0)).' '.$item->getName();
                },
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
            'data_class' => BalanceCategory::class,
            'parents' => [],
        ]);
        $resolver->setRequired('parents');
    }
}
