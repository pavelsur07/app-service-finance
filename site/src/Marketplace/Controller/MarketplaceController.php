<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Marketplace\Application\ProcessOzonRealizationAction;
use App\Marketplace\Application\ReprocessMarketplacePeriodAction;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\ProcessMarketplaceRawDocumentAction;
use App\Marketplace\Application\Processor\MarketplaceRawProcessorRegistry;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\OzonRealizationStatusQuery;
use App\Marketplace\Infrastructure\Query\RawDocumentsListQuery;
use App\Marketplace\Message\SyncOzonRealizationMessage;
use App\Marketplace\Message\TriggerInitialSyncMessage;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Marketplace\Service\Integration\MarketplaceAdapterRegistry;
use App\Repository\ProjectDirectionRepository;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace')]
#[IsGranted('ROLE_USER')]
class MarketplaceController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService              $companyService,
        private readonly MarketplaceConnectionRepository  $connectionRepository,
        private readonly MarketplaceRawDocumentRepository $rawDocumentRepository,
        private readonly MarketplaceAdapterRegistry       $adapterRegistry,
        private readonly MarketplaceRawProcessorRegistry  $processorRegistry,
        private readonly OzonRealizationStatusQuery       $realizationStatusQuery,
        private readonly RawDocumentsListQuery            $rawDocumentsListQuery,
        private readonly ProjectDirectionRepository       $projectDirectionRepository,
        private readonly EntityManagerInterface           $em,
        private readonly MessageBusInterface              $messageBus,
        private readonly ReprocessMarketplacePeriodAction $reprocessAction,
    ) {
    }

    #[Route('', name: 'marketplace_index')]
    public function index(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        $page    = max(1, $request->query->getInt('page', 1));

        $connections = $this->connectionRepository->findByCompany($company);
        $qb          = $this->rawDocumentsListQuery->buildQueryBuilder($company);

        $adapter = new QueryAdapter(
            $qb,
            true,
            null,
            static function (QueryBuilder $qb): void {
                $qb->select('COUNT(d.id)')->resetDQLPart('orderBy');
            }
        );

        $rawDocumentsPager = Pagerfanta::createForCurrentPageWithMaxPerPage(
            $adapter,
            $page,
            50,
        );

        return $this->render('marketplace/index.html.twig', [
            'connections'           => $connections,
            'rawDocumentsPager'     => $rawDocumentsPager,
            'availableMarketplaces' => MarketplaceType::cases(),
        ]);
    }

    #[Route('/connection/create', name: 'marketplace_connection_create', methods: ['POST'])]
    public function createConnection(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();

        $marketplace = MarketplaceType::from($request->request->get('marketplace'));
        $apiKey      = $request->request->get('api_key');
        $clientId    = $request->request->get('client_id');

        $existing = $this->connectionRepository->findByMarketplace($company, $marketplace);

        if ($existing) {
            $this->addFlash('error', 'Подключение к ' . $marketplace->getDisplayName() . ' уже существует');

            return $this->redirectToRoute('marketplace_index');
        }

        $connection = new MarketplaceConnection(
            Uuid::uuid4()->toString(),
            $company,
            $marketplace
        );
        $connection->setApiKey($apiKey);

        if ($clientId) {
            $connection->setClientId($clientId);
        }

        $this->em->persist($connection);
        $this->em->flush();

        $this->messageBus->dispatch(new TriggerInitialSyncMessage(
            companyId:    (string) $company->getId(),
            connectionId: $connection->getId(),
            marketplace:  $marketplace->value,
        ));

        $this->addFlash('success', 'Подключение к ' . $marketplace->getDisplayName() . ' создано');

        return $this->redirectToRoute('marketplace_index');
    }

    #[Route('/connection/{id}/status', name: 'marketplace_connection_status', methods: ['GET'])]
    public function connectionStatus(string $id): JsonResponse
    {
        $company = $this->companyService->getActiveCompany();

        $connection = $this->connectionRepository->find($id);

        if (!$connection || (string) $connection->getCompany()->getId() !== (string) $company->getId()) {
            return $this->json(['error' => 'Not found'], 404);
        }

        $lastSyncAt = $connection->getLastSyncAt();
        $status     = match (true) {
            $connection->getLastSyncError() !== null => 'error',
            $lastSyncAt !== null                     => 'synced',
            default                                  => 'pending',
        };

        return $this->json([
            'status'        => $status,
            'lastSyncAt'    => $lastSyncAt?->format('d.m.Y H:i'),
            'lastSyncError' => $connection->getLastSyncError()
                ? mb_substr($connection->getLastSyncError(), 0, 100)
                : null,
        ]);
    }

    #[Route('/connection/{id}/test', name: 'marketplace_connection_test')]
    public function testConnection(string $id): Response
    {
        $company = $this->companyService->getActiveCompany();

        $connection = $this->connectionRepository->find($id);

        if (!$connection || $connection->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException('Подключение не найдено');
        }

        $success = false;
        $error   = null;

        try {
            $adapter = $this->adapterRegistry->get($connection->getMarketplace());
            $success = $adapter->authenticate($company);
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }

        if ($success) {
            $this->addFlash('success', 'Подключение работает корректно');
        } else {
            $this->addFlash('error', 'Ошибка подключения: ' . ($error ?? 'Неверный API ключ'));
        }

        return $this->redirectToRoute('marketplace_index');
    }

    #[Route('/connection/{id}/sync', name: 'marketplace_connection_sync')]
    public function syncConnection(string $id): Response
    {
        $company = $this->companyService->getActiveCompany();

        $connection = $this->connectionRepository->find($id);

        if (!$connection || $connection->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException('Подключение не найдено');
        }

        $connection->markSyncStarted();
        $this->em->flush();

        try {
            $fromDate = new \DateTimeImmutable('-7 days');
            $toDate   = new \DateTimeImmutable();

            $adapter  = $this->adapterRegistry->get($connection->getMarketplace());
            $response = $adapter->fetchRawReport($company, $fromDate, $toDate);

            $rawDoc = new \App\Marketplace\Entity\MarketplaceRawDocument(
                Uuid::uuid4()->toString(),
                $company,
                $connection->getMarketplace(),
                'sales_report'
            );
            $rawDoc->setPeriodFrom($fromDate);
            $rawDoc->setPeriodTo($toDate);
            $rawDoc->setApiEndpoint($adapter->getApiEndpointName());
            $rawDoc->setRawData($response);
            $rawDoc->setRecordsCount(count($response));

            $this->em->persist($rawDoc);
            $this->em->flush();

            $connection->markSyncSuccess();
            $this->em->flush();

            $this->addFlash('success', sprintf(
                'Загружено %d записей от %s.',
                count($response),
                $connection->getMarketplace()->getDisplayName()
            ));
        } catch (\Exception $e) {
            $connection->markSyncFailed($e->getMessage());
            $this->em->flush();

            $this->addFlash('error', 'Ошибка загрузки: ' . $e->getMessage());
        }

        return $this->redirectToRoute('marketplace_index');
    }

    #[Route('/connection/{id}/sync-period', name: 'marketplace_connection_sync_period')]
    public function syncConnectionPeriod(string $id, Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();

        $connection = $this->connectionRepository->find($id);

        if (!$connection || $connection->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException('Подключение не найдено');
        }

        $dateFromStr = $request->query->get('date_from');
        $dateToStr   = $request->query->get('date_to');

        if (!$dateFromStr || !$dateToStr) {
            $this->addFlash('error', 'Укажите период синхронизации');

            return $this->redirectToRoute('marketplace_index');
        }

        try {
            $fromDate = new \DateTimeImmutable($dateFromStr);
            $toDate   = new \DateTimeImmutable($dateToStr . ' 23:59:59');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Неверный формат дат');

            return $this->redirectToRoute('marketplace_index');
        }

        $diff = $fromDate->diff($toDate)->days;
        if ($diff > 31) {
            $this->addFlash('error', 'Максимальный период — 31 день');

            return $this->redirectToRoute('marketplace_index');
        }

        $connection->markSyncStarted();
        $this->em->flush();

        try {
            $adapter  = $this->adapterRegistry->get($connection->getMarketplace());
            $response = $adapter->fetchRawReport($company, $fromDate, $toDate);

            $rawDoc = new \App\Marketplace\Entity\MarketplaceRawDocument(
                Uuid::uuid4()->toString(),
                $company,
                $connection->getMarketplace(),
                'sales_report'
            );
            $rawDoc->setPeriodFrom($fromDate);
            $rawDoc->setPeriodTo($toDate);
            $rawDoc->setApiEndpoint($adapter->getApiEndpointName());
            $rawDoc->setRawData($response);
            $rawDoc->setRecordsCount(count($response));

            $this->em->persist($rawDoc);
            $this->em->flush();

            $connection->markSyncSuccess();
            $this->em->flush();

            $this->addFlash('success', sprintf(
                'Загружено %d записей за период %s — %s.',
                count($response),
                $fromDate->format('d.m.Y'),
                $toDate->format('d.m.Y')
            ));
        } catch (\Exception $e) {
            $connection->markSyncFailed($e->getMessage());
            $this->em->flush();

            $this->addFlash('error', 'Ошибка загрузки: ' . $e->getMessage());
        }

        return $this->redirectToRoute('marketplace_index');
    }

    #[Route('/connection/{id}/sync-realization', name: 'marketplace_connection_sync_realization', methods: ['POST'])]
    public function syncRealization(string $id): Response
    {
        $company   = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $connection = $this->connectionRepository->find($id);

        if (!$connection || (string) $connection->getCompany()->getId() !== $companyId) {
            throw $this->createNotFoundException('Подключение не найдено');
        }

        if ($connection->getMarketplace() !== MarketplaceType::OZON) {
            $this->addFlash('warning', 'Загрузка реализации доступна только для Ozon.');

            return $this->redirectToRoute('marketplace_index');
        }

        $connectionId = $connection->getId();
        $now          = new \DateTimeImmutable();
        $monthsToSync = $this->resolveRealizationMonths($companyId, $now);

        if (empty($monthsToSync)) {
            $this->addFlash('info', 'Нет месяцев для загрузки реализации.');

            return $this->redirectToRoute('marketplace_index');
        }

        foreach ($monthsToSync as [$year, $month]) {
            $this->messageBus->dispatch(new SyncOzonRealizationMessage(
                companyId:    $companyId,
                connectionId: $connectionId,
                year:         $year,
                month:        $month,
            ));
        }

        $count = count($monthsToSync);
        $this->addFlash(
            'success',
            sprintf(
                'Загрузка реализации запущена: %d %s поставлено в очередь.',
                $count,
                $count === 1 ? 'месяц' : ($count < 5 ? 'месяца' : 'месяцев'),
            ),
        );

        return $this->redirectToRoute('marketplace_index');
    }

    #[Route('/raw/{id}/view', name: 'marketplace_raw_view')]
    public function viewRaw(string $id): Response
    {
        $company = $this->companyService->getActiveCompany();

        $rawDoc = $this->rawDocumentRepository->find($id);

        if (!$rawDoc || $rawDoc->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException();
        }

        return $this->json($rawDoc->getRawData(), 200, [], ['json_encode_options' => JSON_PRETTY_PRINT]);
    }

    #[Route('/raw/{id}/process-sales', name: 'marketplace_raw_process_sales')]
    public function processSales(
        string $id,
        ProcessMarketplaceRawDocumentAction $action,
    ): Response {
        $company = $this->companyService->getActiveCompany();

        $rawDoc = $this->rawDocumentRepository->find($id);

        if (!$rawDoc || $rawDoc->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException();
        }

        try {
            $cmd   = new ProcessMarketplaceRawDocumentCommand((string) $company->getId(), (string) $rawDoc->getId(), 'sales');
            $count = ($action)($cmd);

            $this->addFlash('success', sprintf('Обработано продаж: %d', $count));
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
            preg_match('/Key \((.*?)\)=\((.*?)\)/', $e->getMessage(), $matches);
            $keys   = $matches[1] ?? 'unknown';
            $values = $matches[2] ?? 'unknown';

            $this->addFlash('error', sprintf(
                'Дубликат товара! Поля: %s | Значения: %s. Очистите БД и попробуйте снова.',
                $keys,
                $values
            ));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Ошибка обработки: ' . $e->getMessage());
        }

        return $this->redirectToRoute('marketplace_index');
    }

    #[Route('/raw/{id}/process-returns', name: 'marketplace_raw_process_returns')]
    public function processReturns(
        string $id,
        ProcessMarketplaceRawDocumentAction $action,
    ): Response {
        $company = $this->companyService->getActiveCompany();

        $rawDoc = $this->rawDocumentRepository->find($id);

        if (!$rawDoc || $rawDoc->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException();
        }

        try {
            $cmd   = new ProcessMarketplaceRawDocumentCommand((string) $company->getId(), (string) $rawDoc->getId(), 'returns');
            $count = ($action)($cmd);

            $this->addFlash('success', sprintf('Обработано возвратов: %d', $count));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Ошибка обработки возвратов: ' . $e->getMessage());
        }

        return $this->redirectToRoute('marketplace_index');
    }

    #[Route('/raw/{id}/process-costs', name: 'marketplace_raw_process_costs')]
    public function processCosts(
        string $id,
        ProcessMarketplaceRawDocumentAction $action,
    ): Response {
        $company = $this->companyService->getActiveCompany();

        $rawDoc = $this->rawDocumentRepository->find($id);

        if (!$rawDoc || $rawDoc->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException();
        }

        try {
            $cmd   = new ProcessMarketplaceRawDocumentCommand((string) $company->getId(), (string) $rawDoc->getId(), 'costs');
            $count = ($action)($cmd);

            $this->addFlash('success', sprintf('Обработано затрат: %d', $count));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Ошибка обработки затрат: ' . $e->getMessage());
        }

        return $this->redirectToRoute('marketplace_index');
    }

    #[Route('/raw/{id}/process-realization', name: 'marketplace_raw_process_realization')]
    public function processRealization(
        string $id,
        ProcessOzonRealizationAction $action,
    ): Response {
        $company = $this->companyService->getActiveCompany();

        $rawDoc = $this->rawDocumentRepository->find($id);

        if (!$rawDoc || (string) $rawDoc->getCompany()->getId() !== (string) $company->getId()) {
            throw $this->createNotFoundException();
        }

        if ($rawDoc->getDocumentType() !== 'realization') {
            $this->addFlash('error', 'Документ не является реализацией.');

            return $this->redirectToRoute('marketplace_index');
        }

        try {
            $result = ($action)((string) $company->getId(), (string) $rawDoc->getId());

            // Различаем первичную обработку и переобработку (повторный запуск)
            if ($result['updated'] > 0) {
                $this->addFlash('success', sprintf(
                    'Реализация переобработана: обновлено строк с возвратами %d, пропущено %d.',
                    $result['updated'],
                    $result['skipped'],
                ));
            } else {
                $this->addFlash('success', sprintf(
                    'Реализация обработана: создано строк %d, пропущено %d.',
                    $result['created'],
                    $result['skipped'],
                ));
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Ошибка обработки реализации: ' . $e->getMessage());
        }

        return $this->redirectToRoute('marketplace_index');
    }

    #[Route('/reprocess', name: 'marketplace_reprocess', methods: ['POST'])]
    public function reprocess(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();

        $marketplace  = $request->request->get('marketplace', '');
        $periodFromStr = $request->request->get('period_from', '');
        $periodToStr   = $request->request->get('period_to', '');
        $type          = $request->request->get('type', 'all'); // all | sales_report | realization

        if (MarketplaceType::tryFrom($marketplace) === null) {
            $this->addFlash('error', 'Неизвестный маркетплейс: ' . $marketplace);
            return $this->redirectToRoute('marketplace_index');
        }

        try {
            $periodFrom = new \DateTimeImmutable($periodFromStr);
            $periodTo   = new \DateTimeImmutable($periodToStr);
        } catch (\Exception) {
            $this->addFlash('error', 'Неверный формат дат');
            return $this->redirectToRoute('marketplace_index');
        }

        try {
            $result = ($this->reprocessAction)(
                companyId:   (string) $company->getId(),
                marketplace: $marketplace,
                periodFrom:  $periodFrom,
                periodTo:    $periodTo,
                type:        $type,
            );

            $this->addFlash('success', sprintf(
                'Переобработка завершена: документов %d, продажи %d, возвраты %d, затраты %d, реализация %d.',
                $result['docs'],
                $result['sales'],
                $result['returns'],
                $result['costs'],
                $result['realization'],
            ));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Ошибка переобработки: ' . $e->getMessage());
        }

        return $this->redirectToRoute('marketplace_index');
    }

    #[Route('/costs', name: 'marketplace_costs_index')]
    public function costsIndex(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();

        $categoryId = $request->query->get('category');
        $mapped     = (string) $request->query->get('mapped', 'all');

        $queryBuilder = $this->em->getRepository(\App\Marketplace\Entity\MarketplaceCost::class)
            ->getByCompanyQueryBuilder($company);

        if ($mapped === 'linked') {
            $queryBuilder->andWhere('c.listing IS NOT NULL');
        } elseif ($mapped === 'general') {
            $queryBuilder->andWhere('c.listing IS NULL');
        } else {
            $mapped = 'all';
        }

        if ($categoryId) {
            $queryBuilder->andWhere('c.category = :category')
                ->setParameter('category', $categoryId);
        }

        $page       = max(1, $request->query->getInt('page', 1));
        $adapter    = new QueryAdapter($queryBuilder);
        $pagerfanta = Pagerfanta::createForCurrentPageWithMaxPerPage(
            $adapter,
            $page,
            50
        );

        $categories = $this->em->getRepository(\App\Marketplace\Entity\MarketplaceCostCategory::class)
            ->findByCompany($company);

        return $this->render('marketplace/costs.html.twig', [
            'pager'              => $pagerfanta,
            'categories'         => $categories,
            'selectedCategoryId' => $categoryId,
            'mapped'             => $mapped,
        ]);
    }

    #[Route('/products', name: 'marketplace_products_index')]
    public function productsIndex(): Response
    {
        $company = $this->companyService->getActiveCompany();

        $listings = $this->em->getRepository(MarketplaceListing::class)
            ->createQueryBuilder('l')
            ->leftJoin('l.product', 'p')
            ->where('l.company = :company')
            ->setParameter('company', $company)
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('marketplace/products.html.twig', [
            'listings' => $listings,
        ]);
    }

    #[Route('/connection/{id}/toggle', name: 'marketplace_connection_toggle')]
    public function toggleConnection(string $id): Response
    {
        $company = $this->companyService->getActiveCompany();

        $connection = $this->connectionRepository->find($id);

        if (!$connection || $connection->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException('Подключение не найдено');
        }

        $connection->setIsActive(!$connection->isActive());
        $this->em->flush();

        $status = $connection->isActive() ? 'активировано' : 'деактивировано';
        $this->addFlash('success', 'Подключение ' . $status);

        return $this->redirectToRoute('marketplace_index');
    }

    #[Route('/connection/{id}/delete', name: 'marketplace_connection_delete', methods: ['POST'])]
    public function deleteConnection(string $id): Response
    {
        $company = $this->companyService->getActiveCompany();

        $connection = $this->connectionRepository->find($id);

        if (!$connection || $connection->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException('Подключение не найдено');
        }

        $this->em->remove($connection);
        $this->em->flush();

        $this->addFlash('success', 'Подключение удалено');

        return $this->redirectToRoute('marketplace_index');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Определяет какие месяцы нужно загрузить для realization:
     *   - нет документов → все закрытые месяцы с января текущего года
     *   - есть документы → только прошлый месяц (обновление)
     *
     * @return array<int, array{0: int, 1: int}>  [[year, month], ...]
     */
    private function resolveRealizationMonths(string $companyId, \DateTimeImmutable $now): array
    {
        $lastMonth      = $now->modify('first day of last month');
        $lastMonthYear  = (int) $lastMonth->format('Y');
        $lastMonthMonth = (int) $lastMonth->format('n');

        $hasAny = $this->realizationStatusQuery->hasAny($companyId);

        if ($hasAny) {
            return [[$lastMonthYear, $lastMonthMonth]];
        }

        $currentYear  = (int) $now->format('Y');
        $loadedMonths = $this->realizationStatusQuery->loadedMonths($companyId);

        $months = [];
        $cursor = new \DateTimeImmutable(sprintf('%d-01-01', $currentYear));

        while (true) {
            $year  = (int) $cursor->format('Y');
            $month = (int) $cursor->format('n');

            if ($year > $lastMonthYear || ($year === $lastMonthYear && $month > $lastMonthMonth)) {
                break;
            }

            $key = sprintf('%d-%02d', $year, $month);

            if (!in_array($key, $loadedMonths, true)) {
                $months[] = [$year, $month];
            }

            $cursor = $cursor->modify('+1 month');
        }

        return $months;
    }

    /**
     * Редактирование настроек подключения — API ключ, проект ОПиУ.
     *
     * GET  /marketplace/connection/{id}/edit  — форма
     * POST /marketplace/connection/{id}/edit  — сохранение
     */
    #[Route('/connection/{id}/edit', name: 'marketplace_connection_edit', methods: ['GET', 'POST'])]
    public function editConnection(string $id, Request $request): Response
    {
        $company    = $this->companyService->getActiveCompany();
        $connection = $this->connectionRepository->find($id);

        if (!$connection || (string) $connection->getCompany()->getId() !== (string) $company->getId()) {
            throw $this->createNotFoundException('Подключение не найдено');
        }

        if ($request->isMethod('POST')) {
            $projectId = trim((string) $request->request->get('project_direction_id', '')) ?: null;

            $connection->setProjectDirectionId($projectId);

            $this->em->flush();

            $this->addFlash('success', 'Настройки сохранены');

            return $this->redirectToRoute('marketplace_index');
        }

        // Список проектов для выбора
        $projectDirections = $this->projectDirectionRepository->findBy(
            ['company' => $company],
            ['sort' => 'ASC', 'name' => 'ASC'],
        );

        return $this->render('marketplace/connection/edit.html.twig', [
            'connection'         => $connection,
            'project_directions' => $projectDirections,
        ]);
    }
}
