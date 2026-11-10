<?php

declare(strict_types=1);

namespace App\Cash\Controller\Import;

use App\Cash\Service\Accounts\AccountMasker;
use App\Shared\Infrastructure\Export\SpreadsheetText;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash/import/bank1c')]
final class Bank1CImportPreviewCsvController extends AbstractController
{
    public function __construct(
        private readonly \App\Cash\Http\CashImportSession $importSession,
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly AccountMasker $accountMasker,
        private readonly \App\Cash\Http\Bank1CImportPage $page,
    ) {
    }

    #[Route('/preview/csv', name: 'cash_bank1c_import_preview_csv', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $session = $request->getSession();
        $importState = $this->importSession->read($session, $this->activeCompanyService->getActiveCompany(), 'bank1c_import');
        if (null === $importState) {
            $this->addFlash('danger', 'Сессия предпросмотра не найдена. Пожалуйста, загрузите файл заново.');

            return $this->redirectToRoute('cash_bank1c_import_upload', [], 303);
        }
        $state = $importState->payload;
        $preview = is_array($state['preview']) ? $state['preview'] : [];
        $fileName = is_string($state['file_name'] ?? null) ? $state['file_name'] : 'bank1c';
        $response = new StreamedResponse(function () use ($preview) {
            $handle = fopen('php://output', 'w');
            if (false === $handle) {
                return;
            }
            // UTF-8 BOM
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Дата документа', 'Тип документа', 'Номер документа', 'Тип движения', 'Сумма', 'Назначение', 'Плательщик', 'ИНН плательщика', 'Счёт плательщика', 'Получатель', 'ИНН получателя', 'Счёт получателя', 'Статус контрагента'], ';', '"', '');
            foreach ($preview as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $direction = (string) ($row['direction'] ?? '');
                $amount = $row['amount'] ?? null;
                $amountValue = null;
                if (null !== $amount && is_numeric($amount)) {
                    $amountNumber = (float) $amount;
                    $sign = 'outflow' === $direction ? '-' : ('inflow' === $direction ? '+' : '');
                    $amountValue = $sign.number_format(abs($amountNumber), 2, '.', '');
                }
                fputcsv($handle, [
                    SpreadsheetText::safe((string) ($row['docDate'] ?? '')),
                    SpreadsheetText::safe((string) ($row['docType'] ?? '')),
                    SpreadsheetText::safe((string) ($row['docNumber'] ?? '')),
                    $direction,
                    $amountValue ?? '',
                    SpreadsheetText::safe((string) ($row['purpose'] ?? '')),
                    SpreadsheetText::safe((string) ($row['payerName'] ?? '')),
                    SpreadsheetText::safe((string) ($row['payerInn'] ?? '')),
                    // Тут можно оставить маску из AccountMasker, но она уже «чистая» после sanitizeAccountForView
                    $this->accountMasker->mask(is_string($row['payerAccount'] ?? null) ? $row['payerAccount'] : null),
                    SpreadsheetText::safe((string) ($row['receiverName'] ?? '')),
                    SpreadsheetText::safe((string) ($row['receiverInn'] ?? '')),
                    $this->accountMasker->mask(is_string($row['receiverAccount'] ?? null) ? $row['receiverAccount'] : null),
                    SpreadsheetText::safe((string) ($row['counterpartyStatus'] ?? '')),
                ], ';', '"', '');
            }
            fclose($handle);
        });
        $downloadName = $this->page->generateDownloadName($fileName);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$downloadName.'"');

        return $response;
    }
}
