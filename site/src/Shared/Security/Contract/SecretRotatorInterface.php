<?php

declare(strict_types=1);

namespace App\Shared\Security\Contract;

use App\Shared\Security\ValueObject\KeyVersionCount;
use App\Shared\Security\ValueObject\RotationBatchResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Ротация ключа шифрования секретов одного модуля. Реализация живёт в модуле-владельце
 * секретов; `app:security:rotate-keys` собирает все реализации по тегу.
 *
 * Ротатор только читает и пишет, транзакцией порции владеет команда.
 */
#[AutoconfigureTag(self::TAG)]
interface SecretRotatorInterface
{
    public const TAG = 'app.security.secret_rotator';

    /** Короткое имя для `--module` и вывода, например `marketplace`. */
    public function module(): string;

    /**
     * Секреты модуля по версиям ключа, включая незашифрованные (`keyVersion` = null).
     *
     * @return list<KeyVersionCount>
     */
    public function countByKeyVersion(): array;

    /**
     * Перешифровывает активной версией до `$limit` секретов на других версиях. Запись —
     * только если значение не изменилось с момента чтения.
     */
    public function rotateBatch(string $activeKeyVersion, int $limit): RotationBatchResult;
}
