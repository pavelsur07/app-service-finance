<?php

declare(strict_types=1);

namespace App\Billing\Controller\Client;

use App\Billing\Facade\BillingFacadeInterface;
use App\Billing\Repository\BillingPaymentRepository;
use App\Billing\Repository\TariffPriceRepository;
use App\Billing\Repository\TariffRepository;
use App\Shared\Service\ActiveCompanyService;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Тариф активного кабинета глазами клиента: текущее состояние, цены публичных тарифов,
 * как оплатить; история оплат — только владельцу кабинета.
 *
 * GET /company/price
 */
#[Route('/company/price', name: 'company_price_index', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
final class CompanyTariffController extends AbstractController
{
    private const PAYMENTS_PER_PAGE = 50;
    private const MONTHS = [1, 3, 6, 12];

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly BillingFacadeInterface $billing,
        private readonly TariffRepository $tariffs,
        private readonly TariffPriceRepository $prices,
        private readonly BillingPaymentRepository $payments,
        private readonly ClockInterface $clock,
        #[Autowire('%env(string:APP_MAIL_REPLY_TO)%')]
        private readonly string $billingEmail,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();
        // Владелец — как у карточки компании и налоговых настроек; участникам история оплат не видна.
        $isOwner = null !== $company->getUser() && $company->getUser() === $this->getUser();

        $payments = null;
        if ($isOwner) {
            $payments = Pagerfanta::createForCurrentPageWithMaxPerPage(
                new QueryAdapter($this->payments->createByCompanyQueryBuilder($companyId)),
                max(1, $request->query->getInt('page', 1)),
                self::PAYMENTS_PER_PAGE,
            );
        }

        $amounts = $this->prices->amountsByTariffCode();
        $offers = [];
        foreach ($this->tariffs->findActiveOrdered() as $tariff) {
            $byMonths = $amounts[$tariff->getCode()->value] ?? [];
            if (!$tariff->isPublic() || !isset($byMonths[1])) {
                continue;
            }
            $prices = [];
            foreach (self::MONTHS as $months) {
                if (!isset($byMonths[$months])) {
                    continue;
                }
                $total = $byMonths[$months];
                $prices[$months] = [
                    'total' => $total,
                    'perMonth' => intdiv($total, $months),
                    'discountPercent' => (int) round(100 - $total * 100 / ($byMonths[1] * $months)),
                ];
            }
            $offers[] = ['tariff' => $tariff, 'prices' => $prices];
        }

        return $this->render('billing/client/tariff.html.twig', [
            'company' => $company,
            'access' => $this->billing->accessFor($companyId, $this->clock->now()),
            'offers' => $offers,
            'months' => self::MONTHS,
            'payments' => $payments,
            'billingEmail' => $this->billingEmail,
        ]);
    }
}
