<?php

declare(strict_types=1);

namespace App\Inventory\Controller;

use App\Inventory\Application\RequestWbInventorySnapshotAction;
use App\Inventory\Enum\SnapshotTriggerType;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_COMPANY_OWNER')]
final class WbSnapshotRequestController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly RequestWbInventorySnapshotAction $requestSnapshotAction,
    ) {
    }

    #[Route('/inventory/snapshots/request/wildberries', name: 'inventory_wb_snapshots_request', methods: ['POST'])]
    public function __invoke(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('inventory_wb_snapshots_request', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Неверный CSRF-токен.');

            return $this->redirectToRoute('inventory_snapshots_index');
        }

        $company = $this->activeCompanyService->getActiveCompany();

        try {
            $result = ($this->requestSnapshotAction)(
                companyId: (string) $company->getId(),
                triggerType: SnapshotTriggerType::Manual,
                actorUserId: $this->getUser()?->getId(),
            );

            if (!$result->hasConnections) {
                $this->addFlash('warning', 'Нет активного Wildberries-подключения (SELLER) для запуска синхронизации.');
            } elseif ($result->hasActiveSession) {
                $this->addFlash('warning', 'Синхронизация Wildberries уже выполняется.');
            } elseif ($result->queuedCount > 0) {
                $this->addFlash('success', 'Задача синхронизации остатков Wildberries запущена.');
            } else {
                $this->addFlash('danger', 'Не удалось запустить синхронизацию остатков Wildberries.');
            }
        } catch (\Throwable) {
            $this->addFlash('danger', 'Ошибка запуска синхронизации остатков Wildberries.');
        }

        return $this->redirectToRoute('inventory_snapshots_index');
    }
}
