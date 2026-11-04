<?php

declare(strict_types=1);

namespace App\Budget\Controller;

use App\Budget\Application\DTO\RemoveBudgetRuleCommand;
use App\Budget\Application\DTO\SetBudgetRuleCommand;
use App\Budget\Application\RemoveBudgetRuleAction;
use App\Budget\Application\SetBudgetRuleAction;
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
 * Правило «X % от строки-базы» статьи в паре черновика (задача pl-budget, этап 8). CSRF — заголовок
 * `X-CSRF-Token` (`budget_cells`), запись FINANCE.
 *
 * POST /finance/budget/versions/{id}/rules `{project, center, categoryId, baseCategoryId, percent}` →
 * `{changed}`; POST …/rules/delete `{project, center, categoryId}` → `{removed}`.
 */
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final class BudgetRuleController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly SetBudgetRuleAction $setRule,
        private readonly RemoveBudgetRuleAction $removeRule,
    ) {
    }

    #[Route('/finance/budget/versions/{id}/rules', name: 'budget_version_rule_set', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function set(string $id, Request $request): JsonResponse
    {
        if (null !== $denied = $this->csrfDenied($request)) {
            return $denied;
        }
        $body = self::body($request);
        $percent = $body['percent'] ?? null;
        if (\is_int($percent) || \is_float($percent)) {
            $percent = (string) $percent;
        }

        $changed = ($this->setRule)(new SetBudgetRuleCommand(
            (string) $this->activeCompanyService->getActiveCompany()->getId(),
            $id,
            self::string($body, 'project'),
            self::string($body, 'center'),
            self::string($body, 'categoryId'),
            self::string($body, 'baseCategoryId'),
            \is_string($percent) ? str_replace(',', '.', trim($percent)) : '',
        ));

        return new JsonResponse(['changed' => $changed]);
    }

    #[Route('/finance/budget/versions/{id}/rules/delete', name: 'budget_version_rule_delete', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function delete(string $id, Request $request): JsonResponse
    {
        if (null !== $denied = $this->csrfDenied($request)) {
            return $denied;
        }
        $body = self::body($request);

        $removed = ($this->removeRule)(new RemoveBudgetRuleCommand(
            (string) $this->activeCompanyService->getActiveCompany()->getId(),
            $id,
            self::string($body, 'project'),
            self::string($body, 'center'),
            self::string($body, 'categoryId'),
        ));

        return new JsonResponse(['removed' => $removed]);
    }

    private function csrfDenied(Request $request): ?JsonResponse
    {
        return $this->isCsrfTokenValid('budget_cells', (string) $request->headers->get('X-CSRF-Token', ''))
            ? null
            : new JsonResponse(['error' => ['code' => 'invalid_csrf', 'message' => 'Сессия устарела — обновите страницу.']], 403);
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(Request $request): array
    {
        try {
            $body = json_decode($request->getContent(), true, 4, \JSON_THROW_ON_ERROR);
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
     */
    private static function string(array $body, string $field): string
    {
        $value = $body[$field] ?? '';

        return \is_string($value) ? $value : throw new InvalidBudgetInputException(sprintf('%s — строка.', $field));
    }
}
