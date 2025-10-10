<?php
declare(strict_types=1);

namespace App\Finance\Controller;

use App\Entity\Company;
use App\Entity\PLCategory;
use App\Enum\PLCategoryType;
use App\Finance\Facts\FactsProviderInterface;
use App\Repository\PLCategoryRepository;
use App\Service\ActiveCompanyService;
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
        FactsProviderInterface $facts
    ): Response {
        $company = $activeCompany->getActiveCompany();
        $periodParam = $request->query->get('period') ?? (new \DateTimeImmutable('first day of this month'))->format('Y-m-01');
        $period = new \DateTimeImmutable($periodParam);

        /** @var PLCategory[] $all */
        $all = $categories->findBy(['company' => $company], ['parent' => 'ASC', 'sortOrder' => 'ASC']);

        $rows = [];
        foreach ($all as $c) {
            $code = $c->getCode();
            $value = null;

            if ($code && $c->getType() === PLCategoryType::LEAF_INPUT) {
                $value = (float) $facts->value($company, $period, $code);
            }

            $rows[] = [
                'id' => $c->getId(),
                'level' => $c->getLevel(),
                'name' => $c->getName(),
                'code' => $code,
                'type' => $c->getType()->value,
                'format' => $c->getFormat()->value,
                'value' => $value,
                'isLeaf' => $c->getType() === PLCategoryType::LEAF_INPUT,
            ];
        }

        return $this->render('finance/report/raw.html.twig', [
            'company' => $company,
            'period' => $period,
            'rows' => $rows,
        ]);
    }
}
