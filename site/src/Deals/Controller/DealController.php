<?php

namespace App\Deals\Controller;

use App\Company\Entity\User;
use App\Company\Entity\Company;
use App\Deals\DTO\CreateDealFormData;
use App\Deals\DTO\DealAdjustmentFormData;
use App\Deals\DTO\DealChargeFormData;
use App\Deals\DTO\DealItemFormData;
use App\Deals\Entity\Deal;
use App\Deals\Enum\DealChannel;
use App\Deals\Enum\DealStatus;
use App\Deals\Exception\AccessDenied as DealAccessDenied;
use App\Deals\Exception\DealNotFound;
use App\Deals\Exception\InvalidDealState;
use App\Deals\Exception\ValidationFailed;
use App\Deals\Form\CreateDealType;
use App\Deals\Form\DealAdjustmentType;
use App\Deals\Form\DealChargeType;
use App\Deals\Form\DealItemType;
use App\Deals\Repository\DealRepository;
use App\Deals\Service\DealFilter;
use App\Deals\Service\DealManager;
use App\Deals\Service\Request\AddDealAdjustmentRequest;
use App\Deals\Service\Request\AddDealChargeRequest;
use App\Deals\Service\Request\AddDealItemRequest;
use App\Deals\Service\Request\CreateDealRequest;
use App\Deals\Service\Request\RemoveDealChargeRequest;
use App\Deals\Service\Request\RemoveDealItemRequest;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/deals')]
final class DealController extends AbstractController
{
    private const AMOUNT_SCALE = 2;

    public function __construct(private readonly ActiveCompanyService $companyService)
    {
    }

    #[Route('', name: 'deal_index', methods: ['GET'])]
    public function index(Request $request, DealRepository $dealRepository): Response
    {
        $company = $this->companyService->getActiveCompany();
        $filter = new DealFilter();

        $filter->dateFrom = $this->parseDate($request->query->get('dateFrom'));
        $filter->dateTo = $this->parseDate($request->query->get('dateTo'));
        $filter->status = $this->parseStatus($request->query->get('status'));
        $filter->channel = $this->parseChannel($request->query->get('channel'));
        $filter->page = max(1, (int) $request->query->get('page', 1));
        $filter->limit = (int) $request->query->get('limit', 20);

        $pager = $dealRepository->findListForCompany($company, $filter);
        $deals = iterator_to_array($pager->getCurrentPageResults());

        return $this->render('deals/index.html.twig', [
            'deals' => $deals,
            'filters' => [
                'dateFrom' => $request->query->get('dateFrom'),
                'dateTo' => $request->query->get('dateTo'),
                'status' => $request->query->get('status'),
                'channel' => $request->query->get('channel'),
                'page' => $filter->page,
                'limit' => $filter->limit,
            ],
            'statusOptions' => DealStatus::cases(),
            'channelOptions' => DealChannel::cases(),
            'pager' => $pager,
        ]);
    }

