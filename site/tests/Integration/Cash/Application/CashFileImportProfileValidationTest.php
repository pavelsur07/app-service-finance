<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\Import\File\CreateCashFileImportProfileAction;
use App\Cash\Application\Import\File\UpdateCashFileImportProfileAction;
use App\Cash\Entity\Import\CashFileImportProfile;
use App\Cash\Exception\InvalidCashFileMappingException;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;

final class CashFileImportProfileValidationTest extends IntegrationTestCase
{
    #[DataProvider('operations')]
    public function testProfileActionRejectsMalformedMappingBeforeMutation(bool $update): void
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $profile = null;
        if ($update) {
            $profile = new CashFileImportProfile(Uuid::uuid7()->toString(), $company, 'Original', ['date' => 'Date']);
            $this->em->persist($profile);
        }
        $this->em->flush();
        try {
            if (null !== $profile) {
                self::getContainer()->get(UpdateCashFileImportProfileAction::class)($profile, 'Changed', ['date' => []], [], CashFileImportProfile::TYPE_CASH_TRANSACTION);
            } else {
                self::getContainer()->get(CreateCashFileImportProfileAction::class)($company, 'Changed', ['date' => []], [], CashFileImportProfile::TYPE_CASH_TRANSACTION);
            }
            self::fail('Malformed mapping must be rejected before saving.');
        } catch (InvalidCashFileMappingException) {
            self::assertTrue($this->em->isOpen());
        }
        if (null !== $profile) {
            self::assertSame('Original', $profile->getName());
            $this->em->refresh($profile);
            self::assertSame(['date' => 'Date'], $profile->getMapping());
        }
        self::assertSame($update ? 1 : 0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cash_file_import_profile WHERE company_id = :company', ['company' => $company->getId()]));
    }

    /** @return iterable<array{bool}> */
    public static function operations(): iterable
    {
        yield [false];
        yield [true];
    }
}
