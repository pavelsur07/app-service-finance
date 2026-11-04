<?php

declare(strict_types=1);

namespace App\Budget\Controller;

use App\Budget\Application\DTO\ImportBudgetCellsCommand;
use App\Budget\Application\ImportBudgetCellsAction;
use App\Budget\Application\View\BudgetFillCell;
use App\Budget\Exception\InvalidBudgetInputException;
use App\Budget\Infrastructure\Xlsx\BudgetPlanXlsxReader;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Загрузка плана пары из xlsx (формат выгрузки «Excel»): multipart `file`, `project`, `center`,
 * `apply`, `confirmOverwrite` (`1` — да) → `{cells, changed, overwrite, skipped, applied, warnings,
 * ignored}`. Без `apply` — только предпросмотр. CSRF — заголовок `X-CSRF-Token` (`budget_cells`).
 * POST /finance/budget/versions/{id}/import.
 */
#[Route('/finance/budget/versions/{id}/import', name: 'budget_version_import', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final class ImportBudgetCellsController extends AbstractController
{
    public const int MAX_FILE_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly BudgetPlanXlsxReader $reader,
        private readonly ImportBudgetCellsAction $import,
    ) {
    }

    public function __invoke(string $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('budget_cells', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['error' => ['code' => 'invalid_csrf', 'message' => 'Сессия устарела — обновите страницу.']], 403);
        }
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();

        $upload = $request->files->get('file');
        if (!$upload instanceof UploadedFile || !$upload->isValid()) {
            throw new InvalidBudgetInputException('Выберите файл xlsx.');
        }
        if ((int) $upload->getSize() > self::MAX_FILE_BYTES) {
            throw new InvalidBudgetInputException('Файл больше 2 МБ.');
        }
        if ('xlsx' !== strtolower($upload->getClientOriginalExtension())) {
            throw new InvalidBudgetInputException('Нужен файл .xlsx — выгрузите план кнопкой «Excel».');
        }
        $file = $this->reader->read($upload->getPathname());

        $apply = '1' === $request->request->getString('apply');
        $plan = ($this->import)(new ImportBudgetCellsCommand(
            $companyId,
            $id,
            $request->request->getString('project'),
            $request->request->getString('center'),
            $file,
            $apply,
            '1' === $request->request->getString('confirmOverwrite'),
        ));

        return new JsonResponse([
            'cells' => array_map(static fn (BudgetFillCell $cell): array => [
                'plCategoryId' => $cell->plCategoryId,
                'month' => $cell->month,
                'amount' => $cell->amount,
                'previous' => $cell->previous,
            ], $plan->fill->cells),
            'changed' => $plan->fill->changed,
            'overwrite' => $plan->fill->overwrite,
            'skipped' => $plan->fill->skipped,
            'applied' => $apply && $plan->fill->changed > 0,
            'warnings' => $plan->warnings,
            'ignored' => $plan->ignored,
        ]);
    }
}
