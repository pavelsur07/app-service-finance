<?php

declare(strict_types=1);

namespace App\Balance\Controller;

use App\Balance\Exception\BalanceLedgerException;
use App\Balance\Infrastructure\Query\LedgerQuery;
use App\Balance\Security\BalanceAccess;
use App\Shared\Service\ActiveCompanyService;
use Pagerfanta\Adapter\ArrayAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class BalanceAccountsController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly BalanceAccess $access,
        private readonly LedgerQuery $query,
    ) {
    }

    #[Route('/balance/accounts', name: 'balance_accounts', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $companyId = $this->activeCompany->getActiveCompany()->getId();
        if (null === $companyId) {
            throw $this->createAccessDeniedException();
        }
        $this->access->actor($companyId);
        $limit = $request->query->getInt('limit', 50);
        if ($limit < 1 || $limit > 200) {
            throw new BalanceLedgerException('Количество строк должно быть от 1 до 200.');
        }
        $pager = new Pagerfanta(new ArrayAdapter($this->query->accounts($companyId)));
        $pager->setMaxPerPage($limit);
        $page = $request->query->getInt('page', 1);
        if ($page < 1 || $page > $pager->getNbPages()) {
            throw new BalanceLedgerException('Запрошенная страница не существует.');
        }
        $pager->setCurrentPage($page);

        return $this->render('balance/accounts.html.twig', ['items' => $pager->getCurrentPageResults(), 'pager' => $pager, 'permissions' => $this->access->permissions($companyId), 'book' => $this->query->book($companyId)]);
    }
}
