<?php

namespace App\Entity;

use App\Enum\ConditionField;
use App\Enum\ConditionOperator;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class AutoCategoryCondition
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(inversedBy: 'conditions')]
    #[ORM\JoinColumn(nullable: false)]
    private AutoCategoryTemplate $template;

    #[ORM\Column(enumType: ConditionField::class)]
    private ConditionField $field;

    #[ORM\Column(enumType: ConditionOperator::class)]
    private ConditionOperator $operator;

    #[ORM\Column(type: 'text')]
    private string $value = '';

    #[ORM\Column]
    private bool $caseSensitive = false;

    #[ORM\Column]
    private bool $negate = false;

    #[ORM\Column(type: 'integer')]
    private int $position = 0;

    public function __construct(string $id, AutoCategoryTemplate $template)
    {
        $this->id = $id;
        $this->template = $template;
    }

    public function getId(): string { return $this->id; }
    public function getTemplate(): AutoCategoryTemplate { return $this->template; }
    public function getField(): ConditionField { return $this->field; }
    public function setField(ConditionField $f): self { $this->field = $f; return $this; }
    public function getOperator(): ConditionOperator { return $this->operator; }
    public function setOperator(ConditionOperator $o): self { $this->operator = $o; return $this; }
    public function getValue(): string { return $this->value; }
    public function setValue(string $v): self { $this->value = $v; return $this; }
    public function isCaseSensitive(): bool { return $this->caseSensitive; }
    public function setCaseSensitive(bool $c): self { $this->caseSensitive = $c; return $this; }
    public function isNegate(): bool { return $this->negate; }
    public function setNegate(bool $n): self { $this->negate = $n; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $p): self { $this->position = $p; return $this; }
}
