<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company\Controller\Api;

use App\Company\Domain\Service\CounterpartyNameNormalizer;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Entity\User;
use App\Company\Enum\CounterpartyType;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CounterpartySearchControllerTest extends WebTestCaseBase
{
    public function testSearchReturnsOwnCompanyCounterpartyOnly(): void
    {
        ['client' => $client, 'em' => $em, 'own' => $own, 'other' => $other] = $this->prepare();

        $this->persistCounterparty($em, $own, 'ООО "Ромашка"', '7707083893');
        $this->persistCounterparty($em, $other, 'ООО "Ромашка Чужая"', '7707083894');
        $em->flush();

        $client->request('GET', '/api/counterparties/search?q=ромашка');

        self::assertResponseIsSuccessful();
        $items = $this->decode($client);
        self::assertCount(1, $items);
        self::assertSame('ООО "Ромашка"', $items[0]['name']);
        self::assertSame('7707083893', $items[0]['inn']);
        self::assertArrayNotHasKey('legalFormHint', $items[0]);
    }

    public function testShortQueryReturnsEmptyArray(): void
    {
        ['client' => $client, 'em' => $em, 'own' => $own] = $this->prepare();

        $this->persistCounterparty($em, $own, 'ООО "Ромашка"', '7707083893');
        $em->flush();

        $client->request('GET', '/api/counterparties/search?q=р');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->decode($client));
    }

    public function testMissingQueryReturnsEmptyArray(): void
    {
        ['client' => $client] = $this->prepare();

        $client->request('GET', '/api/counterparties/search');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->decode($client));
    }

    public function testCompanyIdFromRequestIsIgnored(): void
    {
        ['client' => $client, 'em' => $em, 'other' => $other] = $this->prepare();

        $this->persistCounterparty($em, $other, 'ООО "Ромашка Чужая"', '7707083894');
        $em->flush();

        $client->request('GET', sprintf('/api/counterparties/search?q=ромашка&companyId=%s', $other->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->decode($client));
    }

    public function testAnonymousIsRejected(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->resetDb();

        $client->request('GET', '/api/counterparties/search?q=ромашка');

        self::assertResponseStatusCodeSame(302);
    }

    /**
     * @return array{client: KernelBrowser, em: EntityManagerInterface, own: Company, other: Company}
     */
    private function prepare(): array
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $this->resetDb();

        $user = $this->createUser($hasher, 'search-api@example.com');
        $otherUser = $this->createUser($hasher, 'search-api-foreign@example.com');
        $ownCompany = $this->createCompany($user, 'Own Co');
        $otherCompany = $this->createCompany($otherUser, 'Foreign Co');

        $em->persist($user);
        $em->persist($otherUser);
        $em->persist($ownCompany);
        $em->persist($otherCompany);
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $ownCompany->getId());

        return ['client' => $client, 'em' => $em, 'own' => $ownCompany, 'other' => $otherCompany];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decode(KernelBrowser $client): array
    {
        /** @var list<array<string, mixed>> $decoded */
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function persistCounterparty(EntityManagerInterface $em, Company $company, string $name, string $inn): void
    {
        $counterparty = new Counterparty(
            Uuid::uuid4()->toString(),
            $company,
            (new CounterpartyNameNormalizer())->normalize($name),
            CounterpartyType::LEGAL_ENTITY,
        );
        $counterparty->assignTaxIds($inn, null);

        $em->persist($counterparty);
    }

    private function createUser(UserPasswordHasherInterface $hasher, string $email): User
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail($email);
        $user->setPassword($hasher->hashPassword($user, 'password'));

        return $user;
    }

    private function createCompany(User $user, string $name): Company
    {
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName($name);

        return $company;
    }
}
