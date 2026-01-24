<?php

declare(strict_types=1);

namespace App\Finance\Controller;

use App\Entity\PLCategory;
use App\Enum\PLCategoryType;
use App\Finance\Facts\FactsProviderInterface;
use App\Finance\Report\PlReportPeriod;
use App\Repository\PLCategoryRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/finance/report')]
final class PlRawFactsController extends AbstractController
{
    #[Route('/raw', name: 'finance_report_raw', methods: ['GET'])]
    public function raw(
        Request $request,
        ActiveCompanyService $activeCompany,
        PLCategoryRepository $categories,
        FactsProviderInterface $facts,
    ): Response {
        $company = $activeCompany->getActiveCompany();
        $periodParam = $request->query->get('period') ?? (new \DateTimeImmutable('first day of this month'))->format('Y-m-01');
        $periodDate = new \DateTimeImmutable($periodParam);
        $period = PlReportPeriod::forMonth($periodDate);

        /** @var PLCategory[] $all */
        $all = $categories->findBy(['company' => $company], ['parent' => 'ASC', 'sortOrder' => 'ASC']);

        $rows = [];
        foreach ($all as $c) {
            $code = $c->getCode();
            $value = null;

            if ($code && PLCategoryType::LEAF_INPUT === $c->getType()) {
                $value = (float) $facts->value($company, $period, $code, null);
            }

            $rows[] = [
                'id' => $c->getId(),
                'level' => $c->getLevel(),
                'name' => $c->getName(),
                'code' => $code,
                'type' => $c->getType()->value,
                'format' => $c->getFormat()->value,
                'value' => $value,
                'isLeaf' => PLCategoryType::LEAF_INPUT === $c->getType(),
            ];
        }

        return $this->render('finance/report/raw.html.twig', [
            'company' => $company,
            'period' => $periodDate,
            'rows' => $rows,
        ]);
    }
}
