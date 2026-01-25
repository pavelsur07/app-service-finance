<?php

declare(strict_types=1);

namespace App\Cash\Form\PaymentPlan;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\DTO\PaymentPlanDTO;
use App\Company\Entity\Company;
use App\Entity\Counterparty;
use App\Enum\PaymentPlanStatus;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PaymentPlanType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Company|null $company */
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
                    $qb = $repository->createQueryBuilder('category')
                        ->orderBy('category.name', 'ASC');

                    if ($company) {
                        $qb->andWhere('category.company = :company')
                            ->setParameter('company', $company);
                    }

                    return $qb;
                },
            ])
            ->add('moneyAccount', EntityType::class, [
                'class' => MoneyAccount::class,
                'label' => 'Счёт',
                'required' => false,
                'placeholder' => 'Любой счёт',
                'choice_label' => static fn (MoneyAccount $account): string => (string) $account->getName(),
                'query_builder' => static function (EntityRepository $repository) use ($company) {
                    $qb = $repository->createQueryBuilder('account')
                        ->orderBy('account.name', 'ASC');

                    if ($company) {
                        $qb->andWhere('account.company = :company')
                            ->setParameter('company', $company);
                    }

                    return $qb;
                },
            ])
            ->add('counterparty', EntityType::class, [
                'class' => Counterparty::class,
                'label' => 'Контрагент',
                'required' => false,
                'placeholder' => 'Без контрагента',
                'choice_label' => static fn (Counterparty $counterparty): string => (string) $counterparty->getName(),
                'query_builder' => static function (EntityRepository $repository) use ($company) {
                    $qb = $repository->createQueryBuilder('counterparty')
                        ->orderBy('counterparty.name', 'ASC');

                    if ($company) {
                        $qb->andWhere('counterparty.company = :company')
                            ->setParameter('company', $company);
                    }

                    return $qb;
                },
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Статус',
                'required' => false,
                'placeholder' => 'По умолчанию (PLANNED)',
                'choices' => $this->buildStatusChoices(),
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'Комментарий',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PaymentPlanDTO::class,
            'company' => null,
        ]);
        $resolver->setAllowedTypes('company', [Company::class, 'null']);
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
