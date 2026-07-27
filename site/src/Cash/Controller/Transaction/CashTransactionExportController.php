<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\DTO\CashTransactionFilters;
use App\Cash\Infrastructure\Export\CashTransactionXlsxExporter;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
        $filters = CashTransactionFilters::fromQuery($request->query->all());

        $exporter = $this->exporter;
        $response = new StreamedResponse(static function () use ($exporter, $company, $filters): void {
            $tmpFile = tempnam(sys_get_temp_dir(), 'cash_tx_export_');
            if (false === $tmpFile) {
                throw new \RuntimeException('Unable to create temporary file for export');
            }

            try {
                $exporter->export($company, $filters, $tmpFile);
                readfile($tmpFile);
            } finally {
                if (file_exists($tmpFile)) {
                    unlink($tmpFile);
                }
            }
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="%s"', $this->buildFilename($filters)),
        );
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * @param array<string, string|null> $filters
     */
    private function buildFilename(array $filters): string
    {
        $from = $this->isoDateOrNull($filters['dateFrom']);
        $to = $this->isoDateOrNull($filters['dateTo']);

        if (null !== $from && null !== $to) {
            return sprintf('cash-transactions_%s_%s.xlsx', $from, $to);
        }

        return sprintf('cash-transactions_%s.xlsx', (new \DateTimeImmutable())->format('Y-m-d'));
    }

    /**
     * Значение попадает в заголовок Content-Disposition, поэтому в имя файла
     * пускаем только строгую дату, а не произвольную строку из query string.
     */
    private function isoDateOrNull(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : null;
    }
}
