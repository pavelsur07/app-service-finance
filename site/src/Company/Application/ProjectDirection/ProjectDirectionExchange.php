<?php

declare(strict_types=1);

namespace App\Company\Application\ProjectDirection;

use App\Company\Entity\ProjectDirection;
use App\Company\Repository\ProjectDirectionRepository;
use Ramsey\Uuid\Uuid;

/** @phpstan-import-type ProjectRow from ProjectDirectionRepository */
final readonly class ProjectDirectionExchange
{
    private const VERSION = 1;
    // Даже минимальная строка проекта занимает больше 100 байт и содержит 5 запятых.
    private const MAX_JSON_CONTAINERS = 350_000;
    private const MAX_JSON_SEPARATORS = 2_250_000;
    private const JSON_SKIP_STRINGS = '"(?:[^"\\\\]++|\\\\.)*+"(*SKIP)(*F)';
    private const MAX_ERRORS = 100;
    private const REQUIRED_FIELDS = ['id', 'name', 'code', 'description', 'sort', 'parentId'];

    public function __construct(private ProjectDirectionRepository $repository)
    {
    }

    /**
     * @return array{version: int, companyId: string, projects: list<ProjectRow>}
     *
     * @phpstan-impure
     */
    public function export(string $companyId): array
    {
        return $this->document($companyId, $this->repository->readDirectory($companyId)['projects']);
    }

    /**
     * @param array<string, ProjectRow> $projects
     *
     * @return array{version: int, companyId: string, projects: list<ProjectRow>}
     */
    public function document(string $companyId, array $projects): array
    {
        return [
            'version' => self::VERSION,
            'companyId' => $companyId,
            'projects' => array_values($projects),
        ];
    }

    /** @phpstan-impure */
    public function preview(string $companyId, string $json): ProjectDirectionImportPreview
    {
        // Ограничения учитывают компактные выгрузки в пределах 32 МиБ.
        if ($this->exceedsJsonBudget($json, '[{\[]', self::MAX_JSON_CONTAINERS)
            || $this->exceedsJsonBudget($json, ',', self::MAX_JSON_SEPARATORS)
        ) {
            return new ProjectDirectionImportPreview(null, errors: ['JSON содержит слишком много элементов для предпросмотра.']);
        }
        $snapshot = $this->repository->readDirectory($companyId);

        return $this->validate($companyId, $json, $snapshot['revision'], $snapshot['projects']);
    }

    private function exceedsJsonBudget(string $json, string $tokenPattern, int $limit): bool
    {
        $count = preg_match_all('~'.self::JSON_SKIP_STRINGS.'|'.$tokenPattern.'~s', $json);

        return false === $count || $count > $limit;
    }

    /** @param array<string, ProjectRow> $current */
    public function validate(string $companyId, string $json, ?string $revision, array $current): ProjectDirectionImportPreview
    {
        try {
            $file = json_decode($json, depth: 4, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new ProjectDirectionImportPreview($revision, errors: ['Некорректный JSON.']);
        }

        if (!$file instanceof \stdClass || self::VERSION !== ($file->version ?? null)) {
            return new ProjectDirectionImportPreview($revision, errors: ['Поддерживаемая версия файла — 1.']);
        }
        if (!\is_string($file->companyId ?? null) || $companyId !== strtolower($file->companyId)) {
            return new ProjectDirectionImportPreview($revision, errors: ['Файл предназначен для другой компании.']);
        }
        if (!\is_array($file->projects ?? null)) {
            return new ProjectDirectionImportPreview($revision, errors: ['Поле projects должно содержать массив проектов.']);
        }

        $incoming = $numbers = $codes = $errors = [];
        foreach ($file->projects as $index => $value) {
            if (\count($errors) >= self::MAX_ERRORS) {
                break;
            }
            $number = $index + 1;
            $row = $this->readRow($value, $number, $errors);
            if (null === $row) {
                continue;
            }
            $id = $row['id'];
            $code = $row['code'];
            if (isset($numbers[$id])) {
                $errors[] = "Запись $number: UUID повторяется в записи $numbers[$id].";
            }
            if (null !== $code && isset($codes[$code])) {
                $errors[] = "Запись $number: код повторяется в записи $codes[$code].";
            }
            $incoming[$id] = $row;
            $numbers[$id] ??= $number;
            if (null !== $code) {
                $codes[$code] ??= $number;
            }
        }
        if ([] !== $errors) {
            return $this->invalid($revision, $errors);
        }

        foreach ($this->repository->findConflictingIds($companyId, array_keys(array_diff_key($incoming, $current))) as $id) {
            $errors[] = "Запись $numbers[$id]: UUID уже занят в другой компании.";
            if (\count($errors) >= self::MAX_ERRORS) {
                return $this->invalid($revision, $errors);
            }
        }
        array_push($errors, ...$this->directoryErrors(array_replace($current, $incoming), $numbers));
        if ([] !== $errors) {
            return $this->invalid($revision, $errors);
        }

        $created = $updated = $unchanged = 0;
        $changes = [];
        foreach ($incoming as $id => $row) {
            if ($row === ($current[$id] ?? null)) {
                ++$unchanged;
                continue;
            }
            if (isset($current[$id])) {
                ++$updated;
            } else {
                ++$created;
            }
            $changes[$id] = $row;
        }

        return new ProjectDirectionImportPreview($revision, $created, $updated, $unchanged, changes: $changes, projects: $incoming);
    }

    /** @param list<string> $errors */
    private function invalid(?string $revision, array $errors): ProjectDirectionImportPreview
    {
        if (\count($errors) >= self::MAX_ERRORS) {
            $errors = \array_slice($errors, 0, self::MAX_ERRORS);
            $errors[] = 'Показаны первые 100 ошибок. Исправьте их и загрузите файл заново.';
        }

        return new ProjectDirectionImportPreview($revision, errors: $errors);
    }

    /**
     * @param list<string> $errors
     *
     * @return ?ProjectRow
     */
    private function readRow(mixed $value, int $number, array &$errors): ?array
    {
        if (!$value instanceof \stdClass || [] !== array_diff(self::REQUIRED_FIELDS, array_keys(get_object_vars($value)))) {
            $errors[] = "Запись $number: требуются поля id, name, code, description, sort и parentId.";

            return null;
        }

        $id = $value->id;
        $name = $value->name;
        $code = $value->code;
        $description = $value->description;
        if (\is_string($description)) {
            $description = ProjectDirection::normalizeDescriptionNewlines($description);
        }
        $sort = $value->sort;
        $parentId = $value->parentId;

        $error = match (true) {
            !\is_string($id) || 36 !== \strlen($id) || !Uuid::isValid($id) => 'UUID проекта должен быть корректным.',
            !\is_string($name) || '' === trim($name) || mb_strlen($name) > 255 || str_contains($name, "\0") => 'наименование должно содержать от 1 до 255 символов.',
            null !== $code && !\is_string($code) => 'код должен быть строкой или null.',
            null !== $description && (!\is_string($description) || mb_strlen($description) > ProjectDirection::DESCRIPTION_LENGTH || str_contains($description, "\0")) => 'описание должно содержать до 300 символов или быть null.',
            !\is_int($sort) || $sort < ProjectDirection::MIN_SORT || $sort > ProjectDirection::MAX_SORT => 'сортировка должна быть целым числом от −2147483648 до 2147483647.',
            null !== $parentId && (!\is_string($parentId) || 36 !== \strlen($parentId) || !Uuid::isValid($parentId)) => 'родитель должен быть корректным UUID или null.',
            default => null,
        };
        if (null !== $error) {
            $errors[] = "Запись $number: $error";

            return null;
        }

        $code = ProjectDirection::normalizeCode($code);
        if (null !== $code && !preg_match(ProjectDirection::CODE_PATTERN, $code)) {
            $errors[] = "Запись $number: код должен начинаться с латинской буквы и содержать до 15 латинских букв, цифр или знаков подчёркивания.";

            return null;
        }

        return [
            'id' => strtolower($id),
            'name' => $name,
            'code' => $code,
            'description' => ProjectDirection::normalizeDescription($description),
            'sort' => $sort,
            'parentId' => null === $parentId ? null : strtolower($parentId),
        ];
    }

    /**
     * @param array<string, ProjectRow> $projects
     * @param array<string, int> $numbers
     *
     * @return list<string>
     */
    private function directoryErrors(array $projects, array $numbers): array
    {
        $errors = $codes = $checked = [];
        foreach ($projects as $id => $row) {
            if (\count($errors) >= self::MAX_ERRORS) {
                break;
            }
            $label = isset($numbers[$id]) ? 'Запись '.$numbers[$id] : 'Проект '.$id;
            $parentId = $row['parentId'];
            if (null !== $parentId && !isset($projects[$parentId])) {
                $errors[] = "$label: родитель $parentId отсутствует в справочнике компании.";
            }

            $code = $row['code'];
            if (null !== $code) {
                if (isset($codes[$code])) {
                    $conflict = $codes[$code];
                    $label = isset($numbers[$conflict]) ? 'Запись '.$numbers[$conflict] : $label;
                    $errors[] = "$label: код $code уже используется в справочнике.";
                }
                $codes[$code] = $id;
            }

            $path = [];
            $node = $id;
            while (null !== $node && isset($projects[$node]) && !isset($checked[$node]) && !isset($path[$node])) {
                $path[$node] = \count($path);
                $node = $projects[$node]['parentId'];
            }
            if (null !== $node && isset($path[$node])) {
                $cycle = \array_slice(array_keys($path), $path[$node]);
                $label = 'Проект '.$node;
                foreach ($cycle as $member) {
                    if (isset($numbers[$member])) {
                        $label = 'Запись '.$numbers[$member];
                        break;
                    }
                }
                $errors[] = "$label: цикл в дереве проектов.";
            }
            $checked += $path;
        }

        return $errors;
    }
}
