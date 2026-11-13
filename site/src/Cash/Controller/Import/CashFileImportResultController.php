<?php

declare(strict_types=1);

namespace App\Cash\Controller\Import;

use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Application\Service\Import\File\CashFileDescriptionPlan;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\Storage\ObjectStorageException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash/import/file')]
final class CashFileImportResultController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly EntityManagerInterface $entityManager,
        private readonly CashFileDescriptionPlan $plans,
    ) {
    }

    #[Route('/result/{id}', name: 'cash_file_import_result', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(ModuleAccess::FINANCE_READ)]
    public function __invoke(string $id): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $job = $this->entityManager->getRepository(CashFileImportJob::class)->findOneBy(['id' => $id, 'company' => $company]);
        if (!$job instanceof CashFileImportJob) {
            throw $this->createNotFoundException('Импорт не найден.');
        }
        $options = $job->getOptions();
        $summary = array_fill_keys(['create', 'unchanged', 'update_description', 'conflict', 'invalid'], 0);
        if (is_string($options['description_plan'] ?? null)) {
            try {
                $plan = $this->plans->load((string) $company->getId(), $options['description_plan']);
                foreach ($plan['rows'] as $row) {
                    ++$summary[$row['action']];
                }
            } catch (ObjectStorageException|\DomainException) {
                $this->addFlash('error', 'Подробный план недоступен. Показаны сохранённые результаты обработки.');
            }
        }

        return $this->render('cash/file_import_result.html.twig', ['job' => $job, 'options' => $options, 'summary' => $summary]);
    }
}
