<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller\Import;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Entity\Import\CashFileImportProfile;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class CashFileDescriptionPreviewTest extends WebTestCaseBase
{
    public function testExplicitSelectionAndRepeatedConfirmationUseSameJob(): void
    {
        [$client, $company] = $this->context();
        $crawler = $client->request('GET', '/cash/import/file/preview');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Old description', $crawler->text());
        self::assertCount(0, $crawler->filter('input[name="selected[]"][checked]'));
        $token = $this->csrfToken($client, 'cash_file_import_selection');
        $client->request('POST', '/cash/import/file/preview', ['_token' => $token, 'selection_action' => 'all']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="selected[]"][checked]');
        $crawler = $client->getCrawler();
        $commitToken = $this->csrfToken($client, 'cash_file_import_commit');
        $client->request('POST', '/cash/import/file/commit', ['_token' => $commitToken]);
        self::assertResponseIsSuccessful();
        $url = $client->getCrawler()->filter('a[href*="/cash/import/file/result/"]')->attr('href');
        self::assertNotNull($url);
        $client->request('POST', '/cash/import/file/commit', ['_token' => $commitToken]);
        self::assertResponseRedirects($url);
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'В очереди');

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $owner = $company->getUser();
        self::assertInstanceOf(User::class, $owner);
        $managedOwner = $em->getReference(User::class, $owner->getId());
        self::assertInstanceOf(User::class, $managedOwner);
        $other = new Company(Uuid::uuid7()->toString(), $managedOwner);
        $other->setName('Other company');
        $em->persist($other);
        $em->flush();
        $this->setClientSessionValue($client, 'active_company_id', $other->getId());
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPreviewShowsExistingDocumentWhenFileOmitsIt(): void
    {
        [$client] = $this->context(0, true);
        $client->request('GET', '/cash/import/file/preview');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'Документ: 101');
        self::assertSelectorTextContains('tbody', 'В файле: —');
    }

    public function testLiteralZeroDescriptionAndDocumentAreVisible(): void
    {
        [$client] = $this->context(zeroMetadata: true);
        $client->request('GET', '/cash/import/file/preview');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'Документ: 0');
        self::assertSame('0', $client->getCrawler()->filter('tbody tr')->first()->filter('td')->eq(3)->text());
    }

    public function testSelectionRejectsCsrfAndUnavailableRow(): void
    {
        [$client] = $this->context();
        $client->request('GET', '/cash/import/file/preview');
        $token = $this->csrfToken($client, 'cash_file_import_selection');
        $client->request('POST', '/cash/import/file/preview', ['_token' => 'invalid', 'selection_action' => 'all']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/cash/import/file/preview', ['_token' => $token, 'selected' => ['foreign-row']]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnsavedCheckboxesAreIncludedWhenStartingImport(): void
    {
        [$client, $company] = $this->context();
        $client->request('GET', '/cash/import/file/preview');
        $rowId = $client->getCrawler()->filter('input[name="selected[]"]')->attr('value');
        $client->request('POST', '/cash/import/file/commit', [
            '_commit_token' => $this->csrfToken($client, 'cash_file_import_commit'),
            'visible' => [$rowId], 'selected' => [$rowId],
        ]);
        self::assertResponseIsSuccessful();
        $session = $client->getRequest()->getSession()->get('cash_file_import');
        self::assertSame([$rowId], $session['selected_descriptions']);
    }

    public function testWholeFileSelectionSurvivesPaging(): void
    {
        [$client] = $this->context(100);
        $client->request('GET', '/cash/import/file/preview');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $client->getCrawler()->filter('input[name="selected[]"]'));
        $client->request('POST', '/cash/import/file/preview', [
            '_token' => $this->csrfToken($client, 'cash_file_import_selection'), 'selection_action' => 'all',
        ]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/cash/import/file/preview', [
            '_token' => $this->csrfToken($client, 'cash_file_import_selection'), 'page' => 3,
        ]);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $client->getCrawler()->filter('input[name="selected[]"][checked]'));
    }

    public function testApplyingIdenticalProfileClearsPreviousConsent(): void
    {
        [$client, $company] = $this->context();
        $client->request('GET', '/cash/import/file/preview');
        $client->request('POST', '/cash/import/file/preview', [
            '_token' => $this->csrfToken($client, 'cash_file_import_selection'), 'selection_action' => 'all',
        ]);
        $payload = $client->getRequest()->getSession()->get('cash_file_import');
        self::assertCount(1, $payload['selected_descriptions']);
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $managedCompany = $em->getReference(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $managedCompany);
        $profile = new CashFileImportProfile(Uuid::uuid7()->toString(), $managedCompany, 'Same mapping', $payload['mapping']);
        $em->persist($profile);
        $em->flush();
        $client->request('POST', '/cash/import/file/mapping/profile/apply', [
            '_token' => $this->csrfToken($client, 'cash_file_import_profile_apply'), 'profile_id' => $profile->getId(),
        ]);
        self::assertResponseRedirects('/cash/import/file/mapping');
        $payload = $client->getRequest()->getSession()->get('cash_file_import');
        self::assertArrayNotHasKey('description_plan', $payload);
        self::assertArrayNotHasKey('description_context', $payload);
        self::assertArrayNotHasKey('selected_descriptions', $payload);
        $client->request('GET', '/cash/import/file/preview');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $client->getCrawler()->filter('input[name="selected[]"][checked]'));
    }

    public function testSavingIdenticalMappingClearsPreviousConsent(): void
    {
        [$client] = $this->context();
        $client->request('GET', '/cash/import/file/preview');
        $client->request('POST', '/cash/import/file/preview', [
            '_token' => $this->csrfToken($client, 'cash_file_import_selection'), 'selection_action' => 'all',
        ]);
        $payload = $client->getRequest()->getSession()->get('cash_file_import');
        self::assertCount(1, $payload['selected_descriptions']);
        $mapping = $payload['mapping'];
        $client->request('POST', '/cash/import/file/mapping', [
            '_token' => $this->csrfToken($client, 'cash_file_import_mapping'),
            'date_column' => 'Date', 'amount_column' => 'Amount', 'description_column' => 'Description', 'doc_number_column' => 'Document',
        ]);
        self::assertResponseRedirects('/cash/import/file/preview');
        $payload = $client->getRequest()->getSession()->get('cash_file_import');
        self::assertSame($mapping, $payload['mapping']);
        self::assertArrayNotHasKey('description_plan', $payload);
        self::assertArrayNotHasKey('description_context', $payload);
        self::assertArrayNotHasKey('selected_descriptions', $payload);
    }

    public function testCommittedPreviewAndChangedSelectionReturnExistingResultWithoutDispatch(): void
    {
        [$client, $company] = $this->context();
        $client->request('GET', '/cash/import/file/preview');
        $client->request('POST', '/cash/import/file/commit', ['_token' => $this->csrfToken($client, 'cash_file_import_commit')]);
        self::assertResponseIsSuccessful();
        $payload = $client->getRequest()->getSession()->get('cash_file_import');
        $jobId = $payload['committed_job_id'];
        $url = '/cash/import/file/result/'.$jobId;
        $transport = static::getContainer()->get('messenger.transport.async_sync');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(1, $transport->getSent());
        $client->request('GET', '/cash/import/file/preview');
        self::assertResponseRedirects($url);
        $client->request('POST', '/cash/import/file/preview', ['_token' => $this->csrfToken($client, 'cash_file_import_selection'), 'selection_action' => 'all']);
        self::assertResponseRedirects($url);
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $job = $em->getRepository(CashFileImportJob::class)->findOneBy(['id' => $jobId, 'company' => $company->getId()]);
        self::assertInstanceOf(CashFileImportJob::class, $job);
        $job->finishOk();
        $em->flush();
        $client->request('POST', '/cash/import/file/commit', [
            '_token' => $this->csrfToken($client, 'cash_file_import_commit'), 'selected' => ['r2'], 'visible' => ['r2'],
        ]);
        self::assertResponseRedirects($url);
        // In-memory transports reset between browser requests; the repeat must send none.
        self::assertCount(0, $transport->getSent());
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Завершён');
    }

    public function testDamagedPlanInvalidatesPreviewAndCommitWithoutServerError(): void
    {
        [$client, $company] = $this->context();
        $client->request('GET', '/cash/import/file/preview');
        $this->damagePlan($client, $company);
        $client->request('GET', '/cash/import/file/preview');
        self::assertResponseRedirects('/cash/import/file/preview');
        self::assertArrayNotHasKey('description_plan', $client->getRequest()->getSession()->get('cash_file_import'));
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        $this->damagePlan($client, $company);
        $client->request('POST', '/cash/import/file/commit', ['_token' => $this->csrfToken($client, 'cash_file_import_commit')]);
        self::assertResponseRedirects('/cash/import/file/preview');
        self::assertArrayNotHasKey('description_plan', $client->getRequest()->getSession()->get('cash_file_import'));
    }

    public function testResultSurvivesDamagedPlan(): void
    {
        [$client, $company] = $this->context();
        $client->request('GET', '/cash/import/file/preview');
        $client->request('POST', '/cash/import/file/commit', ['_token' => $this->csrfToken($client, 'cash_file_import_commit')]);
        self::assertResponseIsSuccessful();
        $payload = $client->getRequest()->getSession()->get('cash_file_import');
        $this->damagePlan($client, $company);
        $client->request('GET', '/cash/import/file/result/'.$payload['committed_job_id']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'В очереди');
    }

    public function testChangedConfirmationWithoutSessionMarkerFailsGracefully(): void
    {
        [$client] = $this->context();
        $client->request('GET', '/cash/import/file/preview');
        $client->request('POST', '/cash/import/file/commit', ['_token' => $this->csrfToken($client, 'cash_file_import_commit')]);
        self::assertResponseIsSuccessful();
        $payload = $client->getRequest()->getSession()->get('cash_file_import');
        unset($payload['committed_job_id']);
        $this->setClientSessionValue($client, 'cash_file_import', $payload);
        $client->request('POST', '/cash/import/file/commit', [
            '_token' => $this->csrfToken($client, 'cash_file_import_commit'), 'selected' => ['r2'], 'visible' => ['r2'],
        ]);
        self::assertResponseRedirects('/cash/import/file/preview');
        self::assertArrayNotHasKey('description_plan', $client->getRequest()->getSession()->get('cash_file_import'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('incompleteMappingEndpoints')]
    public function testHalfPairDraftCannotBePreviewedOrConfirmed(string $method, string $endpoint): void
    {
        [$client, $company] = $this->context();
        $client->request('GET', '/cash/import/file/preview');
        $payload = $client->getRequest()->getSession()->get('cash_file_import');
        $payload['mapping']['amount'] = null;
        $payload['mapping']['inflow'] = 'Amount';
        $payload['mapping']['outflow'] = null;
        $this->setClientSessionValue($client, 'cash_file_import', $payload);
        $client->request($method, '/cash/import/file/'.$endpoint, 'POST' === $method ? ['_token' => $this->csrfToken($client, 'cash_file_import_commit')] : []);
        self::assertSame('/cash/import/file/mapping', $client->getResponse()->headers->get('Location'));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM cash_file_import_jobs WHERE company_id = :company', ['company' => $company->getId()]));
    }

    /** @return iterable<array{string, string}> */
    public static function incompleteMappingEndpoints(): iterable
    {
        yield ['GET', 'preview'];
        yield ['POST', 'commit'];
    }

    private function damagePlan(KernelBrowser $client, Company $company): void
    {
        $payload = $client->getRequest()->getSession()->get('cash_file_import');
        static::getContainer()->get(ObjectStorageInterface::class)->write('cash-file-plans/'.$company->getId().'/'.$payload['description_plan'].'.json', '{}');
    }

    /** @return array{KernelBrowser, Company} */
    private function context(int $prefixRows = 0, bool $blankDocument = false, bool $zeroMetadata = false): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $user = new User(Uuid::uuid7()->toString());
        $user->setEmail('description-preview-'.Uuid::uuid7()->toString().'@example.test');
        $user->setPassword('unused');
        $company = new Company(Uuid::uuid7()->toString(), $user);
        $company->setName('Description preview');
        $account = new MoneyAccount(Uuid::uuid7()->toString(), $company, MoneyAccountType::CASH, 'Cash', 'RUB');
        $transaction = new CashTransaction(Uuid::uuid7()->toString(), $company, $account, CashDirection::OUTFLOW, '100.00', 'RUB', new \DateTimeImmutable('2026-10-01'));
        $transaction->setDescription($zeroMetadata ? '0' : 'Old description');
        $transaction->setDocNumber($zeroMetadata ? '0' : '101');
        foreach ([$user, $company, $account, $transaction] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $csv = "Date;Amount;Description;Document\n";
        for ($index = 0; $index < $prefixRows; ++$index) {
            $csv .= '01.10.2026;-100.00;Other;N'.$index."\n";
        }
        $csv .= '01.10.2026;-100.00;New description;'.($blankDocument ? '' : ($zeroMetadata ? '0' : '101'))."\n";
        $hash = hash('sha256', $csv);
        static::getContainer()->get(ObjectStorageInterface::class)->write('cash-file-imports/'.$hash.'.csv', $csv);
        $this->setClientSessionValue($client, 'cash_file_import', [
            'company_id' => $company->getId(),
            'file_hash' => $hash, 'file_name' => 'preview.csv', 'stored_ext' => 'csv', 'account_id' => $account->getId(),
            'mapping' => ['date' => 'Date', 'amount' => 'Amount', 'inflow' => null, 'outflow' => null, 'counterparty' => null, 'description' => 'Description', 'currency' => null, 'doc_number' => 'Document'],
        ]);

        return [$client, $company];
    }
}
