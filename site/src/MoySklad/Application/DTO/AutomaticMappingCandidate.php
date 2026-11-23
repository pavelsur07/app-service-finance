<?php

declare(strict_types=1);

namespace App\MoySklad\Application\DTO;

/**
 * linkedBarcode — кандидат найден через листинг другой площадки с тем же штрихкодом, уже связанный с ним;
 * linkedElsewhere — кандидат уже связан с каким-либо листингом (приоритет при нескольких кандидатах).
 * Оба признака в JSON не пишутся: они меняются от чужих связей, а итог решения и так виден в status/target/matchedRules.
 */
final readonly class AutomaticMappingCandidate implements \JsonSerializable
{
    public function __construct(public CatalogTargetDTO $target, public ?string $parentArticle = null, public bool $leaf = true, public bool $linkedBarcode = false, public bool $linkedElsewhere = false)
    {
    }

    /** @return array{target: CatalogTargetDTO, parentArticle: ?string, leaf: bool} */
    public function jsonSerialize(): array
    {
        return ['target' => $this->target, 'parentArticle' => $this->parentArticle, 'leaf' => $this->leaf];
    }
}
