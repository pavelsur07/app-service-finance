<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Company\Facade\CompanyFacade;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Денежные счета новой компании: «Основной счет» (банк) и касса «Основная», оба в RUB.
 * Счёт создаётся, только если у компании нет ни одного счёта этого типа, поэтому
 * переименованный или архивный счёт не дублируется и повторный вызов ничего не меняет.
 */
final readonly class EnsureDefaultMoneyAccountsAction
{
    private const CURRENCY = 'RUB';

    /** @var list<array{MoneyAccountType, string}> тип счёта и название */
    private const DEFAULTS = [
        [MoneyAccountType::BANK, 'Основной счет'],
        [MoneyAccountType::CASH, 'Основная'],
    ];

    public function __construct(
        private CompanyFacade $companyFacade,
        private MoneyAccountRepository $accounts,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int число созданных счетов
     */
    public function __invoke(string $companyId): int
    {
        $company = $this->companyFacade->findById($companyId)
            ?? throw new \DomainException(sprintf('Компания %s не найдена.', $companyId));

        $created = 0;
        foreach (self::DEFAULTS as [$type, $name]) {
            if ($this->accounts->existsByCompanyIdAndType($companyId, $type)) {
                continue;
            }
            // Имя уникально в компании: занято счётом другого типа — не создаём, чтобы
            // не уронить создание компании или дозаполнение на уникальном индексе.
            if ($this->accounts->existsByCompanyIdAndName($companyId, $name)) {
                $this->logger->warning('Default money account skipped: name is taken', [
                    'companyId' => $companyId,
                    'type' => $type->value,
                ]);

                continue;
            }

            $this->entityManager->persist(new MoneyAccount(Uuid::uuid7()->toString(), $company, $type, $name, self::CURRENCY));
            ++$created;
        }

        if ($created > 0) {
            $this->entityManager->flush();
        }

        return $created;
    }
}
