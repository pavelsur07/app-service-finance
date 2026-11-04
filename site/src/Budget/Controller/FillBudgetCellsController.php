<?php

declare(strict_types=1);

namespace App\Budget\Controller;

use App\Budget\Application\DTO\BudgetFillRequest;
use App\Budget\Application\DTO\FillBudgetCellsCommand;
use App\Budget\Application\FillBudgetCellsAction;
use App\Budget\Application\View\BudgetFillCell;
use App\Budget\Enum\BudgetFillHelper;
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
 * Помощник заполнения плана: `{helper, project, center, categoryIds, months, params, apply,
 * confirmOverwrite}` → `{cells, changed, overwrite, skipped, applied}`. Без `apply` — только
 * предпросмотр. CSRF — заголовок `X-CSRF-Token` (`budget_cells`).
 * POST /finance/budget/versions/{id}/fill.
 */
#[Route('/finance/budget/versions/{id}/fill', name: 'budget_version_fill', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final class FillBudgetCellsController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly FillBudgetCellsAction $fill,
    ) {
    }

    public function __invoke(string $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('budget_cells', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['error' => ['code' => 'invalid_csrf', 'message' => 'Сессия устарела — обновите страницу.']], 403);
        }
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $body = self::body($request);
        $apply = true === ($body['apply'] ?? false);

        $plan = ($this->fill)(new FillBudgetCellsCommand(
            $companyId,
            $id,
            new BudgetFillRequest(
                BudgetFillHelper::tryFrom(\is_string($body['helper'] ?? null) ? $body['helper'] : '') ?? throw new InvalidBudgetInputException('Неизвестный помощник.'),
                \is_string($body['project'] ?? null) ? $body['project'] : '',
                \is_string($body['center'] ?? null) ? $body['center'] : '',
                self::strings($body, 'categoryIds'),
                self::strings($body, 'months'),
                \is_array($body['params'] ?? null) ? $body['params'] : [],
            ),
            $apply,
            true === ($body['confirmOverwrite'] ?? false),
        ));

        return new JsonResponse([
            'cells' => array_map(static fn (BudgetFillCell $cell): array => [
                'plCategoryId' => $cell->plCategoryId,
                'month' => $cell->month,
                'amount' => $cell->amount,
                'previous' => $cell->previous,
            ], $plan->cells),
            'changed' => $plan->changed,
            'overwrite' => $plan->overwrite,
            'skipped' => $plan->skipped,
            'applied' => $apply && $plan->changed > 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(Request $request): array
    {
        try {
            $body = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidBudgetInputException('Тело запроса — не JSON.');
        }
        if (!\is_array($body) || array_is_list($body) && [] !== $body) {
            throw new InvalidBudgetInputException('Ожидается объект.');
        }

        /* @var array<string, mixed> $body */
        return $body;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private static function strings(array $body, string $field): array
    {
        $value = $body[$field] ?? [];
        if (!\is_array($value) || !array_is_list($value) || [] !== array_filter($value, static fn (mixed $item): bool => !\is_string($item))) {
            throw new InvalidBudgetInputException(sprintf('%s — список строк.', $field));
        }

        /* @var list<string> $value */
        return $value;
    }
}
