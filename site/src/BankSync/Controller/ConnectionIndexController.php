<?php

declare(strict_types=1);

namespace App\BankSync\Controller;

use App\BankSync\Application\DTO\BankConnectionFormData;
use App\BankSync\Domain\Consent\ConsentExpiryPolicy;
use App\BankSync\Enum\BankCode;
use App\BankSync\Infrastructure\OAuth\BankAuthorizationProviderRegistry;
use App\BankSync\Repository\BankAccountRepository;
use App\BankSync\Repository\BankConnectionRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
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
        private readonly ClockInterface $clock,
        private readonly BankAccountRepository $accounts,
    ) {
    }

    #[Route('/bank-connections/', name: 'bank_sync_connection_index', methods: ['GET'])]
    public function __invoke(): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        // Кнопка входа — на каждую настроенную среду банка: на проде без пары приложения её нет.
        // Песочница — только администратору (проверка и демонстрация банку).
        $sandboxAllowed = $this->isGranted(OAuthStartController::SANDBOX_ROLE);
        $oauthStarts = [];
        foreach (BankCode::cases() as $bankCode) {
            if (!$this->authorizationProviders->has($bankCode)) {
                continue;
            }
            $environments = $this->authorizationProviders->get($bankCode)->environments();
            foreach ([BankConnectionFormData::PRODUCTION_URL, BankConnectionFormData::SANDBOX_URL] as $baseUrl) {
                if (BankConnectionFormData::SANDBOX_URL === $baseUrl && !$sandboxAllowed) {
                    continue;
                }
                if (\in_array($baseUrl, $environments, true)) {
                    $oauthStarts[] = [
                        'bank_code' => $bankCode->value,
                        'base_url' => $baseUrl,
                        'label' => BankConnectionFormData::SANDBOX_URL === $baseUrl ? $bankCode->label().' (песочница)' : $bankCode->label(),
                    ];
                }
            }
        }

        return $this->render('@BankSync/connection/index.html.twig', [
            'connections' => $this->connections->findByCompanyId((string) $company->getId()),
            // Счета банка без денежного счёта: выписка по ним не загружается.
            'unlinked_accounts' => $this->accounts->countUnlinkedByConnection((string) $company->getId()),
            // Полноценный UI — Stage 10.
            'oauth_starts' => $oauthStarts,
            // Переподключение — только там, где вход в эту среду банка настроен.
            'oauth_environments' => array_map(static fn (array $start): string => $start['bank_code'].'|'.$start['base_url'], $oauthStarts),
            'oauth_start_csrf_id' => OAuthStartController::CSRF_ID,
            'can_add_with_token' => [] !== array_filter(BankCode::cases(), static fn (BankCode $code): bool => $code->supportsStaticToken()),
            // Согласие кончается через 30 дней и меньше — клиенту пора войти заново.
            'consent_warning_until' => $this->clock->now()->modify(sprintf('+%d days', ConsentExpiryPolicy::WARNING_DAYS)),
        ]);
    }
}
