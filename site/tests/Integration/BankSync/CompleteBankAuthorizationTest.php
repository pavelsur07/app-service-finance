<?php

declare(strict_types=1);

namespace App\Tests\Integration\BankSync;

use App\BankSync\Application\CompleteBankAuthorizationAction;
use App\BankSync\Domain\OAuth\PendingAuthorization;
use App\BankSync\Domain\OAuth\Pkce;
use App\BankSync\Enum\BankCode;
use App\BankSync\Exception\BankAuthorizationStateInvalidException;
use App\BankSync\Infrastructure\OAuth\AuthorizationSessionStore;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class CompleteBankAuthorizationTest extends IntegrationTestCase
{
    public function testExpiredStateIsRejectedAndConsumed(): void
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);

        $companyId = Uuid::uuid4()->toString();
        $pkce = Pkce::generate();
        $pending = new PendingAuthorization(
            Uuid::uuid4()->toString(),
            $companyId,
            BankCode::ALFA,
            'https://baas.alfabank.ru',
            Uuid::uuid7()->toString(),
            true,
            $pkce->verifier,
            'http://localhost/bank-connections/oauth/callback',
            new \DateTimeImmutable('-1 second'),
            'test-nonce',
        );
        $store = self::getContainer()->get(AuthorizationSessionStore::class);
        $store->save($pending, new \DateTimeImmutable('-11 minutes'));

        /** @var CompleteBankAuthorizationAction $action */
        $action = self::getContainer()->get(CompleteBankAuthorizationAction::class);
        try {
            $action($companyId, $pending->state, 'ok:'.$pkce->challenge, null);
            self::fail('expired state must be rejected');
        } catch (BankAuthorizationStateInvalidException $exception) {
            self::assertSame('expired', $exception->reason);
        }

        self::assertNull($store->take($pending->state), 'просроченный state забран из сессии');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM bank_sync_connection WHERE company_id = :c', ['c' => $companyId]));
    }
}
