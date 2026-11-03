<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Schema;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Дополняет схему из маппинга объектами, которые есть только в миграциях (см. {@see SchemaExtension}).
 *
 * Только для полной схемы проекта: `SchemaTool` по подмножеству сущностей (тестовые фикстуры
 * создают так свои таблицы) не содержит таблиц, на которые ссылаются расширения, а объекты
 * миграций ему не нужны.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final readonly class SchemaExtensionListener
{
    /** @param iterable<SchemaExtension> $extensions */
    public function __construct(
        #[AutowireIterator(SchemaExtension::TAG)]
        private iterable $extensions,
    ) {
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if (!$this->containsAllProjectTables($schema, $args->getEntityManager())) {
            return;
        }

        foreach ($this->extensions as $extension) {
            $extension->extend($schema);
        }
    }

    private function containsAllProjectTables(Schema $schema, EntityManagerInterface $em): bool
    {
        foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass || str_starts_with($metadata->getName(), 'App\\Tests\\')) {
                continue;
            }

            if (!$schema->hasTable($metadata->getTableName())) {
                return false;
            }
        }

        return true;
    }
}
