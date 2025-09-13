<?php
declare(strict_types=1);

namespace App\Controller\Report;

use App\Repository\MoneyAccountDailyBalanceRepository;
use App\Repository\MoneyAccountRepository;
use App\Service\ActiveCompanyService;
use App\Service\AccountBalanceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function bcadd;
use function bccomp;
use function bcsub;

#[IsGranted('ROLE_USER')]
class BalanceVerifyController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private MoneyAccountRepository $accountRepository,
        private MoneyAccountDailyBalanceRepository $balanceRepository,
        private ActiveCompanyService $companyService,
        private AccountBalanceService $accountBalanceService,
    ) {
    }

    #[Route('/reports/balance-verify', name: 'app_reports_balance_verify', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        $accounts = $this->accountRepository->findBy(['company' => $company], ['name' => 'ASC']);

        $to = new \DateTimeImmutable('today');
        $from = $to->modify('-2 days');
        $selectedAccountId = null;
        $expectedJson = <<<JSON
{
  "currency": "RUB",
  "rows": [
    {"date":"2024-01-01","opening":"120000.00","inflow":"0.00","outflow":"0.00","closing":"120000.00"},
    {"date":"2024-01-02","opening":"120000.00","inflow":"5000.00","outflow":"0.00","closing":"125000.00"},
    {"date":"2024-01-03","opening":"125000.00","inflow":"0.00","outflow":"2000.00","closing":"123000.00"}
  ]
}
JSON;
        $resultRows = null;
        $totals = null;
        $recalc = false;

        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted('ROLE_USER');
            if (!$this->isCsrfTokenValid('balance_verify', $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $selectedAccountId = $request->request->get('account_id');
            $fromParam = $request->request->get('from');
            $toParam = $request->request->get('to');
            $recalc = (bool)$request->request->get('recalc');
            $expectedJson = (string)$request->request->get('expected_json', $expectedJson);

            $from = $fromParam ? new \DateTimeImmutable($fromParam) : $from;
            $to = $toParam ? new \DateTimeImmutable($toParam) : $to;

            $account = null;
            foreach ($accounts as $a) {
                if ($a->getId() === $selectedAccountId) {
                    $account = $a;
                    break;
                }
            }
            if (!$account && $accounts) {
                $account = $accounts[0];
                $selectedAccountId = $account->getId();
            }

            if ($account) {
                if ($recalc) {
                    $this->accountBalanceService->recalculateDailyRange($company, $account, $from, $to);
                }
                $qb = $this->balanceRepository->createQueryBuilder('b')
                    ->where('b.company = :company')
                    ->andWhere('b.moneyAccount = :account')
                    ->andWhere('b.date BETWEEN :from AND :to')
                    ->setParameter('company', $company)
                    ->setParameter('account', $account)
                    ->setParameter('from', $from->setTime(0, 0))
                    ->setParameter('to', $to->setTime(0, 0))
                    ->orderBy('b.date', 'ASC');
                $rows = $qb->getQuery()->getResult();
                $actual = [];
                foreach ($rows as $row) {
                    /** @var \App\Entity\MoneyAccountDailyBalance $row */
                    $key = $row->getDate()->format('Y-m-d');
                    $actual[$key] = [
                        'opening' => $row->getOpeningBalance(),
                        'inflow' => $row->getInflow(),
                        'outflow' => $row->getOutflow(),
                        'closing' => $row->getClosingBalance(),
                    ];
                }

                $expectedData = json_decode($expectedJson, true);
                $expected = [];
                if (is_array($expectedData['rows'] ?? null)) {
                    foreach ($expectedData['rows'] as $r) {
                        if (isset($r['date'])) {
                            $expected[$r['date']] = [
                                'opening' => $r['opening'] ?? '0.00',
                                'inflow' => $r['inflow'] ?? '0.00',
                                'outflow' => $r['outflow'] ?? '0.00',
                                'closing' => $r['closing'] ?? '0.00',
                            ];
                        }
                    }
                }
                $dates = array_unique(array_merge(array_keys($expected), array_keys($actual)));
                sort($dates);
                $ok = 0;
                $mismatches = 0;
                $resultRows = [];
                foreach ($dates as $d) {
                    $exp = $expected[$d] ?? null;
                    $act = $actual[$d] ?? null;
                    $status = 'OK';
                    if ($exp === null && $act !== null) {
                        $status = 'UNEXPECTED';
                    } elseif ($exp !== null && $act === null) {
                        $status = 'MISSING';
                    } elseif ($exp !== null && $act !== null) {
                        foreach (['opening', 'inflow', 'outflow', 'closing'] as $f) {
                            if (bccomp($exp[$f], $act[$f], 2) !== 0) {
                                $status = 'MISMATCH';
                                break;
                            }
                        }
                        if ($status === 'OK') {
                            $calc = bcsub(bcadd($act['opening'], $act['inflow'], 2), $act['outflow'], 2);
                            if (bccomp($calc, $act['closing'], 2) !== 0) {
                                $status = 'CONSISTENCY_FAIL';
                            }
                        }
                    }
                    if ($status === 'OK') {
                        $ok++;
                    } else {
                        $mismatches++;
                    }
                    $resultRows[] = [
                        'date' => $d,
                        'expected' => $exp,
                        'actual' => $act,
                        'status' => $status,
                    ];
                }
                $totals = ['ok' => $ok, 'mismatches' => $mismatches];
            }
        }

        return $this->render('reports/balance_verify.html.twig', [
            'accounts' => $accounts,
            'selected_account_id' => $selectedAccountId,
            'date_from' => $from,
            'date_to' => $to,
            'expected_json' => $expectedJson,
            'resultRows' => $resultRows,
            'totals' => $totals,
            'recalc' => $recalc,
        ]);
    }
}
