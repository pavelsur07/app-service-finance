<?php

declare(strict_types=1);

namespace App\Budget\Application;

use App\Budget\Application\DTO\CreateBudgetVersionCommand;
use App\Budget\Entity\BudgetVersion;
use App\Budget\Exception\BudgetVersionNotFoundException;
use App\Budget\Exception\InvalidBudgetInputException;
use App\Budget\Repository\BudgetVersionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/**
 * Создаёт вариант бюджета в черновике: пустой на год или копией варианта своей компании (все
 * строки с новыми ID, год исходника).
 *
 * Точка входа, коммитит сама; вариант и строки копии — одна транзакция. Повтор с тем же
 * `versionId` возвращает существующий вариант и ничего не пишет.
 */
final class CreateBudgetVersionAction
{
    private const COPY_CHUNK = 500;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BudgetVersionRepository $versions,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(CreateBudgetVersionCommand $command): string
    {
        Assert::uuid($command->versionId);
        Assert::uuid($command->companyId);

        if (null !== $this->versions->findOneByIdAndCompanyId($command->versionId, $command->companyId)) {
            return $command->versionId;
        }

        try {
            $name = BudgetVersion::normalizeName($command->name);
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidBudgetInputException($exception->getMessage(), previous: $exception);
        }

        $source = null;
        if (null !== $command->copyFromVersionId) {
            $source = Uuid::isValid($command->copyFromVersionId)
                ? $this->versions->findOneByIdAndCompanyId($command->copyFromVersionId, $command->companyId)
                : null;
            if (null === $source) {
                throw new BudgetVersionNotFoundException();
            }
        }
        $year = $source?->getYear() ?? $command->year;
        if (null === $year || $year < BudgetVersion::MIN_YEAR || $year > BudgetVersion::MAX_YEAR) {
            throw new InvalidBudgetInputException('Год бюджета — от 2000 до 2100.');
        }

        try {
            $copied = $this->entityManager->wrapInTransaction(function () use ($command, $name, $year, $source): int {
                $now = $this->clock->now();
                $this->entityManager->persist(new BudgetVersion($command->versionId, $command->companyId, $year, $name, $now, $source?->getId()));
                // Строки копии ссылаются на вариант внешним ключом — сначала он.
                $this->entityManager->flush();

                if (null === $source) {
                    return 0;
                }
                // Строки и правила источника — одним согласованным снимком: правку источника между
                // ними (новое правило к старым суммам) блокировка варианта не пропустит.
                BudgetVersionLock::acquire($this->entityManager->getConnection(), $source->getId());
                $copied = $this->copyLines($source->getId(), $command, $now);
                $this->copyRules($source->getId(), $command, $now);

                return $copied;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Параллельный повтор: транзакция откачена, EM закрыт — проверяем DBAL.
            $exists = $this->entityManager->getConnection()->fetchOne(
                'SELECT 1 FROM budget_versions WHERE id = :id AND company_id = :companyId',
                ['id' => $command->versionId, 'companyId' => $command->companyId],
            );

            return false !== $exists ? $command->versionId : throw $exception;
        }

        $this->logger->info('Budget: вариант создан', [
            'companyId' => $command->companyId,
            'versionId' => $command->versionId,
            'year' => $year,
            'copiedFromId' => $source?->getId(),
            'copiedLines' => $copied,
        ]);

        return $command->versionId;
    }

    private function copyLines(string $sourceId, CreateBudgetVersionCommand $command, \DateTimeImmutable $now): int
    {
        $connection = $this->entityManager->getConnection();
        /** @var list<array{pl_category_id: string, month: string, project_direction_id: string, responsibility_center_id: string, amount: string, source: string, comment: ?string}> $rows */
        $rows = $connection->fetchAllAssociative(
            'SELECT pl_category_id, month, project_direction_id, responsibility_center_id, amount, source, comment
               FROM budget_lines WHERE version_id = :versionId AND company_id = :companyId',
            ['versionId' => $sourceId, 'companyId' => $command->companyId],
        );

        foreach (array_chunk($rows, self::COPY_CHUNK) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $row) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                array_push(
                    $params,
                    Uuid::uuid7()->toString(),
                    $command->companyId,
                    $command->versionId,
                    $row['pl_category_id'],
                    $row['month'],
                    $row['project_direction_id'],
                    $row['responsibility_center_id'],
                    $row['amount'],
                    $row['source'],
                    $row['comment'],
                    $now->format('Y-m-d H:i:s'),
                );
            }
            $connection->executeStatement(
                'INSERT INTO budget_lines (id, company_id, version_id, pl_category_id, month, project_direction_id, responsibility_center_id, amount, source, comment, updated_at)
                 VALUES '.implode(', ', $values),
                $params,
            );
        }

        return \count($rows);
    }

    /**
     * Правила «% от строки» копии — те же, что у источника; их строки (`rule`) уже скопированы.
     */
    private function copyRules(string $sourceId, CreateBudgetVersionCommand $command, \DateTimeImmutable $now): void
    {
        $connection = $this->entityManager->getConnection();
        $rows = $connection->fetchAllAssociative(
            'SELECT pl_category_id, project_direction_id, responsibility_center_id, base_category_id, percent
               FROM budget_rules WHERE version_id = :versionId AND company_id = :companyId',
            ['versionId' => $sourceId, 'companyId' => $command->companyId],
        );
        foreach ($rows as $row) {
            $connection->executeStatement(
                'INSERT INTO budget_rules (id, company_id, version_id, pl_category_id, project_direction_id, responsibility_center_id, base_category_id, percent, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    Uuid::uuid7()->toString(),
                    $command->companyId,
                    $command->versionId,
                    $row['pl_category_id'],
                    $row['project_direction_id'],
                    $row['responsibility_center_id'],
                    $row['base_category_id'],
                    $row['percent'],
                    $now->format('Y-m-d H:i:s'),
                ],
            );
        }
    }
}
