<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\OutsideRoot\Form;

use App\Tests\Unit\PHPStan\data\ForeignEntityType\ModuleB\Entity\Category;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\FormBuilderInterface;

/** Модуль формы и сущности разные, но форма вне корня правила — молчим. */
final class OutsideType
{
    /**
     * @param FormBuilderInterface<mixed> $builder
     */
    public function buildForm(FormBuilderInterface $builder): void
    {
        $builder->add('category', EntityType::class, ['class' => Category::class]);
    }
}
