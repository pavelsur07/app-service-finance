<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Infrastructure\Messenger\CompanyFilterMiddleware;
use App\Ingestion\Message\CompanyAwareMessage;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class IngestionMessengerCompanyFilterMiddlewareTest extends IntegrationTestCase
{
    public function testCompanyAwareMessageEnablesFilterAndDisablesItAfterHandling(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $middleware = new CompanyFilterMiddleware($this->em);

        $middleware->handle(
            new Envelope(new TestCompanyAwareMessage($companyId)),
            new SingleMiddlewareStack(new CallbackMiddleware(function () use ($companyId): void {
                $filters = $this->em->getFilters();

                self::assertTrue($filters->isEnabled('company'));
                self::assertSame(
                    $this->em->getConnection()->quote($companyId),
                    $filters->getFilter('company')->getParameter('companyId'),
                );
            })),
        );

        self::assertFalse($this->em->getFilters()->isEnabled('company'));
    }

    public function testCompanyAwareMessageRestoresPreviouslyEnabledFilter(): void
    {
        $outerCompanyId = Uuid::uuid7()->toString();
        $messageCompanyId = Uuid::uuid7()->toString();
        $filters = $this->em->getFilters();
        $filters->enable('company')->setParameter('companyId', $outerCompanyId);

        $middleware = new CompanyFilterMiddleware($this->em);

        $middleware->handle(
            new Envelope(new TestCompanyAwareMessage($messageCompanyId)),
            new SingleMiddlewareStack(new CallbackMiddleware(function () use ($messageCompanyId): void {
                $filters = $this->em->getFilters();

                self::assertTrue($filters->isEnabled('company'));
                self::assertSame(
                    $this->em->getConnection()->quote($messageCompanyId),
                    $filters->getFilter('company')->getParameter('companyId'),
                );
            })),
        );

        self::assertTrue($filters->isEnabled('company'));
        self::assertSame(
            $this->em->getConnection()->quote($outerCompanyId),
            $filters->getFilter('company')->getParameter('companyId'),
        );

        $filters->disable('company');
    }

    public function testNonCompanyAwareMessageDoesNotEnableFilter(): void
    {
        $middleware = new CompanyFilterMiddleware($this->em);

        $middleware->handle(
            new Envelope(new \stdClass()),
            new SingleMiddlewareStack(new CallbackMiddleware(function (): void {
                self::assertFalse($this->em->getFilters()->isEnabled('company'));
            })),
        );

        self::assertFalse($this->em->getFilters()->isEnabled('company'));
    }
}

final readonly class TestCompanyAwareMessage implements CompanyAwareMessage
{
    public function __construct(private string $companyId)
    {
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }
}

final readonly class CallbackMiddleware implements MiddlewareInterface
{
    /**
     * @param \Closure(): void $callback
     */
    public function __construct(private \Closure $callback)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        ($this->callback)();

        return $envelope;
    }
}

final class SingleMiddlewareStack implements StackInterface
{
    public function __construct(private MiddlewareInterface $middleware)
    {
    }

    public function next(): MiddlewareInterface
    {
        return $this->middleware;
    }
}
