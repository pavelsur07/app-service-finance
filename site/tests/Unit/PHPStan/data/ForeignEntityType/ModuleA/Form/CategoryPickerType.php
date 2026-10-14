<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\ForeignEntityType\ModuleA\Form;

use Symfony\Bridge\Doctrine\Form\Type\EntityType;

/** Тип-обёртка над EntityType, как ProjectDirectionPickerType. */
final class CategoryPickerType extends EntityType
{
}
