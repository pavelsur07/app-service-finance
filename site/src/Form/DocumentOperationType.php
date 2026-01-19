<?php

namespace App\Form;

use App\Entity\Counterparty;
use App\Entity\DocumentOperation;
use App\Entity\PLCategory;
use App\Entity\ProjectDirection;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DocumentOperationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('category', EntityType::class, [
                'class' => PLCategory::class,
                'choices' => $options['categories'],
                'choice_label' => function (PLCategory $item) {
                    return str_repeat('—', $item->getLevel() - 1).' '.$item->getName();
                },
                'label' => 'Категория',
                'placeholder' => '—',
                'required' => false,
                'empty_data' => null,
            ])
            ->add('amount', NumberType::class, [
                'label' => 'Сумма',
            ])
            ->add('counterparty', EntityType::class, [
                'class' => Counterparty::class,
                'choices' => $options['counterparties'],
                'choice_label' => 'name',
                'required' => false,
                'label' => 'Контрагент',
                'attr' => [
                    'data-operation-counterparty' => 'true',
                ],
            ])
            ->add('projectDirection', EntityType::class, [
                'class' => ProjectDirection::class,
                'choices' => $options['project_directions'],
                'choice_label' => function (ProjectDirection $item) {
                    return str_repeat('—', $item->getLevel() - 1).' '.$item->getName();
                },
                'choice_attr' => fn (ProjectDirection $item) => !$item->getChildren()->isEmpty() ? ['disabled' => 'disabled'] : [],
                'required' => false,
                'label' => 'Проект',
                'attr' => [
                    'data-operation-project-direction' => 'true',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DocumentOperation::class,
            'categories' => [],
            'counterparties' => [],
            'project_directions' => [],
        ]);
    }
}
