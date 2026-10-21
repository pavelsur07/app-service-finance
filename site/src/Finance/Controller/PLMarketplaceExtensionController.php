<?php

declare(strict_types=1);

namespace App\Finance\Controller;

use App\Company\Security\ModuleAccess;
use App\Finance\Application\Action\ExtendPLTreeWithMarketplaceAction;
use App\Finance\Application\DTO\PLFormulaChange;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Добавляет статьи маркетплейса в «Базовое» дерево ОПиУ активной компании.
 */
#[IsGranted('ROLE_USER')]
final class PLMarketplaceExtensionController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly ExtendPLTreeWithMarketplaceAction $extend,
    ) {
    }

    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    #[Route('/pl-categories/import/marketplace-extension', name: 'pl_category_marketplace_extension', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->activeCompany->getActiveCompany()->getId();
        if (!$this->isCsrfTokenValid('pl-marketplace-extension'.$companyId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $result = ($this->extend)($companyId, false);
        } catch (\DomainException $e) {
            // Отказы дополнения и импорта (профиль, чужое дерево, изменённая формула, второе дерево).
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('pl_category_import', ['source' => 'marketplace']);
        }

        $this->addFlash('success', $result->isEmpty()
            ? 'Статьи маркетплейса уже есть — добавлять нечего.'
            : \sprintf(
                'Добавлено статей: %d. Итоги с ними: %s.',
                \count($result->import->created),
                implode(', ', array_map(static fn (PLFormulaChange $c): string => $c->name, $result->formulaChanges)) ?: 'без изменений',
            ));

        return $this->redirectToRoute('pl_category_index');
    }
}
