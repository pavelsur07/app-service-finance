<?php

declare(strict_types=1);

namespace App\Cash\Controller\PaymentIntake;

use App\Cash\Application\PaymentIntake\PaymentIntakeAction;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final class McpCashDraftShowController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companies,
        private readonly PaymentIntakeAction $intake,
        private readonly MoneyAccountRepository $accounts,
        private readonly CashflowCategoryRepository $categories,
    ) {
    }

    #[Route('/finance/cash-transactions/mcp-drafts/{id}', name: 'cash_mcp_draft_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(string $id, #[CurrentUser] User $user): Response
    {
        $company = $this->companies->getActiveCompany();
        $companyId = (string) $company->getId();
        try {
            $draft = $this->intake->getMcpDraft($companyId, (string) $user->getId(), $id);
        } catch (\DomainException) {
            throw $this->createNotFoundException('Черновик не найден.');
        }
        $item = $draft->items[0];
        $account = $this->accounts->findOneByIdAndCompanyId($draft->moneyAccountId, $companyId);
        $category = \is_string($item['category_id']) ? $this->categories->findOneByIdAndCompanyId($item['category_id'], $companyId) : null;

        return $this->render('cash/mcp_draft/show.html.twig', [
            'draft' => $draft,
            'item' => $item,
            'accountName' => $account?->getName(),
            'accountActive' => $account?->isActive() ?? false,
            'categoryName' => $category?->getName(),
            'confirmedBy' => $user->getEmail(),
        ]);
    }
}
