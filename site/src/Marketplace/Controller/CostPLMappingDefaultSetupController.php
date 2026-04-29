<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Marketplace\Application\Action\ApplyDefaultCostMappingAction;
use App\Marketplace\Application\Action\PreviewDefaultCostMappingAction;
use App\Marketplace\Application\Command\ApplyDefaultCostMappingCommand;
use App\Marketplace\Application\Command\PreviewDefaultCostMappingCommand;
use App\Marketplace\Application\DTO\DefaultCostMappingApplyResult;
use App\Marketplace\Application\DTO\DefaultCostMappingPreviewItem;
use App\Marketplace\Application\DTO\DefaultCostMappingPreviewResult;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace/cost-pl-mapping/default')]
#[IsGranted('ROLE_USER')]
final class CostPLMappingDefaultSetupController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly PreviewDefaultCostMappingAction $previewAction,
        private readonly ApplyDefaultCostMappingAction $applyAction,
    ) {
    }

    #[Route('/preview', name: 'marketplace_cost_pl_mapping_default_preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('marketplace_default_cost_mapping', (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'message' => 'Некорректный CSRF token.'], JsonResponse::HTTP_FORBIDDEN);
        }

        try {
            $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
            $marketplace = (string) $request->request->get('marketplace', '');
            $result = ($this->previewAction)(new PreviewDefaultCostMappingCommand($companyId, $marketplace));

            return $this->json($this->previewResultToArray($result));
        } catch (\DomainException $e) {
            return $this->json(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    #[Route('/apply', name: 'marketplace_cost_pl_mapping_default_apply', methods: ['POST'])]
    public function apply(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('marketplace_default_cost_mapping', (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'message' => 'Некорректный CSRF token.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        if ($user === null || !method_exists($user, 'getId')) {
            throw $this->createAccessDeniedException('Пользователь не найден.');
        }

        try {
            $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
            $marketplace = (string) $request->request->get('marketplace', '');
            $result = ($this->applyAction)(new ApplyDefaultCostMappingCommand($companyId, $marketplace, (string) $user->getId()));

            return $this->json($this->applyResultToArray($result));
        } catch (\DomainException $e) {
            return $this->json(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    private function previewResultToArray(DefaultCostMappingPreviewResult $result): array
    {
        return [
            'ok' => true,
            'marketplace' => $result->getMarketplace()->value,
            'summary' => $result->getSummary(),
            'hasBlockingIssues' => $result->hasBlockingIssues(),
            'items' => array_map(fn (DefaultCostMappingPreviewItem $item): array => $this->previewItemToArray($item), $result->getItems()),
        ];
    }

    private function previewItemToArray(DefaultCostMappingPreviewItem $item): array
    {
        return [
            'status' => $item->getStatus()->value,
            'costCode' => $item->getCostCode(),
            'costCategoryId' => $item->getCostCategoryId(),
            'costCategoryName' => $item->getCostCategoryName(),
            'plCode' => $item->getPlCode(),
            'plCategoryId' => $item->getPlCategoryId(),
            'plCategoryName' => $item->getPlCategoryName(),
            'existingMappingId' => $item->getExistingMappingId(),
            'existingPlCategoryId' => $item->getExistingPlCategoryId(),
            'existingPlCategoryName' => $item->getExistingPlCategoryName(),
            'includeInPl' => $item->isIncludeInPl(),
            'isNegative' => $item->isNegative(),
            'confidence' => $item->getConfidence()->value,
            'note' => $item->getNote(),
            'message' => $item->getMessage(),
        ];
    }

    private function applyResultToArray(DefaultCostMappingApplyResult $result): array
    {
        return [
            'ok' => true,
            'marketplace' => $result->getMarketplace()->value,
            'summary' => $result->getSummary(),
            'createdCostCodes' => $result->getCreatedCostCodes(),
            'updatedCostCodes' => $result->getUpdatedCostCodes(),
            'skippedCostCodes' => $result->getSkippedCostCodes(),
        ];
    }
}
