<?php

declare(strict_types=1);

namespace App\Finance\Exception;

/**
 * План импорта к моменту применения разошёлся с показанным в предпросмотре: дерево компании
 * или источника успело измениться. Наследует \DomainException: экран импорта показывает его
 * как любой другой отказ импорта.
 */
final class PLImportPreviewOutdatedException extends \DomainException
{
    public static function create(): self
    {
        return new self('С момента предпросмотра категории изменились, и импорт сделал бы не то, что было показано. Ничего не изменено — откройте предпросмотр заново.');
    }
}
