<?php

namespace App\Marketplace\Controller;

use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Marketplace\Service\Integration\WildberriesAdapter;
use App\Marketplace\Service\MarketplaceSyncService;
use App\Shared\Service\CompanyContextService;
use Doctrine\ORM\EntityManagerInterface;
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
        WildberriesAdapter $wbAdapter,
        MarketplaceSyncService $syncService
    ): Response {
        $company = $this->companyContext->getCompany();

        $connection = $this->connectionRepository->find($id);

        if (!$connection || $connection->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException('Подключение не найдено');
        }

        $connection->markSyncStarted();
        $this->em->flush();

        try {
            // Синхронизация за последние 7 дней
            $fromDate = new \DateTimeImmutable('-7 days');
            $toDate = new \DateTimeImmutable();

            if ($connection->getMarketplace() === MarketplaceType::WILDBERRIES) {
                $salesCount = $syncService->syncSales($company, $wbAdapter, $fromDate, $toDate);
                $costsCount = $syncService->syncCosts($company, $wbAdapter, $fromDate, $toDate);
                $returnsCount = $syncService->syncReturns($company, $wbAdapter, $fromDate, $toDate);

                $connection->markSyncSuccess();
                $this->em->flush();

                $this->addFlash('success', sprintf(
                    'Синхронизация завершена: %d продаж, %d затрат, %d возвратов',
                    $salesCount,
                    $costsCount,
                    $returnsCount
                ));
            }
        } catch (\Exception $e) {
            $connection->markSyncFailed($e->getMessage());
            $this->em->flush();

            $this->addFlash('error', 'Ошибка синхронизации: ' . $e->getMessage());
        }

        return $this->redirectToRoute('marketplace_index');
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
