<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Company\Entity\User;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class AdminUserRolesUpdateTest extends WebTestCaseBase
{
    public function testPlainAdminCannotChangeRoles(): void
    {
        $client = static::createClient();
        $actor = $this->seedUser('roles-plain-admin@example.test', ['ROLE_ADMIN']);
        $target = $this->seedUser('roles-target-1@example.test', ['ROLE_COMPANY_OWNER']);

        $this->postRole($client, $actor, $target, 'ROLE_ADMIN');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['ROLE_COMPANY_OWNER', 'ROLE_USER'], $this->reloadRoles($target));
    }

    public function testSuperAdminTargetCannotBeChangedFromUi(): void
    {
        $client = static::createClient();
        $actor = $this->seedUser('roles-super-actor@example.test', ['ROLE_SUPER_ADMIN']);
        $target = $this->seedUser('roles-super-target@example.test', ['ROLE_SUPER_ADMIN']);

        $this->postRole($client, $actor, $target, 'ROLE_COMPANY_USER');

        self::assertResponseStatusCodeSame(403);
        self::assertContains('ROLE_SUPER_ADMIN', $this->reloadRoles($target));
    }

    public function testSuperAdminChangesRoleOfRegularUser(): void
    {
        $client = static::createClient();
        $actor = $this->seedUser('roles-super-ok@example.test', ['ROLE_SUPER_ADMIN']);
        $target = $this->seedUser('roles-target-2@example.test', ['ROLE_COMPANY_OWNER']);

        $this->postRole($client, $actor, $target, 'ROLE_ADMIN');

        self::assertResponseRedirects('/admin/users');
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $this->reloadRoles($target));
    }

    /**
     * @param list<string> $roles
     */
    private function seedUser(string $email, array $roles): User
    {
        $user = UserBuilder::aUser()
            ->withId(Uuid::uuid7()->toString())
            ->withEmail($email)
            ->withRoles($roles)
            ->build();
        $em = $this->em();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function postRole(KernelBrowser $client, User $actor, User $target, string $role): void
    {
        $client->loginUser($actor, 'admin');
        $token = $this->csrfToken($client, 'admin_user_roles_'.$target->getId());
        $client->request('POST', '/admin/users/'.$target->getId().'/roles', [
            '_token' => $token,
            'role' => $role,
        ]);
    }

    /**
     * @return list<string>
     */
    private function reloadRoles(User $user): array
    {
        $em = $this->em();
        $em->clear();
        $reloaded = $em->find(User::class, $user->getId());
        self::assertNotNull($reloaded);
        $roles = $reloaded->getRoles();
        sort($roles);

        return $roles;
    }
}
