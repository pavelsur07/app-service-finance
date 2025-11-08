<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Controller;

use App\Marketplace\Wildberries\Service\WildberriesRnpReportService;
use App\Service\ActiveCompanyService;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Annotation\Route;

final class WildberriesRnpReportController extends AbstractController
{
    public function __construct(
        private readonly WildberriesRnpReportService $reportService,
        private readonly ActiveCompanyService $companyContext,
    ) {
    }

    #[Route(path: '/wb/reports/rnp', name: 'wb_reports_rnp', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->companyContext->getActiveCompany();

        $period = $request->query->get('period');
        $fromParam = $request->query->get('from');
        $toParam = $request->query->get('to');

        if (null !== $period && '' !== $period) {
            ['from' => $from, 'to' => $to] = $this->reportService->resolvePeriod((string) $period);
        } else {
            if (null === $fromParam || null === $toParam) {
                throw new BadRequestHttpException('Either "period" or both "from" and "to" parameters must be provided.');
            }

            $from = $this->parseDate((string) $fromParam, 'from');
            $to = $this->parseDate((string) $toParam, 'to');
        }

        if ($from > $to) {
            throw new BadRequestHttpException('Parameter "from" must be earlier than or equal to parameter "to".');
        }

        $filters = [
            'sku' => $request->query->all('sku'),
            'brand' => $request->query->all('brand'),
            'category' => $request->query->all('category'),
        ];

        return new JsonResponse($this->reportService->buildReport($company, $from, $to, $filters));
    }

    private function parseDate(string $value, string $name): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value) ?: false;
        if (false === $date) {
            throw new BadRequestHttpException(sprintf('Invalid "%s" date. Expected format YYYY-MM-DD.', $name));
        }

        return $date->setTime(0, 0);
    }
}
