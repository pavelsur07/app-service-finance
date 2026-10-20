<?php

declare(strict_types=1);

namespace App\Finance\Exception;

final class DefaultPLTreeTemplateNotFoundException extends \RuntimeException
{
    public static function at(string $path): self
    {
        return new self(\sprintf('Default P&L tree template not found: %s', $path));
    }

    public static function forProfile(string $profile): self
    {
        return new self(\sprintf('Default P&L tree template is not registered for business profile %s', $profile));
    }
}
