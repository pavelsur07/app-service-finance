<?php

declare(strict_types=1);

namespace App\BankSync\Controller;

use App\BankSync\Application\DTO\BankConnectionFormData;
use App\BankSync\Enum\BankCode;
use App\BankSync\Infrastructure\OAuth\BankAuthorizationProviderRegistry;
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
        private readonly BankAuthorizationProviderRegistry $authorizationProviders,
    ) {
    }

    #[Route('/bank-connections/', name: 'bank_sync_connection_index', methods: ['GET'])]
    public function __invoke(): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();

        return $this->render('@BankSync/connection/index.html.twig', [
            'connections' => $this->connections->findByCompanyId((string) $company->getId()),
            // Вход через банк — только у банков с провайдером авторизации; полноценный UI — Stage 10.
            'oauth_banks' => array_values(array_filter(BankCode::cases(), $this->authorizationProviders->has(...))),
            'oauth_start_csrf_id' => OAuthStartController::CSRF_ID,
            'production_url' => BankConnectionFormData::PRODUCTION_URL,
        ]);
    }
}
