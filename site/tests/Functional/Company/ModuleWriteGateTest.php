<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\User;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

/**
 * Write-гейты Stage 3 по HTTP, по одному репрезентативному эндпоинту на группу.
 *
 * Матрица на каждую группу:
 * - `read`  → GET 200, POST 403 (изолирует именно write-гейт: read-гейт подписчика пропускает);
 * - `write` → POST проходит гейт;
 * - пустой шаблон → GET 403 уже на чтении.
 *
 * Роль всегда получает `<module>:read`, иначе POST отклонил бы read-гейт подписчика и тест
 * остался бы зелёным даже без write-гейта.
 *
 * Проверяется и то, что гейт срабатывает раньше обработки формы и CSRF: POST без тела
 * обязан дать 403, а не 422 и не 500.
 */
final class ModuleWriteGateTest extends WebTestCaseBase
{
    /**
     * @return iterable<string, array{0: Module, 1: string, 2: string}>
     */
    public static function moduleGateProvider(): iterable
    {
        yield 'finance' => [Module::FINANCE, '/counterparties/', '/counterparties/new'];
        yield 'deals' => [Module::DEALS, '/deals', '/deals/new'];
        yield 'catalog' => [Module::CATALOG, '/catalog/products', '/catalog/products/new'];
        yield 'admin' => [Module::ADMIN, '/integrations/telegram', '/integrations/telegram/generate-link'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('moduleGateProvider')]
    public function testReadOnlyRoleReadsButCannotWrite(Module $module, string $readUrl, string $writeUrl): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$company, $memberUser] = $this->seedMemberWithPermissions(
            $module->value.'-read',
            [$module->value => AccessLevel::READ->value],
        );

        $client->loginUser($memberUser);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('GET', $readUrl);
        self::assertResponseIsSuccessful();

        $client->request('POST', $writeUrl);
        self::assertResponseStatusCodeSame(403);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('moduleGateProvider')]
    public function testWriteRolePassesTheGate(Module $module, string $readUrl, string $writeUrl): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$company, $memberUser] = $this->seedMemberWithPermissions(
            $module->value.'-write',
            [$module->value => AccessLevel::WRITE->value],
        );

        $client->loginUser($memberUser);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('POST', $writeUrl);

        // Гейт пропустил: дальше может быть невалидная форма (200), редирект или ошибка CSRF,
        // но не 403. Отдельно исключаем 5xx — иначе сломанный endpoint формально прошёл бы тест.
        $status = $client->getResponse()->getStatusCode();
        self::assertNotSame(403, $status);
        self::assertLessThan(500, $status, 'write-endpoint не должен падать: статус '.$status);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('moduleGateProvider')]
    public function testEmptyPermissionsRoleIsDeniedEvenOnRead(Module $module, string $readUrl, string $writeUrl): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$company, $memberUser] = $this->seedMemberWithPermissions($module->value.'-none', []);

        $client->loginUser($memberUser);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('GET', $readUrl);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', $writeUrl);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, string> $permissions
     *
     * @return array{0: Company, 1: User}
     */
    private function seedMemberWithPermissions(string $slug, array $permissions): array
    {
        $owner = UserBuilder::aUser()
            ->withEmail(sprintf('write-gate-owner-%s@example.test', $slug))
            ->withRoles(['ROLE_COMPANY_OWNER'])
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withOwner($owner)
            ->withName('Write Gate Company')
            ->build();

        $role = new CompanyRole(
            '77777777-7777-4777-8777-777777777777',
            'Шаблон '.$slug,
            $permissions,
            $company,
        );

        $memberUser = UserBuilder::aUser()
            ->withIndex(2)
            ->withEmail(sprintf('write-gate-member-%s@example.test', $slug))
            ->withRoles(['ROLE_COMPANY_USER'])
            ->build();
        $member = CompanyMemberBuilder::aMember()
            ->withCompany($company)
            ->withUser($memberUser)
            ->withRole(CompanyMember::ROLE_OPERATOR)
            ->withStatus(CompanyMember::STATUS_ACTIVE)
            ->withAccessRole($role)
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($role);
        $em->persist($memberUser);
        $em->persist($member);
        $em->flush();

        return [$company, $memberUser];
    }
}
