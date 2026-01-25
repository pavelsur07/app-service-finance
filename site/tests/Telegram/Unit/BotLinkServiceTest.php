<?php

declare(strict_types=1);

namespace App\Tests\Telegram\Unit;

use App\Company\Entity\Company;
use App\Telegram\Entity\BotLink;
use App\Telegram\Entity\TelegramBot;
use App\Telegram\Repository\BotLinkRepository;
use App\Telegram\Service\BotLinkService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class BotLinkServiceTest extends TestCase
{
    private const SECRET = 'test-kernel-secret-123';

    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $em;

    /** @var BotLinkRepository&MockObject */
    private BotLinkRepository $repo;

    private Company $company;
    private TelegramBot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->repo = $this->createMock(BotLinkRepository::class);

        $this->company = $this->makeCompany();
        $this->bot = $this->makeBot();
    }

    private function makeCompany(): Company
    {
        $ref = new \ReflectionClass(Company::class);
        /** @var Company $c */
        $c = $ref->newInstanceWithoutConstructor();
        if ($ref->hasProperty('id')) {
            $p = $ref->getProperty('id');
            $p->setAccessible(true);
            $p->setValue($c, '11111111-1111-1111-1111-111111111111');
        }

        return $c;
    }

    private function makeBot(): TelegramBot
    {
        $ref = new \ReflectionClass(TelegramBot::class);
        /** @var TelegramBot $b */
        $b = $ref->newInstanceWithoutConstructor();
        if ($ref->hasProperty('id')) {
            $p = $ref->getProperty('id');
            $p->setAccessible(true);
            $p->setValue($b, '22222222-2222-2222-2222-222222222222');
        }
        if ($ref->hasProperty('username')) {
            $p = $ref->getProperty('username');
            $p->setAccessible(true);
            $p->setValue($b, 'my_test_bot');
        }

        return $b;
    }

    private function makeService(): BotLinkService
    {
        return new BotLinkService($this->em, $this->repo, self::SECRET);
    }

    public function testCreateFinanceLinkBuildsSignedUrlAndPersists(): void
    {
        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(BotLink::class));
        $this->em->expects(self::once())->method('flush');

        $service = $this->makeService();
        $result = $service->createFinanceLink($this->company, $this->bot, null);

        self::assertArrayHasKey('url', $result);
        self::assertArrayHasKey('token', $result);
        self::assertArrayHasKey('expiresAt', $result);

        self::assertStringStartsWith('https://t.me/my_test_bot?start=', $result['url']);
        self::assertNotEmpty($result['token']);
    }

    /**
     * @dataProvider ttlProvider
     */
    public function testTtlNormalization(?int $inputTtl, int $expectedMin, int $expectedMax): void
    {
        $persisted = null;
        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$persisted) {
            $persisted = $entity;
        });
        $this->em->expects(self::once())->method('flush');

        $service = $this->makeService();
        $now = new \DateTimeImmutable();

        $service->createFinanceLink($this->company, $this->bot, $inputTtl);

        self::assertInstanceOf(BotLink::class, $persisted);
        $delta = $persisted->getExpiresAt()->getTimestamp() - $now->getTimestamp();

        self::assertGreaterThanOrEqual($expectedMin, $delta);
        self::assertLessThanOrEqual($expectedMax, $delta + 2);
    }

    public static function ttlProvider(): array
    {
        return [
            'null -> default 45m' => [null,   45 * 60 - 5, 45 * 60 + 5],
            'too small -> clamp 30m' => [10,     30 * 60 - 5, 30 * 60 + 5],
            'too big -> clamp 60m' => [18000,  60 * 60 - 5, 60 * 60 + 5],
            'inside -> as-is 35m' => [35 * 60,  35 * 60 - 5, 35 * 60 + 5],
        ];
    }

    public function testValidateAndConsumeHappyPath(): void
    {
        $captured = null;
        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$captured) {
            $captured = $entity;
        });
        $this->em->expects(self::any())->method('flush');

        $service = $this->makeService();
        $created = $service->createFinanceLink($this->company, $this->bot, 1800);
        $token = $created['token'];

        self::assertInstanceOf(BotLink::class, $captured);

        $this->repo->method('findOneByTokenForUpdate')->with($token)->willReturn($captured);

        $this->em->expects(self::once())->method('beginTransaction');
        $this->em->expects(self::once())->method('commit');

        $decoded = $service->validateAndConsume($token, $this->bot);

        self::assertSame('11111111-1111-1111-1111-111111111111', $decoded['companyId']);
        self::assertSame('22222222-2222-2222-2222-222222222222', $decoded['botId']);
        self::assertSame('finance', $decoded['scope']);
        self::assertTrue($captured->isUsed());
    }

    public function testValidateAndConsumeRejectsSecondUse(): void
    {
        $service = $this->makeService();

        $link = $this->makeLinkAlive();
        $this->repo->method('findOneByTokenForUpdate')->willReturn($link['entity']);

        // 1-й вызов — begin+commit
        // 2-й вызов — begin + исключение => rollback (commit не вызывается)
        $this->em->expects(self::exactly(2))->method('beginTransaction');
        $this->em->expects(self::exactly(1))->method('commit');
        $this->em->expects(self::atLeastOnce())->method('rollback');

        // 1-й раз — OK
        $service->validateAndConsume($link['token'], $this->bot);

        // 2-й раз — already used
        $this->expectException(\DomainException::class);
        $service->validateAndConsume($link['token'], $this->bot);
    }

    public function testValidateAndConsumeRejectsWrongBot(): void
    {
        $service = $this->makeService();
        $link = $this->makeLinkAlive();
        $this->repo->method('findOneByTokenForUpdate')->willReturn($link['entity']);

        // другой бот с иным id
        $other = $this->makeBot();
        $ref = new \ReflectionClass($other);
        $p = $ref->getProperty('id');
        $p->setAccessible(true);
        $p->setValue($other, '33333333-3333-3333-3333-333333333333');

        // Проверка botId выполняется до транзакции — поэтому транзакции не ожидаем
        $this->expectException(\DomainException::class);
        $service->validateAndConsume($link['token'], $other);
    }

    public function testValidateAndConsumeRejectsWrongScope(): void
    {
        $service = $this->makeService();
        $link = $this->makeLinkAlive();
        $this->repo->method('findOneByTokenForUpdate')->willReturn($link['entity']);

        // Проверка scope выполняется до транзакции — транзакции не ожидаем
        $this->expectException(\DomainException::class);
        $service->validateAndConsume($link['token'], $this->bot, 'sales');
    }

    public function testValidateAndConsumeRejectsExpiredByPayload(): void
    {
        $service = $this->makeService();

        $link = $this->makeLinkAlive();
        $bad = $link['token'].'A'; // ломаем формат/подпись — падение ДО транзакции

        $this->expectException(\DomainException::class);
        $service->validateAndConsume($bad, $this->bot);
    }

    public function testValidateAndConsumeRejectsExpiredByDb(): void
    {
        // Создаём СЕРВИС заново и НЕ вызываем makeLinkAlive(),
        // чтобы не подхватить его stub на репозиторий.
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->repo = $this->createMock(BotLinkRepository::class);
        $service = new BotLinkService($this->em, $this->repo, self::SECRET);

        // Готовим реальные Company/Bot и deep-link
        $company = $this->makeCompany();
        $bot = $this->makeBot();

        // Захватим entity, созданную при createFinanceLink()
        $captured = null;
        $this->em->method('persist')->willReturnCallback(function ($e) use (&$captured) {
            $captured = $e;
        });
        $this->em->expects(self::any())->method('flush');

        $created = $service->createFinanceLink($company, $bot, 45 * 60);
        $token = $created['token'];

        self::assertInstanceOf(BotLink::class, $captured);

        // Клонируем и «просрочим» запись в БД СИЛЬНО (чтобы обойти любой leeway)
        $expiresPast = (new \DateTimeImmutable())->sub(new \DateInterval('PT3600S'));
        $expired = $this->cloneWithExpires($captured, $expiresPast);

        // Репозиторий ДОЛЖЕН вернуть просроченную запись по этому токену
        $this->repo->method('findOneByTokenForUpdate')->with($token)->willReturn($expired);

        // Транзакции: можно не проверять, но допустимо ожидать begin(1) и rollback(>=1)
        $this->em->expects(self::once())->method('beginTransaction');
        $this->em->expects(self::never())->method('commit');
        $this->em->expects(self::atLeastOnce())->method('rollback');

        $this->expectException(\DomainException::class);
        $service->validateAndConsume($token, $bot);
    }

    public function testValidateAndConsumeRejectsInvalidSignature(): void
    {
        $service = $this->makeService();

        $alive = $this->makeLinkAlive();
        [$p, $s] = explode('.', $alive['token'], 2);
        $bad = $p.'.'.$p; // подпись неверная — падение ДО транзакции

        $this->expectException(\DomainException::class);
        $service->validateAndConsume($bad, $this->bot);
    }

    public function testValidateAndConsumeRejectsTokenNotFound(): void
    {
        $service = $this->makeService();

        $link = $this->makeLinkAlive();
        $this->repo->method('findOneByTokenForUpdate')->with($link['token'])->willReturn(null);

        $this->expectException(\DomainException::class);
        $service->validateAndConsume($link['token'], $this->bot);
    }

    public function testValidateAndConsumeRejectsMalformedToken(): void
    {
        $service = $this->makeService();
        $bad = 'not-signed-token-without-dot';

        $this->expectException(\DomainException::class);
        $service->validateAndConsume($bad, $this->bot);
    }

    public function testValidateAndConsumeRejectsInvalidBase64(): void
    {
        $service = $this->makeService();

        $link = $this->makeLinkAlive();
        // портим вторую часть на не-base64url
        [$p] = explode('.', $link['token'], 2);
        $bad = $p.'.***'; // невалидный b64url

        $this->expectException(\DomainException::class);
        $service->validateAndConsume($bad, $this->bot);
    }

    // =========================
    // Helpers
    // =========================

    private function makeLinkAlive(): array
    {
        $captured = null;
        $this->em->method('persist')->willReturnCallback(function ($e) use (&$captured) {
            $captured = $e;
        });
        $this->em->expects(self::any())->method('flush');

        $service = $this->makeService();
        $created = $service->createFinanceLink($this->company, $this->bot, 1200);

        self::assertInstanceOf(BotLink::class, $captured);

        return [
            'entity' => $captured,
            'token' => $created['token'],
        ];
    }

    private function cloneWithExpires(BotLink $src, \DateTimeImmutable $expires): BotLink
    {
        $ref = new \ReflectionClass(BotLink::class);
        /** @var BotLink $clone */
        $clone = $ref->newInstanceWithoutConstructor();

        foreach (['id', 'company', 'bot', 'token', 'scope', 'expiresAt', 'usedAt', 'createdAt'] as $prop) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($clone, $p->getValue($src));
        }

        $p = $ref->getProperty('expiresAt');
        $p->setAccessible(true);
        $p->setValue($clone, $expires);

        return $clone;
    }
}
