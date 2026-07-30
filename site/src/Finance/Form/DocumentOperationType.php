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
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

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
            ->add('category', EntityType::class, [
                'class' => PLCategory::class,
                'choices' => $options['categories'],
                'choice_label' => static function (PLCategory $item) {
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
            ->add('counterparty', CounterpartyPickerType::class, [
                'company_id' => (string) $options['company']->getId(),
                'keep_id' => $builder->getData()?->getCounterparty()?->getId(),
                'value_type' => 'entity',
                'search_url' => '/api/counterparties/search',
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
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DocumentOperation::class,
            'categories' => [],
            'project_directions' => [],
            'extra_responsibility_center_ids' => [],
        ]);
        $resolver->setRequired('company');
        $resolver->setAllowedTypes('company', Company::class);
        $resolver->setAllowedTypes('extra_responsibility_center_ids', ['array']);
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
