<?php

declare(strict_types=1);

namespace App\Company\Application\DTO;

use App\Company\Enum\CounterpartyType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Данные формы справочника контрагентов. Форма больше не пишет в Entity напрямую:
 * название обязано пройти нормализацию, а ИНН и КПП — записываться вместе.
 */
final class CounterpartyFormData
{
    #[Assert\NotBlank(message: 'Укажите наименование.')]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\Regex(pattern: '/^\d{10}(\d{2})?$/', message: 'ИНН — 10 или 12 цифр.')]
    public ?string $inn = null;

    #[Assert\Regex(pattern: '/^\d{9}$/', message: 'КПП — 9 цифр.')]
    public ?string $kpp = null;

    #[Assert\NotNull]
    public ?CounterpartyType $type = CounterpartyType::LEGAL_ENTITY;

    /**
     * Инвариант сущности «КПП только вместе с ИНН» проверяется до Action, иначе
     * пользователь получил бы 500 вместо ошибки поля.
     */
    #[Assert\Callback]
    public function validateKppRequiresInn(ExecutionContextInterface $context): void
    {
        $hasKpp = null !== $this->kpp && '' !== trim($this->kpp);
        $hasInn = null !== $this->inn && '' !== trim($this->inn);

        if ($hasKpp && !$hasInn) {
            $context->buildViolation('КПП можно указать только вместе с ИНН.')
                ->atPath('kpp')
                ->addViolation();
        }
    }
}
