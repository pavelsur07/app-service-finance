<?php

declare(strict_types=1);

namespace App\BankSync\Controller;

use App\BankSync\Repository\BankConnectionRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ConnectionIndexController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly BankConnectionRepository $connections,
    ) {
    }

    #[Route('/bank-connections/', name: 'bank_sync_connection_index', methods: ['GET'])]
    public function __invoke(): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();

        return $this->render('@BankSync/connection/index.html.twig', [
            'connections' => $this->connections->findByCompanyId((string) $company->getId()),
        ]);
    }
}
