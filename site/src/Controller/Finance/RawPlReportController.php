<?php

namespace App\Controller\Finance;

use App\Entity\Document;
use App\Entity\DocumentOperation;
use App\Entity\PLCategory;
use App\Entity\PLDailyTotal;
use App\Enum\PlNature;
use App\Repository\DocumentRepository;
use App\Repository\PLDailyTotalRepository;
use App\Service\PlNatureResolver;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class RawPlReportController extends AbstractController
{
    #[Route('/finance/reports/pl-raw', name: 'finance_report_pl_raw')]
    public function __invoke(
        Request $request,
        ActiveCompanyService $activeCompany,
        DocumentRepository $documentRepo,
        PLDailyTotalRepository $totalsRepo,
        PlNatureResolver $natureResolver,
    ): Response {
        $company = $activeCompany->getActiveCompany();
        $from = new \DateTimeImmutable($request->query->get('from', 'first day of this month'));
        $to = new \DateTimeImmutable($request->query->get('to', 'last day of this month'));

        // --- 1. Получаем операции документов ---
        $qb = $documentRepo->createQueryBuilder('d')
            ->leftJoin('d.operations', 'o')
            ->leftJoin('o.category', 'c')
            ->andWhere('d.company = :company')
            ->andWhere('d.date BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('d.date', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->addOrderBy('o.id', 'ASC');

        $rows = [];
        /** @var Document $doc */
        foreach ($qb->getQuery()->getResult() as $doc) {
            foreach ($doc->getOperations() as $op) {
                if (!$op instanceof DocumentOperation) {
                    continue;
                }

                $category = $op->getPlCategory();
                if (!$category instanceof PLCategory) {
                    continue;
                }

                $nature = $natureResolver->forOperation($op);
                if (!$nature instanceof PlNature) {
                    continue;
                }

                $sign = $nature->sign();

                $documentLabel = $doc->getNumber();
                if (null === $documentLabel || '' === trim((string) $documentLabel)) {
                    $documentLabel = sprintf('#%s', $doc->getId());
                } else {
                    $documentLabel = sprintf('№%s', $documentLabel);
                }

                $rows[] = [
                    'date' => $doc->getDate()->format('Y-m-d'),
                    'document' => $documentLabel,
                    'operation_id' => $op->getId(),
                    'category' => $category->getName(),
                    'nature' => $nature->value,
                    'amount_raw' => $op->getAmount(),
                    'amount_signed' => (float) $op->getAmount() * $sign,
                    'counterparty' => $op->getCounterparty()?->getName() ?? '-',
                    'comment' => $op->getComment() ?? '',
                ];
            }
        }

        // --- 2. Получаем промежуточные итоги (PLDailyTotal) ---
        $qb2 = $totalsRepo->createQueryBuilder('t')
            ->leftJoin('t.plCategory', 'c')
            ->andWhere('t.company = :company')
            ->andWhere('t.date BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('t.date', 'ASC')
            ->addOrderBy('c.name', 'ASC');

        $totals = [];
        /** @var PLDailyTotal $total */
        foreach ($qb2->getQuery()->getResult() as $total) {
            $totals[] = [
                'date' => $total->getDate()->format('Y-m-d'),
                'category' => $total->getPlCategory()?->getName() ?? '-',
                'income' => (float) $total->getAmountIncome(),
                'expense' => (float) $total->getAmountExpense(),
                'net' => (float) $total->getAmountIncome() - (float) $total->getAmountExpense(),
            ];
        }

        return $this->render('finance/reports/pl_raw.html.twig', [
            'company' => $company,
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totals' => $totals,
        ]);
    }
}
