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
        return [
            'version' => self::VERSION,
            'companyId' => $companyId,
            'projects' => array_values($this->repository->readDirectory($companyId)['projects']),
        ];
    }

    /** @phpstan-impure */
    public function preview(string $companyId, string $json): ProjectDirectionImportPreview
    {
        $snapshot = $this->repository->readDirectory($companyId);

        return $this->validate($companyId, $json, $snapshot['revision'], $snapshot['projects']);
    }

    /** @param array<string, ProjectRow> $current */
    public function validate(string $companyId, string $json, ?string $revision, array $current): ProjectDirectionImportPreview
    {
        try {
            $file = json_decode($json, depth: 16, flags: \JSON_THROW_ON_ERROR);
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
            return new ProjectDirectionImportPreview($revision, errors: $errors);
        }

        foreach ($this->repository->findConflictingIds($companyId, array_keys(array_diff_key($incoming, $current))) as $id) {
            $errors[] = "Запись $numbers[$id]: UUID уже занят в другой компании.";
        }
        array_push($errors, ...$this->directoryErrors(array_replace($current, $incoming), $numbers));
        if ([] !== $errors) {
            return new ProjectDirectionImportPreview($revision, errors: $errors);
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

        return new ProjectDirectionImportPreview($revision, $created, $updated, $unchanged, changes: $changes);
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
        $sort = $value->sort;
        $parentId = $value->parentId;

        $error = match (true) {
            !\is_string($id) || 36 !== \strlen($id) || !Uuid::isValid($id) => 'UUID проекта должен быть корректным.',
            !\is_string($name) || '' === trim($name) || mb_strlen($name) > 255 || str_contains($name, "\0") => 'наименование должно содержать от 1 до 255 символов.',
            null !== $code && !\is_string($code) => 'код должен быть строкой или null.',
            null !== $description && (!\is_string($description) || mb_strlen($description) > ProjectDirection::DESCRIPTION_LENGTH || str_contains($description, "\0")) => 'описание должно содержать до 300 символов или быть null.',
            !\is_int($sort) || $sort < -2147483648 || $sort > 2147483647 => 'сортировка должна быть целым числом от −2147483648 до 2147483647.',
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
     * @param array<string, int>        $numbers
     *
     * @return list<string>
     */
    private function directoryErrors(array $projects, array $numbers): array
    {
        $errors = $codes = $checked = [];
        foreach ($projects as $id => $row) {
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
