<?php

declare(strict_types=1);

namespace App\Balance\Controller;

use App\Balance\Security\BalanceAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class BalanceLegacyLinkController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly BalanceAccess $access,
    ) {
    }

    #[Route('/balance/structure/{id}/link-money-accounts-total', name: 'balance_structure_link_money_accounts_total', methods: ['POST'])]
    #[Route('/balance/structure/{id}/link-money-funds-total', name: 'balance_structure_link_money_funds_total', methods: ['POST'])]
    public function __invoke(): Response
    {
        $companyId = $this->activeCompany->getActiveCompany()->getId();
        if (null === $companyId) {
            throw $this->createAccessDeniedException();
        }
        $this->access->actor($companyId);

        return new Response('Прежние привязки отключены. Баланс ведется по собственным учетным счетам.', Response::HTTP_GONE);
    }
}
