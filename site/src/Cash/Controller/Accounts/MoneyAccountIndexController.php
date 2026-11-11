<?php

declare(strict_types=1);

namespace App\Cash\Controller\Accounts;

use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/accounts')]
final class MoneyAccountIndexController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MoneyAccountRepository $repository,
        private readonly \App\Cash\Http\CashListPagination $pagination,
    ) {
    }

    #[Route('/', name: 'money_account_index', methods: ['GET'])]
    public function __invoke(\Symfony\Component\HttpFoundation\Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $pager = ($this->pagination)($request, new \Pagerfanta\Doctrine\ORM\QueryAdapter($this->repository->listQuery((string) $company->getId())));
        $accounts = $pager->getCurrentPageResults();

        return $this->render('profile/money_account/index.html.twig', ['pager' => $pager, 'accounts' => $accounts]);
    }
}
