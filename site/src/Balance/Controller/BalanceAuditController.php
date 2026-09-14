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
final class BalanceAuditController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly BalanceAccess $access,
        private readonly LedgerQuery $query,
        private readonly CompanyFacade $companies,
    ) {
    }

    #[Route('/balance/history/{type}/{id}', name: 'balance_audit', methods: ['GET'])]
    public function __invoke(Request $request, string $type, string $id): Response
    {
        $companyId = $this->activeCompany->getActiveCompany()->getId();
        if (null === $companyId) {
            throw $this->createAccessDeniedException();
        }
        $this->access->actor($companyId);
        $members = [];
        foreach ($this->companies->listMembers($companyId) as $member) {
            $members[$member['id']] = $member['label'];
        }

        return $this->render('balance/audit.html.twig', ['audit' => $this->query->audit($companyId, $type, $id, $request->query->getInt('page', 1)), 'objectType' => $type, 'objectId' => $id, 'members' => $members, 'permissions' => $this->access->permissions($companyId), 'book' => $this->query->book($companyId)]);
    }
}
