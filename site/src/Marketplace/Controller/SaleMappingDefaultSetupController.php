<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Application\Action\ApplyDefaultSaleMappingAction;
use App\Marketplace\Application\Action\PreviewDefaultSaleMappingAction;
use App\Marketplace\Application\Command\ApplyDefaultSaleMappingCommand;
use App\Marketplace\Application\Command\PreviewDefaultSaleMappingCommand;
use App\Marketplace\Application\DTO\DefaultSaleMappingApplyResult;
use App\Marketplace\Application\DTO\DefaultSaleMappingPreviewItem;
use App\Marketplace\Application\DTO\DefaultSaleMappingPreviewResult;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace/pl-mappings/default')]
#[IsGranted('ROLE_USER')]
final class SaleMappingDefaultSetupController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly PreviewDefaultSaleMappingAction $previewAction,
        private readonly ApplyDefaultSaleMappingAction $applyAction,
    ) {
    }

    #[Route('/preview', name: 'marketplace_pl_mappings_default_preview', methods: ['POST'])]
    #[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
    public function preview(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('marketplace_default_sale_mapping', $request->request->getString('_token'))) {
            return $this->json(['ok' => false, 'message' => 'Некорректный CSRF token.'], JsonResponse::HTTP_FORBIDDEN);
        }

        try {
            $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
            $result = ($this->previewAction)(new PreviewDefaultSaleMappingCommand(
                $companyId,
                $request->request->getString('marketplace'),
            ));

            return $this->json($this->previewResultToArray($result));
        } catch (\DomainException $e) {
            return $this->json(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    #[Route('/apply', name: 'marketplace_pl_mappings_default_apply', methods: ['POST'])]
    #[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
    public function apply(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('marketplace_default_sale_mapping', $request->request->getString('_token'))) {
            return $this->json(['ok' => false, 'message' => 'Некорректный CSRF token.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        if (null === $user || !method_exists($user, 'getId')) {
            return $this->json(['ok' => false, 'message' => 'Пользователь не найден.'], JsonResponse::HTTP_FORBIDDEN);
        }

        try {
            $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
            $result = ($this->applyAction)(new ApplyDefaultSaleMappingCommand(
                $companyId,
                $request->request->getString('marketplace'),
                (string) $user->getId(),
            ));

            return $this->json($this->applyResultToArray($result));
        } catch (\DomainException $e) {
            return $this->json(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    /** @return array<string, mixed> */
    private function previewResultToArray(DefaultSaleMappingPreviewResult $result): array
    {
        return [
            'ok' => true,
            'marketplace' => $result->getMarketplace()->value,
            'total' => $result->getTotal(),
            'summary' => $result->getSummary(),
            'hasBlockingIssues' => $result->hasBlockingIssues(),
            'items' => array_map(
                fn (DefaultSaleMappingPreviewItem $item): array => $this->previewItemToArray($item),
                $result->getItems(),
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function previewItemToArray(DefaultSaleMappingPreviewItem $item): array
    {
        return [
            'status' => $item->getStatus()->value,
            'statusLabel' => $item->getStatus()->getLabel(),
            'operationType' => $item->getOperationType(),
            'amountSource' => $item->getAmountSource()->value,
            'amountSourceLabel' => $item->getAmountSource()->getDisplayName(),
            'plCode' => $item->getPlCode(),
            'plCategoryId' => $item->getPlCategoryId(),
            'plCategoryName' => $item->getPlCategoryName(),
            'existingMappingId' => $item->getExistingMappingId(),
            'existingPlCategoryName' => $item->getExistingPlCategoryName(),
            'isNegative' => $item->isNegative(),
            'expectedNegative' => $item->isExpectedNegative(),
            'signMismatch' => $item->hasSignMismatch(),
            'message' => $item->getMessage(),
        ];
    }

    /** @return array<string, mixed> */
    private function applyResultToArray(DefaultSaleMappingApplyResult $result): array
    {
        return [
            'ok' => true,
            'marketplace' => $result->getMarketplace()->value,
            'summary' => $result->getSummary(),
            'createdAmountSources' => $result->getCreatedAmountSources(),
            'skippedAmountSources' => $result->getSkippedAmountSources(),
        ];
    }
}
