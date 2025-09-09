<?php

namespace App\Service\AutoCategory;

use App\Entity\AutoCategoryCondition;

interface ConditionEvaluatorInterface
{
    public function isConditionMatched(array $operation, AutoCategoryCondition $condition): bool;
}
