<?php

namespace App\Marketplace\Controller;

use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Marketplace\Service\Integration\WildberriesAdapter;
use App\Marketplace\Service\MarketplaceSyncService;
use App\Shared\Service\CompanyContextService;
use Doctrine\ORM\EntityManagerInterface;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace')]
#[IsGranted('ROLE_USER')]
class MarketplaceController extends AbstractController
{
    public function __construct(
        private readonly CompanyContextService $companyContext,
        private readonly MarketplaceConnectionRepository $connectionRepository,
        private readonly MarketplaceRawDocumentRepository $rawDocumentRepository,
        private readonly EntityManagerInterface $em
    ) {}

    #[Route('', name: 'marketplace_index')]
    public function index(): Response
    {
        $company = $this->companyContext->getCompany();

        $connections = $this->connectionRepository->findByCompany($company);
        $rawDocuments = $this->rawDocumentRepository->findByCompany($company, 20);

        return $this->render('marketplace/index.html.twig', [
            'connections' => $connections,
            'rawDocuments' => $rawDocuments,
            'availableMarketplaces' => MarketplaceType::cases(),
        ]);
    }

    #[Route('/connection/create', name: 'marketplace_connection_create', methods: ['POST'])]
    public function createConnection(Request $request): Response
    {
        $company = $this->companyContext->getCompany();

        $marketplace = MarketplaceType::from($request->request->get('marketplace'));
        $apiKey = $request->request->get('api_key');

        // Проверка существующего подключения
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

        $this->em->persist($connection);
        $this->em->flush();

        $this->addFlash('success', 'Подключение к ' . $marketplace->getDisplayName() . ' создано');

        return $this->redirectToRoute('marketplace_index');
    }

