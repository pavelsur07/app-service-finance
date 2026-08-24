<?php

declare(strict_types=1);

namespace App\Cash\Form\Transaction;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Company\Form\Type\CounterpartyPickerType;
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
                        CashTransactionAutoRuleConditionField::CURRENCY => 'Валюта (точное совпадение)',
                        CashTransactionAutoRuleConditionField::IMPORT_SOURCE => 'Источник импорта (точное совпадение)',
                        CashTransactionAutoRuleConditionField::IS_TRANSFER => 'Внутренний перевод',
                        CashTransactionAutoRuleConditionField::DOCUMENT_TYPE => 'Тип документа (точное совпадение)',
                        CashTransactionAutoRuleConditionField::MONEY_ACCOUNT => 'Денежный счёт (точное совпадение)',
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
            ->add('counterparty', CounterpartyPickerType::class, [
                'company_id' => $options['company_id'],
                'keep_id' => $builder->getData()?->getCounterparty()?->getId(),
                'value_type' => 'entity',
                'search_url' => '/api/counterparties/search',
                'row_attr' => ['class' => 'condition-counterparty-row flex-fill'],
            ])
            ->add('moneyAccount', EntityType::class, [
                'class' => MoneyAccount::class,
                'choices' => $options['moneyAccounts'],
                'choice_label' => static fn (MoneyAccount $account): string => sprintf(
                    '%s (%s)',
                    $account->getName(),
                    $account->getCurrency(),
                ),
                'required' => false,
                'label' => 'Денежный счёт',
                'row_attr' => ['class' => 'condition-money-account-row flex-fill'],
            ])
            ->add('value', TextType::class, [
                'required' => false,
                'label' => 'Значение',
                'row_attr' => ['class' => 'condition-value-row flex-fill'],
            ])
            ->add('valueTo', TextType::class, [
                'required' => false,
                'label' => 'Значение до',
                'row_attr' => ['class' => 'condition-value-to-row flex-fill'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('company_id');
        $resolver->setAllowedTypes('company_id', 'string');
        $resolver->setDefaults([
            'data_class' => CashTransactionAutoRuleCondition::class,
            'moneyAccounts' => [],
        ]);
    }
}
