<?php

declare(strict_types=1);

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

        // TODO перенести в репозиторий
        /** @var Company $company */
        $company = $this->em->getReference(Company::class, $companyId);
        $log = $this->importLogger->start(
            $company,
            'bank:'.$providerCode,
            false, // preview
            null,  // userId
            null   // fileName
        );

        try {
            // Находим банковские MoneyAccount компании
            // TODO перенести в репозиторий
            $qb = $this->em->getRepository(MoneyAccount::class)->createQueryBuilder('a');
            $accounts = $qb
                ->andWhere('a.company = :company')
                ->andWhere('a.type = :type')
                ->setParameter('company', $company)
                ->setParameter('type', \enum_exists(MoneyAccountType::class) ? MoneyAccountType::BANK->value : 'BANK')
                ->getQuery()
                ->getResult();

            foreach ($accounts as $acc) {
                \assert($acc instanceof MoneyAccount);

                // Только счета нужного провайдера
                if (($acc->getBankProviderCode() ?? '') !== $providerCode) {
                    continue;
                }

                $extAcc = $acc->getBankExternalAccountId();
                if (!$extAcc) {
                    // Нет внешнего ID счёта — пропускаем этот MoneyAccount
                    $this->importLogger->incError($log);
                    continue;
                }

                $auth = $acc->getBankAuth() ?? [];
                $cursor = $this->restoreCursor($acc->getBankCursor());

                try {
                    while (true) {
                        $batch = $provider->fetchTransactions($auth, $extAcc, $cursor, $since, $until);

                        foreach ($batch['transactions'] as $tx) {
                            // Нормализуем валюты к 3-символьному upper
                            $txCurrency = strtoupper(substr($tx->currency, 0, 3));
                            $accountCurrency = strtoupper(substr($acc->getCurrency(), 0, 3));

                            // Валидация валюты счёта
                            if ($txCurrency !== $accountCurrency) {
                                $this->importLogger->incError($log);
                                continue;
                            }

                            // Подготовка DTO под CashTransactionService::add()
                            $dto = new CashTransactionDTO();
                            $dto->companyId = $companyId;
                            $dto->moneyAccountId = $acc->getId();
                            $dto->occurredAt = $tx->postedAt;
                            $dto->currency = $txCurrency;

                            // Направление — enum
                            $dto->direction = ('in' === $tx->direction)
                                ? CashDirection::INFLOW
                                : CashDirection::OUTFLOW;

                            // ВАЖНО: amount — СТРОКА, положительная, 2 знака
                            // amountMinor всегда положительный (абсолют), переводим в основные единицы
                            $dto->amount = number_format($tx->amountMinor / 100, 2, '.', '');
                            $dto->description = $tx->description;

                            // Идемпотентность: внешний ID + источник
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

                        // Обновляем курсор и сохраняем в meta через хелпер
                        /** @var Cursor|null $cursor */
                        $cursor = $batch['nextCursor'];
                        $acc->setBankCursor([
                            'sinceId' => $cursor?->sinceId,
                            'sinceDate' => $cursor?->sinceDate?->format(\DateTimeInterface::ATOM),
                        ]);

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
}
