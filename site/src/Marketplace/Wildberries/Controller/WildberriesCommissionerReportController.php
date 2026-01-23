<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Controller;

use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;
use App\Marketplace\Wildberries\Form\WildberriesCommissionerReportUploadType;
use App\Service\ActiveCompanyService;
use App\Service\Storage\StorageService;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/marketplace/wb/commissioner-reports', name: 'wb_commissioner_reports_')]
final class WildberriesCommissionerReportController extends AbstractController
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ActiveCompanyService $companyContext,
        private readonly StorageService $storageService,
    ) {
    }

    #[Route(path: '', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $company = $this->companyContext->getActiveCompany();
        $reports = $this->doctrine->getRepository(WildberriesCommissionerXlsxReport::class)
            ->findBy(['company' => $company], ['createdAt' => 'DESC']);

        return $this->render('wildberries/commissioner_report/index.html.twig', [
            'reports' => $reports,
        ]);
    }

    #[Route(path: '/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $form = $this->createForm(WildberriesCommissionerReportUploadType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $file = $data['file'] ?? null;

            if ($file instanceof UploadedFile) {
                $company = $this->companyContext->getActiveCompany();
                $reportId = Uuid::uuid4()->toString();
                $storagePath = sprintf(
                    'marketplace/wildberries/commissioner-reports/%s/%s.xlsx',
                    $company->getId(),
                    $reportId
                );

                $stored = $this->storageService->storeUploadedFile($file, $storagePath);

                $report = new WildberriesCommissionerXlsxReport($reportId, $company, new \DateTimeImmutable());
                $report->setPeriodStart($data['periodStart']);
                $report->setPeriodEnd($data['periodEnd']);
                $report->setStoragePath($stored['storagePath']);
                $report->setFileHash($stored['fileHash']);
                $report->setOriginalFilename($stored['originalFilename']);
                $report->setStatus('uploaded');

                $em = $this->doctrine->getManager();
                $em->persist($report);
                $em->flush();

                $this->addFlash('success', 'Отчёт загружен и сохранён.');

                return $this->redirectToRoute('wb_commissioner_reports_show', ['id' => $reportId]);
            }

            $this->addFlash('danger', 'Файл не был загружен.');
        }

        return $this->render('wildberries/commissioner_report/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/{id}', name: 'show', methods: ['GET'])]
    public function show(string $id): Response
    {
        $company = $this->companyContext->getActiveCompany();
        $report = $this->doctrine->getRepository(WildberriesCommissionerXlsxReport::class)
            ->findOneBy(['id' => $id, 'company' => $company]);

        if (!$report instanceof WildberriesCommissionerXlsxReport) {
            throw $this->createNotFoundException('Отчёт не найден.');
        }

        return $this->render('wildberries/commissioner_report/show.html.twig', [
            'report' => $report,
        ]);
    }
}
