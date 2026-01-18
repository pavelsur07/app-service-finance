<?php

namespace App\Controller\Api;

use App\Finance\Report\PlReportGridBuilder;
use App\Finance\Report\PlReportPeriod;
use App\Finance\Report\PlReportProjectsCompareBuilder;
use App\Repository\ProjectDirectionRepository;
use App\Service\RateLimiter\ReportsApiRateLimiter;
use App\Service\ReportApiKeyManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class PublicPlReportController extends AbstractController
{
    public function __construct(
        private readonly ReportApiKeyManager $keys,
        private readonly PlReportGridBuilder $gridBuilder,
        private readonly PlReportProjectsCompareBuilder $projectsCompareBuilder,
        private readonly ProjectDirectionRepository $projectDirections,
        private readonly ReportsApiRateLimiter $rateLimiter,
    ) {
    }

    /**
     * Публичный отчет о прибылях и убытках.
     *
     * Входные параметры (query):
     *  - token (string, required): публичный ключ компании, иначе 401.
     *  - grouping (string, optional): day|week|month, по умолчанию month.
     *  - from, to (string, optional): даты в формате YYYY-MM-DD; если from > to, значения меняются местами;
     *    по умолчанию текущий месяц.
     *  - projectDirectionId (string, optional): идентификатор направления проекта.
     *
     * Контракт ответа (JsonResponse 200):
     *  - company (string): идентификатор компании.
     *  - grouping (string): используемый тип группировки.
     *  - from, to (string): границы периода в формате YYYY-MM-DD.
     *  - projectDirectionId (string|null): выбранное направление проекта.
     *  - periods (array): список периодов с полями id, label, from, to.
     *  - rows (array): агрегированные строки отчета.
     *  - rawValues (array): исходные суммы, которые использовались для построения rows.
     *  - warnings (array): список предупреждений.
     *
     * Ошибки: 401 token_required или unauthorized, 429 rate_limited.
     */
    #[Route('/api/public/reports/pl.json', name: 'api_report_pl_json', methods: ['GET'])]
    public function jsonReport(Request $r): JsonResponse
    {
        $token = (string) $r->query->get('token', '');
        $identifier = '' !== $token ? $token : ($r->getClientIp() ?? 'anon');
        if (!$this->rateLimiter->consume($identifier)) {
            return new JsonResponse(['error' => 'rate_limited'], 429);
        }
        if ('' === $token) {
            return new JsonResponse(['error' => 'token_required'], 401);
        }

        $company = $this->keys->findCompanyByRawKey($token);
        if (!$company) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        $grouping = $r->query->get('grouping', 'month');
        if (!\in_array($grouping, ['day', 'week', 'month'], true)) {
            $grouping = 'month';
        }

        $defaultFrom = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0, 0);
        $defaultTo = (new \DateTimeImmutable('last day of this month'))->setTime(0, 0, 0);

        $from = $this->parseDate($r->query->get('from')) ?? $defaultFrom;
        $to = $this->parseDate($r->query->get('to')) ?? $defaultTo;

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $projectDirectionId = (string) $r->query->get('projectDirectionId', '');
        $projectDirections = $this->projectDirections->findByCompany($company);
        $selectedProjectDirection = null;
        if ('' !== $projectDirectionId) {
            foreach ($projectDirections as $direction) {
                if ((string) $direction->getId() === $projectDirectionId) {
                    $selectedProjectDirection = $direction;

                    break;
                }
            }
        }

        $grid = $this->gridBuilder->build($company, $from, $to, $grouping, $selectedProjectDirection);

        return $this->json([
            'company' => $company->getId(),
            'grouping' => $grouping,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'projectDirectionId' => $selectedProjectDirection?->getId(),
            'periods' => array_map(
                static fn (PlReportPeriod $period): array => [
                    'id' => $period->id,
                    'label' => $period->label,
                    'from' => $period->from->format('Y-m-d'),
                    'to' => $period->to->format('Y-m-d'),
                ],
                $grid['periods']
            ),
            'rows' => $grid['rows'],
            'rawValues' => $grid['rawValues'],
            'warnings' => $grid['warnings'],
        ]);
    }

    #[Route('/api/public/reports/pl-projects.json', name: 'api_report_pl_projects_json', methods: ['GET'])]
    public function jsonProjectsReport(Request $r): JsonResponse
    {
        $token = (string) $r->query->get('token', '');
        $identifier = '' !== $token ? $token : ($r->getClientIp() ?? 'anon');
        if (!$this->rateLimiter->consume($identifier)) {
            return new JsonResponse(['error' => 'rate_limited'], 429);
        }
        if ('' === $token) {
            return new JsonResponse(['error' => 'token_required'], 401);
        }

        $company = $this->keys->findCompanyByRawKey($token);
        if (!$company) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        $defaultFrom = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0, 0);
        $defaultTo = (new \DateTimeImmutable('last day of this month'))->setTime(0, 0, 0);

        $from = $this->parseDate($r->query->get('from')) ?? $defaultFrom;
        $to = $this->parseDate($r->query->get('to')) ?? $defaultTo;

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $projectDirections = $this->projectDirections->findByCompany($company);
        $overhead = null;
        foreach ($projectDirections as $pd) {
            $name = mb_strtolower(trim((string) $pd->getName()));
            if ('общий' === $name || str_starts_with($name, 'общий')) {
                $overhead = $pd;

                break;
            }
        }

        $payload = $this->projectsCompareBuilder->build($company, $from, $to, $projectDirections, $overhead);

        return $this->json([
            'company' => [
                'id' => $company->getId(),
                'name' => $company->getName(),
            ],
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'layout' => 'projects',
            'period' => $payload['period'],
            'projects' => $payload['projects'],
            'rows' => $payload['rows'],
            'rawValues' => $payload['rawValues'],
            'warnings' => $payload['warnings'],
        ]);
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if (!$value) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->setTime(0, 0, 0);
        } catch (\Exception) {
            return null;
        }
    }
}
