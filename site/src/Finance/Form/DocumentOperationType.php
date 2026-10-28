<?php

declare(strict_types=1);

namespace App\Finance\Form;

use App\Company\Application\DTO\FinancialResponsibilityCenterDTO;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Company\Form\Type\CounterpartyPickerType;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Shared\Form\Type\ProjectDirectionPickerType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class DocumentOperationType extends AbstractType
{
    public function __construct(
        private readonly FinancialResponsibilityCenterFacade $responsibilityCenterFacade,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var DocumentOperation|null $operation */
        $operation = $builder->getData();
        /** @var Company $company */
        $company = $options['company'];
        $currentResponsibilityCenterId = $operation instanceof DocumentOperation
            ? $operation->getResponsibilityCenterId()
            : null;

        $builder
            // Виджет «Категория ОПиУ» поверх select; дерево одно на форму — DocumentType::buildView.
            ->add('category', PLCategoryTreeType::class, [
                'choices' => $options['categories'],
                'tree_source' => '#'.DocumentType::CATEGORY_TREE_ID,
                'choice_label' => static function (PLCategory $item) {
                    return str_repeat('—', $item->getLevel() - 1).' '.$item->getName();
                },
                // Знак операции задаёт flow статьи: страница документа показывает по нему тип
                // «Доход/Расход» и считает итоги до сохранения.
                'choice_attr' => static fn (PLCategory $item): array => ['data-flow' => $item->getFlow()->value],
                'label' => 'Категория ОПиУ',
                'placeholder' => 'Выберите категорию',
                'required' => false,
                'empty_data' => null,
            ])
            ->add('amount', NumberType::class, [
                'label' => 'Сумма',
                // Без суммы операция не сохраняется (колонка NOT NULL): ошибка под полем, а не сбой при записи.
                'constraints' => [new NotBlank(message: 'Введите сумму')],
                // Пустое поле не пишется в setAmount(string): иначе очищенная сумма сохранённой операции
                // роняла форму TypeError'ом до валидации. NotBlank проверяет значение поля, а не сущности.
                'setter' => static function (DocumentOperation $operation, mixed $amount): void {
                    if (null !== $amount) {
                        $operation->setAmount((string) $amount);
                    }
                },
            ])
            ->add('counterparty', CounterpartyPickerType::class, [
                'company_id' => (string) $options['company']->getId(),
                'keep_id' => $builder->getData()?->getCounterparty()?->getId() ?? $options['header_counterparty_id'],
                'value_type' => 'entity',
                'search_url' => '/api/counterparties/search',
                'browse' => true,
                'attr' => [
                    'data-operation-counterparty' => 'true',
                ],
            ])
            ->add('projectDirection', ProjectDirectionPickerType::class, [
                'class' => ProjectDirection::class,
                'choices' => $options['project_directions'],
                'choice_label' => static function (ProjectDirection $item) {
                    return str_repeat('—', $item->getLevel() - 1).' '.$item->getName();
                },
                'choice_attr' => static fn (ProjectDirection $item) => !$item->getChildren()->isEmpty() ? ['disabled' => 'disabled'] : [],
                'required' => false,
                // Пустой вариант: без него браузер выбирает первый проект, и операция без проекта
                // (или новая строка при пустой шапке) молча сохраняется с чужим проектом.
                // Пустое значение сервер заполняет из шапки (FinanceDocumentResponsibilityCenterNormalizer).
                'placeholder' => '—',
                'label' => 'Проект',
                'attr' => [
                    'data-operation-project-direction' => 'true',
                ],
            ])
            ->add('responsibilityCenterId', ChoiceType::class, [
                'required' => false,
                'choices' => $this->getResponsibilityCenterChoices(
                    $company,
                    $currentResponsibilityCenterId,
                    $options['extra_responsibility_center_ids'],
                ),
                'placeholder' => 'Не выбран',
                'label' => 'ЦФО',
                'attr' => [
                    'data-operation-responsibility-center' => 'true',
                ],
            ])
            ->add('comment', TextType::class, [
                'required' => false,
                'label' => 'Описание',
                'constraints' => [new Length(max: 255)],
                'attr' => ['maxlength' => 255],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DocumentOperation::class,
            'categories' => [],
            'project_directions' => [],
            'extra_responsibility_center_ids' => [],
            'header_counterparty_id' => null,
        ]);
        $resolver->setRequired('company');
        $resolver->setAllowedTypes('company', Company::class);
        $resolver->setAllowedTypes('extra_responsibility_center_ids', ['array']);
        $resolver->setAllowedTypes('header_counterparty_id', ['string', 'null']);
    }

    /**
     * @return array<string, string>
     */
    private function getResponsibilityCenterChoices(?Company $company, ?string $currentId, array $extraIds): array
    {
        if (null === $company) {
            return [];
        }

        $choices = array_reduce(
            $this->responsibilityCenterFacade->getActiveChoices((string) $company->getId()),
            static function (array $choices, FinancialResponsibilityCenterDTO $center): array {
                $choices[sprintf('%s [%s]', $center->name, $center->code)] = $center->id;

                return $choices;
            },
            [],
        );

        $ids = array_values(array_unique(array_filter(
            [$currentId, ...$extraIds],
            static fn (mixed $id): bool => \is_string($id) && '' !== $id,
        )));

        foreach ($ids as $id) {
            if (\in_array($id, $choices, true)) {
                continue;
            }

            $current = $this->responsibilityCenterFacade->findByIdAndCompany($id, (string) $company->getId());
            if (null !== $current) {
                $choices[sprintf('%s [%s]', $current->name, $current->code)] = $current->id;
            }
        }

        return $choices;
    }
}
