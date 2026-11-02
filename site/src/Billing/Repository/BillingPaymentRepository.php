<?php

declare(strict_types=1);

namespace App\Billing\Repository;

use App\Billing\Domain\PaidPeriodCalculator;
use App\Billing\Entity\BillingPayment;
use App\Billing\Entity\Tariff;
use App\Billing\Enum\PaymentSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BillingPayment>
 */
final class BillingPaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BillingPayment::class);
    }

    public function findOneByIdAndCompanyId(string $id, string $companyId): ?BillingPayment
    {
        return $this->findOneBy(['id' => $id, 'companyId' => $companyId]);
    }

    public function findOneByExternalId(string $companyId, PaymentSource $source, string $externalId): ?BillingPayment
    {
        return $this->findOneBy(['companyId' => $companyId, 'source' => $source, 'externalId' => $externalId]);
    }

    /**
     * Оплата кабинета с этим номером любым способом: ID платежа ЮKassa, отмеченный администратором
     * вручную (`manual_invoice`), и автоматическое зачисление (`yookassa`) — одни и те же деньги.
     */
    public function findOneByCompanyAndExternalIdAnySource(string $companyId, string $externalId): ?BillingPayment
    {
        return $this->findOneBy(['companyId' => $companyId, 'externalId' => $externalId]);
    }

    /**
     * Оплаты тарифа в текущем отрезке подписки, период которых не закончился к дню `$day` и остаток
     * которых ещё не перенесён в повышение, — основа остатка при повышении тарифа.
     *
     * @return list<BillingPayment>
     */
    public function findUnexpiredInSegment(string $companyId, Tariff $tariff, \DateTimeImmutable $segmentFrom, \DateTimeImmutable $day): array
    {
        /* @var list<BillingPayment> */
        return $this->createQueryBuilder('p')
            ->andWhere('p.companyId = :companyId')
            ->andWhere('p.tariff = :tariff')
            ->andWhere('p.periodFrom >= :segmentFrom')
            ->andWhere('p.periodUntil >= :day')
            ->andWhere('p.supersededByPaymentId IS NULL')
            ->setParameter('companyId', $companyId)
            ->setParameter('tariff', $tariff)
            ->setParameter('segmentFrom', $segmentFrom, Types::DATE_IMMUTABLE)
            ->setParameter('day', $day, Types::DATE_IMMUTABLE)
            ->orderBy('p.periodFrom', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Есть ли зачисленная оплата другого тарифа, полученная в более поздний день (МСК), чем
     * `$receivedAt`: оплату, пришедшую после смены тарифа, но полученную раньше неё, автоматически не
     * пересчитать. По дням, не по моментам: оплата по счёту вводится датой (полночь), а план и так
     * считается по дню зачисления.
     */
    public function existsReceivedAfterWithOtherTariff(string $companyId, \DateTimeImmutable $receivedAt, Tariff $tariff): bool
    {
        $nextDay = PaidPeriodCalculator::day($receivedAt)->modify('+1 day');

        return null !== $this->createQueryBuilder('p')
            ->select('1')
            ->andWhere('p.companyId = :companyId')
            ->andWhere('p.receivedAt >= :nextDay')
            ->andWhere('p.tariff <> :tariff')
            ->setParameter('companyId', $companyId)
            ->setParameter('nextDay', $nextDay, Types::DATETIME_IMMUTABLE)
            ->setParameter('tariff', $tariff)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Последний оплаченный день кабинета по журналу: максимум «по» среди оплат, остаток которых не
     * перенесён в повышение (их дни уже в оплате повышения); null — таких оплат нет.
     */
    public function findLatestPaidUntil(string $companyId): ?\DateTimeImmutable
    {
        $value = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT MAX(period_until) FROM billing_payments WHERE company_id = :companyId AND superseded_by_payment_id IS NULL',
            ['companyId' => $companyId],
        );

        return \is_string($value) ? PaidPeriodCalculator::date(new \DateTimeImmutable($value)) : null;
    }

    /**
     * История оплат кабинета для Pagerfanta: новые сверху.
     */
    public function createByCompanyQueryBuilder(string $companyId): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->addSelect('t')
            ->join('p.tariff', 't')
            ->andWhere('p.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('p.receivedAt', \SortDirection::Descending)
            ->addOrderBy('p.id', \SortDirection::Descending);
    }
}
