<?php

declare(strict_types=1);

namespace App\Billing\Controller\Admin;

use App\Billing\Domain\PaidPeriodCalculator;
use App\Billing\Facade\BillingFacadeInterface;
use App\Billing\Repository\BillingCheckoutRepository;
use App\Billing\Repository\BillingPaymentRepository;
use App\Billing\Repository\CompanySubscriptionRepository;
use App\Billing\Repository\TariffPriceRepository;
use App\Billing\Repository\TariffRepository;
use App\Company\Facade\CompanyFacade;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Тариф кабинета: текущее состояние, назначение тарифа, отметка оплаты, история оплат.
 *
 * GET /admin/billing/companies/{companyId}
 */
#[Route('/admin/billing/companies/{companyId}', name: 'admin_billing_company', requirements: ['companyId' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
final class CompanyBillingController extends AbstractController
{
    private const PAYMENTS_PER_PAGE = 50;

    public function __construct(
        private readonly CompanyFacade $companies,
        private readonly BillingFacadeInterface $billing,
        private readonly CompanySubscriptionRepository $subscriptions,
        private readonly TariffRepository $tariffs,
        private readonly TariffPriceRepository $prices,
        private readonly BillingPaymentRepository $payments,
        private readonly BillingCheckoutRepository $checkouts,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(string $companyId, Request $request): Response
    {
        $company = $this->companies->findById($companyId) ?? throw $this->createNotFoundException('Кабинет не найден.');
        $subscription = $this->subscriptions->findOneByCompanyId($companyId);

        $pager = Pagerfanta::createForCurrentPageWithMaxPerPage(
            new QueryAdapter($this->payments->createByCompanyQueryBuilder($companyId)),
            max(1, $request->query->getInt('page', 1)),
            self::PAYMENTS_PER_PAGE,
        );

        $currentTariff = $subscription?->getTariff();

        return $this->render('billing/admin/company.html.twig', [
            'company' => $company,
            'access' => $this->billing->accessFor($companyId, $this->clock->now()),
            'subscription' => $subscription,
            'tariffs' => $this->tariffs->findActiveOrdered(),
            'pricesByTariff' => $this->prices->amountsByTariffCode(),
            'payments' => $pager,
            'failedCheckouts' => $this->checkouts->findNeedingAttentionByCompanyId($companyId, $this->clock->now()->modify('-24 hours')),
            'paymentFormId' => Uuid::uuid7()->toString(),
            'defaultPaymentTariff' => null !== $currentTariff && !$currentTariff->isPerpetual() ? $currentTariff->getCode()->value : 'start',
            'today' => PaidPeriodCalculator::day($this->clock->now())->format('Y-m-d'),
        ]);
    }
}
