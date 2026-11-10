<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Cash\Repository\Transaction\CashTransactionAutoRuleRepository;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash-transaction-auto-rules')]
final class CashAutoRuleIndexController extends AbstractController
{
    private const LIST_PER_PAGE = 50;
    private const STATUS_ACTIVE = 'active';
    private const STATUS_DISABLED = 'disabled';
    private const STATUS_ALL = 'all';

    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CashflowCategoryRepository $categoryRepo,
        private readonly CashTransactionAutoRuleRepository $repo,
        private readonly \App\Cash\Http\CashAutoRulePage $page,
        private readonly FinancialResponsibilityCenterFacade $responsibilityCenterFacade,
    ) {
    }

    #[Route('/', name: 'cash_transaction_auto_rule_index', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();
        $categories = $this->categoryRepo->findTreeByCompany($company);
        $actionValue = $request->query->get('action');
        $operationTypeValue = $request->query->get('operationType');
        $categoryValue = $request->query->get('category');
        $actionFilter = $actionValue ? CashTransactionAutoRuleAction::tryFrom($actionValue) : null;
        $operationTypeFilter = $operationTypeValue ? CashTransactionAutoRuleOperationType::tryFrom($operationTypeValue) : null;
        $categoryFilter = null;
        if ($categoryValue) {
            foreach ($categories as $category) {
                if ($category->getId() === $categoryValue) {
                    $categoryFilter = $category;
                    break;
                }
            }
        }
        // По умолчанию показываются только активные правила: отключённые — архив,
        // в рабочем списке они только шумят.
        $statusValue = (string) $request->query->get('status', self::STATUS_ACTIVE);
        if (!in_array($statusValue, [self::STATUS_ACTIVE, self::STATUS_DISABLED, self::STATUS_ALL], true)) {
            $statusValue = self::STATUS_ACTIVE;
        }
        $isActiveFilter = match ($statusValue) {
            self::STATUS_ACTIVE => true,
            self::STATUS_DISABLED => false,
            default => null,
        };
        $searchValue = trim((string) $request->query->get('q', ''));
        $pager = $this->repo->paginateByCompany($company, $actionFilter, $operationTypeFilter, $categoryFilter, '' === $searchValue ? null : $searchValue, $isActiveFilter, max(1, (int) $request->query->get('page', 1)), self::LIST_PER_PAGE);
        $items = iterator_to_array($pager->getCurrentPageResults());
        $hasAnyRule = $this->repo->countByCompany($company) > 0;
        $actionOptions = array_map(static fn (CashTransactionAutoRuleAction $action) => ['value' => $action->value, 'label' => match ($action) {
            CashTransactionAutoRuleAction::FILL => 'Безопасно заполнить пустые поля',
            CashTransactionAutoRuleAction::UPDATE => 'Безопасно заполнить пустые поля (legacy UPDATE)',
        }], CashTransactionAutoRuleAction::cases());
        $operationOptions = array_map(static fn (CashTransactionAutoRuleOperationType $type) => ['value' => $type->value, 'label' => match ($type) {
            CashTransactionAutoRuleOperationType::OUTFLOW => 'Отток',
            CashTransactionAutoRuleOperationType::INFLOW => 'Приток',
            CashTransactionAutoRuleOperationType::ANY => 'Любое',
        }], [CashTransactionAutoRuleOperationType::OUTFLOW, CashTransactionAutoRuleOperationType::INFLOW, CashTransactionAutoRuleOperationType::ANY]);
        $categoryOptions = array_map(static fn (CashflowCategory $category) => ['id' => $category->getId(), 'label' => trim(str_repeat('—', $category->getLevel() - 1).' '.$category->getName())], $categories);

        return $this->render('cash_transaction_auto_rule/index.html.twig', ['items' => $items, 'pager' => $pager, 'hasAnyRule' => $hasAnyRule, 'categories' => $categories, 'actionOptions' => $actionOptions, 'operationOptions' => $operationOptions, 'categoryOptions' => $categoryOptions, 'statusOptions' => [['value' => self::STATUS_ACTIVE, 'label' => 'Активные'], ['value' => self::STATUS_DISABLED, 'label' => 'Отключённые'], ['value' => self::STATUS_ALL, 'label' => 'Все']], 'responsibilityCenterLabels' => array_flip($this->page->getResponsibilityCenterChoices($this->responsibilityCenterFacade, $companyId)), 'filters' => ['category' => $categoryValue, 'action' => $actionValue, 'operationType' => $operationTypeValue, 'status' => $statusValue, 'q' => $searchValue]]);
    }
}
