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
        if ('disabled' === $connection['status']) {
            throw new OneCApiException(403, 'disabled', 'Подключение отключено.');
        }
        if (!$this->onecPackagesLimiter->create($connection['id'])->consume()->isAccepted()) {
            throw new OneCApiException(429, 'rate_limited', 'Повторите отправку через минуту.');
        }
        $envelope = PackageEnvelope::parse($body);
        if ($connection['base_id'] !== $envelope->baseId) {
            throw new OneCApiException(409, 'base_conflict', null === $connection['base_id'] ? 'Сначала выполните рукопожатие.' : 'Подключение привязано к другой базе.');
        }
        if ('error' === $connection['status']) {
            throw new OneCApiException(422, 'unsupported', 'Конфигурация или обработка не поддерживается, см. ответ рукопожатия.');
        }

        // ponytail: согласованность частей проверяется без блокировки пакета; две
        // разные части с разной оболочкой, пришедшие одновременно, обе пройдут.
        $sibling = $db->fetchAssociative(
            'SELECT kind, period_from, period_to, include_open_outside_period, parts_total FROM onec_package WHERE connection_id = ? AND package_id = ? AND part <> ? LIMIT 1',
            [$connection['id'], $envelope->packageId, $envelope->part],
        );
        if (false !== $sibling && [$sibling['kind'], $sibling['period_from'], $sibling['period_to'], (bool) $sibling['include_open_outside_period'], (int) $sibling['parts_total']]
            !== [$envelope->kind, $envelope->periodFrom, $envelope->periodTo, $envelope->includeOpenOutsidePeriod, $envelope->partsTotal]) {
            throw self::conflict();
        }

        // Объект пишется до строки и вне транзакции: путь детерминирован, повтор
        // перезаписывает те же байты, а другое тело ложится рядом и получает 409.
        $sha256 = hash('sha256', $body);
        $path = \sprintf('onec/%s/%s/%d-%s.json.gz', $connection['id'], $envelope->packageId, $envelope->part, $sha256);
        $this->storage->write($path, (string) gzencode($body));

        $inserted = $this->em->wrapInTransaction(function () use ($db, $connection, $tokenHash, $envelope, $sha256, $path, $body): bool {
            // Токен могли отозвать или перевыпустить, пока писался объект.
            $live = $db->fetchOne("SELECT 1 FROM onec_connection WHERE id = ? AND token_hash = ? AND status <> 'disabled' FOR SHARE", [$connection['id'], $tokenHash]);
            if (false === $live) {
                throw new OneCApiException(401, 'invalid_token', 'Неверный токен подключения.');
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

        $status = 'stored';
        if (!$inserted) {
            $existing = $db->fetchAssociative('SELECT sha256, status FROM onec_package WHERE connection_id = ? AND package_id = ? AND part = ?', [$connection['id'], $envelope->packageId, $envelope->part]);
            if (false === $existing || $existing['sha256'] !== $sha256) {
                throw self::conflict();
            }
            $status = $existing['status'];
        }

        return ['accepted' => true, 'package_id' => $envelope->packageId, 'part' => $envelope->part, 'status' => $status, 'duplicate' => !$inserted];
    }

    private static function conflict(): OneCApiException
    {
        return new OneCApiException(409, 'package_conflict', 'Часть с этим package_id уже принята с другим содержимым или оболочкой. Исправленная выгрузка получает новый package_id.');
    }
}
