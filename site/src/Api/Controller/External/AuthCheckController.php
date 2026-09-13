<?php

declare(strict_types=1);

namespace App\Api\Controller\External;

use App\Api\Application\RecordApiKeyUseAction;
use App\Api\Security\ApiAccess;
use App\Api\Security\ApiPrincipal;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[OA\Tag(name: 'External API')]
final readonly class AuthCheckController
{
    public function __construct(private RecordApiKeyUseAction $recordUse)
    {
    }

    #[Route('/api/external/v1/auth/check', name: 'external_api_auth_check', methods: ['GET'])]
    #[ApiAccess(ApiAccess::CONNECTION)]
    #[OA\Get(summary: 'Проверка Bearer-ключа без cookies', security: [['ExternalBearer' => []]])]
    #[OA\Parameter(name: 'X-Company-Id', in: 'header', required: true, schema: new OA\Schema(type: 'integer', example: 100025))]
    #[OA\Response(response: 200, description: 'Действующий ключ', content: new OA\JsonContent(properties: [
        new OA\Property(property: 'company_id', type: 'integer'),
        new OA\Property(property: 'key_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'prepared_scopes', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'effective_scopes', type: 'array', items: new OA\Items(type: 'string')),
    ]))]
    #[OA\Response(response: 401, description: 'Отсутствующий или недействующий ключ; WWW-Authenticate: Bearer', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')))]
    #[OA\Response(response: 403, description: 'Ключ другой компании', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')))]
    #[OA\Response(response: 422, description: 'Неверный X-Company-Id', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')))]
    #[OA\Response(response: 429, description: 'Лимит запросов', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')))]
    public function __invoke(#[CurrentUser] ApiPrincipal $principal): JsonResponse
    {
        ($this->recordUse)($principal->companyId, $principal->keyId);

        return new JsonResponse($principal->connectionDetails(), headers: ['Cache-Control' => 'no-store']);
    }
}
