<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\DTO\CashTransactionFilters;
use App\Cash\Infrastructure\Export\CashTransactionXlsxExporter;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/finance/cash-transactions/export', name: 'cash_transaction_export', methods: ['GET'])]
final class CashTransactionExportController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CashTransactionXlsxExporter $exporter,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        try {
            $filters = CashTransactionFilters::fromQuery($request->query->all());
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('cash_transaction_index');
        }

        $from = $this->isoDateOrNull($filters['dateFrom']);
        $to = $this->isoDateOrNull($filters['dateTo']);

        if ((null !== $filters['dateFrom'] && null === $from) || (null !== $filters['dateTo'] && null === $to)) {
            $this->addFlash('danger', 'Период указан в неверном формате.');

            return $this->redirectToRoute('cash_transaction_index');
        }

        $file = tempnam(sys_get_temp_dir(), 'cash_tx_export_');
        if (false === $file) {
            throw new \RuntimeException('Unable to create temporary file for export');
        }

        // Файл собираем до ответа: ошибка выборки даст обычную страницу ошибки,
        // а не оборванную середину уже начавшегося скачивания.
        try {
            $this->exporter->export($company, $filters, $file);
        } catch (\Throwable $e) {
            unlink($file);

            throw $e;
        }

        $response = new BinaryFileResponse($file);
        $response->deleteFileAfterSend(true);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->setContentDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $this->buildFilename($from, $to),
        );
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

        return $response;
    }

    private function buildFilename(?string $from, ?string $to): string
    {
        if (null !== $from && null !== $to) {
            return sprintf('cash-transactions_%s_%s.xlsx', $from, $to);
        }

        return sprintf('cash-transactions_%s.xlsx', (new \DateTimeImmutable())->format('Y-m-d'));
    }

    private function isoDateOrNull(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : null;
    }
}
