<?php

declare(strict_types=1);

namespace App\Balance\Controller;

use App\Balance\Infrastructure\Query\LedgerQuery;
use App\Balance\Security\BalanceAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class BalanceStatementController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly BalanceAccess $access,
        private readonly LedgerQuery $query,
    ) {
    }

    #[Route('/balance/statement', name: 'balance_statement', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $companyId = $this->activeCompany->getActiveCompany()->getId();
        if (null === $companyId) {
            throw $this->createAccessDeniedException();
        }
        $this->access->actor($companyId);
        $from = $request->query->getString('from', (new \DateTimeImmutable('first day of this month', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d'));
        $to = $request->query->getString('to', (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d'));

        return $this->render('balance/statement.html.twig', ['statement' => $this->query->statement($companyId, $from, $to), 'from' => $from, 'to' => $to, 'permissions' => $this->access->permissions($companyId), 'book' => $this->query->book($companyId)]);
    }
}
