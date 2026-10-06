<?php

declare(strict_types=1);

namespace App\Balance\Application;

use App\Balance\DTO\BalanceStructureTemplateNode;
use App\Balance\Enum\BalanceCategoryType;
use App\Balance\Exception\BalanceStructureTemplateException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Читает и проверяет шаблон структуры баланса до того, как сид начнёт писать в БД:
 * любая проблема — BalanceStructureTemplateException с путём узла, а не исключение
 * Doctrine на середине транзакции.
 */
final readonly class BalanceStructureTemplateReader
{
    public const VERSION = 1;
    private const MAX_LEVEL = 4;
    private const MAX_NODES = 200;
    private const MAX_NAME_LENGTH = 255;
    private const CODE_PATTERN = '/^[A-Z][A-Z0-9_]{0,63}$/';

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/balance/default_structure.yaml')]
        private string $path,
    ) {
    }

    /**
     * @return list<BalanceStructureTemplateNode>
     */
    public function read(): array
    {
        $content = @file_get_contents($this->path);
        if (false === $content) {
            throw new BalanceStructureTemplateException('Файл шаблона структуры баланса не найден.');
        }

        return $this->parse($content);
    }

    /**
     * @return list<BalanceStructureTemplateNode>
     */
    public function parse(string $yaml): array
    {
        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw new BalanceStructureTemplateException('Шаблон структуры баланса не разобран: '.$e->getMessage(), 0, $e);
        }

        if (!\is_array($data) || self::VERSION !== ($data['version'] ?? null)) {
            throw new BalanceStructureTemplateException(sprintf('Ожидается шаблон версии %d.', self::VERSION));
        }
        $articles = $data['articles'] ?? null;
        if (!\is_array($articles) || [] === $articles || !array_is_list($articles)) {
            throw new BalanceStructureTemplateException('Список articles пуст или не является списком.');
        }

        $seen = [];

        return $this->nodes($articles, null, 1, 'articles', $seen);
    }

    /**
     * @param array<mixed> $items
     * @param array<string, true> $seen
     *
     * @return list<BalanceStructureTemplateNode>
     */
    private function nodes(array $items, ?BalanceCategoryType $parentType, int $level, string $path, array &$seen): array
    {
        if ($level > self::MAX_LEVEL) {
            throw new BalanceStructureTemplateException(sprintf('%s: глубина больше %d уровней.', $path, self::MAX_LEVEL));
        }

        $nodes = [];
        foreach (array_values($items) as $index => $item) {
            $nodePath = sprintf('%s[%d]', $path, $index);
            if (!\is_array($item)) {
                throw new BalanceStructureTemplateException($nodePath.': узел должен быть объектом.');
            }
            if (\count($seen) >= self::MAX_NODES) {
                throw new BalanceStructureTemplateException(sprintf('Узлов больше %d.', self::MAX_NODES));
            }

            $code = $item['code'] ?? null;
            if (!\is_string($code) || 1 !== preg_match(self::CODE_PATTERN, $code)) {
                throw new BalanceStructureTemplateException($nodePath.': code обязателен, формат A-Z, 0-9, _, до 64 символов.');
            }
            if (isset($seen[$code])) {
                throw new BalanceStructureTemplateException(sprintf('%s: code %s повторяется.', $nodePath, $code));
            }
            $seen[$code] = true;

            $name = $item['name'] ?? null;
            if (!\is_string($name) || '' === trim($name) || mb_strlen($name) > self::MAX_NAME_LENGTH) {
                throw new BalanceStructureTemplateException(sprintf('%s (%s): name обязателен, до %d символов.', $nodePath, $code, self::MAX_NAME_LENGTH));
            }

            $type = \is_string($item['type'] ?? null) ? BalanceCategoryType::tryFrom($item['type']) : null;
            if (null === $type) {
                throw new BalanceStructureTemplateException(sprintf('%s (%s): type должен быть asset или passive.', $nodePath, $code));
            }
            if (null !== $parentType && $type !== $parentType) {
                throw new BalanceStructureTemplateException(sprintf('%s (%s): сторона баланса отличается от родителя.', $nodePath, $code));
            }

            $kind = $item['kind'] ?? null;
            if (!\in_array($kind, ['group', 'article'], true)) {
                throw new BalanceStructureTemplateException(sprintf('%s (%s): kind должен быть group или article.', $nodePath, $code));
            }

            $childItems = $item['children'] ?? [];
            if (!\is_array($childItems) || ([] !== $childItems && !array_is_list($childItems))) {
                throw new BalanceStructureTemplateException(sprintf('%s (%s): children должен быть списком.', $nodePath, $code));
            }
            if ('article' === $kind && [] !== $childItems) {
                throw new BalanceStructureTemplateException(sprintf('%s (%s): у конечной статьи не бывает children.', $nodePath, $code));
            }

            $nodes[] = new BalanceStructureTemplateNode(
                code: $code,
                name: trim($name),
                type: $type,
                kind: $kind,
                sortOrder: ($index + 1) * 10,
                children: $this->nodes($childItems, $type, $level + 1, $nodePath.'.children', $seen),
            );
        }

        return $nodes;
    }
}
