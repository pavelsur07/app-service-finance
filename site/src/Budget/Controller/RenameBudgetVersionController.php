<?php

declare(strict_types=1);

namespace App\Budget\Controller;

use App\Budget\Application\DTO\RenameBudgetVersionCommand;
use App\Budget\Application\RenameBudgetVersionAction;
use App\Budget\Exception\InvalidBudgetInputException;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Переименовать черновик: `{"name": "…"}`, CSRF — заголовок `X-CSRF-Token` (`budget_cells`).
 * Ответ `{name}`; ошибки — `{error: {code, message}}` (BudgetExceptionListener).
 * POST /finance/budget/versions/{id}/name.
 */
#[Route('/finance/budget/versions/{id}/name', name: 'budget_version_rename', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final class RenameBudgetVersionController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly RenameBudgetVersionAction $rename,
    ) {
    }

    public function __invoke(string $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('budget_cells', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['error' => ['code' => 'invalid_csrf', 'message' => 'Сессия устарела — обновите страницу.']], 403);
        }
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();

        try {
            $body = json_decode($request->getContent(), true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidBudgetInputException('Тело запроса — не JSON.');
        }
        if (!\is_array($body) || !\is_string($body['name'] ?? null)) {
            throw new InvalidBudgetInputException('Ожидается {"name": "…"}.');
        }

        $name = ($this->rename)(new RenameBudgetVersionCommand($companyId, $id, $body['name']));

        return new JsonResponse(['name' => $name]);
    }
}
