<?php

declare(strict_types=1);

namespace App\Ai\Service\Prompt;

use App\Ai\Dto\QaRequestContext;

final class QaPromptBuilder
{
    public function build(QaRequestContext $context): string
    {
        $lines = [];
        $lines[] = sprintf('Компания ID: %s', $context->companyId->toString());
        $lines[] = sprintf('Вопрос: %s', $context->question);

        if ($context->periodFrom || $context->periodTo) {
            $lines[] = sprintf(
                'Период: %s — %s',
                $context->periodFrom?->format('Y-m-d') ?? 'не задан',
                $context->periodTo?->format('Y-m-d') ?? 'не задан'
            );
        }

        return implode("\n", $lines);
    }
}
