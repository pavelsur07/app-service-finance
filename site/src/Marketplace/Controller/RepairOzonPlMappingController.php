<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Application\Action\RepairOzonPlMappingAction;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace/pl-mappings/ozon/repair', name: 'marketplace_pl_mappings_ozon_repair', methods: ['POST'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
final class RepairOzonPlMappingController extends AbstractController
{
    public function __construct(private readonly ActiveCompanyService $companyService, private readonly RepairOzonPlMappingAction $repair)
    {
    }

    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->companyService->getActiveCompany()->getId();
        if (!$this->isCsrfTokenValid('ozon_pl_mapping_repair_'.$companyId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Недействительный CSRF-токен.');
        }
        try {
            $changed = ($this->repair)($companyId);
            $this->addFlash('success', $changed > 0 ? 'Двойной учёт устранён. Продажи и возвраты с СПП рассчитываются по реализации Ozon.' : 'Настройка Ozon уже правильная.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('marketplace_pl_mappings_index', ['marketplace' => 'ozon', 'op' => 'return' === $request->request->getString('op') ? 'return' : 'sale']);
    }
}
