<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Company\Facade\CompanyFacade;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Денежные счета новой компании: «Основной счет» (банк) и касса «Основная», оба в RUB.
 * Создаются, только если у компании нет ни одного денежного счёта (любого типа, в том числе
 * архивного): у компании со своими счетами параллельный набор «по умолчанию» не появляется,
 * повторный вызов ничего не меняет.
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
    ) {
    }

    /**
     * @param bool $dryRun только посчитать, ничего не записывая
     *
     * @return int число созданных (в dry-run — недостающих) счетов
     */
    public function __invoke(string $companyId, bool $dryRun = false): int
    {
        $company = $this->companyFacade->findById($companyId)
            ?? throw new \DomainException(sprintf('Компания %s не найдена.', $companyId));

        if ($this->accounts->existsByCompanyId($companyId)) {
            return 0;
        }

        if (!$dryRun) {
            foreach (self::DEFAULTS as [$type, $name]) {
                $this->entityManager->persist(new MoneyAccount(Uuid::uuid7()->toString(), $company, $type, $name, self::CURRENCY));
            }
            $this->entityManager->flush();
        }

        return \count(self::DEFAULTS);
    }
}
