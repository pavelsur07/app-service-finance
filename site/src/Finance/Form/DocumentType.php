<?php

declare(strict_types=1);

namespace App\Finance\Form;

use App\Company\Application\DTO\FinancialResponsibilityCenterDTO;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Company\Form\Type\CounterpartyPickerType;
use App\Finance\Entity\Document;
use App\Finance\Enum\DocumentType as DocumentTypeEnum;
use App\Shared\Form\Type\ProjectDirectionPickerType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DocumentType extends AbstractType
{
    public function __construct(
        private readonly FinancialResponsibilityCenterFacade $responsibilityCenterFacade,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Document|null $document */
        $document = $builder->getData();
        /** @var Company $company */
        $company = $options['company'];
        $currentResponsibilityCenterId = $document instanceof Document
            ? $document->getResponsibilityCenterId()
            : null;
        $extraResponsibilityCenterIds = $this->getExistingResponsibilityCenterIds($document);

        $builder
            ->add('date', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'label' => 'Дата',
            ])
            ->add('number', TextType::class, [
                'required' => false,
                'label' => 'Номер',
            ])
            ->add('type', ChoiceType::class, [
                'choices' => DocumentTypeEnum::cases(),
                'choice_label' => static fn (DocumentTypeEnum $type) => $type->label(),
                'choice_value' => 'value',
                'label' => 'Тип документа',
            ])
            ->add('counterparty', CounterpartyPickerType::class, [
                'company_id' => (string) $options['company']->getId(),
                'keep_id' => $builder->getData()?->getCounterparty()?->getId(),
                'value_type' => 'entity',
                'placeholder' => '—',
                'search_url' => '/api/counterparties/search',
                'attr' => [
                    'data-document-counterparty' => 'true',
                ],
            ])
            ->add('projectDirection', ProjectDirectionPickerType::class, [
                'class' => ProjectDirection::class,
                'choices' => $options['project_directions'],
                'choice_label' => static function (ProjectDirection $item) {
                    return str_repeat('—', $item->getLevel() - 1).' '.$item->getName();
                },
                'choice_attr' => static fn (ProjectDirection $item) => !$item->getChildren()->isEmpty() ? ['disabled' => 'disabled'] : [],
                'placeholder' => '—',
                'required' => false,
                'label' => 'Проект',
                'attr' => [
                    'data-document-project-direction' => 'true',
                ],
            ])
            ->add('responsibilityCenterId', ChoiceType::class, [
                'required' => false,
                'choices' => $this->getResponsibilityCenterChoices($company, $currentResponsibilityCenterId),
                'placeholder' => 'Не выбран',
                'label' => 'ЦФО',
                'attr' => [
                    'data-document-responsibility-center' => 'true',
                ],
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'label' => 'Описание',
            ])
            ->add('operations', CollectionType::class, [
                'entry_type' => DocumentOperationType::class,
                'entry_options' => [
                    'categories' => $options['categories'],
                    'project_directions' => $options['project_directions'],
                    'company' => $company,
                    'extra_responsibility_center_ids' => $extraResponsibilityCenterIds,
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => 'Операции',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Document::class,
            'categories' => [],
            'project_directions' => [],
        ]);
        $resolver->setRequired('company');
        $resolver->setAllowedTypes('company', Company::class);
    }

    /**
     * @return array<string, string>
     */
    private function getResponsibilityCenterChoices(?Company $company, ?string $currentId): array
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

        if (null !== $currentId && !\in_array($currentId, $choices, true)) {
            $current = $this->responsibilityCenterFacade->findByIdAndCompany($currentId, (string) $company->getId());
            if (null !== $current) {
                $choices[sprintf('%s [%s]', $current->name, $current->code)] = $current->id;
            }
        }

        return $choices;
    }

    /**
     * @return list<string>
     */
    private function getExistingResponsibilityCenterIds(?Document $document): array
    {
        if (!$document instanceof Document) {
            return [];
        }

        $ids = [];
        if (null !== $document->getResponsibilityCenterId()) {
            $ids[] = $document->getResponsibilityCenterId();
        }

        foreach ($document->getOperations() as $operation) {
            if (null !== $operation->getResponsibilityCenterId()) {
                $ids[] = $operation->getResponsibilityCenterId();
            }
        }

        return array_values(array_unique($ids));
    }
}
