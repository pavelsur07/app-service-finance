<?php

namespace App\Finance\Controller;

use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Finance\Application\Service\PlNatureResolver;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Finance\Entity\PLDailyTotal;
use App\Finance\Enum\PlNature;
use App\Finance\Repository\DocumentRepository;
use App\Finance\Repository\PLDailyTotalRepository;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class RawPlReportController extends AbstractController
{
    #[Route('/finance/reports/pl-raw', name: 'finance_report_pl_raw', methods: ['GET'])]
    public function __invoke(
        Request $request,
        ActiveCompanyService $activeCompany,
        DocumentRepository $documentRepo,
        PLDailyTotalRepository $totalsRepo,
        PlNatureResolver $natureResolver,
        FinancialResponsibilityCenterFacade $responsibilityCenters,
    ): Response {
        $company = $activeCompany->getActiveCompany();
        $from = new \DateTimeImmutable($request->query->get('from', 'first day of this month'));
        $to = new \DateTimeImmutable($request->query->get('to', 'last day of this month'));
        $responsibilityCenterLabels = [];
        $responsibilityCenterChoices = $responsibilityCenters->getActiveChoices((string) $company->getId());
        foreach ($responsibilityCenterChoices as $center) {
            $responsibilityCenterLabels[$center->id] = $center->name;
        }
        $selectedResponsibilityCenterId = $this->resolveResponsibilityCenterId(
            (string) $request->query->get('responsibilityCenterId', ''),
            $responsibilityCenterLabels,
        );

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

        if (null !== $selectedResponsibilityCenterId) {
            $qb
                ->andWhere('o.responsibilityCenterId = :responsibilityCenterId OR (o.responsibilityCenterId IS NULL AND d.responsibilityCenterId = :responsibilityCenterId)')
                ->setParameter('responsibilityCenterId', $selectedResponsibilityCenterId);
        }

        $rows = [];
        /** @var Document $doc */
        foreach ($qb->getQuery()->getResult() as $doc) {
            foreach ($doc->getOperations() as $op) {
                if (!$op instanceof DocumentOperation) {
                    continue;
                }

                $operationResponsibilityCenterId = $op->getResponsibilityCenterId() ?? $doc->getResponsibilityCenterId();
                if (null !== $selectedResponsibilityCenterId && $operationResponsibilityCenterId !== $selectedResponsibilityCenterId) {
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
                    'project' => $op->getProjectDirection()?->getName() ?? $doc->getProjectDirection()?->getName() ?? '-',
                    'responsibility_center' => $this->responsibilityCenterLabel(
                        $operationResponsibilityCenterId,
                        $responsibilityCenterLabels,
                    ),
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
            ->leftJoin('t.projectDirection', 'pd')
            ->andWhere('t.company = :company')
            ->andWhere('t.date BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('t.date', 'ASC')
            ->addOrderBy('pd.name', 'ASC')
            ->addOrderBy('c.name', 'ASC');

        if (null !== $selectedResponsibilityCenterId) {
            $qb2
                ->andWhere('t.responsibilityCenterId = :responsibilityCenterId')
                ->setParameter('responsibilityCenterId', $selectedResponsibilityCenterId);
        }

        $totals = [];
        /** @var PLDailyTotal $total */
        foreach ($qb2->getQuery()->getResult() as $total) {
            $totals[] = [
                'date' => $total->getDate()->format('Y-m-d'),
                'category' => $total->getPlCategory()?->getName() ?? '-',
                'project' => $total->getProjectDirection()->getName(),
                'responsibility_center' => $this->responsibilityCenterLabel(
                    $total->getResponsibilityCenterId(),
                    $responsibilityCenterLabels,
                ),
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
            'responsibilityCenters' => $responsibilityCenterChoices,
            'selectedResponsibilityCenterId' => $selectedResponsibilityCenterId,
        ]);
    }

    /**
     * @param array<string, string> $labels
     */
    private function resolveResponsibilityCenterId(string $responsibilityCenterId, array $labels): ?string
    {
        if ('' === $responsibilityCenterId || !Uuid::isValid($responsibilityCenterId)) {
            return null;
        }

        return isset($labels[$responsibilityCenterId]) ? $responsibilityCenterId : null;
    }

    /**
     * @param array<string, string> $labels
     */
    private function responsibilityCenterLabel(?string $responsibilityCenterId, array $labels): string
    {
        if (null === $responsibilityCenterId || '' === $responsibilityCenterId) {
            return '-';
        }

        return $labels[$responsibilityCenterId] ?? $responsibilityCenterId;
    }
}
