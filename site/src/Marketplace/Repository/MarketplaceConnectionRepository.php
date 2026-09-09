<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionAuthStatus;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MarketplaceConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceConnection::class);
    }

    /**
     * @return MarketplaceConnection[]
     */
    public function findByCompany(Company $company): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->setParameter('company', $company)
            ->orderBy('c.marketplace', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Найти подключение для компании по маркетплейсу и типу.
     *
     * Тип по умолчанию — SELLER, это сохраняет поведение вызовов без явного типа
     * (основные API-клиенты и UI пока работают только с Seller API).
     * Для Performance API вызывающий обязан передать MarketplaceConnectionType::PERFORMANCE
     * явно — иначе будет возвращено Seller-подключение.
     */
    public function findByMarketplace(
        Company $company,
        MarketplaceType $marketplace,
        MarketplaceConnectionType $connectionType = MarketplaceConnectionType::SELLER,
    ): ?MarketplaceConnection {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->andWhere('c.marketplace = :marketplace')
            ->andWhere('c.connectionType = :connectionType')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('connectionType', $connectionType)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Найти подключение по компании, маркетплейсу и типу подключения.
     *
     * В отличие от {@see self::findByMarketplace()} тип обязателен — вызывающий
     * должен явно указать SELLER или PERFORMANCE. Используется в контроллерах
     * создания подключений для проверки отсутствия дубликата по конкретному типу.
     */
    public function findByCompanyMarketplaceAndType(
        Company $company,
        MarketplaceType $marketplace,
        MarketplaceConnectionType $connectionType,
    ): ?MarketplaceConnection {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->andWhere('c.marketplace = :marketplace')
            ->andWhere('c.connectionType = :connectionType')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('connectionType', $connectionType)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByIdAndCompany(string $connectionId, Company $company): ?MarketplaceConnection
    {
        return $this->createQueryBuilder('c')
            ->where('c.id = :connectionId')
            ->andWhere('c.company = :company')
            ->setParameter('connectionId', $connectionId)
            ->setParameter('company', $company)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return MarketplaceConnection[]
     */
    public function findActiveConnections(Company $company): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->andWhere('c.isActive = :active')
            ->setParameter('company', $company)
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }

    /**
     * Отказ аутентификации: один атомарный оператор вместо чтения, правки и
     * flush.
     *
     * Так, а не через сущность, по двум причинам, и обе обязательные.
     *
     * Первая — закрытый EntityManager. Doctrine закрывает менеджер в `finally`
     * при неудачном flush (UnitOfWork::commit). Вызов идёт из обработчика,
     * который уже обрабатывает ошибку API и продолжает работу с тем же
     * менеджером: проглоченный сбой flush оставил бы ему мёртвый EM, и
     * настоящая причина — протухший ключ — утонула бы в каскаде
     * `EntityManagerClosed`.
     *
     * Вторая — гонка. Чтение-правка-запись без блокировки позволяет двум
     * параллельным отказам прочитать один и тот же счётчик и записать
     * одинаковое значение, из-за чего порог не достигается никогда, а признак
     * перехода теряется. Инкремент внутри UPDATE атомарен по определению.
     *
     * `company_id` в WHERE — обязательная часть, а не украшение: идентификатор
     * подключения приходит из очереди, и без проверки владельца чужое задание
     * останавливало бы загрузку соседней компании.
     *
     * @return bool true — подключение ТОЛЬКО ЧТО перешло в FAILED. Счётчик
     *              монотонно растёт, поэтому равенство порогу истинно ровно
     *              один раз на серию
     */
    public function registerAuthFailure(string $connectionId, string $companyId, int $threshold, \DateTimeImmutable $now): bool
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'UPDATE marketplace_connections
                SET auth_failure_count = auth_failure_count + 1,
                    auth_failed_at = COALESCE(auth_failed_at, :now),
                    auth_status = CASE WHEN auth_failure_count + 1 >= :threshold THEN :failed ELSE auth_status END,
                    updated_at = :now
              WHERE id = :connectionId
                AND company_id = :companyId
          RETURNING auth_failure_count',
            [
                'now' => $now->format('Y-m-d H:i:s'),
                'threshold' => $threshold,
                'failed' => MarketplaceConnectionAuthStatus::FAILED->value,
                'connectionId' => $connectionId,
                'companyId' => $companyId,
            ],
        );

        return false !== $row && (int) $row['auth_failure_count'] === $threshold;
    }

    /**
     * Успешная аутентификация: серия оборвана, состояние снимается целиком.
     *
     * Условие в WHERE делает оператор бесплатным для здорового подключения:
     * успех приходит на каждом удачном обращении к API, и без него каждая
     * синхронизация писала бы строку заново, двигая `updatedAt`.
     *
     * Прежний статус читается из самоприсоединения `FROM`: PostgreSQL отдаёт
     * там снимок строки ДО обновления, а вызывающему нужно знать именно то,
     * было ли подключение сломано, — по этому событию пишется запись о
     * восстановлении.
     *
     * @return bool true — подключение ТОЛЬКО ЧТО восстановилось
     */
    public function registerAuthSuccess(string $connectionId, string $companyId, \DateTimeImmutable $now): bool
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'UPDATE marketplace_connections mc
                SET auth_status = :ok,
                    auth_failure_count = 0,
                    auth_failed_at = NULL,
                    updated_at = :now
               FROM marketplace_connections previous
              WHERE mc.id = previous.id
                AND mc.id = :connectionId
                AND mc.company_id = :companyId
                AND (mc.auth_status <> :ok OR mc.auth_failure_count <> 0)
          RETURNING previous.auth_status AS previous_status',
            [
                'ok' => MarketplaceConnectionAuthStatus::OK->value,
                'now' => $now->format('Y-m-d H:i:s'),
                'connectionId' => $connectionId,
                'companyId' => $companyId,
            ],
        );

        return false !== $row
            && MarketplaceConnectionAuthStatus::FAILED->value === $row['previous_status'];
    }

    /**
     * Найти подключение по UUID компании, маркетплейсу и типу.
     * Используется в worker-контексте где Company entity не загружена.
     *
     * Тип по умолчанию — SELLER, это сохраняет поведение вызовов без явного типа.
     * Для Performance API вызывающий обязан передать MarketplaceConnectionType::PERFORMANCE
     * явно — иначе будет возвращено Seller-подключение.
     */
    public function findByCompanyIdAndMarketplace(
        string $companyId,
        MarketplaceType $marketplace,
        MarketplaceConnectionType $connectionType = MarketplaceConnectionType::SELLER,
    ): ?MarketplaceConnection {
        $conn = $this->getEntityManager()->getConnection();
        $id = $conn->fetchOne(
            'SELECT id FROM marketplace_connections
             WHERE company_id = :companyId
               AND marketplace = :marketplace
               AND connection_type = :connectionType
             LIMIT 1',
            [
                'companyId' => $companyId,
                'marketplace' => $marketplace->value,
                'connectionType' => $connectionType->value,
            ],
        );

        if (!$id) {
            return null;
        }

        return $this->find($id);
    }
}
