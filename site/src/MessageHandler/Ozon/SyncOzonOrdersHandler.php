<?php

namespace App\MessageHandler\Ozon;

use App\Entity\Ozon\OzonSyncCursor;
use App\Message\Ozon\SyncOzonOrders;
use App\Repository\CompanyRepository;
use App\Repository\Ozon\OzonSyncCursorRepository;
use App\Service\Ozon\OzonOrderSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Обрабатывает сообщения синхронизации заказов Ozon:
 *  - берёт блокировку на компанию и схему, чтобы не запускать синк параллельно;
 *  - загружает компанию и курсор последней синхронизации;
 *  - вычисляет диапазон дат с учётом оверлапа и запускает нужный тип синка (FBS/FBO);
 *  - обновляет курсор и пишет подробные логи по результатам.
 */
#[AsMessageHandler]
final class SyncOzonOrdersHandler
{
    public function __construct(
        private CompanyRepository $companies,
        private OzonOrderSyncService $sync,
        private OzonSyncCursorRepository $cursors,
        private EntityManagerInterface $em,
        private LockFactory $lockFactory,
        private LoggerInterface $logger, // через services.yaml привязан к monolog.logger.ozon.sync
    ) {
    }

    public function __invoke(SyncOzonOrders $m): void
    {
        $lockKey = sprintf('ozon:sync:%s:%s', $m->companyId, strtoupper($m->scheme));
        $lock = $this->lockFactory->createLock($lockKey, $m->lockTtlSec);

        if (!$lock->acquire()) {
            $this->logger->info('Skip: lock busy', ['key' => $lockKey]);

            return;
        }

        try {
            $company = $this->companies->find($m->companyId);
            if (!$company) {
                $this->logger->warning('Company not found', ['companyId' => $m->companyId]);

                return;
            }

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            $cursor = $this->cursors->findOneByCompanyAndScheme($company, strtoupper($m->scheme))
                ?? new OzonSyncCursor(Uuid::uuid4()->toString(), $company, strtoupper($m->scheme));

            $since = $m->sinceIso
                ? new \DateTimeImmutable($m->sinceIso)
                : ($cursor->getLastTo()
                    ? $cursor->getLastTo()->sub(new \DateInterval('PT'.$m->overlapMinutes.'M'))
                    : $now->sub(new \DateInterval('PT70M'))); // 60м + 10м оверлапа
            $to = $m->toIso ? new \DateTimeImmutable($m->toIso) : $now;

            $result = match (strtoupper($m->scheme)) {
                'FBS' => $this->sync->syncFbs($company, $since, $to, $m->status),
                'FBO' => $this->sync->syncFbo($company, $since, $to),
                default => throw new \InvalidArgumentException('Unknown scheme '.$m->scheme),
            };

            $cursor->setLastSince($since);
            $cursor->setLastTo($to);
            $cursor->setLastRunAt($now);
            $this->em->persist($cursor);
            $this->em->flush();

            $this->logger->info('Ozon synced', [
                'company' => $company->getName(),
                'scheme' => $m->scheme,
                'since' => $since->format(\DATE_ATOM),
                'to' => $to->format(\DATE_ATOM),
                'orders' => $result['orders'] ?? 0,
                'statusChanges' => $result['statusChanges'] ?? 0,
            ]);
        } finally {
            $lock->release();
        }
    }
}
