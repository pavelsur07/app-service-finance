<?php

declare(strict_types=1);

namespace App\Cash\Form\PaymentPlan;

use App\Cash\DTO\PaymentPlanDTO;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\PaymentPlan\PaymentPlanStatus;
use App\Company\Entity\Company;
use App\Company\Form\Type\CounterpartyPickerType;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PaymentPlanType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Company $company */
        $company = $options['company'];

        $builder
            ->add('plannedAt', DateType::class, [
                'widget' => 'single_text',
                'label' => 'Дата',
            ])
            ->add('amount', NumberType::class, [
                'label' => 'Сумма',
                'scale' => 2,
                'input' => 'string',
            ])
            ->add('cashflowCategory', EntityType::class, [
                'class' => CashflowCategory::class,
                'label' => 'Категория',
                'placeholder' => 'Выберите категорию',
                'choice_label' => static fn (CashflowCategory $category): string => (string) $category->getName(),
                'query_builder' => static function (EntityRepository $repository) use ($company) {
                    return $repository->createQueryBuilder('category')
                        ->andWhere('category.company = :company')
                        ->setParameter('company', $company)
                        ->orderBy('category.name', \SortDirection::Ascending);
                },
            ])
            ->add('moneyAccount', EntityType::class, [
                'class' => MoneyAccount::class,
                'label' => 'Счёт',
                'required' => false,
                'placeholder' => 'Любой счёт',
                'choice_label' => static fn (MoneyAccount $account): string => (string) $account->getName(),
                'query_builder' => static function (EntityRepository $repository) use ($company) {
                    return $repository->createQueryBuilder('account')
                        ->andWhere('account.company = :company')
                        ->setParameter('company', $company)
                        ->orderBy('account.name', \SortDirection::Ascending);
                },
            ])
            ->add('counterparty', CounterpartyPickerType::class, [
                'company_id' => (string) $company->getId(),
                'keep_id' => $builder->getData()?->counterparty?->getId(),
                'value_type' => 'entity',
                'search_url' => '/api/counterparties/search',
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Статус',
                'required' => false,
                'placeholder' => 'По умолчанию (PLANNED)',
                'choices' => $this->buildStatusChoices(),
            ])
            ->add('probability', ChoiceType::class, [
                'label' => 'Вероятность',
                'choices' => [
                    '100%' => 100,
                    '90%' => 90,
                    '50%' => 50,
                    '10%' => 10,
                ],
            ])
            ->add('isFrozen', CheckboxType::class, [
                'label' => 'Зафиксировать платёж',
                'required' => false,
            ])
            ->add('expectedAt', DateType::class, [
                'widget' => 'single_text',
                'required' => false,
                'label' => 'Ожидаемая дата',
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'Комментарий',
                'required' => false,
            ]);

        // Только форма создания: повтор той же отправки возвращает уже созданный план.
        if ($options['idempotency_key']) {
            $builder->add('idempotencyKey', HiddenType::class);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PaymentPlanDTO::class,
            'idempotency_key' => false,
        ]);
        $resolver->setAllowedTypes('idempotency_key', 'bool');
        // company обязателен: см. комментарий в CreateDealType — tenant-фильтр
        // не должен зависеть от того, передали опцию или нет.
        $resolver->setRequired('company');
        $resolver->setAllowedTypes('company', Company::class);
    }

    /**
     * @return array<string, string>
     */
    private function buildStatusChoices(): array
    {
        $choices = [];

        foreach (PaymentPlanStatus::cases() as $status) {
            $choices[$status->value] = $status->value;
        }

        return $choices;
    }
}
