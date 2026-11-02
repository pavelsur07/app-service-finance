<?php

declare(strict_types=1);

namespace App\Billing\Controller\Client;

use App\Billing\Application\ApplyYooKassaPaymentAction;
use App\Billing\Application\PaymentPlanResolver;
use App\Billing\Domain\PaidPeriodCalculator;
use App\Billing\Domain\PaymentPlan;
use App\Billing\Entity\BillingCheckout;
use App\Billing\Entity\Tariff;
use App\Billing\Enum\CheckoutStatus;
use App\Billing\Exception\YooKassaRequestFailedException;
use App\Billing\Exception\YooKassaUnavailableException;
use App\Billing\Facade\BillingFacadeInterface;
use App\Billing\Infrastructure\YooKassa\YooKassaConfig;
use App\Billing\Repository\BillingCheckoutRepository;
use App\Billing\Repository\BillingPaymentRepository;
use App\Billing\Repository\CompanySubscriptionRepository;
use App\Billing\Repository\TariffPriceRepository;
use App\Billing\Repository\TariffRepository;
use App\Shared\Service\ActiveCompanyService;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Exception\OutOfRangeCurrentPageException;
use Pagerfanta\Pagerfanta;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Тариф активного кабинета глазами клиента: текущее состояние, цены публичных тарифов,
 * как оплатить; история оплат и онлайн-оплата — только владельцу кабинета.
 *
 * Возврат с ЮKassa (`?checkout={id}`) сразу сверяет платёж: клиент видит итог, не дожидаясь
 * уведомления. Сверка идемпотентна, повторное открытие ссылки ничего не дублирует.
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
        private readonly CompanySubscriptionRepository $subscriptions,
        private readonly BillingCheckoutRepository $checkouts,
        private readonly ApplyYooKassaPaymentAction $applyYooKassaPayment,
        private readonly PaymentPlanResolver $planResolver,
        private readonly YooKassaConfig $yooKassa,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
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
            try {
                $payments = Pagerfanta::createForCurrentPageWithMaxPerPage(
                    new QueryAdapter($this->payments->createByCompanyQueryBuilder($companyId)),
                    max(1, $request->query->getInt('page', 1)),
                    self::PAYMENTS_PER_PAGE,
                );
            } catch (OutOfRangeCurrentPageException $exception) {
                throw $this->createNotFoundException('Страница истории оплат не найдена.', $exception);
            }
        }

        $checkout = $isOwner ? $this->returnedCheckout($request, $companyId) : null;

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

        $online = $isOwner && $this->yooKassa->isEnabled() ? $this->onlinePayment($companyId, $offers) : null;

        $response = $this->render('billing/client/tariff.html.twig', [
            'company' => $company,
            'access' => $this->billing->accessFor($companyId, $this->clock->now()),
            'offers' => $offers,
            'months' => self::MONTHS,
            'payments' => $payments,
            'billingEmail' => $this->billingEmail,
            'checkout' => $checkout,
            'online' => $online,
        ]);
        // Скрытый ID формы оплаты не должен вернуться из кэша браузера («Назад» из ЮKassa).
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Попытка оплаты, с которой клиент вернулся с ЮKassa, и её статус; незавершённую сверяет с ЮKassa сразу
     * (завершённую Action возвращает без запроса в ЮKassa).
     * Статус берётся из ответа Action: `failed_to_apply` пишется DBAL, объект в identity map его не видит.
     *
     * @return array{checkout: BillingCheckout, status: CheckoutStatus}|null
     */
    private function returnedCheckout(Request $request, string $companyId): ?array
    {
        $id = $request->query->get('checkout');
        if (!\is_string($id) || !Uuid::isValid($id)) {
            return null;
        }
        $checkout = $this->checkouts->findOneByIdAndCompanyId(strtolower($id), $companyId);
        if (null === $checkout) {
            return null;
        }
        if (null === $checkout->getProviderPaymentId()) {
            return ['checkout' => $checkout, 'status' => $checkout->getStatus()];
        }

        try {
            $status = ($this->applyYooKassaPayment)($checkout->getProviderPaymentId());
        } catch (YooKassaUnavailableException|YooKassaRequestFailedException) {
            // Статус покажем «обрабатывается»; добор — уведомлением ЮKassa или cron-сверкой.
            $this->logger->warning('Billing: сверка платежа при возврате с ЮKassa не удалась', ['companyId' => $companyId, 'checkoutId' => $checkout->getId()]);
            $status = null;
        }

        return ['checkout' => $checkout, 'status' => $status ?? CheckoutStatus::PENDING];
    }

    /**
     * Что можно оплатить онлайн и что произойдёт при оплате: те же правила, что у
     * `StartCheckoutAction` и зачисления (`PaymentPlanResolver`). null — онлайн-оплата этому
     * кабинету не нужна или недоступна (бессрочный тариф).
     *
     * @param list<array{tariff: Tariff, prices: array<int, array{total: int, perMonth: int, discountPercent: int}>}> $offers
     *
     * @return array{checkoutId: string, offers: list<array{tariff: Tariff, prices: array<int, array{total: int, perMonth: int, discountPercent: int}>}>, selected: string, plans: array<string, PaymentPlan>, unavailable: list<string>}|null
     */
    private function onlinePayment(string $companyId, array $offers): ?array
    {
        $subscription = $this->subscriptions->findOneByCompanyId($companyId);
        if (null !== $subscription && $subscription->getTariff()->isPerpetual()) {
            return null;
        }

        $options = [];
        $keys = [];
        foreach ($offers as $index => $offer) {
            foreach (array_keys($offer['prices']) as $months) {
                $options[] = [$offer['tariff'], $months];
                $keys[] = [$index, $offer['tariff']->getCode()->value.':'.$months];
            }
        }

        $payable = [];
        $plans = [];
        $unavailable = [];
        foreach ($this->planResolver->planMany($subscription, $this->clock->now(), $options) as $i => $result) {
            [$index, $key] = $keys[$i];
            if ($result instanceof PaymentPlan) {
                $payable[$index] = $offers[$index];
                $plans[$key] = $result;
            } elseif (!\in_array($result->getMessage(), $unavailable, true)) {
                $unavailable[] = $result->getMessage();
            }
        }
        if ([] === $payable) {
            return null;
        }
        $payable = array_values($payable);

        // По умолчанию — запланированный тариф (до перехода можно оплатить только его) или текущий.
        $day = PaidPeriodCalculator::day($this->clock->now());
        $preferred = ($subscription?->scheduledTariffAfter($day) ?? $subscription?->tariffOn($day))?->getCode()->value;
        $codes = array_map(static fn (array $offer): string => $offer['tariff']->getCode()->value, $payable);
        $selected = null !== $preferred && \in_array($preferred, $codes, true) ? $preferred : $codes[0];

        return [
            'checkoutId' => Uuid::uuid7()->toString(),
            'offers' => $payable,
            'selected' => $selected,
            'plans' => $plans,
            'unavailable' => $unavailable,
        ];
    }
}
