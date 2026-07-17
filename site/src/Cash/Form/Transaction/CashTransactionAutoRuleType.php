<?php

namespace App\Cash\Form\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Company\Entity\Counterparty;
use App\Company\Entity\ProjectDirection;
use App\Shared\Form\Type\ProjectDirectionPickerType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
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
            ->add('priority', IntegerType::class, [
                'label' => 'Приоритет',
                'help' => 'Чем больше число, тем выше приоритет.',
            ])
            ->add('action', EnumType::class, [
                'class' => CashTransactionAutoRuleAction::class,
                'label' => 'Действие с операцией ДДС',
                'choice_label' => static function (CashTransactionAutoRuleAction $choice) {
                    return match ($choice) {
                        CashTransactionAutoRuleAction::FILL => 'Безопасно заполнить пустые поля',
                        CashTransactionAutoRuleAction::UPDATE => 'Безопасно заполнить пустые поля (legacy UPDATE)',
                    };
                },
            ])
            ->add('operationType', EnumType::class, [
                'class' => CashTransactionAutoRuleOperationType::class,
                'label' => 'Тип операции',
                'choices' => [
                    CashTransactionAutoRuleOperationType::OUTFLOW,
                    CashTransactionAutoRuleOperationType::INFLOW,
                    CashTransactionAutoRuleOperationType::ANY,
                ],
                'choice_label' => static function (CashTransactionAutoRuleOperationType $choice) {
                    return match ($choice) {
                        CashTransactionAutoRuleOperationType::OUTFLOW => 'Отток',
                        CashTransactionAutoRuleOperationType::INFLOW => 'Приток',
                        CashTransactionAutoRuleOperationType::ANY => 'Любое',
                    };
                },
            ])
            ->add('cashflowCategory', EntityType::class, [
                'class' => CashflowCategory::class,
                'choices' => $options['categories'],
                'choice_label' => static function (CashflowCategory $item) {
                    return str_repeat('—', $item->getLevel() - 1).' '.$item->getName();
                },
                'label' => 'Категория движения ДДС',
            ])
            ->add('projectDirection', ProjectDirectionPickerType::class, [
                'class' => ProjectDirection::class,
                'choices' => $options['projectDirections'],
                'choice_label' => static function (ProjectDirection $item) {
                    return str_repeat('—', $item->getLevel() - 1).' '.$item->getName();
                },
                'choice_attr' => static fn (ProjectDirection $item) => !$item->getChildren()->isEmpty() ? ['disabled' => 'disabled'] : [],
                'placeholder' => 'Не выбрано',
                'required' => false,
                'label' => 'Направление / проект',
            ])
            ->add('responsibilityCenterId', ChoiceType::class, [
                'choices' => $options['responsibilityCenterChoices'],
                'placeholder' => 'Не выбран',
                'required' => false,
                'label' => 'ЦФО',
                'help' => 'Для выбранного проекта укажите совместимый ЦФО.',
            ])
            ->add('counterparty', EntityType::class, [
                'class' => Counterparty::class,
                'choices' => $options['counterparties'],
                'choice_label' => static fn (Counterparty $item) => $item->getName(),
                'placeholder' => 'Не выбран',
                'required' => false,
                'label' => 'Контрагент',
            ])
            ->add('conditions', CollectionType::class, [
                'entry_type' => CashTransactionAutoRuleConditionType::class,
                'entry_options' => [
                    'counterparties' => $options['counterparties'],
                    'moneyAccounts' => $options['moneyAccounts'],
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => 'Правила',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CashTransactionAutoRule::class,
            'categories' => [],
            'counterparties' => [],
            'moneyAccounts' => [],
            'projectDirections' => [],
            'responsibilityCenterChoices' => [],
        ]);
    }
}
