<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/marketplace/reprocess', name: 'admin_marketplace_reprocess_')]
#[IsGranted('ROLE_ADMIN')]
final class MarketplaceReprocessController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $process = new Process([
            'php', 'bin/console', 'marketplace:costs:reprocess', '--dry-run', '--no-interaction',
        ]);
        $process->setWorkingDirectory($this->getParameter('kernel.project_dir'));
        $process->run();

        $output = $process->getOutput();
        $documents = $this->parseDryRunOutput($output);

        return $this->render('admin/marketplace/reprocess/index.html.twig', [
            'documents' => $documents,
            'rawOutput' => $output,
        ]);
    }

    #[Route('/run', name: 'run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        $csrfToken = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('admin_marketplace_reprocess', $csrfToken)) {
            throw $this->createAccessDeniedException('Недействительный CSRF токен.');
        }

        $companyId = trim((string) $request->request->get('company_id', ''));

        $command = ['php', 'bin/console', 'marketplace:costs:reprocess', '--no-interaction'];
        if ($companyId !== '') {
            $command[] = '--company-id=' . $companyId;
        }

        $process = new Process($command);
        $process->setWorkingDirectory($this->getParameter('kernel.project_dir'));
        $process->setTimeout(300);
        $process->run();

        if ($process->isSuccessful()) {
            $this->addFlash('success', 'Переобработка затрат завершена.' . "\n" . $process->getOutput());
        } else {
            $this->addFlash('error', 'Ошибка переобработки: ' . $process->getErrorOutput());
        }

        return $this->redirectToRoute('admin_marketplace_reprocess_index');
    }

    /**
     * @return array<int, array{id: string, company_id: string}>
     */
    private function parseDryRunOutput(string $output): array
    {
        $documents = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);
            // Table rows from SymfonyStyle: "| <uuid> | <uuid> | <date> | <date> |"
            if (!str_starts_with($line, '|') || str_contains($line, '---') || str_contains($line, 'Raw Document ID')) {
                continue;
            }

            $cells = array_map('trim', explode('|', $line));
            // After split: ['', cell1, cell2, cell3, cell4, '']
            $cells = array_values(array_filter($cells, static fn(string $s): bool => $s !== ''));

            if (count($cells) >= 2) {
                $documents[] = [
                    'id' => $cells[0],
                    'company_id' => $cells[1],
                ];
            }
        }

        return $documents;
    }
}
