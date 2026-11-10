<?php

declare(strict_types=1);

namespace App\Cash\Controller\Import;

use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Message\Import\CashFileImportMessage;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\Storage\ObjectStorageException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash/import/file')]
final class CashFileImportCommitController extends AbstractController
{
    public function __construct(
        private readonly \App\Cash\Http\CashImportSession $importSession,
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly \App\Cash\Http\CashFileImportPage $page,
        private readonly \App\Cash\Application\ConfirmCashFileImportAction $confirmImport,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route('/commit', name: 'cash_file_import_commit', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(Request $request): Response
    {
        $session = $request->getSession();
        if (!$this->isCsrfTokenValid('cash_file_import_commit', (string) $request->request->get('_commit_token', $request->request->get('_token')))) {
            $this->addFlash('error', 'Некорректный CSRF-токен.');

            return $this->redirectToRoute('cash_file_import_preview');
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
        if (!is_string($fileHash) || '' === $fileHash) {
            $this->addFlash('error', 'Не удалось определить файл импорта.');

            return $this->redirectToRoute('cash_file_import_upload');
        }
        $fileName = $importPayload['file_name'] ?? null;
        if (!is_string($fileName) || '' === $fileName) {
            $this->addFlash('error', 'Не удалось определить имя файла.');

            return $this->redirectToRoute('cash_file_import_upload');
        }
        $storedExtension = $importPayload['stored_ext'] ?? null;
        $account = $importState->account;

        $userIdentifier = $this->page->actorId($this->getUser());
        $planHash = $importPayload['description_plan'] ?? null;
        if (!is_string($planHash)) {
            $this->addFlash('error', 'Сначала просмотрите план импорта.');

            return $this->redirectToRoute('cash_file_import_preview');
        }
        try {
            $job = ($this->confirmImport)($account, $fileName, $fileHash, $mapping, is_string($storedExtension) ? $storedExtension : null, $userIdentifier, $planHash, $importPayload['selected_descriptions'] ?? [], $request->request->all('visible'), $request->request->all('selected'));
        } catch (\App\Cash\Exception\InvalidCashFileMappingException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('cash_file_import_mapping');
        } catch (\App\Cash\Exception\CashFileImportSelectionException $exception) {
            throw $this->createAccessDeniedException($exception->getMessage());
        } catch (\App\Cash\Exception\CashFileImportConfirmationException $exception) {
            $this->page->invalidateDescriptionPlan($session, $importPayload);
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('cash_file_import_preview');
        } catch (\DomainException) {
            $this->page->invalidateDescriptionPlan($session, $importPayload);
            $this->addFlash('error', 'План импорта устарел или повреждён. Проверьте новый план и повторно выберите обновления.');

            return $this->redirectToRoute('cash_file_import_preview');
        } catch (ObjectStorageException) {
            $this->page->invalidateDescriptionPlan($session, $importPayload);
            $this->addFlash('error', 'План импорта недоступен. Загрузите файл заново.');

            return $this->redirectToRoute('cash_file_import_upload');
        }
        $importPayload['selected_descriptions'] = $job->getOptions()['selected_descriptions'] ?? [];
        if (CashFileImportJob::STATUS_QUEUED === $job->getStatus()) {
            $this->messageBus->dispatch(new CashFileImportMessage((string) $job->getId()));
        }
        $importPayload['committed_job_id'] = $job->getId();
        $session->set('cash_file_import', $importPayload);
        if (CashFileImportJob::STATUS_QUEUED !== $job->getStatus()) {
            return $this->redirectToRoute('cash_file_import_result', ['id' => $job->getId()]);
        }

        return $this->render('cash/file_import_queued.html.twig', ['jobId' => $job->getId(), 'importLogId' => $job->getImportLog()?->getId()]);
    }
}