    #[Route('/connection/{id}/test', name: 'marketplace_connection_test')]
    public function testConnection(
        string $id,
        WildberriesAdapter $wbAdapter
    ): Response {
        $company = $this->companyContext->getCompany();

        $connection = $this->connectionRepository->find($id);

        if (!$connection || $connection->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException('Подключение не найдено');
        }

        $success = false;
        $error = null;

        try {
            if ($connection->getMarketplace() === MarketplaceType::WILDBERRIES) {
                $success = $wbAdapter->authenticate($company);
            }
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
    public function syncConnection(
        string $id,
        WildberriesAdapter $wbAdapter
    ): Response {
        $company = $this->companyContext->getCompany();

        $connection = $this->connectionRepository->find($id);

        if (!$connection || $connection->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException('Подключение не найдено');
        }

        $connection->markSyncStarted();
        $this->em->flush();

        try {
            // ТОЛЬКО загружаем raw данные
            $fromDate = new \DateTimeImmutable('-7 days');
            $toDate = new \DateTimeImmutable();

            if ($connection->getMarketplace() === MarketplaceType::WILDBERRIES) {
                // Получаем ОРИГИНАЛЬНЫЕ данные от WB API
                $response = $wbAdapter->fetchRawSales($company, $fromDate, $toDate);

                // Создаём RawDocument с ОРИГИНАЛЬНЫМ JSON
                $rawDoc = new \App\Marketplace\Entity\MarketplaceRawDocument(
                    \Ramsey\Uuid\Uuid::uuid4()->toString(),
                    $company,
                    MarketplaceType::WILDBERRIES,
                    'sales_report'
                );
                $rawDoc->setPeriodFrom($fromDate);
                $rawDoc->setPeriodTo($toDate);
                $rawDoc->setApiEndpoint('wildberries::reportDetailByPeriod');
                $rawDoc->setRawData($response); // Сохраняем КАК ЕСТЬ от WB
                $rawDoc->setRecordsCount(count($response));

                $this->em->persist($rawDoc);
                $this->em->flush();

                $connection->markSyncSuccess();
                $this->em->flush();

                $this->addFlash('success', sprintf(
                    'Загружено %d записей от WB. Посмотрите JSON и обработайте.',
                    count($response)
                ));
            }
        } catch (\Exception $e) {
            $connection->markSyncFailed($e->getMessage());
            $this->em->flush();

            $this->addFlash('error', 'Ошибка загрузки: ' . $e->getMessage());
        }

        return $this->redirectToRoute('marketplace_index');
    }

    #[Route('/connection/{id}/sync-period', name: 'marketplace_connection_sync_period')]
    public function syncConnectionPeriod(
        string $id,
        Request $request,
        WildberriesAdapter $wbAdapter
    ): Response {
        $company = $this->companyContext->getCompany();

        $connection = $this->connectionRepository->find($id);

        if (!$connection || $connection->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException('Подключение не найдено');
        }

        $dateFromStr = $request->query->get('date_from');
        $dateToStr = $request->query->get('date_to');

        if (!$dateFromStr || !$dateToStr) {
            $this->addFlash('error', 'Укажите период синхронизации');
            return $this->redirectToRoute('marketplace_index');
        }

        try {
            $fromDate = new \DateTimeImmutable($dateFromStr);
            $toDate = new \DateTimeImmutable($dateToStr . ' 23:59:59');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Неверный формат дат');
            return $this->redirectToRoute('marketplace_index');
        }

        // Проверяем что период не больше 31 дня
        $diff = $fromDate->diff($toDate)->days;
        if ($diff > 31) {
            $this->addFlash('error', 'Максимальный период — 31 день');
            return $this->redirectToRoute('marketplace_index');
        }

        $connection->markSyncStarted();
        $this->em->flush();

        try {
            if ($connection->getMarketplace() === MarketplaceType::WILDBERRIES) {
                $response = $wbAdapter->fetchRawSales($company, $fromDate, $toDate);

                $rawDoc = new \App\Marketplace\Entity\MarketplaceRawDocument(
                    \Ramsey\Uuid\Uuid::uuid4()->toString(),
                    $company,
                    MarketplaceType::WILDBERRIES,
                    'sales_report'
                );
                $rawDoc->setPeriodFrom($fromDate);
                $rawDoc->setPeriodTo($toDate);
                $rawDoc->setApiEndpoint('wildberries::reportDetailByPeriod');
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
            }
        } catch (\Exception $e) {
            $connection->markSyncFailed($e->getMessage());
            $this->em->flush();

            $this->addFlash('error', 'Ошибка загрузки: ' . $e->getMessage());
        }

        return $this->redirectToRoute('marketplace_index');
    }

    #[Route('/raw/{id}/view', name: 'marketplace_raw_view')]
    public function viewRaw(string $id): Response
    {
        $company = $this->companyContext->getCompany();

        $rawDoc = $this->rawDocumentRepository->find($id);

        if (!$rawDoc || $rawDoc->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException();
        }

        return $this->json($rawDoc->getRawData(), 200, [], ['json_encode_options' => JSON_PRETTY_PRINT]);
    }

    #[Route('/raw/{id}/process-sales', name: 'marketplace_raw_process_sales')]
    public function processSales(
        string $id,
        MarketplaceSyncService $syncService
    ): Response {
        $company = $this->companyContext->getCompany();

        $rawDoc = $this->rawDocumentRepository->find($id);

        if (!$rawDoc || $rawDoc->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException();
        }

        try {
            $count = $syncService->processSalesFromRaw($company, $rawDoc);

            $this->addFlash('success', sprintf('Обработано продаж: %d', $count));
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
            // Детальная ошибка duplicate
            preg_match('/Key \((.*?)\)=\((.*?)\)/', $e->getMessage(), $matches);
            $keys = $matches[1] ?? 'unknown';
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
        MarketplaceSyncService $syncService
    ): Response {
        $company = $this->companyContext->getCompany();

        $rawDoc = $this->rawDocumentRepository->find($id);

        if (!$rawDoc || $rawDoc->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException();
        }

        try {
            $count = $syncService->processReturnsFromRaw($company, $rawDoc);

            $this->addFlash('success', sprintf('Обработано возвратов: %d', $count));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Ошибка обработки возвратов: ' . $e->getMessage());
        }

        return $this->redirectToRoute('marketplace_index');
    }

    #[Route('/raw/{id}/process-costs', name: 'marketplace_raw_process_costs')]
    public function processCosts(
        string $id,
        MarketplaceSyncService $syncService
    ): Response {
        $company = $this->companyContext->getCompany();

        $rawDoc = $this->rawDocumentRepository->find($id);

        if (!$rawDoc || $rawDoc->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException();
        }

        try {
            $count = $syncService->processCostsFromRaw($company, $rawDoc);

            $this->addFlash('success', sprintf('Обработано затрат: %d', $count));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Ошибка обработки затрат: ' . $e->getMessage());
        }

        return $this->redirectToRoute('marketplace_index');
    }

    #[Route('/sales', name: 'marketplace_sales_index')]
    public function salesIndex(Request $request): Response
    {
        $company = $this->companyContext->getCompany();

        $queryBuilder = $this->em->getRepository(MarketplaceSale::class)
            ->getByCompanyQueryBuilder($company);

        $adapter = new QueryAdapter($queryBuilder);
        $pagerfanta = Pagerfanta::createForCurrentPageWithMaxPerPage(
            $adapter,
            $request->query->get('page', 1),
            50
        );

        return $this->render('marketplace/sales.html.twig', [
            'pager' => $pagerfanta,
        ]);
    }

    #[Route('/returns', name: 'marketplace_returns_index')]
    public function returnsIndex(Request $request): Response
    {
        $company = $this->companyContext->getCompany();

        $queryBuilder = $this->em->getRepository(MarketplaceReturn::class)
            ->getByCompanyQueryBuilder($company);

        $adapter = new QueryAdapter($queryBuilder);
        $pagerfanta = Pagerfanta::createForCurrentPageWithMaxPerPage(
            $adapter,
            $request->query->get('page', 1),
            50
        );

        return $this->render('marketplace/returns.html.twig', [
            'pager' => $pagerfanta,
        ]);
    }

    #[Route('/costs', name: 'marketplace_costs_index')]
    public function costsIndex(Request $request): Response
    {
        $company = $this->companyContext->getCompany();

        $categoryId = $request->query->get('category');

        $queryBuilder = $this->em->getRepository(\App\Marketplace\Entity\MarketplaceCost::class)
            ->getByCompanyQueryBuilder($company);

        // Фильтр по категории
        if ($categoryId) {
            $queryBuilder->andWhere('c.category = :category')
                ->setParameter('category', $categoryId);
        }

        $adapter = new QueryAdapter($queryBuilder);
        $pagerfanta = Pagerfanta::createForCurrentPageWithMaxPerPage(
            $adapter,
            $request->query->get('page', 1),
            50
        );

        // Получаем все категории для фильтра
        $categories = $this->em->getRepository(\App\Marketplace\Entity\MarketplaceCostCategory::class)
            ->findByCompany($company);

        return $this->render('marketplace/costs.html.twig', [
            'pager' => $pagerfanta,
            'categories' => $categories,
            'selectedCategoryId' => $categoryId,
        ]);
    }

    #[Route('/products', name: 'marketplace_products_index')]
    public function productsIndex(): Response
    {
        $company = $this->companyContext->getCompany();

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
        $company = $this->companyContext->getCompany();

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
        $company = $this->companyContext->getCompany();

        $connection = $this->connectionRepository->find($id);

        if (!$connection || $connection->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException('Подключение не найдено');
        }

        $this->em->remove($connection);
        $this->em->flush();

        $this->addFlash('success', 'Подключение удалено');

        return $this->redirectToRoute('marketplace_index');
    }
}
