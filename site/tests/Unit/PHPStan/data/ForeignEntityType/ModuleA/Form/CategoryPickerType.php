<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\ForeignEntityType\ModuleA\Form;

use Symfony\Bridge\Doctrine\Form\Type\EntityType;

/** Тип-обёртка над EntityType (до этапа 7.5b плана Cash так был устроен ProjectDirectionPickerType). */
final class CategoryPickerType extends EntityType
{
}
