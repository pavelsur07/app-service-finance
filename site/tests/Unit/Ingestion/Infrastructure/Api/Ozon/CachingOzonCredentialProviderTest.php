<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\Api\Ozon;

use App\Ingestion\Infrastructure\Api\Ozon\CachingOzonCredentialProvider;
use App\Ingestion\Infrastructure\Api\Ozon\OzonCredentialProviderInterface;
use PHPUnit\Framework\TestCase;

final class CachingOzonCredentialProviderTest extends TestCase
{
    /**
     * Регрессия: перепрос статусов ходит в Ozon по одному отправлению — до
     * тысячи вызовов на подключение, — и каждый читал учётные данные из БД с
     * расшифровкой. Это запрещённый правилами проекта N+1, и без этого теста
     * он вернулся бы незаметно: тесты перепроса подменяют клиент целиком, а
     * unit-тесты клиента делают по одному запросу.
     */
    public function testCredentialsAreReadOncePerConnection(): void
    {
        $source = $this->createMock(OzonCredentialProviderInterface::class);
        $source->expects(self::once())
            ->method('read')
            ->with('company-1', 'connection-1')
            ->willReturn(['api_key' => 'key', 'client_id' => 'client']);

        $provider = new CachingOzonCredentialProvider($source);

        $first = $provider->read('company-1', 'connection-1');
        $second = $provider->read('company-1', 'connection-1');

        self::assertSame($first, $second);
    }

    /**
     * Память ключуется парой: два кабинета одной компании ходят под разными
     * ключами, и один на двоих означал бы запрос чужим ключом.
     */
    public function testEachConnectionKeepsItsOwnCredentials(): void
    {
        $source = $this->createMock(OzonCredentialProviderInterface::class);
        $source->expects(self::exactly(2))
            ->method('read')
            ->willReturnCallback(static fn (string $companyId, string $connectionRef): array => [
                'api_key' => 'key-'.$connectionRef,
                'client_id' => 'client',
            ]);

        $provider = new CachingOzonCredentialProvider($source);

        self::assertSame('key-connection-1', $provider->read('company-1', 'connection-1')['api_key']);
        self::assertSame('key-connection-2', $provider->read('company-1', 'connection-2')['api_key']);
        self::assertSame('key-connection-1', $provider->read('company-1', 'connection-1')['api_key']);
    }
}
