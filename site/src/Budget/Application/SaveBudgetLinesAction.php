<?php

declare(strict_types=1);

namespace App\Budget\Application;

use App\Budget\Application\DTO\SaveBudgetLinesCommand;
use App\Budget\Application\Service\BudgetLinesWriter;
use App\Budget\Enum\BudgetVersionStatus;
use App\Budget\Exception\BudgetVersionNotEditableException;
use App\Budget\Exception\BudgetVersionNotFoundException;
use App\Budget\Exception\InvalidBudgetInputException;
use App\Budget\Repository\BudgetVersionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

/**
 * Сохраняет пачку ячеек черновика: upsert по ячейке, ноль без комментария удаляет строку.
 *
 * Пачка принимается целиком или отклоняется целиком (BudgetLinesWriter): листовая статья ОПиУ
 * своей компании, активная пара «проект × ЦФО», месяц года варианта, сумма NUMERIC(18,2). Повтор
 * той же пачки даёт те же строки. Одна и та же ячейка в пачке — побеждает последняя.
 *
 * Точка входа, коммитит сама; под блокировкой варианта ({@see BudgetVersionLock}) статус
 * перечитывается — в параллельно утверждённый вариант ничего не пишется. Только DBAL.
 */
final class SaveBudgetLinesAction
{
    public const MAX_CELLS = BudgetLinesWriter::MAX_CELLS;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BudgetVersionRepository $versions,
        private readonly BudgetLinesWriter $writer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return int число сохранённых ячеек (с удалёнными нулевыми)
     */
    public function __invoke(SaveBudgetLinesCommand $command): int
    {
        Assert::uuid($command->companyId);
        Assert::uuid($command->versionId);

        if (\count($command->cells) > self::MAX_CELLS) {
            throw new InvalidBudgetInputException(sprintf('За один раз — не больше %d ячеек.', self::MAX_CELLS));
        }
        $version = $this->versions->findOneByIdAndCompanyId($command->versionId, $command->companyId)
            ?? throw new BudgetVersionNotFoundException();
        if (!$version->isEditable()) {
            throw new BudgetVersionNotEditableException($version->getStatus());
        }
        if ([] === $command->cells) {
            return 0;
        }

        $cells = $this->writer->validate($command->companyId, $version->getYear(), $command->cells);

        $status = $this->entityManager->wrapInTransaction(function () use ($command, $version, $cells): ?BudgetVersionStatus {
            $connection = $this->entityManager->getConnection();
            BudgetVersionLock::acquire($connection, $version->getId());
            $status = BudgetVersionStatus::from((string) $connection->fetchOne(
                'SELECT status FROM budget_versions WHERE id = :id AND company_id = :companyId',
                ['id' => $command->versionId, 'companyId' => $command->companyId],
            ));
            if (BudgetVersionStatus::DRAFT !== $status) {
                return $status;
            }
            $this->writer->write($command->companyId, $command->versionId, $cells);

            return null;
        });
        if (null !== $status) {
            throw new BudgetVersionNotEditableException($status);
        }

        $this->logger->info('Budget: ячейки плана сохранены', [
            'companyId' => $command->companyId,
            'versionId' => $command->versionId,
            'cells' => \count($cells),
        ]);

        return \count($cells);
    }
}
