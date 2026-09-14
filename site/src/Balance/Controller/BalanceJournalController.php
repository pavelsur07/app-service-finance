<?php

declare(strict_types=1);

namespace App\Balance\Controller;

use App\Balance\Infrastructure\Query\LedgerQuery;
use App\Balance\Security\BalanceAccess;
use App\Company\Facade\CompanyFacade;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class BalanceJournalController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly BalanceAccess $access,
        private readonly LedgerQuery $query,
        private readonly CompanyFacade $companies,
    ) {
    }

    #[Route('/balance/journal', name: 'balance_journal', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $companyId = $this->activeCompany->getActiveCompany()->getId();
        if (null === $companyId) {
            throw $this->createAccessDeniedException();
        }
        $this->access->actor($companyId);
        $filters = [];
        foreach (['search', 'status', 'kind', 'from', 'to', 'account_id', 'author_id'] as $key) {
            $filters[$key] = $request->query->getString($key);
        }
        $members = [];
        foreach ($this->companies->listMembers($companyId) as $member) {
            $members[$member['id']] = $member['label'];
        }

        return $this->render('balance/journal.html.twig', ['journal' => $this->query->journal($companyId, $filters, $request->query->getInt('page', 1)), 'filters' => $filters, 'accounts' => $this->query->accounts($companyId), 'members' => $members, 'permissions' => $this->access->permissions($companyId), 'book' => $this->query->book($companyId)]);
    }
}
