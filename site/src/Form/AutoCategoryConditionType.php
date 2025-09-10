<?php

namespace App\Form;

use App\Entity\AutoCategoryCondition;
use App\Enum\ConditionField;
use App\Enum\ConditionOperator;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AutoCategoryConditionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $opt): void
    {
        $b
            ->add('field', ChoiceType::class, [
                'choices' => [
                    'ИНН плательщика' => ConditionField::PLAT_INN,
                    'ИНН получателя' => ConditionField::POL_INN,
                    'Описание платежа (DESCRIPTION)' => ConditionField::DESCRIPTION,
                    'Сумма (AMOUNT)' => ConditionField::AMOUNT,
                    'Имя контрагента (raw)' => ConditionField::COUNTERPARTY_NAME_RAW,
                    'Наш счёт' => ConditionField::MONEY_ACCOUNT,
                    'Номер документа' => ConditionField::DOC_NUMBER,
                    'Дата' => ConditionField::DATE,
                ],
                'placeholder' => 'Выберите поле',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('operator', ChoiceType::class, [
                'choices' => [
                    'Равно' => ConditionOperator::EQUALS,
                    'Содержит' => ConditionOperator::CONTAINS,
                    'Регулярное выражение' => ConditionOperator::REGEX,
                    'Между (числа/дата)' => ConditionOperator::BETWEEN,
                    'IN (список)' => ConditionOperator::IN,
                    'Не содержит' => ConditionOperator::NOT_CONTAINS,
                    'Не равно' => ConditionOperator::NOT_EQUALS,
                ],
                'placeholder' => 'Выберите оператор',
                'attr' => ['class' => 'form-select'],
            ])
            // ВАЖНО: для строковых полей это обычное текстовое поле, куда пользователь ВРУЧНУЮ вводит триггер
            ->add('value', TextType::class, [
                'attr' => [
                    'class' => 'form-control js-value',
                    'placeholder' => 'Введите триггер (слово/фразу) или значение',
                ],
                'help' => 'CONTAINS — слово/фраза. BETWEEN — min..max (числа или YYYY-MM-DD). IN — ["A","B"]. REGEX — валидный PCRE.',
            ])
            ->add('caseSensitive', CheckboxType::class, [
                'required' => false,
                'label' => 'Учитывать регистр (для строковых операторов)',
            ])
            ->add('negate', CheckboxType::class, [
                'required' => false,
                'label' => 'Инвертировать условие',
            ])
            ->add('position', HiddenType::class, [
                'empty_data' => '0',
            ]);

        $b->addEventListener(FormEvents::POST_SUBMIT, function(FormEvent $event) {
            /** @var AutoCategoryCondition $data */
            $data = $event->getData();
            $form = $event->getForm();
            if ($data->getOperator() === ConditionOperator::REGEX) {
                $pattern = $data->getValue();
                $delim = '/';
                $regex = $delim . str_replace($delim, '\\' . $delim, $pattern) . $delim;
                if (@preg_match($regex, '') === false) {
                    $form->get('value')->addError(new FormError('Некорректное регулярное выражение'));
                }
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AutoCategoryCondition::class]);
    }
}
