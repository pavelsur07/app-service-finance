<?php

declare(strict_types=1);

namespace App\OneC\Application;

use App\OneC\Exception\OneCApiException;
use App\Shared\Service\Storage\ObjectStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Приём части пакета: сырьё в объектное хранилище, метаданные в onec_package.
 * Ключ повтора — (подключение, package_id, part), держит unique-индекс.
 */
final readonly class ReceivePackageAction
{
    public function __construct(
        private EntityManagerInterface $em,
        private ObjectStorageInterface $storage,
        private ClockInterface $clock,
        private RateLimiterFactory $onecPackagesLimiter,
    ) {
    }

    /** @return array{accepted: true, package_id: string, part: int, status: string, duplicate: bool} */
    public function __invoke(#[\SensitiveParameter] string $token, string $body): array
    {
        $db = $this->em->getConnection();
        $tokenHash = hash('sha256', $token);
        $connection = $db->fetchAssociative('SELECT id, status, base_id, versions FROM onec_connection WHERE token_hash = ?', [$tokenHash]);
        if (false === $connection) {
            throw new OneCApiException(401, 'invalid_token', 'Неверный токен подключения.');
        }
        if (!$this->onecPackagesLimiter->create($connection['id'])->consume()->isAccepted()) {
            throw new OneCApiException(429, 'rate_limited', 'Повторите отправку через минуту.');
        }
        $envelope = PackageEnvelope::parse($body);
        self::assertAccepts($connection, $envelope);
        $sha256 = hash('sha256', $body);
        $existing = $this->existing($connection['id'], $envelope);
        if (null !== $existing) {
            return self::repeat($existing, $sha256, $envelope);
        }

        // Объект пишется до строки и вне транзакции: путь детерминирован, гонка
        // повторов перезаписывает те же байты.
        $path = \sprintf('onec/%s/%s/%d-%s.json.gz', $connection['id'], $envelope->packageId, $envelope->part, $sha256);
        $this->storage->write($path, (string) gzencode($body));

        $inserted = $this->em->wrapInTransaction(function () use ($db, $connection, $tokenHash, $envelope, $sha256, $path, $body): bool {
            // Части одного подключения идут по очереди: строка подключения под
            // FOR NO KEY UPDATE (не FOR SHARE — тот с UPDATE last_success_at даёт
            // взаимную блокировку). Перевыпуск и отключение ждут её же.
            $locked = $db->fetchAssociative('SELECT status, base_id FROM onec_connection WHERE id = ? AND token_hash = ? FOR NO KEY UPDATE', [$connection['id'], $tokenHash]);
            if (false === $locked) {
                throw new OneCApiException(401, 'invalid_token', 'Неверный токен подключения.');
            }
            self::assertAccepts($locked, $envelope);
            $sibling = $db->fetchAssociative(
                'SELECT kind, period_from, period_to, include_open_outside_period, parts_total FROM onec_package WHERE connection_id = ? AND package_id = ? AND part <> ? LIMIT 1',
                [$connection['id'], $envelope->packageId, $envelope->part],
            );
            if (false !== $sibling && [$sibling['kind'], $sibling['period_from'], $sibling['period_to'], (bool) $sibling['include_open_outside_period'], (int) $sibling['parts_total']]
                !== [$envelope->kind, $envelope->periodFrom, $envelope->periodTo, $envelope->includeOpenOutsidePeriod, $envelope->partsTotal]) {
                throw self::conflict();
            }
            $now = $this->clock->now()->format('Y-m-d H:i:s');
            $id = $db->fetchOne(<<<'SQL'
                INSERT INTO onec_package (id, connection_id, package_id, part, parts_total, kind, period_from, period_to,
                    include_open_outside_period, processor_version, versions, warnings, document_count, storage_path,
                    sha256, byte_size, status, received_at)
                VALUES (:id, :connection, :package, :part, :total, :kind, :from, :to, :open, :processor,
                    CAST(:versions AS jsonb), CAST(:warnings AS jsonb), :documents, :path, :sha, :size, 'stored', :now)
                ON CONFLICT (connection_id, package_id, part) DO NOTHING
                RETURNING id
                SQL, [
                'id' => Uuid::uuid7()->toString(), 'connection' => $connection['id'], 'package' => $envelope->packageId,
                'part' => $envelope->part, 'total' => $envelope->partsTotal, 'kind' => $envelope->kind,
                'from' => $envelope->periodFrom, 'to' => $envelope->periodTo,
                'open' => $envelope->includeOpenOutsidePeriod ? 'true' : 'false', 'processor' => $envelope->processorVersion,
                'versions' => $connection['versions'], 'warnings' => json_encode($envelope->warnings, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                'documents' => $envelope->documentCount, 'path' => $path, 'sha' => $sha256, 'size' => \strlen($body), 'now' => $now,
            ]);
            if (false === $id) {
                return false;
            }
            $db->executeStatement('UPDATE onec_connection SET last_success_at = ? WHERE id = ?', [$now, $connection['id']]);

            return true;
        });

        if (!$inserted) {
            return self::repeat($this->existing($connection['id'], $envelope), $sha256, $envelope);
        }

        return ['accepted' => true, 'package_id' => $envelope->packageId, 'part' => $envelope->part, 'status' => 'stored', 'duplicate' => false];
    }

    /** @param array<string, mixed> $connection строка onec_connection: status, base_id */
    private static function assertAccepts(array $connection, PackageEnvelope $envelope): void
    {
        if (null !== $connection['base_id'] && $connection['base_id'] !== $envelope->baseId) {
            throw new OneCApiException(409, 'base_conflict', 'Подключение привязано к другой базе.');
        }
        if ('error' === $connection['status']) {
            throw new OneCApiException(422, 'unsupported', 'Конфигурация или обработка не поддерживается, см. ответ рукопожатия.');
        }
        if ('connected' !== $connection['status']) {
            throw new OneCApiException(409, 'base_conflict', 'Сначала выполните рукопожатие.');
        }
    }

    /** @return array<string, mixed>|null sha256, status */
    private function existing(string $connectionId, PackageEnvelope $envelope): ?array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT sha256, status FROM onec_package WHERE connection_id = ? AND package_id = ? AND part = ?',
            [$connectionId, $envelope->packageId, $envelope->part],
        );

        return false === $row ? null : $row;
    }

    /**
     * @param array<string, mixed>|null $existing
     *
     * @return array{accepted: true, package_id: string, part: int, status: string, duplicate: true}
     */
    private static function repeat(?array $existing, string $sha256, PackageEnvelope $envelope): array
    {
        if (null === $existing || $existing['sha256'] !== $sha256) {
            throw self::conflict();
        }

        return ['accepted' => true, 'package_id' => $envelope->packageId, 'part' => $envelope->part, 'status' => (string) $existing['status'], 'duplicate' => true];
    }

    private static function conflict(): OneCApiException
    {
        return new OneCApiException(409, 'package_conflict', 'Часть с этим package_id уже принята с другим содержимым или оболочкой. Исправленная выгрузка получает новый package_id.');
    }
}
