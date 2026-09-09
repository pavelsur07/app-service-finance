<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Repository\MarketplaceConnectionRepository;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

/**
 * Записывает исход аутентификации подключения по результату обращения к API.
 *
 * Вызывается из конвейера загрузки через фасад. Здесь живёт политика — порог
 * перехода и решение, что считать событием, — а сам переход выполняет один
 * атомарный оператор в репозитории.
 *
 * `flush()` тут нет намеренно: см. комментарий у
 * {@see MarketplaceConnectionRepository::registerAuthFailure()}. Неудачный
 * flush закрывает EntityManager, а этот код работает на пути, где вызывающий
 * продолжает пользоваться тем же менеджером.
 */
final readonly class RecordConnectionAuthResultAction
{
    /**
     * Отказов подряд до перевода подключения в FAILED.
     *
     * Порог, а не первая неудача: 401/403 приходит и от кратковременного сбоя
     * на стороне маркетплейса, а FAILED останавливает загрузку до вмешательства
     * человека. Три отказа — это три часовых цикла крона, то есть протухший
     * ключ будет пойман в тот же рабочий день, а разовый сбой не остановит
     * ничего.
     */
    public const AUTH_FAILURE_THRESHOLD = 3;

    public function __construct(
        private MarketplaceConnectionRepository $connectionRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool true — подключение ТОЛЬКО ЧТО перешло в FAILED
     */
    public function recordFailure(string $companyId, string $connectionId): bool
    {
        Assert::uuid($companyId);
        Assert::uuid($connectionId);

        return $this->guard(
            fn (): bool => $this->connectionRepository->registerAuthFailure(
                $connectionId,
                $companyId,
                self::AUTH_FAILURE_THRESHOLD,
                new \DateTimeImmutable(),
            ),
            $companyId,
            $connectionId,
        );
    }

    /**
     * @return bool true — подключение ТОЛЬКО ЧТО восстановилось
     */
    public function recordSuccess(string $companyId, string $connectionId): bool
    {
        Assert::uuid($companyId);
        Assert::uuid($connectionId);

        return $this->guard(
            fn (): bool => $this->connectionRepository->registerAuthSuccess(
                $connectionId,
                $companyId,
                new \DateTimeImmutable(),
            ),
            $companyId,
            $connectionId,
        );
    }

    /**
     * Учёт состояния не должен ронять загрузку.
     *
     * Вызов идёт по пути, который уже обрабатывает ошибку API, и брошенная
     * отсюда ошибка БД подменила бы исходную причину сбоя на постороннюю — ту,
     * которую труднее всего опознать по логам. Оператор атомарный и вне
     * UnitOfWork, поэтому его провал не оставляет за собой ни закрытого
     * EntityManager, ни полузаписанного состояния.
     *
     * @param callable(): bool $write
     */
    private function guard(callable $write, string $companyId, string $connectionId): bool
    {
        try {
            return $write();
        } catch (DbalException $exception) {
            $this->logger->error('Connector auth state could not be persisted.', [
                'companyId' => $companyId,
                'connectionId' => $connectionId,
                'exceptionClass' => $exception::class,
                'errorMessage' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
