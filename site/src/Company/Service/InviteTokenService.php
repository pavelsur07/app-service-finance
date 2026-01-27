<?php

namespace App\Company\Service;

class InviteTokenService
{
    public function generatePlainToken(): string
    {
        return \bin2hex(\random_bytes(32));
    }

    public function hashToken(string $plainToken): string
    {
        return \hash('sha256', $plainToken);
    }
}
