<?php

namespace App\Form;

use App\DTO\CashTransactionDTO;
use App\Entity\CashflowCategory;
use App\Entity\Company;
use App\Entity\Counterparty;
use App\Entity\MoneyAccount;
use App\Enum\CashDirection;
use App\Repository\CashflowCategoryRepository;
use App\Repository\CounterpartyRepository;
use App\Repository\MoneyAccountRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CashTransactionType extends AbstractType
{
    public function __construct(
        private MoneyAccountRepository $accountRepo,
        private CashflowCategoryRepository $categoryRepo,
        private CounterpartyRepository $counterpartyRepo
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
                'choices' => $company ? $this->categoryRepo->findBy(['company' => $company], ['sort' => 'ASC']) : [],
                'choice_label' => fn (CashflowCategory $c) => str_repeat(' ', $c->getLevel()-1).$c->getName(),
                'choice_value' => 'id',
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
