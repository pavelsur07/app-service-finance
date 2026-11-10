<?php

declare(strict_types=1);

namespace App\Cash\Controller\Import;

use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash/import/bank1c')]
final class Bank1CImportPreviewController extends AbstractController
{
    public function __construct(
        private readonly \App\Cash\Http\CashImportSession $importSession,
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly \App\Cash\Http\Bank1CImportPage $page,
    ) {
    }

    /**
     * GET превью. Читает state из сессии и рендерит таблицу.
     */
    #[Route('/preview', name: 'cash_bank1c_import_preview', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $session = $request->getSession();
        $importState = $this->importSession->read($session, $this->activeCompanyService->getActiveCompany(), 'bank1c_import');
        if (null === $importState) {
            $this->addFlash('danger', 'Сессия предпросмотра не найдена. Пожалуйста, загрузите файл заново.');

            return $this->redirectToRoute('cash_bank1c_import_upload', [], 303);
        }
        $state = $importState->payload;
        $selectedAccount = $importState->account;

        $preview = is_array($state['preview']) ? $state['preview'] : [];
        $totalRows = count($preview);
        $perPage = 100;
        $page = max(1, (int) $request->query->get('page', 1));
        $pages = (int) ceil($totalRows / $perPage);
        if ($pages < 1) {
            $pages = 1;
        }
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;
        $previewRows = array_slice($preview, $offset, $perPage);
        $pager = null;
        if ($totalRows > $perPage) {
            $pager = ['current' => $page, 'pages' => $pages, 'hasPrevious' => $page > 1, 'hasNext' => $page < $pages, 'previous' => max(1, $page - 1), 'next' => min($pages, $page + 1)];
        }
        // Чистый номер счёта для отображения в заголовке превью
        $statementAccountDisplay = is_string($state['statement_account_display'] ?? null) ? $state['statement_account_display'] : (is_string($state['statement_account'] ?? null) ? $this->page->sanitizeAccountForView($state['statement_account']) : null);

        return $this->render('cash/bank1c_import_preview.html.twig', ['filename' => is_string($state['file_name'] ?? null) ? $state['file_name'] : null, 'selectedAccount' => $selectedAccount, 'statementAccountDisplay' => $statementAccountDisplay, 'previewRows' => $previewRows, 'pager' => $pager, 'totalRows' => $totalRows, 'perPage' => $perPage]);
    }
}
