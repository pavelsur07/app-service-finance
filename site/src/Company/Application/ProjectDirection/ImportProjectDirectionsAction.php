<?php

declare(strict_types=1);

namespace App\Company\Application\ProjectDirection;

use App\Company\Entity\Company;
use App\Company\Repository\ProjectDirectionRepository;
use Doctrine\DBAL\Connection;

final class ImportProjectDirectionsAction
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly ProjectDirectionRepository $repository,
        private readonly ProjectDirectionExchange $exchange,
    ) {
    }

    public function __invoke(Company $company, string $json, ?string $revision): ProjectDirectionImportPreview
    {
        $companyId = (string) $company->getId();

        return $this->connection->transactional(function () use ($companyId, $json, $revision): ProjectDirectionImportPreview {
            $this->repository->lockDirectory($companyId);
            $snapshot = $this->repository->readDirectory($companyId);
            if ($revision !== $snapshot['revision']) {
                return new ProjectDirectionImportPreview($revision, errors: ['Справочник изменился. Загрузите файл для нового предпросмотра.']);
            }

            $current = $snapshot['projects'];
            $result = $this->exchange->validate($companyId, $json, $revision, $current);
            if ([] === $result->changes) {
                return $result;
            }

            $changes = $result->changes;
            // Разные компании могут одновременно импортировать один и тот же новый UUID.
            ksort($changes);
            $batches = array_chunk($changes, self::BATCH_SIZE, true);
            foreach ($batches as $batch) {
                // Освобождаем прежние коды для обмена кодами. Новые узлы пока создаём без родителей.
                $releasedCodes = [];
                foreach (array_intersect_key($batch, $current) as $id => $row) {
                    if (null !== $current[$id]['code'] && $current[$id]['code'] !== $row['code']) {
                        $releasedCodes[] = $id;
                    }
                }
                $this->repository->clearCodesForImport($companyId, $releasedCodes);
                $this->repository->insertForImport($companyId, array_values(array_diff_key($batch, $current)));
            }

            foreach ($batches as $batch) {
                $this->repository->updateFromImport($companyId, array_values($batch));
            }
            $this->repository->advanceDirectoryRevision($companyId);

            return $result;
        });
    }
}
