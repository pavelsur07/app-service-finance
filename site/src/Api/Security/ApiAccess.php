<?php

declare(strict_types=1);

namespace App\Api\Security;

/** Every external controller must declare a policy; absence is denied. */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class ApiAccess
{
    public const CONNECTION = 'auth.check';

    public function __construct(public string $scope)
    {
    }
}
