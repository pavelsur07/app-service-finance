<?php

declare(strict_types=1);

namespace App\Company\Api\Response;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'AccountSessionResponse', required: ['user', 'active_company', 'permissions'], properties: [
    new OA\Property(property: 'user', type: 'object', required: ['id', 'email'], properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'email', type: 'string', format: 'email')]),
    new OA\Property(property: 'active_company', type: 'object', nullable: true, required: ['id', 'name'], properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'name', type: 'string')]),
    new OA\Property(property: 'permissions', type: 'object', required: ['finance', 'marketplace', 'deals', 'catalog', 'admin'], properties: [
        new OA\Property(property: 'finance', type: 'string', enum: ['none', 'read', 'write']),
        new OA\Property(property: 'marketplace', type: 'string', enum: ['none', 'read', 'write']),
        new OA\Property(property: 'deals', type: 'string', enum: ['none', 'read', 'write']),
        new OA\Property(property: 'catalog', type: 'string', enum: ['none', 'read', 'write']),
        new OA\Property(property: 'admin', type: 'string', enum: ['none', 'read', 'write']),
    ]),
])]
final readonly class AccountSessionResponse
{
    /**
     * @param array{id: string, name: string}|null $activeCompany
     * @param array<string, string> $permissions
     */
    public function __construct(private string $id, private string $email, private ?array $activeCompany, private array $permissions)
    {
    }

    /** @return array{user: array{id: string, email: string}, active_company: array{id: string, name: string}|null, permissions: array<string, string>} */
    public function toArray(): array
    {
        return ['user' => ['id' => $this->id, 'email' => $this->email], 'active_company' => $this->activeCompany, 'permissions' => $this->permissions];
    }
}
