<?php

namespace App\Marketplace\Controller\Api;

use App\Marketplace\DTO\MapListingToProductCommand;
use App\Marketplace\Application\MapListingToProductAction;
use App\Shared\Service\ActiveCompanyService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API для маппинга листингов на продукты
 *
 * ВАЖНО: Единственное место где используется ActiveCompanyService!
 */
#[Route('/api/marketplace/listings')]
#[IsGranted('ROLE_USER')]
class ListingMappingController extends AbstractController
{
    public function __construct(
        private readonly MapListingToProductAction $mapListingAction,
        private readonly ActiveCompanyService $activeCompanyService,  // ← ТОЛЬКО в Controller!
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Связать листинг с продуктом
     *
     * POST /api/marketplace/listings/{listingId}/map
     * Body: {"productId": "uuid"}
     */
    #[Route('/{listingId}/map', name: 'api_marketplace_listing_map', methods: ['POST'])]
    public function mapToProduct(string $listingId, Request $request): JsonResponse
    {
        // ✅ ПРАВИЛЬНО: Получаем компанию через ActiveCompanyService в Controller
        $company = $this->activeCompanyService->getActiveCompany();
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);

        if (!isset($data['productId'])) {
            return new JsonResponse([
                'error' => 'productId is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        $productId = $data['productId'];

        // ✅ ПРАВИЛЬНО: Создаем Command со scalar companyId
        $command = new MapListingToProductCommand(
            companyId: (string) $company->getId(),      // ← SCALAR string!
            actorUserId: (string) $user->getId(),       // ← SCALAR string!
            listingId: $listingId,
            productId: $productId
        );

        try {
            // ✅ ПРАВИЛЬНО: Передаем Command в Application
            ($this->mapListingAction)($command);

            return new JsonResponse([
                'success' => true,
                'message' => 'Листинг успешно привязан к продукту'
            ]);

        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);

        } catch (\LogicException $e) {
            return new JsonResponse([
                'error' => $e->getMessage()
            ], Response::HTTP_CONFLICT);

        } catch (\Exception $e) {
            $this->logger->error('Failed to map listing', [
                'company_id' => $company->getId(),
                'listing_id' => $listingId,
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'error' => 'Не удалось связать листинг с продуктом'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
