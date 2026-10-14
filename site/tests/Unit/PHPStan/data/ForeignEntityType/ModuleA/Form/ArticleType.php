<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\ForeignEntityType\ModuleA\Form;

use App\Tests\Unit\PHPStan\data\ForeignEntityType\ModuleA\Entity\Article;
use App\Tests\Unit\PHPStan\data\ForeignEntityType\ModuleB\Entity\Category;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

final class ArticleType
{
    /**
     * @param FormBuilderInterface<mixed> $builder
     */
    public function buildForm(FormBuilderInterface $builder): void
    {
        $builder
            ->add('parent', EntityType::class, ['class' => Article::class])
            ->add('category', EntityType::class, ['class' => Category::class])
            ->add('title', TextType::class, ['label' => 'Title'])
            ->add('picked', CategoryPickerType::class, ['class' => Category::class])
            ->add('byString', EntityType::class, ['class' => 'App\\Tests\\Unit\\PHPStan\\data\\ForeignEntityType\\ModuleB\\Entity\\Category'])
            ->add(child: 'named', options: ['class' => Category::class], type: EntityType::class);
    }
}
