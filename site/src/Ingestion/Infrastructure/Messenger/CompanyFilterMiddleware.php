<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Messenger;

use App\Ingestion\Message\CompanyAwareMessage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class CompanyFilterMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();

        if (!$message instanceof CompanyAwareMessage) {
            return $stack->next()->handle($envelope, $stack);
        }

        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('company');
        $previousParameters = [];

        if ($wasEnabled) {
            $companyFilter = $filters->getFilter('company');
            $previousParameters = $this->snapshotParameters($companyFilter);
        } else {
            $companyFilter = $filters->enable('company');
        }

        $companyFilter->setParameter('companyId', $message->getCompanyId());

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            if ($wasEnabled) {
                $this->restoreParameters($filters->getFilter('company'), $previousParameters);
                $filters->setFiltersStateDirty();
            } else {
                $filters->disable('company');
            }
        }
    }

    /**
     * @return array<string, object>
     */
    private function snapshotParameters(SQLFilter $filter): array
    {
        return $this->parametersReflection()->getValue($filter);
    }

    /**
     * @param array<string, object> $parameters
     */
    private function restoreParameters(SQLFilter $filter, array $parameters): void
    {
        $this->parametersReflection()->setValue($filter, $parameters);
    }

    private function parametersReflection(): \ReflectionProperty
    {
        static $property = null;

        if (!$property instanceof \ReflectionProperty) {
            // Doctrine ORM has no public API to snapshot SQLFilter parameters.
            // Re-check this internal property access on Doctrine ORM upgrades.
            $property = new \ReflectionProperty(SQLFilter::class, 'parameters');
        }

        return $property;
    }
}
