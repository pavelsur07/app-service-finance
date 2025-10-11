<?php

namespace App\Form;

use App\Entity\Counterparty;
use App\Entity\DocumentOperation;
use App\Entity\PLCategory;
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
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DocumentOperation::class,
            'categories' => [],
            'counterparties' => [],
        ]);
    }
}
