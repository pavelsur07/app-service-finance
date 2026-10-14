<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rules;

use Doctrine\ORM\Mapping\Entity;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Doctrine\ObjectMetadataResolver;

/**
 * Сущность принадлежит компании, если это Doctrine-сущность с полем `company`
 * или `companyId` — тот же признак, что у `RepositoryCompanyScopeRule`
 * (владение прослеживается на один уровень).
 */
final class CompanyOwnedEntity
{
    private const COMPANY_PROPERTIES = ['company', 'companyId'];

    /** @var array<string, string>|null класс репозитория => сущность */
    private ?array $repositoryEntities = null;

    /**
     * @param array<string, string> $knownRepositories явная карта для тестов правил
     */
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly ?ObjectMetadataResolver $metadataResolver = null,
        private readonly array $knownRepositories = [],
    ) {
    }

    /**
     * Сущность кастомного репозитория по метаданным Doctrine.
     *
     * Нужна для репозиториев без `@extends ServiceEntityRepository<X>`: у них
     * `find()` возвращает `object|null`, и тип результата сущность не выдаёт.
     */
    public function entityOfRepository(string $repositoryClass): ?string
    {
        if (null === $this->repositoryEntities) {
            $this->repositoryEntities = $this->knownRepositories;
            $manager = $this->metadataResolver?->getObjectManager();
            if (null !== $manager) {
                foreach ($manager->getMetadataFactory()->getAllMetadata() as $metadata) {
                    $repository = $metadata instanceof \Doctrine\ORM\Mapping\ClassMetadata ? $metadata->customRepositoryClassName : null;
                    if (null !== $repository) {
                        $this->repositoryEntities[ltrim($repository, '\\')] = $metadata->getName();
                    }
                }
            }
        }

        return $this->repositoryEntities[ltrim($repositoryClass, '\\')] ?? null;
    }

    public function isCompanyOwned(string $className): bool
    {
        if (!$this->reflectionProvider->hasClass($className)) {
            return false;
        }

        $class = $this->reflectionProvider->getClass($className);
        if (!$class->isClass() || [] === $class->getNativeReflection()->getAttributes(Entity::class)) {
            return false;
        }

        foreach (self::COMPANY_PROPERTIES as $property) {
            if ($class->hasNativeProperty($property)) {
                return true;
            }
        }

        return false;
    }

    public static function isController(?ClassReflection $class): bool
    {
        if (null === $class) {
            return false;
        }

        $name = $class->getName();

        // Тесты лежат в `App\Tests\…\Controller\` — это не контроллеры.
        if (str_starts_with($name, 'App\\Tests\\') && !str_starts_with($name, 'App\\Tests\\Unit\\PHPStan\\data\\')) {
            return false;
        }

        return str_contains($name, '\\Controller\\') || str_ends_with($name, 'Controller');
    }
}
