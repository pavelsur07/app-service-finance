<?php

declare(strict_types=1);

namespace App\Cash\Controller\Import;

use App\Cash\Application\ImportBank1CStatementAction;
use App\Cash\Exception\CashImportAccountBusyException;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash/import/bank1c')]
final class Bank1CImportCommitController extends AbstractController
{
    public function __construct(
        private readonly \App\Cash\Http\CashImportSession $importSession,
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly \App\Cash\Http\CashFileImportPage $filePage,
        private readonly ImportBank1CStatementAction $importStatement,
    ) {
    }

    #[Route('/commit', name: 'cash_bank1c_import_commit', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('bank1c_import_commit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $session = $request->getSession();
        $importState = $this->importSession->read($session, $this->activeCompanyService->getActiveCompany(), 'bank1c_import');
        if (null === $importState) {
            $this->addFlash('danger', 'Сессия импорта не найдена. Пожалуйста, загрузите файл заново.');

            return $this->redirectToRoute('cash_bank1c_import_upload', [], 303);
        }
        $state = $importState->payload;
        $preview = $state['preview'];
        if (!is_array($preview)) {
            $this->addFlash('danger', 'Данные для импорта повреждены. Пожалуйста, повторите загрузку файла.');

            return $this->redirectToRoute('cash_bank1c_import_upload', [], 303);
        }
        $account = $importState->account;
        $company = $account->getCompany();

        $overwrite = $request->request->getBoolean('overwrite_duplicates', false);
        $userIdentifier = $this->filePage->actorId($this->getUser());
        $fileName = is_string($state['file_name'] ?? null) ? $state['file_name'] : null;
        $statementAccount = is_string($state['statement_account'] ?? null) ? $state['statement_account'] : null;
        $periodStart = is_string($state['statement_period_start'] ?? null) ? $state['statement_period_start'] : null;
        $periodEnd = is_string($state['statement_period_end'] ?? null) ? $state['statement_period_end'] : null;
        try {
            $result = ($this->importStatement)($company, $preview, $account, $overwrite, $userIdentifier, $fileName, ['user' => $userIdentifier, 'file' => $fileName, 'statement_account' => $statementAccount, 'date_start' => $periodStart, 'date_end' => $periodEnd]);
        } catch (CashImportAccountBusyException|\DomainException $exception) {
            // Предпросмотр в сессии сохраняется: импорт можно повторить, когда счёт освободится.
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('cash_bank1c_import_preview', [], 303);
        }
        $session->remove('bank1c_import');

        return $this->render('cash/bank1c_import_result.html.twig', ['filename' => $fileName, 'selectedAccount' => $account, 'summary' => $result['summary'], 'importLog' => $result['importLog']]);
    }
}
