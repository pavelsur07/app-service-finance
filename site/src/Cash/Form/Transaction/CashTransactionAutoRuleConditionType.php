<?php

namespace App\Cash\Form\Transaction;

use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Company\Entity\Counterparty;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CashTransactionAutoRuleConditionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('field', EnumType::class, [
                'class' => CashTransactionAutoRuleConditionField::class,
                'label' => 'Поле операции',
                'row_attr' => ['class' => 'condition-field-row'],
                'choice_label' => static function (CashTransactionAutoRuleConditionField $choice) {
                    return match ($choice) {
                        CashTransactionAutoRuleConditionField::COUNTERPARTY => 'Контрагент (точное совпадение)',
                        CashTransactionAutoRuleConditionField::COUNTERPARTY_NAME => 'Название контрагента содержит',
                        CashTransactionAutoRuleConditionField::INN => 'ИНН контрагента',
                        CashTransactionAutoRuleConditionField::DATE => 'Дата операции',
                        CashTransactionAutoRuleConditionField::AMOUNT => 'Сумма',
                        CashTransactionAutoRuleConditionField::DESCRIPTION => 'Описание содержит',
                    };
                },
            ])
            ->add('operator', EnumType::class, [
                'class' => CashTransactionAutoRuleConditionOperator::class,
                'label' => 'Оператор',
                'row_attr' => ['class' => 'condition-operator-row'],
                'choice_label' => static function (CashTransactionAutoRuleConditionOperator $choice) {
                    return match ($choice) {
                        CashTransactionAutoRuleConditionOperator::EQUAL => 'Равно',
                        CashTransactionAutoRuleConditionOperator::GREATER_THAN => 'Больше',
                        CashTransactionAutoRuleConditionOperator::LESS_THAN => 'Меньше',
                        CashTransactionAutoRuleConditionOperator::BETWEEN => 'Между',
                        CashTransactionAutoRuleConditionOperator::CONTAINS => 'Содержит',
                    };
                },
            ])
            ->add('counterparty', EntityType::class, [
                'class' => Counterparty::class,
                'choices' => $options['counterparties'],
                'choice_label' => 'name',
                'required' => false,
                'label' => 'Контрагент',
                'row_attr' => ['class' => 'condition-counterparty-row'],
            ])
            ->add('value', TextType::class, [
                'required' => false,
                'label' => 'Значение',
                'row_attr' => ['class' => 'condition-value-row'],
            ])
            ->add('valueTo', TextType::class, [
                'required' => false,
                'label' => 'Значение до',
                'row_attr' => ['class' => 'condition-value-to-row'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CashTransactionAutoRuleCondition::class,
            'counterparties' => [],
        ]);
    }
}
