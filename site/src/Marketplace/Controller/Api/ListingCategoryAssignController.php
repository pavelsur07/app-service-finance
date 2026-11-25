<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Api;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Application\AssignListingCategoryAction;
use App\Marketplace\Application\DTO\ListingCategoryAssignPayload;
use App\Marketplace\Exception\InvalidListingTagPayloadException;
use App\Marketplace\Exception\ListingCategoryNotFoundException;
use App\Marketplace\Exception\ListingFilterTooBroadException;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/marketplace/listings/categories/assign', name: 'api_marketplace_listing_categories_assign', methods: ['POST'])]
#[IsGranted('ROLE_USER')]
final class ListingCategoryAssignController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly AssignListingCategoryAction $assign,
    ) {
    }

    #[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
    public function __invoke(Request $request): JsonResponse
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();

        try {
            $payload = ListingCategoryAssignPayload::fromArray(json_decode($request->getContent(), true));
            $result = ($this->assign)($companyId, $payload->categoryId, $payload->listingIds, $payload->filter);
        } catch (InvalidListingTagPayloadException $e) {
            return $this->error($e->errorCode, $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ListingFilterTooBroadException $e) {
            return $this->error('filter_too_broad', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ListingCategoryNotFoundException $e) {
            return $this->error('category_not_found', $e->getMessage(), Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'matched' => $result->matched,
            'changed' => $result->changed,
            'categoryName' => $result->categoryName,
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return $this->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
