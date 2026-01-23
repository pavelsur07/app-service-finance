<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Controller;

use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;
use App\Marketplace\Wildberries\Form\WildberriesCommissionerReportUploadType;
use App\Marketplace\Wildberries\Message\WbCommissionerXlsxImportMessage;
use App\Marketplace\Wildberries\Service\CommissionerReport\WbCommissionerXlsxFormatValidator;
use App\Marketplace\Wildberries\Service\WildberriesWeeklyPnlGenerator;
use App\Repository\PLCategoryRepository;
use App\Service\ActiveCompanyService;
use App\Service\Storage\StorageService;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/marketplace/wb/commissioner-reports', name: 'wb_commissioner_reports_')]
final class WildberriesCommissionerReportController extends AbstractController
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ActiveCompanyService $companyContext,
        private readonly StorageService $storageService,
        private readonly WbCommissionerXlsxFormatValidator $formatValidator,
        private readonly MessageBusInterface $bus,
        private readonly WildberriesWeeklyPnlGenerator $weeklyPnlGenerator,
        private readonly PLCategoryRepository $plCategoryRepository,
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
                $validationResult = $this->formatValidator->validate(
                    $this->storageService->getAbsolutePath($stored['storagePath'])
                );

                $report = new WildberriesCommissionerXlsxReport($reportId, $company, new \DateTimeImmutable());
                $report->setPeriodStart($data['periodStart']);
                $report->setPeriodEnd($data['periodEnd']);
                $report->setStoragePath($stored['storagePath']);
                $report->setFileHash($stored['fileHash']);
                $report->setOriginalFilename($stored['originalFilename']);
                $report->setHeadersHash($validationResult->headersHash);
                $report->setFormatStatus($validationResult->status);
                $report->setWarningsJson([] !== $validationResult->warnings ? $validationResult->warnings : null);
                $report->setErrorsJson([] !== $validationResult->errors ? $validationResult->errors : null);
                $report->setWarningsCount(count($validationResult->warnings));
                $report->setErrorsCount(count($validationResult->errors));
                $report->setStatus('uploaded');
                if (WbCommissionerXlsxFormatValidator::STATUS_FAILED === $validationResult->status) {
                    $report->setStatus('failed');
                }

                $em = $this->doctrine->getManager();
                $em->persist($report);
                $em->flush();

                if (WbCommissionerXlsxFormatValidator::STATUS_FAILED === $validationResult->status) {
                    $this->addFlash(
                        'danger',
                        'Отчёт загружен, но формат не распознан. Открой карточку отчёта и посмотри ошибки валидации.'
                    );
                } else {
                    $this->addFlash('success', 'Отчёт загружен и сохранён.');
                }

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

        $aggregated = $this->weeklyPnlGenerator->aggregateForImportId($company, $report->getId());
        $categories = $this->plCategoryRepository->findTreeByCompany($company);
        $categoryById = [];

        foreach ($categories as $category) {
            $categoryById[(string) $category->getId()] = $category;
        }

        return $this->render('wildberries/commissioner_report/show.html.twig', [
            'report' => $report,
            'totals' => $aggregated['totals'],
            'unmapped' => $aggregated['unmapped'],
            'hasUnmapped' => [] !== $aggregated['unmapped'],
            'categoryById' => $categoryById,
        ]);
    }

    #[Route(path: '/{id}/process', name: 'process', methods: ['POST'])]
    public function process(Request $request, string $id): Response
    {
        $company = $this->companyContext->getActiveCompany();
        $report = $this->doctrine->getRepository(WildberriesCommissionerXlsxReport::class)
            ->findOneBy(['id' => $id, 'company' => $company]);

        if (!$report instanceof WildberriesCommissionerXlsxReport) {
            throw $this->createNotFoundException('Отчёт не найден.');
        }

        if (!$this->isCsrfTokenValid('wb_commissioner_report_process'.$report->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Неверный CSRF-токен при запуске обработки.');

            return $this->redirectToRoute('wb_commissioner_reports_show', ['id' => $report->getId()]);
        }

        $this->bus->dispatch(new WbCommissionerXlsxImportMessage((string) $company->getId(), $report->getId()));

        $this->addFlash('success', 'Отчёт отправлен на обработку.');

        return $this->redirectToRoute('wb_commissioner_reports_show', ['id' => $report->getId()]);
    }
}
