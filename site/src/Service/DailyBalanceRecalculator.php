<?php

namespace App\Service;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Accounts\MoneyAccountDailyBalance;
use App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Entity\Company;
use App\Enum\CashDirection;
use App\Repository\CashTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Пересчёт ежедневных остатков по счётам на основе транзакций.
 *
 * Правила:
 * - INFLOW  => +abs(amount)
 * - OUTFLOW => -abs(amount)
 * - Внутри диапазона не используем сохранённые факты для расчёта — только транзакции.
 * - Стартовый opening (на дату FROM):
 *     1) closing последней записи ДО FROM, если есть;
 *     2) иначе opening записи В ДЕНЬ FROM, если есть;
 *     3) иначе 0.00.
 * - Сохраняем/перезаписываем: opening, inflow, outflow, closing.
 */
class DailyBalanceRecalculator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CashTransactionRepository $trxRepo,
        private readonly MoneyAccountDailyBalanceRepository $dailyRepo,
        private readonly MoneyAccountRepository $accountRepo,
    ) {
    }

    /**
     * Пересчёт по компании и (опционально) списку счетов.
     *
     * @param array<string>|null $accountIds
     */
    public function recalcRange(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to, ?array $accountIds = null): void
    {
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        if ($accountIds && \count($accountIds) > 0) {
            $accounts = $this->accountRepo->createQueryBuilder('a')
                ->where('a.company = :c')->setParameter('c', $company)
                ->andWhere('a.id IN (:ids)')->setParameter('ids', $accountIds)
                ->getQuery()->getResult();
        } else {
            $accounts = $this->accountRepo->findBy(['company' => $company]);
        }

        foreach ($accounts as $account) {
            if ($account instanceof MoneyAccount) {
                $this->recalcRangeForAccount($company, $account, $from, $to);
            }
        }
    }

    /**
     * Пересчитать диапазон по одному счёту.
     */
    private function recalcRangeForAccount(Company $company, MoneyAccount $account, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        $fmt = static function (float $v): string {
            return number_format($v, 2, '.', '');
        };

        // --- 0) Расширяем правую границу до последней даты факта (если вызывающий код передал слишком короткий to)
        $maxDateStr = $this->dailyRepo->createQueryBuilder('b')
            ->select('MAX(b.date)')
            ->where('b.company = :c')->setParameter('c', $company)
            ->andWhere('b.moneyAccount = :a')->setParameter('a', $account)
            ->getQuery()
            ->getSingleScalarResult();

        $effectiveTo = $to;
        if ($maxDateStr) {
            $maxDate = (new \DateTimeImmutable($maxDateStr))->setTime(0, 0);
            if ($maxDate > $effectiveTo) {
                $effectiveTo = $maxDate;
            }
        }

        // --- 1) Подготовка дней диапазона
        $days = [];
        for ($d = $from; $d <= $effectiveTo; $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            $days[$k] = [
                'date' => $d,
                'inflow' => 0.0,
                'outflow' => 0.0,
                'delta' => 0.0, // inflow - outflow
            ];
        }

        // --- 2) Транзакции по счёту в диапазоне
        $txList = $this->trxRepo->createQueryBuilder('t')
            ->where('t.company = :c')->setParameter('c', $company)
            ->andWhere('t.moneyAccount = :a')->setParameter('a', $account)
            ->andWhere('t.occurredAt BETWEEN :from AND :to')
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $effectiveTo->setTime(23, 59, 59))
            ->orderBy('t.occurredAt', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()->getResult();

        foreach ($txList as $t) {
            if (!\is_object($t)) {
                continue;
            }
            $key = $t->getOccurredAt()->format('Y-m-d');
            if (!isset($days[$key])) {
                continue;
            }
            $abs = abs((float) $t->getAmount());
            if (CashDirection::OUTFLOW === $t->getDirection()) {
                $days[$key]['outflow'] += $abs;
                $days[$key]['delta'] -= $abs;
            } else {
                $days[$key]['inflow'] += $abs;
                $days[$key]['delta'] += $abs;
            }
        }

        // --- 3) Стартовый opening на FROM
        $startOpening = null;

        // 3.1. closing последней записи ДО FROM
        $prevEntity = $this->dailyRepo->createQueryBuilder('b')
            ->where('b.company = :c')->setParameter('c', $company)
            ->andWhere('b.moneyAccount = :a')->setParameter('a', $account)
            ->andWhere('b.date < :from')->setParameter('from', $from)
            ->orderBy('b.date', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($prevEntity instanceof MoneyAccountDailyBalance) {
            $startOpening = (float) $prevEntity->getClosingBalance();
        }

        // 3.2. если нет предыдущей — возьмём opening факта В ДЕНЬ FROM
        if (null === $startOpening) {
            $sameEntity = $this->dailyRepo->createQueryBuilder('b')
                ->where('b.company = :c')->setParameter('c', $company)
                ->andWhere('b.moneyAccount = :a')->setParameter('a', $account)
                ->andWhere('b.date = :d')->setParameter('d', $from)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($sameEntity instanceof MoneyAccountDailyBalance) {
                $startOpening = (float) $sameEntity->getOpeningBalance();
            }
        }

        if (null === $startOpening) {
            $startOpening = 0.0;
        }

        // --- 4) Посуточный расчёт opening/closing
        $prevClose = $startOpening;

        foreach ($days as $k => &$day) {
            $opening = $prevClose;
            $closing = round($opening + $day['delta'], 2);

            $day['opening'] = $opening;
            $day['closing'] = $closing;

            $prevClose = $closing;
        }
        unset($day);

        // --- 5) Перезапись в БД
        $currency = $account->getCurrency();

        foreach ($days as $k => $day) {
            /** @var \DateTimeImmutable $date */
            $date = $day['date'];

            $entity = $this->dailyRepo->findOneBy([
                'company' => $company,
                'moneyAccount' => $account,
                'date' => $date,
            ]);

            if (!$entity) {
                // У сущности нет авто-генерации id => генерируем вручную
                $id = Uuid::uuid4()->toString();
                $entity = new MoneyAccountDailyBalance(
                    $id,
                    $company,
                    $account,
                    $date,
                    $fmt((float) $day['opening']),
                    $fmt((float) $day['inflow']),
                    $fmt((float) $day['outflow']),
                    $fmt((float) $day['closing']),
                    $currency
                );
            } else {
                $entity
                    ->setOpeningBalance($fmt((float) $day['opening']))
                    ->setInflow($fmt((float) $day['inflow']))
                    ->setOutflow($fmt((float) $day['outflow']))
                    ->setClosingBalance($fmt((float) $day['closing']));
            }

            $this->em->persist($entity);
        }

        $this->em->flush();
    }
}
