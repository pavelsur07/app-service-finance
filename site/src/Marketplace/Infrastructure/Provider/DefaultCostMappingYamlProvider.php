<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Provider;

use App\Marketplace\Application\DTO\DefaultCostMappingRule;
use App\Marketplace\Application\DTO\DefaultCostMappingRuleSet;
use App\Marketplace\Application\Exception\DefaultCostMappingConfigException;
use App\Marketplace\Enum\DefaultCostMappingConfidence;
use App\Marketplace\Enum\MarketplaceType;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class DefaultCostMappingYamlProvider
{
    private const SUPPORTED_VERSION = 1;
    
    /** @var array<string, DefaultCostMappingRuleSet>|null */
    private ?array $cachedRuleSets = null;

    public function __construct(private readonly string $configPath)
    {
    }

    public function getForMarketplace(MarketplaceType $marketplace): DefaultCostMappingRuleSet
    {
        $all = $this->getAll();

        return $all[$marketplace->value] ?? new DefaultCostMappingRuleSet($marketplace, []);
    }

    /** @return array<string, DefaultCostMappingRuleSet> */
    public function getAll(): array
    {
        if ($this->cachedRuleSets !== null) {
            return $this->cachedRuleSets;
        }

        $this->cachedRuleSets = $this->loadRuleSets();

        return $this->cachedRuleSets;
    }

    /** @return array<string, DefaultCostMappingRuleSet> */
    private function loadRuleSets(): array
    {
        if (!is_file($this->configPath)) {
            throw new DefaultCostMappingConfigException(sprintf('Default cost mapping config file not found: %s', $this->configPath));
        }

        try {
            $data = Yaml::parseFile($this->configPath);
        } catch (ParseException $exception) {
            throw new DefaultCostMappingConfigException(
                sprintf('Failed to parse YAML config: %s', $exception->getMessage()),
                previous: $exception,
            );
        }

        if (!is_array($data)) {
            throw new DefaultCostMappingConfigException('Default cost mapping YAML root must be an array.');
        }

        if (($data['version'] ?? null) !== self::SUPPORTED_VERSION) {
            throw new DefaultCostMappingConfigException('Unsupported default cost mapping version. Expected version: 1.');
        }

        if (!isset($data['marketplaces']) || !is_array($data['marketplaces'])) {
            throw new DefaultCostMappingConfigException('Missing or invalid "marketplaces" section in default cost mapping config.');
        }

        $result = [];
        foreach ($data['marketplaces'] as $marketplaceKey => $marketplaceConfig) {
            $marketplace = MarketplaceType::tryFrom((string) $marketplaceKey);
            if ($marketplace === null) {
                throw new DefaultCostMappingConfigException(sprintf('Unknown marketplace "%s" in default cost mapping config.', (string) $marketplaceKey));
            }

            if (!is_array($marketplaceConfig)) {
                throw new DefaultCostMappingConfigException(sprintf('Marketplace "%s" config must be an array.', $marketplace->value));
            }

            $costMappings = $marketplaceConfig['cost_mappings'] ?? null;
            if (!is_array($costMappings)) {
                throw new DefaultCostMappingConfigException(sprintf('Marketplace "%s" must contain array "cost_mappings".', $marketplace->value));
            }

            $rules = [];
            $seenCostCodes = [];
            foreach ($costMappings as $index => $rawRule) {
                if (!is_array($rawRule)) {
                    throw new DefaultCostMappingConfigException(sprintf('Rule #%d in marketplace "%s" must be an array.', $index, $marketplace->value));
                }

                $costCode = $this->requireNonEmptyString($rawRule, 'cost_code', $marketplace->value, $index);
                $plCode = $this->requireNonEmptyString($rawRule, 'pl_code', $marketplace->value, $index);

                if (!array_key_exists('include_in_pl', $rawRule) || !is_bool($rawRule['include_in_pl'])) {
                    throw new DefaultCostMappingConfigException(sprintf('Rule #%d in marketplace "%s" must contain boolean "include_in_pl".', $index, $marketplace->value));
                }

                if (isset($seenCostCodes[$costCode])) {
                    throw new DefaultCostMappingConfigException(sprintf('Duplicate cost_code "%s" in marketplace "%s".', $costCode, $marketplace->value));
                }
                $seenCostCodes[$costCode] = true;

                $isNegative = $rawRule['is_negative'] ?? true;
                if (!is_bool($isNegative)) {
                    throw new DefaultCostMappingConfigException(sprintf('Rule #%d in marketplace "%s" has invalid "is_negative" value.', $index, $marketplace->value));
                }

                $rawConfidence = $rawRule['confidence'] ?? DefaultCostMappingConfidence::HIGH->value;
                $confidence = is_string($rawConfidence) ? DefaultCostMappingConfidence::tryFrom($rawConfidence) : null;
                if ($confidence === null) {
                    throw new DefaultCostMappingConfigException(sprintf('Rule #%d in marketplace "%s" has invalid confidence "%s".', $index, $marketplace->value, (string) $rawConfidence));
                }

                $note = $rawRule['note'] ?? null;
                if ($note !== null && !is_string($note)) {
                    throw new DefaultCostMappingConfigException(sprintf('Rule #%d in marketplace "%s" has invalid "note" value.', $index, $marketplace->value));
                }

                $rules[] = new DefaultCostMappingRule(
                    marketplace: $marketplace,
                    costCode: $costCode,
                    plCode: $plCode,
                    includeInPl: $rawRule['include_in_pl'],
                    isNegative: $isNegative,
                    confidence: $confidence,
                    note: $note,
                );
            }

            $result[$marketplace->value] = new DefaultCostMappingRuleSet($marketplace, $rules);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $rawRule
     */
    private function requireNonEmptyString(array $rawRule, string $field, string $marketplace, int $index): string
    {
        $value = $rawRule[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new DefaultCostMappingConfigException(sprintf('Rule #%d in marketplace "%s" must contain non-empty string "%s".', $index, $marketplace, $field));
        }

        return trim($value);
    }
}
