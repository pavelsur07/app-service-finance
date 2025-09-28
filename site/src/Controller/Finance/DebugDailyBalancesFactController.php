<?php

namespace App\Controller\Finance;

use App\Repository\MoneyAccountDailyBalanceRepository;
use App\Repository\MoneyAccountRepository;
use App\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/finance/reports/_debug/daily-facts', name: 'report_debug_daily_facts', methods: ['GET'])]
class DebugDailyBalancesFactController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly MoneyAccountDailyBalanceRepository $dailyRepo,
        private readonly MoneyAccountRepository $accountRepo,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $company = $this->activeCompany->getActiveCompany();

        $from = new \DateTimeImmutable($request->query->get('date_from', 'first day of this month'));
        $to = new \DateTimeImmutable($request->query->get('date_to', 'last day of this month'));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $accountId = $request->query->get('account') ?: null;

        $qb = $this->dailyRepo->createQueryBuilder('b')
            ->leftJoin('b.moneyAccount', 'a')->addSelect('a')
            ->where('b.company = :company')
            ->andWhere('b.date BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('b.date', 'ASC')
            ->addOrderBy('a.name', 'ASC');

        if ($accountId) {
            $qb->andWhere('IDENTITY(b.moneyAccount) = :acc')->setParameter('acc', $accountId);
        }

        $rows = $qb->getQuery()->getResult();

        $facts = [];
        foreach ($rows as $r) {
            $facts[] = [
                'date' => $r->getDate(),
                'account' => $r->getMoneyAccount()?->getName(),
                'opening' => (float) $r->getOpeningBalance(),
                'closing' => (float) $r->getClosingBalance(),
            ];
        }

        $accountOptions = $this->accountRepo->findBy(['company' => $company], ['name' => 'ASC']);

        return $this->render('report/debug_daily_facts.html.twig', [
            'filters' => [
                'date_from' => $from,
                'date_to' => $to,
                'account' => $accountId,
            ],
            'accounts' => $accountOptions,
            'facts' => $facts,
        ]);
    }
}
