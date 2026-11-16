<?php

declare(strict_types=1);

namespace App\MarketplacePlProjection\Application\Action;

use App\Finance\Enum\PLCategoryType;
use App\Finance\Facade\PLCategoryFacade;
use App\MarketplacePlProjection\Application\DTO\TemplateSyncPlan;
use App\MarketplacePlProjection\Domain\MappingTemplate;
use App\MarketplacePlProjection\Domain\Rule;
use App\MarketplacePlProjection\Domain\TemplateRule;
use App\MarketplacePlProjection\Enum\RuleOrigin;
use App\MarketplacePlProjection\Infrastructure\Query\RuleStore;
use Doctrine\DBAL\Connection;

/**
 * Приводит `preset`-правила компании к шаблону платформы: статья — лист дерева компании с кодом
 * шаблона. Ключ с правилом `custom` не трогает; статьи нет — правила нет («не сопоставлено»);
 * `preset` на ключ вне шаблона удаляется. Повтор ничего не меняет. `$execute = false` — только план.
 */
final readonly class SyncTemplateAction
{
    public function __construct(
        private RuleStore $store,
        private PLCategoryFacade $categories,
        private Connection $connection,
    ) {
    }

    public function __invoke(string $companyId, bool $execute): TemplateSyncPlan
    {
        $categoryByCode = [];
        foreach ($this->categories->getTreeByCompanyId($companyId) as $category) {
            if (null !== $category->code && PLCategoryType::LEAF_INPUT === $category->type) {
                $categoryByCode[$category->code] = $category->id;
            }
        }

        return $this->connection->transactional(function () use ($companyId, $execute, $categoryByCode): TemplateSyncPlan {
            // Синхронизации одной компании идут по очереди: план и запись — над одним набором правил.
            $this->connection->executeStatement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['mpp_rule:'.$companyId]);

            /** @var array<string, Rule> $preset базовые preset-правила по ключу */
            $preset = [];
            $customKeys = [];
            foreach ($this->store->rules($companyId) as $rule) {
                if (RuleOrigin::CUSTOM === $rule->origin) {
                    $customKeys[$rule->key()] = true;
                } elseif (Rule::BASE_DATE === $rule->validFrom) {
                    $preset[$rule->key()] = $rule;
                }
            }

            $created = $updated = $deleted = $unchanged = $custom = 0;
            $missing = [];
            foreach (MappingTemplate::rules() as $template) {
                $key = $template->key();
                $existing = $preset[$key] ?? null;
                unset($preset[$key]);
                if (isset($customKeys[$key])) {
                    ++$custom;
                    continue;
                }
                if (null !== $template->plCode && !isset($categoryByCode[$template->plCode])) {
                    $missing[$template->plCode] = true;
                    $this->delete($execute, $companyId, $template, $existing, $deleted);
                    continue;
                }
                $categoryId = null === $template->plCode ? null : $categoryByCode[$template->plCode];
                if (null !== $existing && $existing->plCategoryId === $categoryId && MappingTemplate::VERSION === $existing->templateVersion) {
                    ++$unchanged;
                    continue;
                }
                null === $existing ? ++$created : ++$updated;
                if ($execute) {
                    $this->store->savePreset($companyId, $template, $categoryId, MappingTemplate::VERSION);
                }
            }
            foreach ($preset as $stale) {
                $this->delete($execute, $companyId, new TemplateRule($stale->kind, $stale->channel, $stale->sourceType, null), $stale, $deleted);
            }

            $codes = array_keys($missing);
            sort($codes);

            return new TemplateSyncPlan($created, $updated, $deleted, $unchanged, $custom, $codes);
        });
    }

    private function delete(bool $execute, string $companyId, TemplateRule $key, ?Rule $existing, int &$deleted): void
    {
        if (null === $existing) {
            return;
        }
        ++$deleted;
        if ($execute) {
            $this->store->deletePreset($companyId, $key);
        }
    }
}
