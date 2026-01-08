<?php

declare(strict_types=1);

namespace App\Finance\Controller;

use App\Cash\Service\PaymentPlan\ForecastBalanceService;
use App\Cash\Service\PaymentPlan\PaymentPlanService;
use App\Cash\Service\PaymentPlan\RecurrenceMaterializer;
use App\DTO\ForecastDTO;
use App\DTO\PaymentPlanDTO;
use App\Entity\CashflowCategory;
use App\Entity\Company;
use App\Entity\Counterparty;
use App\Entity\MoneyAccount;
use App\Entity\PaymentPlan;
use App\Enum\PaymentPlanStatus as PaymentPlanStatusEnum;
use App\Enum\PaymentPlanType as PaymentPlanTypeEnum;
use App\Form\PaymentPlanType;
use App\Repository\PaymentPlanRepository;
use App\Service\ActiveCompanyService;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/finance/payment-calendar')]
final class PaymentCalendarController extends AbstractController
{
    public function __construct(
        private ActiveCompanyService $activeCompanyService,
        private PaymentPlanRepository $paymentPlanRepository,
        private EntityManagerInterface $entityManager,
        private PaymentPlanService $paymentPlanService,
        private ForecastBalanceService $forecastBalanceService,
        private RecurrenceMaterializer $recurrenceMaterializer,
    ) {
    }

    #[Route('', name: 'payment_calendar_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();

