<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin\Api;

use App\Company\Entity\User;
use App\Tests\Support\Spa\SpaWebTestCase;

final class AdminSessionTest extends SpaWebTestCase
{
    public function testSessionContainsOnlyIdentityAndAdminAccessWithoutCompany(): void
    {
        $client = static::createClient();
        $user = $this->user(['ROLE_SUPER_ADMIN']);
        $this->login($client, $user, 'admin');
        $this->get($client, 'session', 'admin');
        self::assertResponseIsSuccessful();
        self::assertSame(['user' => ['id' => $user->getId(), 'email' => $user->getEmail()], 'access' => ['admin' => true]], $this->body($client));
        $this->get($client, 'session', 'account');
        self::assertResponseStatusCodeSame(401);
        $storedUser = $this->em()->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $storedUser);
        $storedUser->setRoles([]);
        $this->em()->flush();
        $this->get($client, 'session', 'admin');
        self::assertResponseStatusCodeSame(401);
    }
}
