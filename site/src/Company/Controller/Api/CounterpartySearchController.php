<?php

declare(strict_types=1);

namespace App\Company\Controller\Api;

use App\Company\Infrastructure\Query\CounterpartySearchQuery;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class CounterpartySearchController extends AbstractController
{
    #[Route('/api/counterparties/search', name: 'api_counterparty_search', methods: ['GET'])]
    public function __invoke(
        Request $request,
        CounterpartySearchQuery $query,
        ActiveCompanyService $companyService,
    ): JsonResponse {
        // Справочник общий для двух модулей (форма сделки и финансовые экраны), поэтому он
        // exempt в ModuleAccessMap — модульный read-гейт подписчика выбрал бы один модуль.
        // Но данные контрагентов — бизнес-данные, и без права хотя бы на один из этих модулей
        // отдавать их нельзя: tenant-scope ограничивает компанию, а не право на модуль.
        if (!$this->isGranted(ModuleAccess::FINANCE_READ) && !$this->isGranted(ModuleAccess::DEALS_READ)) {
            throw $this->createAccessDeniedException('No finance or deals module access.');
        }

        // companyId берётся из контекста аутентификации, никогда из параметра запроса.
        $company = $companyService->getActiveCompany();

        $items = $query->search($company->getId(), (string) $request->query->get('q', ''));

        return $this->json($items);
    }
}
