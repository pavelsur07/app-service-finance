<?php

namespace App\Banking\Service;

use App\Banking\Dto\Cursor;
use App\Banking\Provider\ProviderRegistry;
use App\DTO\CashTransactionDTO;
use App\Entity\Company;
use App\Entity\MoneyAccount;
use App\Enum\CashDirection;
use App\Enum\MoneyAccountType;
use App\Service\CashTransactionService;
use App\Service\Import\ImportLogger;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final class BankImportService
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly EntityManagerInterface $em,
        private readonly CashTransactionService $cashflow,
        private readonly ImportLogger $importLogger,
    ) {
    }

    /**
     * Импорт транзакций банка в ДДС для одной компании и одного провайдера.
     *
     * @param string $companyId UUID/ID компании
     * @param string $providerCode Код провайдера ('alfa'|'sber'|'tinkoff'|'demo'|...)
     * @param \DateTimeImmutable|null $since Начало периода (по умолчанию -30 дней)
     * @param \DateTimeImmutable|null $until Конец периода (по умолчанию now)
     */
    public function run(
        string $companyId,
        string $providerCode,
        ?\DateTimeImmutable $since = null,
        ?\DateTimeImmutable $until = null,
    ): void {
        $since ??= new \DateTimeImmutable('-30 days');
        $until ??= new \DateTimeImmutable('now');

        $provider = $this->registry->get($providerCode);

        // Старт лога импорта — требуется сущность Company, а не строка ID
        $company = $this->em->getReference(Company::class, $companyId);
        $log = $this->importLogger->start(
            $company,
            'bank:'.$providerCode,
            false,   // preview
            null,    // userId (если есть — подставьте)
            null     // fileName (для API-импорта не нужен)
        );

        try {
            // Находим банковские MoneyAccount компании
            $qb = $this->em->getRepository(MoneyAccount::class)->createQueryBuilder('a');
            $accounts = $qb
                ->andWhere('a.company = :company')
                ->andWhere('a.type = :type')
                ->setParameter('company', $company)
                ->setParameter(
                    'type',
                    \enum_exists(MoneyAccountType::class) ? MoneyAccountType::BANK->value : 'BANK'
                )
                ->getQuery()
                ->getResult();

            foreach ($accounts as $acc) {
                \assert($acc instanceof MoneyAccount);

                $meta = $acc->getMeta() ?? [];
                $bank = $meta['bank'] ?? null;

                // Берём только те счета, которые привязаны к нужному провайдеру
                if (!$bank || ($bank['provider'] ?? null) !== $providerCode) {
                    continue;
                }

                $auth = $bank['auth'] ?? [];
                $extAcc = $bank['external_account_id'] ?? null;

                if (!$extAcc) {
                    // Нет внешнего ID счёта — пропускаем этот MoneyAccount
                    $this->importLogger->incError($log);
                    continue;
                }

                $cursor = $this->restoreCursor($bank['cursor'] ?? null);

                try {
                    while (true) {
                        $batch = $provider->fetchTransactions($auth, $extAcc, $cursor, $since, $until);

                        foreach ($batch['transactions'] as $tx) {
                            // Валидация валюты счёта
                            if ($tx->currency !== $acc->getCurrency()) {
                                $this->importLogger->incError($log);
                                continue;
                            }

                            // Формируем DTO под ваш CashTransactionService::add()
                            $dto = new CashTransactionDTO();
                            $dto->companyId = $companyId;
                            $dto->moneyAccountId = $acc->getId();
                            $dto->occurredAt = $tx->postedAt;
                            $dto->currency = $tx->currency;

                            // Направление — через enum проекта
                            $dto->direction = 'in' === $tx->direction
                                ? CashDirection::INFLOW
                                : CashDirection::OUTFLOW;

                            // В вашем DTO, как правило, сумма в основных единицах
                            $dto->amount = $tx->amountMinor / 100;
                            $dto->description = $tx->description;

                            // Идемпотентность: внешний ID + источник импорта
                            $dto->externalId = $tx->externalId;
                            $dto->importSource = 'bank:'.$providerCode;

                            try {
                                $this->cashflow->add($dto);
                                $this->importLogger->incCreated($log);
                            } catch (UniqueConstraintViolationException) {
                                // Повторный импорт той же операции
                                $this->importLogger->incSkippedDuplicate($log);
                            } catch (\Throwable) {
                                // Ошибка добавления движения
                                $this->importLogger->incError($log);
                            }
                        }

                        // Обновляем курсор и сохраняем в meta
                        $cursor = $batch['nextCursor'];
                        $this->saveCursor($acc, $cursor);
                        $this->em->flush();

                        if (null === $cursor) {
                            break;
                        }
                    }
                } catch (\Throwable) {
                    // Ошибка общения с провайдером/парсинга — считаем как ошибку импорта
                    $this->importLogger->incError($log);
                }
            }
        } catch (\Throwable) {
            // Общая ошибка импорта для компании/провайдера
            $this->importLogger->incError($log);
        } finally {
            $this->importLogger->finish($log);
        }
    }

    private function restoreCursor(?array $raw): ?Cursor
    {
        if (!$raw) {
            return null;
        }

        return new Cursor(
            $raw['sinceId'] ?? null,
            isset($raw['sinceDate']) ? new \DateTimeImmutable($raw['sinceDate']) : null
        );
    }

    private function saveCursor(MoneyAccount $acc, ?Cursor $cursor): void
    {
        $meta = $acc->getMeta() ?? [];
        $meta['bank'] ??= [];

        $meta['bank']['cursor'] = [
            'sinceId' => $cursor?->sinceId,
            'sinceDate' => $cursor?->sinceDate?->format(\DateTimeInterface::ATOM),
        ];

        $acc->setMeta($meta);
    }
}
