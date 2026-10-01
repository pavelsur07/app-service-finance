<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Controller;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Ozon\Application\Action\RunOzonReconciliationAction;
use App\Marketplace\Ozon\Application\Reconciliation\ReconciliationMonth;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Пересчёт сверки за месяц по кнопке. Пишет только снимок сверки, учёт не меняет.
 */
#[Route('/marketplace/ozon-reconciliation/run', name: 'marketplace_ozon_reconciliation_run', methods: ['POST'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
final class OzonReconciliationRunController extends AbstractController
{
    public const CSRF_TOKEN_ID = 'marketplace_ozon_reconciliation_run';

    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly RunOzonReconciliationAction $action,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $companyId = (string) $this->companyService->getActiveCompany()->getId();

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Недействительный CSRF-токен');
        }

        $month = ReconciliationMonth::tryParse($request->request->all()['month'] ?? null);
        if (null === $month) {
            $this->addFlash('error', 'Месяц сверки указан неверно.');

            return $this->redirectToRoute('marketplace_ozon_reconciliation_index');
        }

        $run = ($this->action)($companyId, $month->from, $month->to());

        $this->addFlash('success', sprintf(
            'Сверка за %s выполнена: %s, расхождений: %d.',
            $month->label(),
            mb_strtolower($run->getOverallStatus()->getLabel()),
            $run->getMismatchCount(),
        ));

        return $this->redirectToRoute('marketplace_ozon_reconciliation_index', ['month' => $month->value()]);
    }
}
