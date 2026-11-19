<?php

declare(strict_types=1);

namespace App\Company\Application\ProjectDirection;

use App\Company\Entity\ProjectDirection;
use App\Company\Repository\ProjectDirectionCopyMappingRepository;
use App\Company\Repository\ProjectDirectionRepository;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * @phpstan-import-type ProjectRow from ProjectDirectionRepository
 * @phpstan-import-type DirectorySnapshot from ProjectDirectionRepository
 * @phpstan-import-type CopyDecision from ProjectDirectionCopyPreview
 */
final readonly class CopyProjectDirectionsAction
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private Connection $connection,
        private ProjectDirectionRepository $projects,
        private ProjectDirectionCopyMappingRepository $mappings,
        private ProjectDirectionExchange $exchange,
    ) {
    }

    /** @param array<string, CopyDecision> $decisions */
    public function preview(string $sourceId, string $targetId, array $decisions = []): ProjectDirectionCopyPreview
    {
        $this->assertDifferentCompanies($sourceId, $targetId);
        $source = $this->projects->readDirectory($sourceId);
        $target = $this->projects->readDirectory($targetId);
        $mappings = $this->mappings->findBetweenCompanies($sourceId, $targetId);
        if ($source['revision'] !== $this->projects->getDirectoryRevision($sourceId)
            || $target['revision'] !== $this->projects->getDirectoryRevision($targetId)
        ) {
            return $this->stale($source['revision'], $target['revision']);
        }

        return $this->plan($source, $target, $mappings, $decisions);
    }

    /** @param array<string, CopyDecision> $decisions */
    public function confirm(string $sourceId, string $targetId, ?string $sourceRevision, ?string $targetRevision, array $decisions): ProjectDirectionCopyPreview
    {
        $this->assertDifferentCompanies($sourceId, $targetId);

        return $this->connection->transactional(function () use ($sourceId, $targetId, $sourceRevision, $targetRevision, $decisions): ProjectDirectionCopyPreview {
            $this->lockDirectories($sourceId, $targetId);
            $source = $this->projects->readDirectory($sourceId);
            $target = $this->projects->readDirectory($targetId);
            if ($sourceRevision !== $source['revision'] || $targetRevision !== $target['revision']) {
                return $this->stale($sourceRevision, $targetRevision);
            }

            $plan = $this->plan($source, $target, $this->mappings->findBetweenCompanies($sourceId, $targetId), $decisions);
            if ([] !== $plan->errors || [] === $plan->mappings) {
                return $plan;
            }

            $batches = array_chunk($plan->creations, self::BATCH_SIZE);
            // Сначала вставляем все UUID, затем проставляем родителей, в том числе между пакетами.
            foreach ($batches as $batch) {
                $this->projects->insertForImport($targetId, $batch);
            }
            foreach ($batches as $batch) {
                $this->projects->updateFromImport($targetId, $batch);
            }
            foreach (array_chunk($plan->mappings, self::BATCH_SIZE, true) as $batch) {
                $this->mappings->insertBetweenCompanies($sourceId, $targetId, $batch);
            }
            $this->projects->advanceDirectoryRevision($targetId);

            return $plan;
        });
    }

    private function stale(?string $sourceRevision, ?string $targetRevision): ProjectDirectionCopyPreview
    {
        return new ProjectDirectionCopyPreview($sourceRevision, $targetRevision, errors: ['Справочник одной из компаний изменился. Сформируйте новый предпросмотр.']);
    }

    private function assertDifferentCompanies(string $sourceId, string $targetId): void
    {
        if ($sourceId === $targetId) {
            throw new \InvalidArgumentException('Выберите другую компанию для копирования проектов.');
        }
    }

    private function lockDirectories(string $sourceId, string $targetId): void
    {
        $companies = [$sourceId, $targetId];
        sort($companies, \SORT_STRING);
        foreach ($companies as $companyId) {
            $this->projects->lockDirectory($companyId);
        }
    }

    /**
     * @param DirectorySnapshot $source
     * @param DirectorySnapshot $target
     * @param array<string, string> $existingMappings
     * @param array<string, CopyDecision> $decisions
     */
    private function plan(array $source, array $target, array $existingMappings, array $decisions): ProjectDirectionCopyPreview
    {
        $errors = $this->exchange->directoryErrors($source['projects']);
        if ([] !== $errors) {
            return new ProjectDirectionCopyPreview($source['revision'], $target['revision'], errors: $errors);
        }

        $resolved = $existingMappings;
        $systemCodes = array_flip($source['systemProjects']);
        foreach ($source['systemProjects'] as $code => $id) {
            if (isset($target['systemProjects'][$code])) {
                $resolved[$id] = $target['systemProjects'][$code];
            } else {
                $errors[] = sprintf('В компании-получателе отсутствует системный проект %s (%s).', $source['projects'][$id]['name'], $code);
            }
        }

        $targetProjects = $target['projects'];
        $targetCodes = [];
        foreach ($targetProjects as $id => $row) {
            if (null !== $row['code']) {
                $targetCodes[$row['code']] = $id;
            }
        }

        $rows = $creations = $mappings = $createdCodes = [];
        foreach ($this->tree($source['projects']) as [$project, $depth]) {
            $id = $project['id'];
            $code = $project['code'];
            $parent = $project['parentId'];
            $targetProjectId = $resolved[$id] ?? null;
            $conflictId = null === $code || null !== $targetProjectId || isset($systemCodes[$id]) ? null : ($targetCodes[$code] ?? null);
            $decision = $decisions[$id] ?? ['mode' => 'skip', 'code' => ''];
            $mode = $decision['mode'];
            $invalidCode = false;
            if (null !== $conflictId && 'rename' === $mode && (null === $parent || isset($resolved[$parent]))) {
                $code = ProjectDirection::normalizeCode($decision['code']);
                $invalidCode = null === $code || !preg_match(ProjectDirection::CODE_PATTERN, $code) || isset($targetCodes[$code]);
            }

            if (null !== $targetProjectId) {
                $action = 'Уже сопоставлен';
                $detail = isset($systemCodes[$id]) ? 'Системный проект' : 'Соответствие сохранено ранее';
                $detail .= ' · '.$targetProjects[$targetProjectId]['name'];
            } elseif (isset($systemCodes[$id])) {
                $action = 'Пропуск';
                $detail = 'Системный проект отсутствует в получателе';
            } elseif (null !== $parent && !isset($resolved[$parent])) {
                $action = 'Пропуск';
                $detail = 'Родитель не сопоставлен · '.$source['projects'][$parent]['name'];
            } elseif (null !== $conflictId && 'link' === $mode) {
                $action = 'Связать';
                $detail = $targetProjects[$conflictId]['name'];
                $resolved[$id] = $mappings[$id] = $conflictId;
            } elseif (null !== $conflictId && 'rename' !== $mode) {
                $action = 'Пропуск';
                $detail = 'Код занят';
            } elseif ($invalidCode) {
                $action = 'Пропуск';
                $detail = 'Новый код некорректен или уже занят';
                $errors[] = sprintf('Проект «%s». Укажите свободный код из 1–15 латинских букв, цифр или знаков подчёркивания. Первый символ — буква.', $project['name']);
            } else {
                if (null !== $code) {
                    if (isset($createdCodes[$code])) {
                        $errors[] = sprintf('Для проектов «%s» и «%s» выбран один код %s.', $createdCodes[$code], $project['name'], $code);
                    }
                    $createdCodes[$code] = $project['name'];
                }
                $action = 'Создать';
                $parentId = null === $parent ? null : $resolved[$parent];
                $detail = null === $parentId ? 'Корневой проект' : 'Родитель · '.($creations[$parentId] ?? $targetProjects[$parentId])['name'];
                $newId = Uuid::uuid7()->toString();
                $creations[$newId] = array_replace($project, ['id' => $newId, 'code' => $code, 'parentId' => $parentId]);
                $resolved[$id] = $mappings[$id] = $newId;
            }

            $rows[] = [
                'id' => $id,
                'name' => $project['name'],
                'sourceCode' => $project['code'],
                'code' => $code,
                'indent' => str_repeat('— ', min($depth, 8)),
                'action' => $action,
                'detail' => $detail,
                'conflict' => null === $conflictId ? null : $targetProjects[$conflictId]['name'],
                'decision' => $decision,
            ];
        }

        if ([] === $errors) {
            $errors = $this->exchange->directoryErrors($targetProjects + $creations);
        }

        return new ProjectDirectionCopyPreview($source['revision'], $target['revision'], $rows, $creations, $mappings, $errors);
    }

    /**
     * @param array<string, ProjectRow> $projects
     *
     * @return iterable<array{ProjectRow, int}>
     */
    private function tree(array $projects): iterable
    {
        uasort($projects, static fn (array $a, array $b): int => [$a['sort'], $a['name'], $a['id']] <=> [$b['sort'], $b['name'], $b['id']]);
        $children = [];
        foreach ($projects as $row) {
            $children[$row['parentId'] ?? ''][] = $row['id'];
        }

        $stack = [[array_reverse($children[''] ?? []), 0]];
        while ([] !== $stack) {
            [$ids, $depth] = array_pop($stack);
            while (null !== ($id = array_pop($ids))) {
                yield [$projects[$id], $depth];
                if (isset($children[$id])) {
                    $stack[] = [$ids, $depth];
                    $stack[] = [array_reverse($children[$id]), $depth + 1];
                    break;
                }
            }
        }
    }
}
