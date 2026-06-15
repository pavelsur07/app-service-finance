<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Http;

use App\Shared\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class CompanyFilterRequestSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly ActiveCompanyService $activeCompanyService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (null === $this->security->getUser()) {
            return;
        }

        $company = $this->activeCompanyService->getActiveCompany();
        $filters = $this->entityManager->getFilters();

        if (!$filters->isEnabled('company')) {
            $filters->enable('company');
        }

        $filters->getFilter('company')->setParameter('companyId', (string) $company->getId());
    }
}
