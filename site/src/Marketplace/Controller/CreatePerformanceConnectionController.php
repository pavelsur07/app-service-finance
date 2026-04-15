<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Marketplace\Application\Service\OzonPerformanceConnectionValidator;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\OzonPerformanceValidationException;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Создание подключения Ozon Performance API.
 *
 * В отличие от Seller-подключения:
 *  - credentials (client_id / client_secret) валидируются синхронно против
 *    живого API до сохранения — если OAuth не проходит, подключение не создаётся;
 *  - TriggerInitialSyncMessage НЕ диспатчится: Performance API не поставляет
 *    продажи/возвраты/затраты, бэкфилл здесь не применим.
 */
#[Route('/marketplace')]
#[IsGranted('ROLE_USER')]
final class CreatePerformanceConnectionController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly MarketplaceConnectionRepository $connectionRepository,
        private readonly OzonPerformanceConnectionValidator $validator,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route(
        '/connection/performance/create',
        name: 'marketplace_connection_performance_create',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();

        $clientId     = trim((string) $request->request->get('client_id', ''));
        $clientSecret = trim((string) $request->request->get('client_secret', ''));

        if ('' === $clientId || '' === $clientSecret) {
            $this->addFlash('error', 'Укажите client_id и client_secret Ozon Performance API.');

            return $this->redirectToRoute('marketplace_index');
        }

        $existing = $this->connectionRepository->findByCompanyMarketplaceAndType(
            $company,
            MarketplaceType::OZON,
            MarketplaceConnectionType::PERFORMANCE,
        );

        if (null !== $existing) {
            $this->addFlash('error', 'Подключение Ozon Performance API уже существует.');

            return $this->redirectToRoute('marketplace_index');
        }

        try {
            $this->validator->validate($clientId, $clientSecret);
        } catch (OzonPerformanceValidationException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('marketplace_index');
        }

        $connection = new MarketplaceConnection(
            Uuid::uuid4()->toString(),
            $company,
            MarketplaceType::OZON,
            MarketplaceConnectionType::PERFORMANCE,
        );
        $connection->setApiKey($clientSecret);
        $connection->setClientId($clientId);

        $this->em->persist($connection);
        $this->em->flush();

        $this->addFlash('success', 'Подключение Ozon Performance API создано.');

        return $this->redirectToRoute('marketplace_index');
    }
}
