<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\DTO\CategoryGroupSuggestion;
use App\Ingestion\Entity\CategoryGroup;
use App\Ingestion\Entity\CategoryGroupRule;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Repository\CategoryGroupRepository;
use App\Ingestion\Repository\CategoryGroupRuleRepository;
use Psr\Log\LoggerInterface;

/**
 * Подсказывает группу для нового типа начисления Ozon по `name` и
 * `description` из справочника — правилами `ingest_category_group_rules`.
 *
 * Правила и группы читаются один раз до `reset()`, результат кэшируется по
 * тексту: разбор зовёт подсказчик на каждую строку. Правило с невалидным
 * выражением или ссылкой на группу вне закрытого списка пропускается с WARNING;
 * правило, на котором PCRE упал во время работы (лимит бэктрекинга, JIT-стек),
 * выключается до `reset()` тоже с WARNING — сломанное правило не должно ни
 * ронять нормализацию, ни молча перестать срабатывать.
 */
final class OzonAccrualGroupSuggester
{
    /**
     * @var array<int, array{rule: CategoryGroupRule, regex: string, group: CategoryGroup}>|null
     */
    private ?array $compiledRules = null;

    /**
     * @var array<string, CategoryGroupSuggestion|null>
     */
    private array $suggestions = [];

    public function __construct(
        private readonly CategoryGroupRuleRepository $ruleRepository,
        private readonly CategoryGroupRepository $groupRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function suggest(string $name, ?string $description): ?CategoryGroupSuggestion
    {
        $text = trim($name.' '.($description ?? ''));
        if ('' === $text) {
            return null;
        }

        if (array_key_exists($text, $this->suggestions)) {
            return $this->suggestions[$text];
        }

        return $this->suggestions[$text] = $this->match($text);
    }

    private function match(string $text): ?CategoryGroupSuggestion
    {
        foreach ($this->compiledRules() as $index => $compiled) {
            $matched = preg_match($compiled['regex'], $text);
            if (false === $matched) {
                $this->logger->warning('Ozon category group rule failed at runtime and is disabled until reset.', [
                    'ruleId' => $compiled['rule']->getId(),
                    'pcreError' => preg_last_error_msg(),
                ]);
                unset($this->compiledRules[$index]);
                continue;
            }
            if (1 !== $matched) {
                continue;
            }

            return new CategoryGroupSuggestion(
                groupCode: $compiled['group']->getCode(),
                groupLabel: $compiled['group']->getLabel(),
                confidence: $compiled['rule']->getConfidence(),
                ruleId: $compiled['rule']->getId(),
            );
        }

        return null;
    }

    public function reset(): void
    {
        $this->compiledRules = null;
        $this->suggestions = [];
    }

    /**
     * @return array<int, array{rule: CategoryGroupRule, regex: string, group: CategoryGroup}>
     */
    private function compiledRules(): array
    {
        if (null !== $this->compiledRules) {
            return $this->compiledRules;
        }

        $groups = $this->groupRepository->findBySourceIndexedByCode(IngestSource::OZON);
        $compiled = [];
        $skipped = [];

        foreach ($this->ruleRepository->findActiveBySource(IngestSource::OZON) as $rule) {
            $group = $groups[$rule->getGroupCode()] ?? null;
            $regex = $rule->regex();
            if (null === $group || !self::isValidRegex($regex)) {
                $skipped[] = $rule->getId();
                continue;
            }

            $compiled[] = ['rule' => $rule, 'regex' => $regex, 'group' => $group];
        }

        if ([] !== $skipped) {
            $this->logger->warning('Ozon category group rules skipped: invalid pattern or unknown group.', [
                'ruleIds' => $skipped,
            ]);
        }

        return $this->compiledRules = $compiled;
    }

    private static function isValidRegex(string $regex): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            return false !== preg_match($regex, '');
        } finally {
            restore_error_handler();
        }
    }
}
