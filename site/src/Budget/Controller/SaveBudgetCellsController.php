<?php

declare(strict_types=1);

namespace App\Budget\Controller;

use App\Budget\Application\DTO\BudgetCellInput;
use App\Budget\Application\DTO\SaveBudgetLinesCommand;
use App\Budget\Application\SaveBudgetLinesAction;
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
 * Сохранить пачку ячеек черновика: `{"cells": [{plCategoryId, month, projectDirectionId,
 * responsibilityCenterId, amount, comment}]}`, CSRF — заголовок `X-CSRF-Token` (`budget_cells`).
 * Ошибки — `{error: {code, message}}` (BudgetExceptionListener). POST /finance/budget/versions/{id}/cells.
 */
#[Route('/finance/budget/versions/{id}/cells', name: 'budget_version_cells', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final class SaveBudgetCellsController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly SaveBudgetLinesAction $saveLines,
    ) {
    }

    public function __invoke(string $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('budget_cells', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['error' => ['code' => 'invalid_csrf', 'message' => 'Сессия устарела — обновите страницу.']], 403);
        }
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();

        $saved = ($this->saveLines)(new SaveBudgetLinesCommand($companyId, $id, self::cells($request)));

        return new JsonResponse(['saved' => $saved]);
    }

    /**
     * @return list<BudgetCellInput>
     */
    private static function cells(Request $request): array
    {
        try {
            $body = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidBudgetInputException('Тело запроса — не JSON.');
        }
        if (!\is_array($body) || !\is_array($body['cells'] ?? null) || !array_is_list($body['cells'])) {
            throw new InvalidBudgetInputException('Ожидается {"cells": [...]}.');
        }

        $cells = [];
        foreach ($body['cells'] as $index => $cell) {
            $fields = ['plCategoryId', 'month', 'projectDirectionId', 'responsibilityCenterId', 'amount'];
            if (!\is_array($cell)) {
                throw new InvalidBudgetInputException(sprintf('Ячейка %d: неверный формат.', $index + 1));
            }
            foreach ($fields as $field) {
                if (!\is_string($cell[$field] ?? null)) {
                    throw new InvalidBudgetInputException(sprintf('Ячейка %d: нет поля %s.', $index + 1, $field));
                }
            }
            $comment = $cell['comment'] ?? null;
            if (null !== $comment && !\is_string($comment)) {
                throw new InvalidBudgetInputException(sprintf('Ячейка %d: комментарий — строка.', $index + 1));
            }
            $cells[] = new BudgetCellInput($cell['plCategoryId'], $cell['month'], $cell['projectDirectionId'], $cell['responsibilityCenterId'], $cell['amount'], $comment);
        }

        return $cells;
    }
}