        return $this->renderCalendar($request, $company, []);
    }

    #[Route('/new', name: 'payment_calendar_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $filters = $this->extractFilters($request);

        $dto = new PaymentPlanDTO();
        $form = $this->createForm(PaymentPlanType::class, $dto, [
            'company' => $company,
            'action' => $this->generateUrl('payment_calendar_new', $this->buildFilterQuery($filters)),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && null !== $dto->cashflowCategory) {
            $plan = new PaymentPlan(
                Uuid::uuid4()->toString(),
                $company,
                $dto->cashflowCategory,
                \DateTimeImmutable::createFromInterface($dto->plannedAt),
                (string) $dto->amount
            );

            $this->paymentPlanService->applyCompanyScope($plan, $company);
            $plan->setCashflowCategory($dto->cashflowCategory);
            $plan->setPlannedAt(\DateTimeImmutable::createFromInterface($dto->plannedAt));
            $plan->setAmount((string) $dto->amount);
            $plan->setMoneyAccount($dto->moneyAccount);
            $plan->setCounterparty($dto->counterparty);
            $plan->setComment($dto->comment);

            $resolvedType = $this->paymentPlanService->resolveTypeByCategory($dto->cashflowCategory);
            $plan->setType(PaymentPlanTypeEnum::from($resolvedType));
            $plan->setStatus($this->resolveStatus($dto->status));

            $this->entityManager->persist($plan);
            $this->entityManager->flush();

            $this->addFlash('success', 'Создано');

            return $this->redirectToRoute('payment_calendar_index', $this->buildFilterQuery($filters));
        }

        return $this->renderCalendar($request, $company, [
            'form' => $form->createView(),
            'showForm' => true,
            'formTitle' => 'Новая запись',
        ], $filters);
    }

    #[Route('/{id}/edit', name: 'payment_calendar_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, PaymentPlan $plan): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();

        if ($plan->getCompany()->getId() !== $company->getId()) {
            throw $this->createAccessDeniedException();
        }

        $filters = $this->extractFilters($request);

        $dto = new PaymentPlanDTO();
        $dto->plannedAt = $plan->getPlannedAt();
        $dto->amount = $plan->getAmount();
        $dto->cashflowCategory = $plan->getCashflowCategory();
        $dto->moneyAccount = $plan->getMoneyAccount();
        $dto->counterparty = $plan->getCounterparty();
        $dto->comment = $plan->getComment();
        $dto->status = $plan->getStatus()->value;

        $form = $this->createForm(PaymentPlanType::class, $dto, [
            'company' => $company,
            'action' => $this->generateUrl('payment_calendar_edit', array_merge(
                ['id' => (string) $plan->getId()],
                $this->buildFilterQuery($filters)
            )),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && null !== $dto->cashflowCategory) {
            $this->paymentPlanService->applyCompanyScope($plan, $company);
            $plan->setCashflowCategory($dto->cashflowCategory);
            $plan->setPlannedAt(\DateTimeImmutable::createFromInterface($dto->plannedAt));
            $plan->setAmount((string) $dto->amount);
            $plan->setMoneyAccount($dto->moneyAccount);
            $plan->setCounterparty($dto->counterparty);
            $plan->setComment($dto->comment);

            $resolvedType = $this->paymentPlanService->resolveTypeByCategory($dto->cashflowCategory);
            $plan->setType(PaymentPlanTypeEnum::from($resolvedType));
            $plan->setStatus($this->resolveStatus($dto->status));

            $this->entityManager->flush();

            $this->addFlash('success', 'Сохранено');

            return $this->redirectToRoute('payment_calendar_index', $this->buildFilterQuery($filters));
        }

        return $this->renderCalendar($request, $company, [
            'form' => $form->createView(),
            'showForm' => true,
            'formTitle' => 'Редактирование',
        ], $filters);
    }

    #[Route('/{id}/status', name: 'payment_calendar_status', methods: ['POST'])]
    public function changeStatus(Request $request, PaymentPlan $plan): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();

        if ($plan->getCompany()->getId() !== $company->getId()) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('payment_plan_status_'.$plan->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $filters = $this->extractFilters($request);
        $action = (string) $request->request->get('action');
        $statusMap = [
            'approve' => PaymentPlanStatusEnum::APPROVED->value,
            'pay' => PaymentPlanStatusEnum::PAID->value,
            'cancel' => PaymentPlanStatusEnum::CANCELED->value,
        ];

        if (!\array_key_exists($action, $statusMap)) {
            $this->addFlash('danger', 'Неизвестное действие.');

            return $this->redirectToRoute('payment_calendar_index', $this->buildFilterQuery($filters));
        }

        $targetStatus = $statusMap[$action];

        if ($plan->getStatus()->value === $targetStatus) {
            $this->addFlash('info', 'Статус уже установлен.');

            return $this->redirectToRoute('payment_calendar_index', $this->buildFilterQuery($filters));
        }

        try {
            $this->paymentPlanService->transitionStatus($plan, $targetStatus);
            $this->entityManager->flush();
        } catch (\DomainException $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('payment_calendar_index', $this->buildFilterQuery($filters));
        }

        $messages = [
            'approve' => 'Платёж одобрен.',
            'pay' => 'Платёж помечен как оплаченный.',
            'cancel' => 'Платёж отменён.',
        ];

        $this->addFlash('success', $messages[$action] ?? 'Статус обновлён.');

        return $this->redirectToRoute('payment_calendar_index', $this->buildFilterQuery($filters));
    }

    #[Route('/{id}/postpone', name: 'payment_calendar_postpone', methods: ['POST'])]
    public function postpone(Request $request, PaymentPlan $plan): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();

        if ($plan->getCompany()->getId() !== $company->getId()) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('payment_plan_postpone_'.$plan->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $filters = $this->extractFilters($request);
        $days = (int) $request->request->get('days', 0);

        if ($days <= 0) {
            $this->addFlash('danger', 'Количество дней должно быть больше нуля.');

            return $this->redirectToRoute('payment_calendar_index', $this->buildFilterQuery($filters));
        }

        $plannedAt = $plan->getPlannedAt();
        if (null === $plannedAt) {
            $this->addFlash('danger', 'Не удалось перенести платёж.');

            return $this->redirectToRoute('payment_calendar_index', $this->buildFilterQuery($filters));
        }

        $plan->setPlannedAt($plannedAt->modify(sprintf('+%d day', $days)));
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Платёж перенесён на %d дн.', $days));

        return $this->redirectToRoute('payment_calendar_index', $this->buildFilterQuery($filters));
    }

    /**
     * @param array{
     *     from: string,
     *     to: string,
     *     account_id: string,
     *     category_id: string,
     *     status: string,
     *     counterparty_id: string
     * } $filters
     */
    private function renderCalendar(Request $request, Company $company, array $extra, ?array $filters = null): Response
    {
        $filters ??= $this->extractFilters($request);

        $plans = [];
        $period = null;
        $forecast = new ForecastDTO();
        $forecastSeries = [];
        $from = $this->parseDate($filters['from'] ?? null);
        $to = $this->parseDate($filters['to'] ?? null);

        if (null === $from || null === $to) {
            $this->addFlash('danger', 'Пожалуйста, укажите период (from/to).');
        } elseif ($from > $to) {
            $this->addFlash('danger', 'Дата начала периода не может быть позже даты окончания.');
        } else {
            $this->recurrenceMaterializer->materialize($company, $from, $to);
            $plans = $this->loadPlans($company, $from, $to, $filters);
            $period = ['from' => $from, 'to' => $to];

            $account = null;
            if (!empty($filters['account_id'])) {
                $candidate = $this->entityManager->getRepository(MoneyAccount::class)->find($filters['account_id']);
                if ($candidate instanceof MoneyAccount && $candidate->getCompany()->getId() === $company->getId()) {
                    $account = $candidate;
                }
            }

            $forecast = $this->forecastBalanceService->buildForecast($company, $from, $to, $account);
            $forecastSeries = $forecast->series;
        }

        $filterQuery = $this->buildFilterQuery($filters);

        $context = [
            'plans' => $plans,
            'filters' => $filters,
            'filterQuery' => $filterQuery,
            'period' => $period,
            'accounts' => $this->entityManager->getRepository(MoneyAccount::class)->findBy(['company' => $company], ['name' => 'ASC']),
            'categories' => $this->entityManager->getRepository(CashflowCategory::class)->findBy(['company' => $company], ['name' => 'ASC']),
            'counterparties' => $this->entityManager->getRepository(Counterparty::class)->findBy(['company' => $company], ['name' => 'ASC']),
            'statuses' => $this->statusChoices(),
            'forecast' => $forecast,
            'forecastSeries' => $forecastSeries,
        ];

        return $this->render('payment_calendar/index.html.twig', array_merge($context, $extra));
    }

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     account_id: string,
     *     category_id: string,
     *     status: string,
     *     counterparty_id: string
     * }
     */
    private function extractFilters(Request $request): array
    {
        return [
            'from' => (string) $request->query->get('from', ''),
            'to' => (string) $request->query->get('to', ''),
            'account_id' => (string) $request->query->get('account_id', ''),
            'category_id' => (string) $request->query->get('category_id', ''),
            'status' => (string) $request->query->get('status', ''),
            'counterparty_id' => (string) $request->query->get('counterparty_id', ''),
        ];
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }

    /**
     * @param array{
     *     from: string,
     *     to: string,
     *     account_id: string,
     *     category_id: string,
     *     status: string,
     *     counterparty_id: string
     * } $filters
     *
     * @return array<string, string>
     */
    private function buildFilterQuery(array $filters): array
    {
        $result = [];

        foreach ($filters as $key => $value) {
            if (null !== $value && '' !== $value) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array{
     *     from: string,
     *     to: string,
     *     account_id: string,
     *     category_id: string,
     *     status: string,
     *     counterparty_id: string
     * } $filters
     *
     * @return list<PaymentPlan>
     */
    private function loadPlans(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to, array $filters): array
    {
        $qb = $this->paymentPlanRepository->createQueryBuilder('plan')
            ->andWhere('plan.company = :company')
            ->andWhere('plan.plannedAt BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->orderBy('plan.plannedAt', 'ASC')
            ->addOrderBy('plan.createdAt', 'ASC');

        if (!empty($filters['account_id'])) {
            $qb->andWhere('plan.moneyAccount = :accountId')
                ->setParameter('accountId', $filters['account_id']);
        }

        if (!empty($filters['category_id'])) {
            $qb->andWhere('plan.cashflowCategory = :categoryId')
                ->setParameter('categoryId', $filters['category_id']);
        }

        if (!empty($filters['counterparty_id'])) {
            $qb->andWhere('plan.counterparty = :counterpartyId')
                ->setParameter('counterpartyId', $filters['counterparty_id']);
        }

        if (!empty($filters['status'])) {
            $status = PaymentPlanStatusEnum::tryFrom((string) $filters['status']);
            if ($status) {
                $qb->andWhere('plan.status = :status')
                    ->setParameter('status', $status);
            }
        }

        /** @var list<PaymentPlan> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    private function resolveStatus(?string $status): PaymentPlanStatusEnum
    {
        if (null === $status || '' === $status) {
            return PaymentPlanStatusEnum::PLANNED;
        }

        return PaymentPlanStatusEnum::from($status);
    }

    /**
     * @return array<string, string>
     */
    private function statusChoices(): array
    {
        $choices = [];

        foreach (PaymentPlanStatusEnum::cases() as $status) {
            $choices[$status->value] = $status->value;
        }

        return $choices;
    }
}
