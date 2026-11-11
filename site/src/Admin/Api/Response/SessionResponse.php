<?php

declare(strict_types=1);

namespace App\Admin\Api\Response;

final readonly class SessionResponse
{
    public function __construct(private string $id, private string $email)
    {
    }

    /** @return array{user: array{id: string, email: string}, access: array{admin: true}} */
    public function toArray(): array
    {
        return ['user' => ['id' => $this->id, 'email' => $this->email], 'access' => ['admin' => true]];
    }
}
