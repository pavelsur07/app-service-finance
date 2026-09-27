<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Application\Action\UpdateCostPLMappingAction;
use App\Marketplace\Entity\MarketplaceCostPLMapping;
use App\Marketplace\Exception\CostCategoryNotFoundException;
use App\Marketplace\Exception\CostMappingPLCategoryNotFoundException;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Сохранение маппинга одной строки страницы /marketplace/cost-pl-mapping.
 *
 * Тело: {"plCategoryId": string|null, "includeInPl": bool, "sortOrder": int},
 * CSRF — заголовок X-CSRF-Token с id CSRF_TOKEN_ID.
 */
#[Route(
    '/marketplace/cost-pl-mapping/{costCategoryId}',
    name: 'marketplace_cost_pl_mapping_update',
    requirements: ['costCategoryId' => Requirement::UUID],
    methods: ['POST'],
)]
#[IsGranted('ROLE_USER')]
final class CostPLMappingUpdateController extends AbstractController
{
    public const CSRF_TOKEN_ID = 'marketplace_cost_pl_mapping_update';

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly UpdateCostPLMappingAction $updateMapping,
    ) {
    }

    #[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
    public function __invoke(string $costCategoryId, Request $request): JsonResponse
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->error('csrf_invalid', 'Недействительный CSRF-токен. Обновите страницу.', Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->error('payload_invalid', 'Некорректный запрос.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $plCategoryId = $data['plCategoryId'] ?? null;
        if ('' === $plCategoryId) {
            $plCategoryId = null;
        }
        $includeInPl = $data['includeInPl'] ?? null;
        $sortOrder = $data['sortOrder'] ?? null;

        if ((null !== $plCategoryId && !is_string($plCategoryId)) || !is_bool($includeInPl)) {
            return $this->error('payload_invalid', 'Некорректный запрос.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!is_int($sortOrder) || $sortOrder < 0 || $sortOrder > MarketplaceCostPLMapping::SORT_ORDER_MAX) {
            return $this->error('sort_order_invalid', sprintf('Порядок — целое число от 0 до %d.', MarketplaceCostPLMapping::SORT_ORDER_MAX), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $mapping = ($this->updateMapping)($companyId, $costCategoryId, $plCategoryId, $includeInPl, $sortOrder);
        } catch (CostCategoryNotFoundException) {
            return $this->error('cost_category_not_found', 'Категория затрат не найдена.', Response::HTTP_NOT_FOUND);
        } catch (CostMappingPLCategoryNotFoundException $e) {
            return $this->error('pl_category_invalid', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'costCategoryId' => $costCategoryId,
            'plCategoryId' => $mapping->getPlCategoryId(),
            'includeInPl' => $mapping->isIncludeInPl(),
            'sortOrder' => $mapping->getSortOrder(),
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return $this->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
