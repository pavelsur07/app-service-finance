<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Provider;

use App\Marketplace\Application\DTO\DefaultSaleMappingRule;
use App\Marketplace\Application\DTO\DefaultSaleMappingRuleSet;
use App\Marketplace\Application\Exception\DefaultSaleMappingConfigException;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceType;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class DefaultSaleMappingYamlProvider
{
    private const SUPPORTED_VERSION = 1;

    /** @var array<string, DefaultSaleMappingRuleSet>|null */
    private ?array $cachedRuleSets = null;

    public function __construct(private readonly string $configPath)
    {
    }

    public function getForMarketplace(MarketplaceType $marketplace): DefaultSaleMappingRuleSet
    {
        $all = $this->getAll();

        return $all[$marketplace->value] ?? new DefaultSaleMappingRuleSet($marketplace, []);
    }

    /** @return array<string, DefaultSaleMappingRuleSet> */
    public function getAll(): array
    {
        if (null !== $this->cachedRuleSets) {
            return $this->cachedRuleSets;
        }

        $this->cachedRuleSets = $this->loadRuleSets();

        return $this->cachedRuleSets;
    }

    /** @return array<string, DefaultSaleMappingRuleSet> */
    private function loadRuleSets(): array
    {
        if (!is_file($this->configPath)) {
            throw new DefaultSaleMappingConfigException(sprintf('Default sale mapping config file not found: %s', $this->configPath));
        }

        try {
            $data = Yaml::parseFile($this->configPath);
        } catch (ParseException $exception) {
            throw new DefaultSaleMappingConfigException(sprintf('Failed to parse YAML config: %s', $exception->getMessage()), previous: $exception);
        }

        if (!is_array($data)) {
            throw new DefaultSaleMappingConfigException('Default sale mapping YAML root must be an array.');
        }

        if (($data['version'] ?? null) !== self::SUPPORTED_VERSION) {
            throw new DefaultSaleMappingConfigException('Unsupported default sale mapping version. Expected version: 1.');
        }

        if (!isset($data['marketplaces']) || !is_array($data['marketplaces'])) {
            throw new DefaultSaleMappingConfigException('Missing or invalid "marketplaces" section in default sale mapping config.');
        }

        $result = [];
        foreach ($data['marketplaces'] as $marketplaceKey => $marketplaceConfig) {
            $marketplace = MarketplaceType::tryFrom((string) $marketplaceKey);
            if (null === $marketplace) {
                throw new DefaultSaleMappingConfigException(sprintf('Unknown marketplace "%s" in default sale mapping config.', (string) $marketplaceKey));
            }

            if (!is_array($marketplaceConfig)) {
                throw new DefaultSaleMappingConfigException(sprintf('Marketplace "%s" config must be an array.', $marketplace->value));
            }

            $saleMappings = $marketplaceConfig['sale_mappings'] ?? null;
            if (!is_array($saleMappings)) {
                throw new DefaultSaleMappingConfigException(sprintf('Marketplace "%s" must contain array "sale_mappings".', $marketplace->value));
            }

            $result[$marketplace->value] = new DefaultSaleMappingRuleSet(
                $marketplace,
                $this->buildRules($marketplace, $saleMappings),
            );
        }

        return $result;
    }

    /**
     * @param array<int|string, mixed> $saleMappings
     *
     * @return list<DefaultSaleMappingRule>
     */
    private function buildRules(MarketplaceType $marketplace, array $saleMappings): array
    {
        $rules = [];
        $seenAmountSources = [];

        foreach ($saleMappings as $index => $rawRule) {
            if (!is_array($rawRule)) {
                throw new DefaultSaleMappingConfigException(sprintf('Rule #%s in marketplace "%s" must be an array.', (string) $index, $marketplace->value));
            }

            $amountSourceValue = $this->requireNonEmptyString($rawRule, 'amount_source', $marketplace->value, (string) $index);
            $amountSource = AmountSource::tryFrom($amountSourceValue);
            if (null === $amountSource) {
                throw new DefaultSaleMappingConfigException(sprintf('Unknown amount_source "%s" in marketplace "%s".', $amountSourceValue, $marketplace->value));
            }

            $restriction = $amountSource->getMarketplaceRestriction();
            if (null !== $restriction && $restriction !== $marketplace) {
                throw new DefaultSaleMappingConfigException(sprintf('Amount source "%s" is only available for marketplace "%s", but configured for "%s".', $amountSource->value, $restriction->value, $marketplace->value));
            }

            if (isset($seenAmountSources[$amountSource->value])) {
                throw new DefaultSaleMappingConfigException(sprintf('Duplicate amount_source "%s" in marketplace "%s".', $amountSource->value, $marketplace->value));
            }
            $seenAmountSources[$amountSource->value] = true;

            $plCode = $this->requireNonEmptyString($rawRule, 'pl_code', $marketplace->value, (string) $index);

            if (!array_key_exists('is_negative', $rawRule) || !is_bool($rawRule['is_negative'])) {
                throw new DefaultSaleMappingConfigException(sprintf('Rule "%s" in marketplace "%s" must contain boolean "is_negative".', $amountSource->value, $marketplace->value));
            }

            $description = $rawRule['description'] ?? null;
            if (null !== $description && (!is_string($description) || '' === trim($description))) {
                throw new DefaultSaleMappingConfigException(sprintf('Rule "%s" in marketplace "%s" has invalid "description" value.', $amountSource->value, $marketplace->value));
            }

            $rules[] = new DefaultSaleMappingRule(
                marketplace: $marketplace,
                amountSource: $amountSource,
                plCode: $plCode,
                isNegative: $rawRule['is_negative'],
                description: null === $description ? null : trim($description),
            );
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $rawRule
     */
    private function requireNonEmptyString(array $rawRule, string $field, string $marketplace, string $index): string
    {
        $value = $rawRule[$field] ?? null;
        if (!is_string($value) || '' === trim($value)) {
            throw new DefaultSaleMappingConfigException(sprintf('Rule #%s in marketplace "%s" must contain non-empty string "%s".', $index, $marketplace, $field));
        }

        return trim($value);
    }
}
