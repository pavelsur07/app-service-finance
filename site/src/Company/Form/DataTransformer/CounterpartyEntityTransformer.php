<?php

declare(strict_types=1);

namespace App\Company\Form\DataTransformer;

use App\Company\Entity\Counterparty;
use App\Company\Facade\CounterpartyFacade;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * id ↔ Counterparty для форм, привязанных к сущности.
 *
 * Заменяет ручное перекладывание значения в слушателях форм. Разрешение id идёт
 * только в рамках компании: даже если бы id прошёл проверку choices, чужая запись
 * здесь не найдётся.
 *
 * @implements DataTransformerInterface<Counterparty|null, string|null>
 */
final class CounterpartyEntityTransformer implements DataTransformerInterface
{
    public function __construct(
        private readonly CounterpartyFacade $facade,
        private readonly string $companyId,
    ) {
    }

    public function transform(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof Counterparty) {
            throw new TransformationFailedException('Ожидался контрагент.');
        }

        return $value->getId();
    }

    public function reverseTransform(mixed $value): ?Counterparty
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (!is_string($value)) {
            throw new TransformationFailedException('Ожидался идентификатор контрагента.');
        }

        $counterparty = $this->facade->findEntityByIdAndCompany($value, $this->companyId);

        if (null === $counterparty) {
            throw new TransformationFailedException('Контрагент не найден в текущей компании.');
        }

        return $counterparty;
    }
}
