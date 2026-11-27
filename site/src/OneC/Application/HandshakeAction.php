<?php

declare(strict_types=1);

namespace App\OneC\Application;

use App\Company\Facade\CompanyFacade;
use App\OneC\Entity\OneCConnection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class HandshakeAction
{
    /** Глубина выгрузки открытых счетов вне периода, контракт v1 «Открытые счета вне периода». */
    public const OPEN_OUTSIDE_PERIOD_MONTHS = 12;

    public function __construct(
        private EntityManagerInterface $em,
        private ConfigurationSupport $support,
        private CompanyFacade $companies,
        private ClockInterface $clock,
        private RateLimiterFactory $onecHandshakeLimiter,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(#[\SensitiveParameter] string $token, string $json): array
    {
        $hash = hash('sha256', $token);
        $credentials = $this->em->getConnection()->fetchAssociative('SELECT id, status FROM onec_connection WHERE token_hash = ?', [$hash]);
        if (false === $credentials) {
            throw new \DomainException('Неверный токен подключения.', 401);
        }
        if ('disabled' === $credentials['status']) {
            throw new \DomainException('Подключение отключено.', 403);
        }
        if (!$this->onecHandshakeLimiter->create($credentials['id'])->consume()->isAccepted()) {
            throw new \DomainException('Повторите рукопожатие через минуту.', 429);
        }
        $snapshot = HandshakeSnapshot::parse($json);

        // Во время разбора токен могли отозвать или перевыпустить.
        return $this->em->wrapInTransaction(function () use ($hash, $snapshot): array {
            $connection = $this->em->createQueryBuilder()
                ->select('c')->from(OneCConnection::class, 'c')
                ->where('c.tokenHash = :hash')->setParameter('hash', $hash)
                ->getQuery()->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true)->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();
            if (!$connection instanceof OneCConnection) {
                throw new \DomainException('Неверный токен подключения.', 401);
            }
            if ('disabled' === $connection->status) {
                throw new \DomainException('Подключение отключено.', 403);
            }
            $now = $this->clock->now();
            $support = $this->support->evaluate($snapshot);
            $connection->handshake($snapshot->baseId, $snapshot->versions, 'unsupported' !== $support['level'], $now);
            $this->em->flush();
            $organizations = $this->syncOrganizations($connection, $snapshot);

            return [
                'connection_id' => $connection->id,
                'status' => $connection->status,
                'server_time' => $now->format(\DATE_ATOM),
                'support' => $support,
                'organizations' => $organizations,
                'messages' => [],
                'settings' => ['open_outside_period_months' => self::OPEN_OUTSIDE_PERIOD_MONTHS],
            ];
        });
    }

    /** @return list<array{guid: string, mapped: bool, company_name: ?string}> */
    private function syncOrganizations(OneCConnection $connection, HandshakeSnapshot $snapshot): array
    {
        $db = $this->em->getConnection();
        $db->executeStatement('UPDATE onec_organization SET present = FALSE WHERE connection_id = ? AND present', [$connection->id]);
        $rows = $db->fetchAllAssociative(<<<'SQL'
            INSERT INTO onec_organization (connection_id, guid, name, inn, kpp, present, enabled)
            SELECT :id, guid, name, inn, kpp, TRUE, FALSE FROM jsonb_to_recordset(CAST(:organizations AS jsonb))
                AS org(guid uuid, name text, inn text, kpp text)
            ON CONFLICT (connection_id, guid) DO UPDATE SET
                name = EXCLUDED.name, inn = EXCLUDED.inn, kpp = EXCLUDED.kpp, present = TRUE
            RETURNING guid, enabled
            SQL, ['id' => $connection->id, 'organizations' => json_encode($snapshot->organizations, \JSON_THROW_ON_ERROR)]);
        // Организация привязывается только к компании своего подключения; имя чужих компаний не раскрывается.
        $name = null;
        if (\in_array(true, array_column($rows, 'enabled'), true)) {
            $company = $this->companies->findById($connection->companyId);
            $name = null === $company || $company->isDeletionRequested() ? null : (string) $company->getName();
        }

        return array_map(static function (array $row) use ($name): array {
            $mapped = $row['enabled'] && null !== $name;

            return ['guid' => (string) $row['guid'], 'mapped' => $mapped, 'company_name' => $mapped ? $name : null];
        }, $rows);
    }
}
