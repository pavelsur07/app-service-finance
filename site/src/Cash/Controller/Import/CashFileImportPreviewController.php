<?php

declare(strict_types=1);

namespace App\Cash\Controller\Import;

use App\Cash\Service\Import\File\CashFileDescriptionPlan;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\Storage\ObjectStorageException;
use App\Shared\Service\Storage\ObjectStorageInterface;
use Pagerfanta\Adapter\ArrayAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash/import/file')]
final class CashFileImportPreviewController extends AbstractController
{
    public function __construct(
        private readonly \App\Cash\Http\CashImportSession $importSession,
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly \App\Cash\Http\CashFileImportPage $page,
        private readonly ObjectStorageInterface $storage,
        private readonly \App\Cash\Application\PrepareCashFileImportAction $prepareImport,
        private readonly CashFileDescriptionPlan $plans,
    ) {
    }

    #[Route('/preview', name: 'cash_file_import_preview', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $session = $request->getSession();
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }
        $importState = $this->importSession->read($session, $this->activeCompanyService->getActiveCompany());
        if (null === $importState) {
            $this->addFlash('error', 'Сессия импорта устарела или относится к другому счёту/компании. Загрузите файл заново.');

            return $this->redirectToRoute('cash_file_import_upload');
        }
        $importPayload = $importState->payload;
        $committedJobId = $importPayload['committed_job_id'] ?? null;
        if (is_string($committedJobId)) {
            return $this->redirectToRoute('cash_file_import_result', ['id' => $committedJobId]);
        }
        $mapping = $importPayload['mapping'] ?? null;
        if (!is_array($mapping) || [] === $mapping) {
            $this->addFlash('error', 'Сначала настройте маппинг колонок.');

            return $this->redirectToRoute('cash_file_import_mapping');
        }
        $fileHash = $importPayload['file_hash'] ?? null;
        $storedExtension = $importPayload['stored_ext'] ?? null;
        if (!$fileHash || !is_string($fileHash)) {
            $this->addFlash('error', 'Не удалось определить файл импорта.');

            return $this->redirectToRoute('cash_file_import_upload');
        }
        $account = $importState->account;

        $storageKey = \App\Cash\Service\Import\File\CashFileImportService::storageKey($fileHash, is_string($storedExtension) ? $storedExtension : '');
        if (!$this->storage->exists($storageKey)) {
            $this->addFlash('error', 'Файл импорта не найден. Загрузите файл заново.');

            return $this->redirectToRoute('cash_file_import_upload');
        }
        $actor = $this->page->actorId($this->getUser());
        try {
            $prepared = ($this->prepareImport)($account, $fileHash, $storageKey, $mapping, $actor, is_string($importPayload['description_context'] ?? null) ? $importPayload['description_context'] : null, is_string($importPayload['description_plan'] ?? null) ? $importPayload['description_plan'] : null);
            $plan = $prepared['plan'];
            $importPayload['description_plan'] = $prepared['hash'];
            $importPayload['description_context'] = $prepared['context'];
            if ($prepared['changed']) {
                $importPayload['selected_descriptions'] = [];
            }
        } catch (\App\Cash\Exception\InvalidCashFileMappingException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('cash_file_import_mapping');
        } catch (\DomainException $exception) {
            $this->page->invalidateDescriptionPlan($session, $importPayload);
            if ($exception->getPrevious() instanceof \JsonException) {
                $this->addFlash('error', 'Файл содержит некорректный текст. Проверьте кодировку UTF-8 и загрузите его заново.');

                return $this->redirectToRoute('cash_file_import_upload');
            }
            $this->addFlash('error', 'План импорта устарел или повреждён. Проверьте новый план и повторно выберите обновления.');

            return $this->redirectToRoute('cash_file_import_preview');
        } catch (ObjectStorageException) {
            $this->page->invalidateDescriptionPlan($session, $importPayload);
            $this->addFlash('error', 'Не удалось прочитать или сохранить план импорта. Загрузите файл заново.');

            return $this->redirectToRoute('cash_file_import_upload');
        }
        if ($request->isMethod('POST') && !$this->isCsrfTokenValid('cash_file_import_selection', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Некорректный CSRF-токен.');
        }
        try {
            $selection = $this->plans->selectDescriptions($plan, $importPayload['selected_descriptions'] ?? [], $request->isMethod('POST') ? $request->request->getString('selection_action') : '', $request->isMethod('POST') ? $request->request->all('visible') : [], $request->isMethod('POST') ? $request->request->all('selected') : []);
        } catch (\App\Cash\Exception\CashFileImportSelectionException $exception) {
            throw $this->createAccessDeniedException($exception->getMessage());
        }
        $selected = array_fill_keys($selection, true);
        $filter = $request->request->getString('filter', $request->query->getString('filter'));
        $filterChanged = $filter !== ($importPayload['description_filter'] ?? '');
        $importPayload['description_filter'] = $filter;
        $importPayload['selected_descriptions'] = array_map('strval', array_keys($selected));
        $session->set('cash_file_import', $importPayload);
        $summary = array_fill_keys(['create', 'unchanged', 'update_description', 'conflict', 'invalid'], 0);
        $previewRows = [];
        foreach ($plan['rows'] as $rowId => $row) {
            ++$summary[$row['action']];
            if ('' === $filter || $filter === $row['action']) {
                $previewRows[] = $row + ['rowId' => (string) $rowId, 'selected' => isset($selected[$rowId])];
            }
        }
        $page = $filterChanged ? 1 : max(1, $request->request->getInt('page', $request->query->getInt('page', 1)));
        $pager = new Pagerfanta(new ArrayAdapter($previewRows));
        $pager->setMaxPerPage(50);
        $pager->setNormalizeOutOfRangePages(true);
        $pager->setCurrentPage($page);

        return $this->render('cash/file_import_preview.html.twig', ['fileName' => $importPayload['file_name'] ?? '', 'previewRows' => $pager->getCurrentPageResults(), 'pager' => $pager, 'summary' => $summary, 'filter' => $filter, 'selectedCount' => count($selected)]);
    }
}
