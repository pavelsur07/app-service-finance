<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class PLCategoryExportControllerTest extends WebTestCaseBase
{
    private const EXPORT_URL = '/pl-categories/export/json';

    public function testGuestIsRedirectedOrForbidden(): void
    {
        $client = static::createClient();

        $client->request('GET', self::EXPORT_URL);

        $statusCode = $client->getResponse()->getStatusCode();
        self::assertContains($statusCode, [302, 403]);
    }

    public function testExportsActiveCompanyTreeAsAttachment(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->withName('Target Co')->build();
        $other = CompanyBuilder::aCompany()->withIndex(2)->withOwner($user)->withName('Other Co')->build();

        $root = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Расходы')->withCode('EXP')->build();
        $child = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Реклама')->withParent($root)->build();
        $foreign = PLCategoryBuilder::aPLCategory()->forCompany($other)->withName('Чужая категория')->build();

        $em = $this->em();
        foreach ([$user, $company, $other, $root, $child, $foreign] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('GET', self::EXPORT_URL);

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));

        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringStartsWith('attachment;', $disposition);
        self::assertStringContainsString('pl-categories-Target-Co-', $disposition);

        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(1, $payload['version']);
        self::assertSame('Target Co', $payload['company']);
        self::assertCount(1, $payload['categories']);

        $exportedRoot = $payload['categories'][0];
        self::assertSame('Расходы', $exportedRoot['name']);
        self::assertSame('EXP', $exportedRoot['code']);
        self::assertCount(1, $exportedRoot['children']);
        self::assertSame('Реклама', $exportedRoot['children'][0]['name']);

        // id и level в файл не попадают: первое бессмысленно в чужом аккаунте,
        // второе выводится из вложенности.
        self::assertArrayNotHasKey('id', $exportedRoot);
        self::assertArrayNotHasKey('level', $exportedRoot);

        self::assertStringNotContainsString('Чужая категория', (string) $response->getContent());
    }

    public function testCyrillicCompanyNameKeepsAsciiFallbackInDisposition(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->withName('ООО «Ромашка»')->build();

        $em = $this->em();
        foreach ([$user, $company] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('GET', self::EXPORT_URL);

        self::assertResponseIsSuccessful();
        $disposition = (string) $client->getResponse()->headers->get('Content-Disposition');

        // Кириллическое имя уходит только в filename*; параметр filename обязан
        // остаться ASCII, иначе часть клиентов не скачает файл вовсе.
        self::assertMatchesRegularExpression('/filename="?pl-categories-\d{4}-\d{2}-\d{2}\.json"?;/', $disposition);
        self::assertStringContainsString("filename*=utf-8''", $disposition);
    }
}
