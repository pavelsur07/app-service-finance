<?php

declare(strict_types=1);

namespace App\Cash\Controller\Import;

use App\Cash\Infrastructure\Export\CashImportTemplateXlsxWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash/import/file/template', name: 'cash_file_import_template', methods: ['GET'])]
final class CashFileImportTemplateController extends AbstractController
{
    private const FILENAME = 'cash-import-template.xlsx';

    public function __construct(
        private readonly CashImportTemplateXlsxWriter $templateWriter,
    ) {
    }

    public function __invoke(): Response
    {
        $file = tempnam(sys_get_temp_dir(), 'cash_import_template_');
        if (false === $file) {
            throw new \RuntimeException('Unable to create temporary file for import template');
        }

        try {
            $this->templateWriter->write($file);
        } catch (\Throwable $e) {
            unlink($file);

            throw $e;
        }

        $response = new BinaryFileResponse($file);
        $response->deleteFileAfterSend(true);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->setContentDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, self::FILENAME);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

        return $response;
    }
}
