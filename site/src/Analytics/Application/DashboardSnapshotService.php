<?php

namespace App\Analytics\Application;

use App\Analytics\Api\Response\SnapshotContextResponse;
use App\Analytics\Api\Response\SnapshotResponse;
use App\Analytics\Application\Widget\FreeCashWidgetBuilder;
use App\Analytics\Domain\Period;
use App\Company\Entity\Company;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class DashboardSnapshotService
{
    private const int SNAPSHOT_TTL_SECONDS = 120;
    private const string VAT_MODE_EXCLUDE = 'exclude';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly FreeCashWidgetBuilder $freeCashWidgetBuilder,
    )
    {
    }

    public function getSnapshot(Company $company, Period $period): SnapshotResponse
    {
        $cacheKey = sprintf(
            'dashboard:v1:snapshot:%s:%s:%s:%s',
            (string) $company->getId(),
            $period->getFrom()->format('Y-m-d'),
            $period->getTo()->format('Y-m-d'),
            self::VAT_MODE_EXCLUDE,
        );

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($company, $period) {
            $item->expiresAfter(self::SNAPSHOT_TTL_SECONDS);

            $prevPeriod = $period->prevPeriod();

            return new SnapshotResponse(
                new SnapshotContextResponse(
                    companyId: (string) $company->getId(),
                    from: $period->getFrom(),
                    to: $period->getTo(),
                    days: $period->days(),
                    prevFrom: $prevPeriod->getFrom(),
                    prevTo: $prevPeriod->getTo(),
                    vatMode: self::VAT_MODE_EXCLUDE,
                    lastUpdatedAt: null,
                ),
                $this->freeCashWidgetBuilder->build($company, $period),
            );
        });
    }
}
