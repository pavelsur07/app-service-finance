<?php

declare(strict_types=1);

namespace App\Budget\Controller;

use App\Budget\Application\Service\BudgetGridBuilder;
use App\Budget\Application\Service\BudgetPairResolver;
use App\Budget\Exception\BudgetVersionNotFoundException;
use App\Budget\Infrastructure\Xlsx\BudgetPlanXlsxWriter;
use App\Budget\Repository\BudgetVersionRepository;
use App\Shared\Service\ActiveCompanyService;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Вариант бюджета в xlsx: `?project=&center=` — одна пара, без них — вся компания (как сетка).
 * GET /finance/budget/versions/{id}/export. Формат загружается обратно (этап 7).
 */
#[Route('/finance/budget/versions/{id}/export', name: 'budget_version_export', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
#[IsGranted('ROLE_USER')]
final class BudgetExportController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly BudgetVersionRepository $versions,
        private readonly BudgetPairResolver $pairs,
        private readonly BudgetGridBuilder $gridBuilder,
        private readonly BudgetPlanXlsxWriter $xlsx,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id, Request $request): Response
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $version = $this->versions->findOneByIdAndCompanyId($id, $companyId) ?? throw new BudgetVersionNotFoundException();
        $pair = $this->pairs->resolve($companyId, $request->query->getString('project'), $request->query->getString('center'));

        $pairLabel = 'Вся компания (сумма всех пар, только просмотр)';
        if (null !== $pair) {
            foreach ($this->pairs->options($companyId) as $option) {
                if ($option['projectDirectionId'] === $pair->projectDirectionId && $option['responsibilityCenterId'] === $pair->responsibilityCenterId) {
                    $pairLabel = $option['label'];
                }
            }
        }

        $grid = $this->gridBuilder->build($version, $pair?->projectDirectionId, $pair?->responsibilityCenterId);
        $file = tempnam(sys_get_temp_dir(), 'budget_export_');
        if (false === $file) {
            throw new \RuntimeException('Не удалось создать временный файл выгрузки бюджета.');
        }
        try {
            $this->xlsx->write($grid, $version->getName(), $version->getStatus()->label(), $pair?->projectDirectionId, $pair?->responsibilityCenterId, $pairLabel, $this->clock->now(), $file);
        } catch (\Throwable $exception) {
            unlink($file);

            throw $exception;
        }

        $response = new BinaryFileResponse($file);
        $response->deleteFileAfterSend(true);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->setContentDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            sprintf('budget_%d_%s.xlsx', $version->getYear(), substr($version->getId(), -8)),
        );
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

        return $response;
    }
}
