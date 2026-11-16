<?php

declare(strict_types=1);

namespace App\MoySklad\Application\DTO;

use App\MoySklad\Enum\AutomaticMappingDecisionStatus;
use Webmozart\Assert\Assert;

final readonly class AutomaticMappingDecision
{
    public string $reason;

    /** @param list<string> $matchedRules */
    public function __construct(
        public AutomaticMappingDecisionStatus $status,
        public ?CatalogTargetDTO $target = null,
        public array $matchedRules = [],
        public ?string $comparedSize = null,
        public ?string $selectedCharacteristicId = null,
        /** @var list<AutomaticMappingCandidate> */
        public array $candidates = [],
    ) {
        Assert::true((AutomaticMappingDecisionStatus::READY === $status) === (null !== $target));
        $this->reason = $status->label();
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(AutomaticMappingDecisionStatus::from($data['status']),
            null === $data['target'] ? null : new CatalogTargetDTO(...$data['target']), $data['matchedRules'], $data['comparedSize'], $data['selectedCharacteristicId'],
            array_values(array_map(static fn (array $candidate): AutomaticMappingCandidate => new AutomaticMappingCandidate(new CatalogTargetDTO(...$candidate['target']), $candidate['parentArticle'], $candidate['leaf']), $data['candidates'] ?? [])));
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->status, $this->target, $this->matchedRules, $this->comparedSize, $this->selectedCharacteristicId, $this->candidates], \JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    public function getRuleLabels(): array
    {
        return array_map(static fn (string $rule): string => match ($rule) {
            'direct_article' => 'Артикул позиции', 'parent_article_size' => 'Артикул родителя и размер',
            'exact_code' => 'Точный код позиции', 'wb_article_alias' => 'Алиас WB (конечный W/w)', 'exact_barcode' => 'Точный штрихкод', default => $rule,
        }, $this->matchedRules);
    }
}
