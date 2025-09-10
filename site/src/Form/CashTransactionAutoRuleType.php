<?php

namespace App\Form;

use App\Entity\CashTransactionAutoRule;
use App\Entity\CashflowCategory;
use App\Enum\CashTransactionAutoRuleAction;
use App\Enum\CashTransactionAutoRuleOperationType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CashTransactionAutoRuleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Название автоправила',
            ])
            ->add('action', EnumType::class, [
                'class' => CashTransactionAutoRuleAction::class,
                'label' => 'Действие с операцией ДДС',
            ])
            ->add('operationType', EnumType::class, [
                'class' => CashTransactionAutoRuleOperationType::class,
                'label' => 'Тип операции',
            ])
            ->add('cashflowCategory', EntityType::class, [
                'class' => CashflowCategory::class,
                'choices' => $options['categories'],
                'choice_label' => function (CashflowCategory $item) {
                    return str_repeat('—', $item->getLevel() - 1) . ' ' . $item->getName();
                },
                'label' => 'Категория движения ДДС',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CashTransactionAutoRule::class,
            'categories' => [],
        ]);
    }
}
