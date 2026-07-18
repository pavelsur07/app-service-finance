<?php

namespace App\Cash\Form\Transaction;

use App\Cash\DTO\CashTransactionDTO;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Company\Application\DTO\FinancialResponsibilityCenterDTO;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Company\Repository\CounterpartyRepository;
use App\Company\Repository\ProjectDirectionRepository;
use App\Shared\Form\Type\ProjectDirectionPickerType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CashTransactionType extends AbstractType
{
    public function __construct(
        private MoneyAccountRepository $accountRepo,
        private CashflowCategoryRepository $categoryRepo,
        private CounterpartyRepository $counterpartyRepo,
        private ProjectDirectionRepository $projectDirectionRepo,
        private FinancialResponsibilityCenterFacade $responsibilityCenterFacade,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Company|null $company */
        $company = $options['company'];
        $data = $builder->getData();
        $currentResponsibilityCenterId = $data instanceof CashTransactionDTO
            ? $data->responsibilityCenterId
            : null;

        $builder
            ->add('occurredAt', DateType::class, ['widget' => 'single_text'])
            ->add('moneyAccount', ChoiceType::class, [
                'choices' => $company ? $this->accountRepo->findBy(['company' => $company]) : [],
                'choice_label' => static fn (MoneyAccount $a) => $a->getName(),
                'choice_value' => 'id',
                'choice_attr' => static fn (MoneyAccount $a) => ['data-currency' => $a->getCurrency()],
                'mapped' => false,
            ])
            ->add('direction', ChoiceType::class, [
                'choices' => ['Приток' => CashDirection::INFLOW, 'Отток' => CashDirection::OUTFLOW],
            ])
            ->add('amount', NumberType::class, ['scale' => 2])
            ->add('currency', ChoiceType::class, [
                'choices' => ['RUB' => 'RUB'],
                'disabled' => true,
                'mapped' => false,
            ])
            ->add('cashflowCategory', ChoiceType::class, [
                'required' => false,
                'choices' => $company ? $this->categoryRepo->findTreeByCompany($company) : [],
                'choice_label' => static fn (CashflowCategory $c) => str_repeat("\u{a0}", $c->getLevel() - 1).$c->getName(),
                'choice_value' => 'id',
                'choice_attr' => static fn (CashflowCategory $c) => !$c->getChildren()->isEmpty() ? ['disabled' => 'disabled'] : [],
                'mapped' => false,
            ])
            ->add('projectDirection', ProjectDirectionPickerType::class, [
                'required' => false,
                'choices' => $company ? $this->projectDirectionRepo->findTreeByCompany($company) : [],
                'choice_label' => static fn (ProjectDirection $projectDirection) => str_repeat("\u{a0}", $projectDirection->getLevel() - 1).$projectDirection->getName(),
                'choice_attr' => static fn (ProjectDirection $projectDirection) => !$projectDirection->getChildren()->isEmpty() ? ['disabled' => 'disabled'] : [],
                'mapped' => false,
            ])
            ->add('responsibilityCenterId', ChoiceType::class, [
                'required' => false,
                'choices' => $this->getResponsibilityCenterChoices($company, $currentResponsibilityCenterId),
                'placeholder' => 'Не выбран',
                'label' => 'ЦФО',
            ])
            ->add('counterparty', ChoiceType::class, [
                'required' => false,
                'choices' => $company ? $this->counterpartyRepo->findBy(['company' => $company], ['name' => 'ASC']) : [],
                'choice_label' => 'name',
                'choice_value' => 'id',
                'mapped' => false,
            ])
            ->add('description', TextareaType::class, ['required' => false]);

        $builder->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event) use ($company) {
            /** @var CashTransactionDTO $data */
            $data = $event->getData();
            $form = $event->getForm();
            $account = $form->get('moneyAccount')->getData();

            $data->companyId = $company?->getId();
            $data->moneyAccountId = $account?->getId();
            $data->currency = $account?->getCurrency();

            $cat = $form->get('cashflowCategory')->getData();
            $cp = $form->get('counterparty')->getData();
            $projectDirection = $form->get('projectDirection')->getData();
            $data->cashflowCategoryId = $cat?->getId();
            $data->counterpartyId = $cp?->getId();
            $data->projectDirectionId = $projectDirection?->getId();
        }, 1);
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

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CashTransactionDTO::class,
            'company' => null,
        ]);
        $resolver->setAllowedTypes('company', [Company::class, 'null']);
    }
}
