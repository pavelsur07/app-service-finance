<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application;

use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAds\Entity\AdLoadJob;
use App\MarketplaceAds\Message\LoadOzonAdStatisticsRangeMessage;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class DispatchOzonAdLoadAction
{
    public function __construct(
        private readonly MarketplaceFacade $marketplaceFacade,
        private readonly AdLoadJobRepository $adLoadJobRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
    ) {}

    public function __invoke(
        string $companyId,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ): AdLoadJob {
        $credentials = $this->marketplaceFacade->getConnectionCredentials(
            $companyId,
            MarketplaceType::OZON,
            MarketplaceConnectionType::PERFORMANCE,
        );

        if (null === $credentials) {
            throw new \DomainException('Ozon Performance connection not configured');
        }

        $dateFromNorm = $dateFrom->setTime(0, 0);
        $dateToNorm = $dateTo->setTime(0, 0);

        if ($dateFromNorm > $dateToNorm) {
            throw new \DomainException('Дата начала не может быть позже даты окончания.');
        }

        $yesterday = (new \DateTimeImmutable('yesterday'))->setTime(0, 0);
        if ($dateToNorm > $yesterday) {
            throw new \DomainException('Нельзя загружать данные за будущие даты. Дата окончания должна быть не позже вчерашнего дня.');
        }

        $activeJob = $this->adLoadJobRepository->findLatestActiveJobByCompanyAndMarketplace(
            $companyId,
            MarketplaceType::OZON,
        );

        if (null !== $activeJob) {
            throw new \DomainException('Load already in progress');
        }

        $job = new AdLoadJob($companyId, MarketplaceType::OZON, $dateFrom, $dateTo);
        $this->adLoadJobRepository->save($job);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new LoadOzonAdStatisticsRangeMessage($job->getId()));

        return $job;
    }
}
