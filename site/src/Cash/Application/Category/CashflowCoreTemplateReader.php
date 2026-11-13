<?php

declare(strict_types=1);

namespace App\Cash\Application\Category;

use App\Cash\Application\DTO\Template\CashflowCoreTemplateNode;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\PaymentPlan\PaymentPlanType;
use App\Cash\Exception\CashflowCoreTemplateException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Читает и проверяет шаблон ядра статей ДДС до записи в БД: любая проблема —
 * CashflowCoreTemplateException с путём узла, а не исключение Doctrine посреди транзакции.
 */
final readonly class CashflowCoreTemplateReader
{
    public const VERSION = 1;
    public const SECTIONS = [
        CashflowCategory::CODE_OPERATING,
        CashflowCategory::CODE_FINANCING,
        CashflowCategory::CODE_INVESTING,
    ];
    private const NODE_KEYS = ['name', 'description', 'direction', 'children'];
    private const MAX_LEVEL = 3;
    private const MAX_NAME_LENGTH = 255;
    private const MAX_DESCRIPTION_LENGTH = 500;

    public function __construct(
        #[Autowire('%cash.cashflow_core_template%')]
        private string $path,
    ) {
    }

    /**
     * @return array<string, list<CashflowCoreTemplateNode>> системный код группы деятельности → узлы под ней
     */
    public function read(): array
    {
        $content = @file_get_contents($this->path);
        if (false === $content) {
            throw new CashflowCoreTemplateException(sprintf('Файл шаблона ядра ДДС не найден: %s.', basename($this->path)));
        }

        return $this->parse($content);
    }

    /**
     * @return array<string, list<CashflowCoreTemplateNode>>
     */
    public function parse(string $yaml): array
    {
        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw new CashflowCoreTemplateException('Шаблон ядра ДДС не разобран: '.$e->getMessage(), 0, $e);
        }

        if (!\is_array($data) || self::VERSION !== ($data['version'] ?? null)) {
            throw new CashflowCoreTemplateException(sprintf('Ожидается шаблон ядра ДДС версии %d.', self::VERSION));
        }
        $sections = $data['sections'] ?? null;
        if (!\is_array($sections) || [] === $sections) {
            throw new CashflowCoreTemplateException('Секции sections пусты.');
        }

        $result = [];
        foreach ($sections as $code => $items) {
            if (!\in_array($code, self::SECTIONS, true)) {
                throw new CashflowCoreTemplateException(sprintf('sections.%s: ожидается одна из групп %s.', (string) $code, implode(', ', self::SECTIONS)));
            }
            if (!\is_array($items) || [] === $items || !array_is_list($items)) {
                throw new CashflowCoreTemplateException(sprintf('sections.%s: список узлов пуст или не является списком.', $code));
            }
            $result[$code] = $this->nodes($items, null, 1, 'sections.'.$code);
        }

        return $result;
    }

    /**
     * @param list<mixed> $items
     *
     * @return list<CashflowCoreTemplateNode>
     */
    private function nodes(array $items, ?PaymentPlanType $parentDirection, int $level, string $path): array
    {
        if ([] !== $items && $level > self::MAX_LEVEL) {
            throw new CashflowCoreTemplateException(sprintf('%s: глубина больше %d уровней.', $path, self::MAX_LEVEL));
        }

        $nodes = [];
        $names = [];
        foreach ($items as $index => $item) {
            $nodePath = sprintf('%s[%d]', $path, $index);
            if (!\is_array($item)) {
                throw new CashflowCoreTemplateException($nodePath.': узел должен быть объектом.');
            }

            $unknown = array_diff(array_keys($item), self::NODE_KEYS);
            if ([] !== $unknown) {
                throw new CashflowCoreTemplateException(sprintf('%s: неизвестные ключи %s.', $nodePath, implode(', ', $unknown)));
            }

            $name = $item['name'] ?? null;
            if (!\is_string($name) || '' === trim($name) || mb_strlen($name) > self::MAX_NAME_LENGTH) {
                throw new CashflowCoreTemplateException(sprintf('%s: name обязателен, до %d символов.', $nodePath, self::MAX_NAME_LENGTH));
            }
            $name = trim($name);
            if (isset($names[$name])) {
                throw new CashflowCoreTemplateException(sprintf('%s: «%s» повторяется на одном уровне.', $nodePath, $name));
            }
            $names[$name] = true;

            $direction = $this->direction($item, $parentDirection, $nodePath);

            $children = $item['children'] ?? [];
            if (!\is_array($children) || !array_is_list($children)) {
                throw new CashflowCoreTemplateException(sprintf('%s (%s): children должен быть списком.', $nodePath, $name));
            }

            $description = $item['description'] ?? null;
            if (null !== $description && (!\is_string($description) || mb_strlen(trim($description)) > self::MAX_DESCRIPTION_LENGTH)) {
                throw new CashflowCoreTemplateException(sprintf('%s (%s): description — строка до %d символов.', $nodePath, $name, self::MAX_DESCRIPTION_LENGTH));
            }
            $description = null === $description || '' === trim($description) ? null : trim($description);
            // Описание статьи — то, по чему ИИ-автораспределение выбирает статью.
            if ([] === $children && null === $description) {
                throw new CashflowCoreTemplateException(sprintf('%s (%s): у статьи обязательно описание.', $nodePath, $name));
            }

            $nodes[] = new CashflowCoreTemplateNode(
                name: $name,
                description: $description,
                direction: $direction,
                sort: ($index + 1) * 10,
                children: $this->nodes($children, $direction, $level + 1, $nodePath.'.children'),
            );
        }

        return $nodes;
    }

    /**
     * @param array<mixed> $item
     */
    private function direction(array $item, ?PaymentPlanType $parentDirection, string $nodePath): PaymentPlanType
    {
        $raw = $item['direction'] ?? null;
        if (null === $raw) {
            return $parentDirection ?? throw new CashflowCoreTemplateException($nodePath.': direction обязателен у узла верхнего уровня секции.');
        }

        $direction = \is_string($raw) ? PaymentPlanType::tryFrom($raw) : null;
        if (!\in_array($direction, [PaymentPlanType::INFLOW, PaymentPlanType::OUTFLOW], true)) {
            throw new CashflowCoreTemplateException($nodePath.': direction должен быть INFLOW или OUTFLOW.');
        }
        if (null !== $parentDirection && $direction !== $parentDirection) {
            throw new CashflowCoreTemplateException($nodePath.': direction отличается от родителя.');
        }

        return $direction;
    }
}
