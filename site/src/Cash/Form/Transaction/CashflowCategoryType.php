<?php

namespace App\Cash\Form\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashflowCategoryStatus;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Finance\Entity\PLCategory;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CashflowCategoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (!$options['protected_system_fields']) {
            $builder
                ->add('name', TextType::class, [
                    'label' => 'Наименование',
                ])
                ->add('code', TextType::class, [
                    'label' => 'Код',
                    'required' => false,
                    'help' => 'Уникальный код в пределах компании: латинские буквы, цифры и _',
                ]);

            $builder->get('code')->addModelTransformer(new CashflowCategoryCodeTransformer());
        }

        $builder
            ->add('description', TextareaType::class, [
                'label' => 'Описание',
                'required' => false,
            ])
            ->add('status', EnumType::class, [
                'class' => CashflowCategoryStatus::class,
                'label' => 'Статус',
            ]);

        if ($options['allow_flow_kind_edit']) {
            $builder->add('flowKind', ChoiceType::class, [
                'label' => 'Вид деятельности',
                'choices' => [
                    CashflowFlowKind::OPERATING->label() => CashflowFlowKind::OPERATING,
                    CashflowFlowKind::INVESTING->label() => CashflowFlowKind::INVESTING,
                    CashflowFlowKind::FINANCING->label() => CashflowFlowKind::FINANCING,
                ],
                'choice_value' => static fn (?CashflowFlowKind $flowKind) => $flowKind?->value,
                'help' => 'Для root-категории. Дочерняя категория наследует вид деятельности от root.',
                'attr' => [
                    'data-cashflow-category-flow-kind-target' => 'flowKind',
                ],
            ]);
            $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
                $data = $event->getData();
                $category = $event->getForm()->getData();
                if (!\is_array($data) || !$category instanceof CashflowCategory || '' !== ($data['flowKind'] ?? '')) {
                    return;
                }

                $data['flowKind'] = $category->getFlowKind()->value;
                $event->setData($data);
            });
        }

        if (!$options['protected_system_fields']) {
            $builder
                ->add('sort', IntegerType::class, [
                    'label' => 'Сортировка',
                ])
                ->add('parent', EntityType::class, [
                    'class' => CashflowCategory::class,
                    'choices' => $options['parents'],
                    'choice_label' => static function (CashflowCategory $item) {
                        return str_repeat('—', $item->getLevel() - 1).' '.$item->getName();
                    },
                    'required' => false,
                    'placeholder' => '— Корневая категория —',
                    'label' => 'Родитель',
                    'attr' => [
                        'data-cashflow-category-flow-kind-target' => 'parent',
                        'data-action' => 'change->cashflow-category-flow-kind#toggle',
                    ],
                ]);
        }

        $builder
            ->add('allowPlDocument', CheckboxType::class, [
                'label' => 'Разрешено создавать документы ОПиУ из этой категории',
                'required' => false,
            ])
            ->add('plCategory', EntityType::class, [
                'class' => PLCategory::class,
                'choices' => $options['plCategories'],
                'choice_label' => static function (PLCategory $item) {
                    return str_repeat('—', $item->getLevel() - 1).' '.$item->getName();
                },
                'required' => false,
                'placeholder' => '—',
                'label' => 'Категория ОПиУ по умолчанию',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CashflowCategory::class,
            'parents' => [],
            'plCategories' => [],
            'allow_flow_kind_edit' => false,
            'protected_system_fields' => false,
        ]);
    }
}
