<?php

namespace App\Cash\Form\Transaction;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\DTO\CashTransactionDTO;
use App\Entity\Company;
use App\Entity\ProjectDirection;
use App\Repository\CounterpartyRepository;
use App\Repository\ProjectDirectionRepository;
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
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Company|null $company */
        $company = $options['company'];

        $builder
            ->add('occurredAt', DateType::class, ['widget' => 'single_text'])
            ->add('moneyAccount', ChoiceType::class, [
                'choices' => $company ? $this->accountRepo->findBy(['company' => $company]) : [],
                'choice_label' => fn (MoneyAccount $a) => $a->getName(),
                'choice_value' => 'id',
                'choice_attr' => fn (MoneyAccount $a) => ['data-currency' => $a->getCurrency()],
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
                'choice_label' => fn (CashflowCategory $c) => str_repeat("\u{a0}", $c->getLevel() - 1).$c->getName(),
                'choice_value' => 'id',
                'choice_attr' => fn (CashflowCategory $c) => !$c->getChildren()->isEmpty() ? ['disabled' => 'disabled'] : [],
                'mapped' => false,
            ])
            ->add('projectDirection', ProjectDirectionPickerType::class, [
                'required' => false,
                'choices' => $company ? $this->projectDirectionRepo->findTreeByCompany($company) : [],
                'choice_label' => fn (ProjectDirection $projectDirection) => str_repeat("\u{a0}", $projectDirection->getLevel() - 1).$projectDirection->getName(),
                'choice_attr' => fn (ProjectDirection $projectDirection) => !$projectDirection->getChildren()->isEmpty() ? ['disabled' => 'disabled'] : [],
                'mapped' => false,
            ])
            ->add('counterparty', ChoiceType::class, [
                'required' => false,
                'choices' => $company ? $this->counterpartyRepo->findBy(['company' => $company], ['name' => 'ASC']) : [],
                'choice_label' => 'name',
                'choice_value' => 'id',
                'mapped' => false,
            ])
            ->add('description', TextareaType::class, ['required' => false]);

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event) use ($company) {
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

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CashTransactionDTO::class,
            'company' => null,
        ]);
        $resolver->setAllowedTypes('company', [Company::class, 'null']);
    }
}