    #[Route('/new', name: 'deal_new', methods: ['GET', 'POST'])]
    public function new(Request $request, DealManager $dealManager): Response
    {
        $company = $this->companyService->getActiveCompany();
        $formData = new CreateDealFormData();
        $form = $this->createForm(CreateDealType::class, $formData, ['company' => $company]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->getUserOrFail();
            $createRequest = new CreateDealRequest(
                $formData->type,
                $formData->channel,
                $formData->recognizedAt,
                title: $formData->title,
                counterpartyId: $formData->counterpartyId?->getId(),
            );

            try {
                $deal = $dealManager->createDeal($createRequest, $user, $company);
            } catch (\Throwable $exception) {
                return $this->handleDealException($exception, $request);
            }

            $this->addFlash('success', 'Сделка создана.');

            return $this->redirectToRoute('deal_show', ['id' => $deal->getId()]);
        }

        return $this->render('deals/new.html.twig', [
            'form' => $form->createView(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}', name: 'deal_show', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function show(Request $request, string $id, DealRepository $dealRepository): Response
    {
        $company = $this->companyService->getActiveCompany();
        try {
            $deal = $this->getDealOrFail($id, $company, $dealRepository);
        } catch (\Throwable $exception) {
            return $this->handleDealException($exception, $request);
        }

        $chargeData = $this->createChargeFormData($deal);
        $adjustmentData = $this->createAdjustmentFormData($deal);
        $activeTab = $this->resolveActiveTab($request);

        return $this->renderShowPage(
            $deal,
            $this->createItemForm($company),
            $this->createChargeForm($company, $chargeData),
            $this->createAdjustmentForm($company, $adjustmentData),
            $activeTab,
        );
    }

    #[Route('/{id}/confirm', name: 'deal_confirm', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function confirm(
        Request $request,
        string $id,
        DealRepository $dealRepository,
        DealManager $dealManager,
    ): Response {
        $company = $this->companyService->getActiveCompany();
        try {
            $deal = $this->getDealOrFail($id, $company, $dealRepository);
        } catch (\Throwable $exception) {
            return $this->handleDealException($exception, $request);
        }

        if (!$this->isCsrfTokenValid('deal_confirm'.$deal->getId(), (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $user = $this->getUserOrFail();

        try {
            $dealManager->confirmDeal($deal, $user, $company);
        } catch (\Throwable $exception) {
            return $this->handleDealException($exception, $request);
        }

        $this->addFlash('success', 'Сделка подтверждена.');
        $activeTab = $this->resolveActiveTab($request);

        return $this->redirectToRoute('deal_show', ['id' => $deal->getId(), 'tab' => $activeTab]);
    }

    #[Route('/{id}/cancel', name: 'deal_cancel', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function cancel(
        Request $request,
        string $id,
        DealRepository $dealRepository,
        DealManager $dealManager,
    ): Response {
        $company = $this->companyService->getActiveCompany();
        try {
            $deal = $this->getDealOrFail($id, $company, $dealRepository);
        } catch (\Throwable $exception) {
            return $this->handleDealException($exception, $request);
        }

        if (!$this->isCsrfTokenValid('deal_cancel'.$deal->getId(), (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $user = $this->getUserOrFail();

        try {
            $dealManager->cancelDeal($deal, $user, $company);
        } catch (\Throwable $exception) {
            return $this->handleDealException($exception, $request);
        }

        $this->addFlash('success', 'Сделка отменена.');
        $activeTab = $this->resolveActiveTab($request);

        return $this->redirectToRoute('deal_show', ['id' => $deal->getId(), 'tab' => $activeTab]);
    }

    #[Route('/{id}/items/add', name: 'deal_item_add', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function addItem(
        Request $request,
        string $id,
        DealRepository $dealRepository,
        DealManager $dealManager,
    ): Response {
        $company = $this->companyService->getActiveCompany();
        try {
            $deal = $this->getDealOrFail($id, $company, $dealRepository);
        } catch (\Throwable $exception) {
            return $this->handleDealException($exception, $request);
        }

        $form = $this->createItemForm($company);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $activeTab = $this->resolveActiveTab($request);
            if ($activeTab !== 'items') {
                $activeTab = 'items';
            }
            return $this->renderShowPage(
                $deal,
                $form,
                $this->createChargeForm($company, $this->createChargeFormData($deal)),
                $this->createAdjustmentForm($company, $this->createAdjustmentFormData($deal)),
                $activeTab,
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $user = $this->getUserOrFail();
        $data = $form->getData();
        $itemRequest = $this->buildItemRequest($deal, $data);

        try {
            $dealManager->addItem($itemRequest, $deal, $user, $company);
        } catch (\Throwable $exception) {
            return $this->handleDealException($exception, $request);
        }

        $this->addFlash('success', 'Позиция добавлена.');
        $activeTab = $this->resolveActiveTab($request);

        return $this->redirectToRoute('deal_show', ['id' => $deal->getId(), 'tab' => $activeTab]);
    }

    #[Route('/{id}/charges/add', name: 'deal_charge_add', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function addCharge(
        Request $request,
        string $id,
        DealRepository $dealRepository,
        DealManager $dealManager,
    ): Response {
        $company = $this->companyService->getActiveCompany();
        try {
            $deal = $this->getDealOrFail($id, $company, $dealRepository);
        } catch (\Throwable $exception) {
            return $this->handleDealException($exception, $request);
        }

        $form = $this->createChargeForm($company);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $activeTab = $this->resolveActiveTab($request);
            if ($activeTab !== 'charges') {
                $activeTab = 'charges';
            }
            return $this->renderShowPage(
                $deal,
                $this->createItemForm($company),
                $form,
                $this->createAdjustmentForm($company, $this->createAdjustmentFormData($deal)),
                $activeTab,
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $user = $this->getUserOrFail();
        $data = $form->getData();
        $chargeType = $data->chargeType;
        if (!$chargeType) {
            throw new ValidationFailed('Charge type is required.');
        }
        $chargeTypeId = $chargeType->getId();

        $chargeRequest = new AddDealChargeRequest(
            $data->recognizedAt,
            $data->amount,
            (string) $chargeTypeId,
            $data->comment,
        );

        try {
            $dealManager->addCharge($chargeRequest, $deal, $user, $company);
        } catch (\Throwable $exception) {
            return $this->handleDealException($exception, $request);
        }

        $this->addFlash('success', 'Начисление добавлено.');
        $activeTab = $this->resolveActiveTab($request);

        return $this->redirectToRoute('deal_show', ['id' => $deal->getId(), 'tab' => $activeTab]);
    }

    #[Route('/{id}/adjustments/add', name: 'deal_adjustment_add', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function addAdjustment(
        Request $request,
        string $id,
        DealRepository $dealRepository,
        DealManager $dealManager,
    ): Response {
        $company = $this->companyService->getActiveCompany();
        try {
            $deal = $this->getDealOrFail($id, $company, $dealRepository);
        } catch (\Throwable $exception) {
            return $this->handleDealException($exception, $request);
        }

        $form = $this->createAdjustmentForm($company);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $activeTab = $this->resolveActiveTab($request);
            if ($activeTab !== 'adjustments') {
                $activeTab = 'adjustments';
            }
            return $this->renderShowPage(
                $deal,
                $this->createItemForm($company),
                $this->createChargeForm($company, $this->createChargeFormData($deal)),
                $form,
                $activeTab,
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $user = $this->getUserOrFail();
        $data = $form->getData();
        $adjustmentRequest = new AddDealAdjustmentRequest(
            $data->recognizedAt,
            $data->amount,
            $data->type,
            $data->comment,
        );

        try {
            $dealManager->addAdjustment($adjustmentRequest, $deal, $user, $company);
        } catch (\Throwable $exception) {
            return $this->handleDealException($exception, $request);
        }

        $this->addFlash('success', 'Корректировка добавлена.');
        $activeTab = $this->resolveActiveTab($request);

        return $this->redirectToRoute('deal_show', ['id' => $deal->getId(), 'tab' => $activeTab]);
    }

    #[Route('/{id}/items/{itemId}/remove', name: 'deal_item_remove', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function removeItem(
        Request $request,
        string $id,
        string $itemId,
        DealRepository $dealRepository,
        DealManager $dealManager,
    ): Response {
        $company = $this->companyService->getActiveCompany();
        try {
            $deal = $this->getDealOrFail($id, $company, $dealRepository);
        } catch (\Throwable $exception) {
            return $this->handleDealException($exception, $request);
        }

        if (!$this->isCsrfTokenValid('deal_item_remove'.$itemId, (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $user = $this->getUserOrFail();

        try {
            $dealManager->removeItem(new RemoveDealItemRequest($itemId), $deal, $user, $company);
        } catch (\Throwable $exception) {
            return $this->handleDealException($exception, $request);
        }

        $this->addFlash('success', 'Позиция удалена.');
        $activeTab = $this->resolveActiveTab($request);

        return $this->redirectToRoute('deal_show', ['id' => $deal->getId(), 'tab' => $activeTab]);
    }

    #[Route('/{id}/charges/{chargeId}/remove', name: 'deal_charge_remove', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function removeCharge(
        Request $request,
        string $id,
        string $chargeId,
        DealRepository $dealRepository,
        DealManager $dealManager,
    ): Response {
        $company = $this->companyService->getActiveCompany();
        try {
            $deal = $this->getDealOrFail($id, $company, $dealRepository);
        } catch (\Throwable $exception) {
            return $this->handleDealException($exception, $request);
        }

        if (!$this->isCsrfTokenValid('deal_charge_remove'.$chargeId, (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $user = $this->getUserOrFail();

        try {
            $dealManager->removeCharge(new RemoveDealChargeRequest($chargeId), $deal, $user, $company);
        } catch (\Throwable $exception) {
            return $this->handleDealException($exception, $request);
        }

        $this->addFlash('success', 'Начисление удалено.');
        $activeTab = $this->resolveActiveTab($request);

        return $this->redirectToRoute('deal_show', ['id' => $deal->getId(), 'tab' => $activeTab]);
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if (!$value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable $exception) {
            throw new BadRequestHttpException('Invalid date value.', $exception);
        }
    }

    private function parseStatus(?string $value): ?DealStatus
    {
        if (!$value) {
            return null;
        }

        $status = DealStatus::tryFrom($value);
        if (!$status) {
            throw new BadRequestHttpException('Invalid deal status.');
        }

        return $status;
    }

    private function parseChannel(?string $value): ?DealChannel
    {
        if (!$value) {
            return null;
        }

        $channel = DealChannel::tryFrom($value);
        if (!$channel) {
            throw new BadRequestHttpException('Invalid deal channel.');
        }

        return $channel;
    }

    private function getDealOrFail(string $id, Company $company, DealRepository $dealRepository): Deal
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException $exception) {
            throw new ValidationFailed('Invalid deal id.', previous: $exception);
        }

        $deal = $dealRepository->findOneByIdForCompany($uuid, $company);

        if (!$deal) {
            throw new DealNotFound('Deal not found.');
        }

        return $deal;
    }

    private function getUserOrFail(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        return $user;
    }

    private function handleDealException(\Throwable $exception, Request $request): Response
    {
        if ($exception instanceof DealAccessDenied) {
            return $this->buildDealErrorResponse($exception->getMessage(), Response::HTTP_FORBIDDEN, $request);
        }

        if ($exception instanceof DealNotFound) {
            return $this->buildDealErrorResponse($exception->getMessage(), Response::HTTP_NOT_FOUND, $request);
        }

        if ($exception instanceof InvalidDealState) {
            return $this->buildDealErrorResponse($exception->getMessage(), Response::HTTP_CONFLICT, $request);
        }

        if ($exception instanceof ValidationFailed) {
            return $this->buildDealErrorResponse($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $request, $exception->errors);
        }

        throw $exception;
    }

    /**
     * @param array<string, mixed> $errors
     */
    private function buildDealErrorResponse(
        string $message,
        int $status,
        Request $request,
        array $errors = [],
    ): Response {
        if ($this->isApiRequest($request)) {
            $payload = ['message' => $message];
            if ($errors !== []) {
                $payload['errors'] = $errors;
            }

            return new JsonResponse($payload, $status);
        }

        $this->addFlash('danger', $message);

        return $this->render('deals/error.html.twig', [
            'message' => $message,
            'status' => $status,
        ], new Response(status: $status));
    }

    private function isApiRequest(Request $request): bool
    {
        if ($request->isXmlHttpRequest()) {
            return true;
        }

        $format = $request->getRequestFormat();
        if ($format === 'json') {
            return true;
        }

        $acceptHeader = (string) $request->headers->get('Accept');
        return str_contains($acceptHeader, 'application/json');
    }

    private function createItemForm(Company $company, ?DealItemFormData $data = null): FormInterface
    {
        return $this->createForm(DealItemType::class, $data ?? new DealItemFormData(), ['company' => $company]);
    }

    private function createChargeForm(Company $company, ?DealChargeFormData $data = null): FormInterface
    {
        return $this->createForm(DealChargeType::class, $data ?? new DealChargeFormData(), ['company' => $company]);
    }

    private function createAdjustmentForm(Company $company, ?DealAdjustmentFormData $data = null): FormInterface
    {
        return $this->createForm(DealAdjustmentType::class, $data ?? new DealAdjustmentFormData(), ['company' => $company]);
    }

    private function createChargeFormData(Deal $deal): DealChargeFormData
    {
        $chargeData = new DealChargeFormData();
        $chargeData->recognizedAt = $deal->getRecognizedAt();

        return $chargeData;
    }

    private function createAdjustmentFormData(Deal $deal): DealAdjustmentFormData
    {
        $adjustmentData = new DealAdjustmentFormData();
        $adjustmentData->recognizedAt = $deal->getRecognizedAt();

        return $adjustmentData;
    }

    private function buildItemRequest(Deal $deal, DealItemFormData $data): AddDealItemRequest
    {
        $name = trim((string) $data->name);
        if ($name === '') {
            throw new ValidationFailed('Название позиции обязательно.');
        }

        $kind = $data->kind;
        if (!$kind) {
            throw new ValidationFailed('Тип позиции обязателен.');
        }
        $unit = $data->unit ? trim($data->unit) : null;

        $qty = (string) $data->qty;
        $price = (string) $data->price;
        $amount = $this->multiplyAmount($qty, $price);

        return new AddDealItemRequest(
            name: $name,
            kind: $kind,
            qty: $qty,
            price: $price,
            amount: $amount,
            lineIndex: $this->nextLineIndex($deal),
            unit: $unit,
        );
    }

    private function nextLineIndex(Deal $deal): int
    {
        $max = 0;

        foreach ($deal->getItems() as $item) {
            $max = max($max, $item->getLineIndex());
        }

        return $max + 1;
    }

    private function multiplyAmount(string $qty, string $price): string
    {
        return bcmul($qty, $price, self::AMOUNT_SCALE);
    }

    private function renderShowPage(
        Deal $deal,
        FormInterface $itemForm,
        FormInterface $chargeForm,
        FormInterface $adjustmentForm,
        string $activeTab,
        int $status = Response::HTTP_OK,
    ): Response {
        return $this->render('deals/show.html.twig', [
            'deal' => $deal,
            'itemForm' => $itemForm->createView(),
            'chargeForm' => $chargeForm->createView(),
            'adjustmentForm' => $adjustmentForm->createView(),
            'activeTab' => $activeTab,
        ], new Response(status: $status));
    }

    private function resolveActiveTab(Request $request): string
    {
        $tab = $request->request->get('_tab');
        if (!is_string($tab) || $tab === '') {
            $tab = $request->query->get('tab');
        }
        if (!is_string($tab) || $tab === '') {
            $tab = 'items';
        }

        $allowedTabs = ['items', 'charges', 'adjustments'];

        return in_array($tab, $allowedTabs, true) ? $tab : 'items';
    }
}
