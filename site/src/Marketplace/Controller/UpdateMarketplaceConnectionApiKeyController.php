<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\MarketplaceApiException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Infrastructure\Api\Ozon\OzonCredentialValidationStatus;
use App\Marketplace\Infrastructure\Api\Ozon\OzonSellerCredentialValidatorInterface;
use App\Marketplace\Infrastructure\Api\Wildberries\WbFinanceSalesReportClient;
use App\Marketplace\Infrastructure\Security\ConnectionApiKeyCodec;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\AppLogger;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace')]
#[IsGranted('ROLE_USER')]
final class UpdateMarketplaceConnectionApiKeyController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly MarketplaceConnectionRepository $connectionRepository,
        private readonly OzonSellerCredentialValidatorInterface $ozonCredentialValidator,
        private readonly WbFinanceSalesReportClient $wbFinanceSalesReportClient,
        private readonly ConnectionApiKeyCodec $connectionApiKeyCodec,
        private readonly EntityManagerInterface $em,
        private readonly AppLogger $logger,
    ) {
    }

    #[Route(
        '/connection/{id}/api-key',
        name: 'marketplace_connection_api_key_update',
        methods: ['POST'],
        requirements: ['id' => Requirement::UUID],
    )]
    #[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
    public function __invoke(string $id, Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        $connection = $this->connectionRepository->findByIdAndCompany($id, $company);

        if (null === $connection) {
            throw $this->createNotFoundException('Подключение не найдено');
        }

        if (!$this->isCsrfTokenValid('marketplace_connection_api_key_update'.$id, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Недействительный CSRF-токен');
        }

        if (MarketplaceConnectionType::SELLER !== $connection->getConnectionType()
            || !in_array($connection->getMarketplace(), [MarketplaceType::OZON, MarketplaceType::WILDBERRIES], true)
        ) {
            $this->addFlash('error', 'Обновление API-ключа для этого подключения не поддерживается.');

            return $this->redirectToRoute('marketplace_connection_edit', ['id' => $id]);
        }

        $apiKey = trim((string) $request->request->get('api_key', ''));
        if ('' === $apiKey) {
            $this->addFlash('error', 'Введите новый API-ключ.');

            return $this->redirectToRoute('marketplace_connection_edit', ['id' => $id]);
        }

        if (MarketplaceType::OZON === $connection->getMarketplace()) {
            $result = $this->ozonCredentialValidator->validate($connection->getClientId(), $apiKey);
            if (!$result->isValid()) {
                $message = match ($result->status) {
                    OzonCredentialValidationStatus::INVALID_CREDENTIALS => $result->message,
                    OzonCredentialValidationStatus::RATE_LIMITED => 'Ozon ограничил количество запросов. Повторите позже.',
                    default => 'Ozon API временно недоступен. Новый ключ не сохранён.',
                };
                $this->addFlash('error', $message);

                return $this->redirectToRoute('marketplace_connection_edit', ['id' => $id]);
            }
        } elseif (MarketplaceType::WILDBERRIES === $connection->getMarketplace()) {
            $storedSellerId = $this->wbSellerId($this->connectionApiKeyCodec->apiKeyFor($connection));
            $newSellerId = $this->wbSellerId($apiKey);
            if (null === $storedSellerId || null === $newSellerId) {
                $this->addFlash('error', 'Не удалось определить ID продавца Wildberries. Новый ключ не сохранён.');

                return $this->redirectToRoute('marketplace_connection_edit', ['id' => $id]);
            }

            if ($storedSellerId !== $newSellerId) {
                $this->addFlash('error', 'Новый API-ключ принадлежит другому магазину Wildberries.');

                return $this->redirectToRoute('marketplace_connection_edit', ['id' => $id]);
            }

            try {
                $isValid = $this->wbFinanceSalesReportClient->probeAccess(
                    $apiKey,
                    $this->wbFinanceSalesReportClient->resolveSalesReportsBucketId($connection),
                );
            } catch (MarketplaceRateLimitException) {
                $this->addFlash('error', 'Wildberries ограничил количество запросов. Повторите позже.');

                return $this->redirectToRoute('marketplace_connection_edit', ['id' => $id]);
            } catch (MarketplaceApiException) {
                $this->addFlash('error', 'Wildberries API временно недоступен. Новый ключ не сохранён.');

                return $this->redirectToRoute('marketplace_connection_edit', ['id' => $id]);
            }

            if (!$isValid) {
                $this->addFlash('error', 'Wildberries отклонил новый API-ключ.');

                return $this->redirectToRoute('marketplace_connection_edit', ['id' => $id]);
            }
        } else {
            $this->addFlash('error', 'Обновление API-ключа для этого подключения не поддерживается.');

            return $this->redirectToRoute('marketplace_connection_edit', ['id' => $id]);
        }

        $this->connectionApiKeyCodec->applyApiKey($connection, $apiKey);
        $connection->setIsActive(true);
        $connection->setLastSyncError(null);
        $this->em->flush();

        $this->logger->info('Marketplace connection API key updated.', [
            'company_id' => (string) $company->getId(),
            'connection_id' => $connection->getId(),
            'marketplace' => $connection->getMarketplace()->value,
            'connection_type' => $connection->getConnectionType()->value,
        ]);

        $this->addFlash('success', 'API-ключ обновлён. Подключение активно.');

        return $this->redirectToRoute('marketplace_connections_index');
    }

    private function wbSellerId(string $token): ?string
    {
        $parts = explode('.', trim($token));
        if (3 !== count($parts) || '' === $parts[1]) {
            return null;
        }

        $payload = strtr($parts[1], '-_', '+/');
        $padding = strlen($payload) % 4;
        if (0 !== $padding) {
            $payload .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($payload, true);
        if (false === $decoded) {
            return null;
        }

        try {
            $claims = json_decode($decoded, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $sellerId = is_array($claims) ? ($claims['sid'] ?? null) : null;

        return is_string($sellerId) && Uuid::isValid($sellerId) ? strtolower($sellerId) : null;
    }
}
