<?php

namespace App\Admin\Form\Analytics;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class PlRecalcFormData
{
    #[Assert\Choice(choices: ['day', 'week', 'month'], message: 'Недопустимый preset периода.')]
    public ?string $preset = 'month';

    public ?\DateTimeImmutable $from = null;

    public ?\DateTimeImmutable $to = null;

    public bool $recalcPl = true;

    public bool $warmupSnapshot = true;

    #[Assert\Callback]
    public function validatePeriod(ExecutionContextInterface $context): void
    {
        $hasPreset = null !== $this->preset && '' !== $this->preset;
        $hasCustomDates = null !== $this->from || null !== $this->to;

        if ($hasPreset && $hasCustomDates) {
            $context->buildViolation('Выберите preset либо укажите from/to.')
                ->atPath('preset')
                ->addViolation();

            return;
        }

        if (!$hasPreset) {
            if (null === $this->from || null === $this->to) {
                $context->buildViolation('Для custom-периода нужно заполнить обе даты from и to.')
                    ->atPath('from')
                    ->addViolation();

                return;
            }

            if ($this->from > $this->to) {
                $context->buildViolation('Дата from должна быть меньше или равна to.')
                    ->atPath('from')
                    ->addViolation();
            }
        }
    }
}

