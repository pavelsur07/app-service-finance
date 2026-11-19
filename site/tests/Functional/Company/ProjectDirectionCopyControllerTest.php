<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Application\Service\CompanyOwnerMembershipCreator;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Company\Repository\ProjectDirectionRepository;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;

final class ProjectDirectionCopyControllerTest extends WebTestCaseBase
{
    private const INDEX = '/project-directions/';
    private const COPY = '/project-directions/copy';
    private const PREVIEW = '/project-directions/copy/preview';
    private const CONFIRM = '/project-directions/copy/confirm';

    public function testChoosesReadableSourceAndCopiesAnUpdatedPreviewExactlyOnce(): void
    {
        $client = static::createClient();
        $owner = $this->user(910);
        $actor = $this->user(911);
        $target = $this->company($owner, 'Получатель');
        $source = $this->company($owner, 'Источник с правом чтения');
        $noFinance = $this->company($owner, 'Без прав');
        $inaccessible = $this->company($owner, 'Чужая компания');
        $this->grant($target, $actor, AccessLevel::WRITE);
        $this->grant($source, $actor, AccessLevel::READ);
        $this->grant($noFinance, $actor, AccessLevel::NONE);
        $this->project($target, 'TARGET', 'Занятый код');
        $conflict = $this->project($source, 'TARGET', 'Проект источника');
        $this->project($source, 'NEW', 'Новый проект');
        $this->login($client, $actor, $target);

        $client->request('GET', self::INDEX);
        self::assertSelectorExists('a[href="'.self::COPY.'"]');
        $page = $client->clickLink('Скопировать из компании');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#project-direction-copy');
        self::assertSame(['', $source->getId()], $page->filter('#copy-source option')->each(static fn (Crawler $option): ?string => $option->attr('value')));
        $preview = $client->submitForm('Предпросмотр', ['sourceId' => $source->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Код занят');
        self::assertSelectorTextContains('table', 'Создать');

        $id = (string) $conflict->getId();
        $client->submitForm('Подтвердить копирование', ["decisions[$id][mode]" => 'link']);
        self::assertResponseRedirects(self::COPY);
        self::assertSame(['TARGET'], $this->codes($target));

        $form = $this->preview($client, $source)->selectButton('Обновить предпросмотр')->form();
        $data = $form->getPhpValues();
        unset($data['decisions']);
        $data['decisionsJson'] = json_encode([$id => ['mode' => 'rename', 'code' => 'FREE']], \JSON_THROW_ON_ERROR);
        $preview = $client->request('POST', self::PREVIEW, $data);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'FREE');
        self::assertSame(['TARGET'], $this->codes($target));
        $confirm = $preview->selectButton('Подтвердить копирование')->form();
        $client->submit($confirm);
        self::assertResponseRedirects(self::INDEX);
        self::assertSame(['FREE', 'NEW', 'TARGET'], $this->codes($target));
        $client->submit($confirm);
        self::assertResponseRedirects(self::COPY);

        $token = $this->csrfToken($client, 'project_direction_copy_preview', self::PREVIEW);
        foreach ([$target, $noFinance, $inaccessible] as $forbidden) {
            $client->request('POST', self::PREVIEW, ['sourceId' => $forbidden->getId(), '_token' => $token]);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testBothPostsCheckCsrfAndFinanceRightsAgain(): void
    {
        $client = static::createClient();
        $owner = $this->user(912);
        $actor = $this->user(913);
        $target = $this->company($owner, 'Получатель');
        $source = $this->company($owner, 'Источник');
        $targetRole = $this->grant($target, $actor, AccessLevel::WRITE);
        $sourceRole = $this->grant($source, $actor, AccessLevel::READ);
        $this->project($source, 'NEW', 'Новый проект');
        $this->login($client, $actor, $target);

        $client->request('POST', self::PREVIEW, ['sourceId' => $source->getId()]);
        self::assertResponseStatusCodeSame(403);
        $confirm = $this->preview($client, $source)->selectButton('Подтвердить копирование')->form();
        $data = $confirm->getPhpValues();
        $client->request('POST', self::CONFIRM, array_replace($data, ['confirmToken' => 'invalid']));
        self::assertResponseStatusCodeSame(403);

        $this->changeFinance($sourceRole, AccessLevel::NONE);
        $client->submit($confirm);
        self::assertResponseStatusCodeSame(403);
        $this->changeFinance($sourceRole, AccessLevel::READ);
        $this->changeFinance($targetRole, AccessLevel::READ);
        $client->submit($confirm);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', self::PREVIEW, ['sourceId' => $source->getId(), '_token' => $this->csrfToken($client, 'project_direction_copy_preview')]);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', self::INDEX);
        self::assertSelectorNotExists('a[href="'.self::COPY.'"]');
        self::assertSame([], $this->codes($target));
    }

    public function testExpiryUserSessionAndBothCompaniesBindThePreview(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $clock = new MockClock();
        static::getContainer()->set(ClockInterface::class, $clock);
        $owner = $this->user(914);
        $writer = $this->user(915);
        $target = $this->company($owner, 'Получатель');
        $source = $this->company($owner, 'Источник');
        $other = $this->company($owner, 'Другая компания');
        $this->grant($target, $writer, AccessLevel::WRITE);
        $this->grant($source, $writer, AccessLevel::READ);
        $this->project($source, 'NEW', 'Новый проект');
        $this->login($client, $owner, $target);

        $form = $this->preview($client, $source)->selectButton('Подтвердить копирование')->form();
        $data = $form->getPhpValues();
        self::assertSelectorTextContains('#project-direction-copy .card-title', 'Предпросмотр из компании «Источник»');
        $client->submitForm('Подтвердить копирование', ['sourceId' => $other->getId()]);
        self::assertResponseRedirects(self::COPY);
        $this->setClientSessionValue($client, 'active_company_id', (string) $other->getId());
        $client->submit($form);
        self::assertResponseRedirects(self::COPY);
        $this->login($client, $writer, $target);
        $client->request('POST', self::CONFIRM, array_replace($data, ['confirmToken' => $this->csrfToken($client, 'project_direction_copy_confirm')]));
        self::assertResponseRedirects(self::COPY);

        $this->login($client, $owner, $target);
        $form = $this->preview($client, $source)->selectButton('Подтвердить копирование')->form();
        $originalCookies = $client->getCookieJar()->all();
        $client->restart();
        $this->login($client, $owner, $target);
        $client->request('POST', self::CONFIRM, array_replace($form->getPhpValues(), ['confirmToken' => $this->csrfToken($client, 'project_direction_copy_confirm')]));
        self::assertResponseRedirects(self::COPY);

        $client->restart();
        foreach ($originalCookies as $cookie) {
            $client->getCookieJar()->set($cookie);
        }
        $clock->sleep(900);
        $client->submit($form);
        self::assertResponseRedirects(self::COPY);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Предпросмотр недоступен или истёк');
        self::assertSame([], $this->codes($target));
    }

    private function preview(KernelBrowser $client, Company $source): Crawler
    {
        $client->request('GET', self::COPY);

        return $client->submitForm('Предпросмотр', ['sourceId' => $source->getId()]);
    }

    private function login(KernelBrowser $client, User $user, Company $target): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', (string) $target->getId());
    }

    /** @return list<string> */
    private function codes(Company $target): array
    {
        $rows = static::getContainer()->get(ProjectDirectionRepository::class)->readDirectory((string) $target->getId());
        $codes = array_values(array_filter(array_column($rows['projects'], 'code')));
        sort($codes);

        return $codes;
    }

    private function company(User $owner, string $name): Company
    {
        $company = static::getContainer()->get(CompanyOwnerMembershipCreator::class)->createCompany($owner, $name);
        $this->em()->flush();

        return $company;
    }

    private function user(int $index): User
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function grant(Company $company, User $user, AccessLevel $level): CompanyRole
    {
        $role = new CompanyRole(Uuid::uuid7()->toString(), 'Доступ к финансам', [Module::FINANCE->value => $level->value]);
        $member = CompanyMemberBuilder::aMember()->withId(Uuid::uuid7()->toString())->withCompany($company)->withUser($user)->withRole(CompanyMember::ROLE_OPERATOR)->withAccessRole($role)->build();
        $this->em()->persist($role);
        $this->em()->persist($member);
        $this->em()->flush();

        return $role;
    }

    private function changeFinance(CompanyRole $role, AccessLevel $level): void
    {
        $current = $this->em()->find(CompanyRole::class, $role->getId());
        self::assertNotNull($current);
        $current->setPermissions([Module::FINANCE->value => $level->value]);
        $this->em()->flush();
    }

    private function project(Company $company, string $code, string $name): ProjectDirection
    {
        $project = (new ProjectDirection(Uuid::uuid7()->toString(), $company, $name))->setCode($code);
        $this->em()->persist($project);
        $this->em()->flush();

        return $project;
    }
}
