<?php

declare(strict_types=1);

namespace App\Tests\Unit\OneC;

use App\OneC\Application\HandshakeSnapshot;
use App\OneC\Application\PackageEnvelope;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Примеры контракта src/OneC/Contract/v1 проходят схему и разбор сервера.
 */
final class ContractV1Test extends TestCase
{
    private const DIR = __DIR__.'/../../../src/OneC/Contract/v1';
    private const ID = 'https://vashfindir.ru/contracts/onec/v1/schema.json';

    /** @return iterable<string, array{string, string}> */
    public static function examples(): iterable
    {
        foreach (['handshake_request', 'handshake_response', 'package_part', 'package_response', 'error'] as $name) {
            yield $name => [$name, $name];
        }
    }

    #[DataProvider('examples')]
    public function testExampleMatchesSchema(string $example, string $def): void
    {
        $result = $this->validate($this->example($example), $def);

        self::assertTrue($result, $example.' не проходит схему');
    }

    public function testServerAcceptsExamples(): void
    {
        $handshake = HandshakeSnapshot::parse((string) file_get_contents(self::DIR.'/examples/handshake_request.json'));
        $part = PackageEnvelope::parse((string) file_get_contents(self::DIR.'/examples/package_part.json'));

        self::assertSame('26c4bc0e-58e8-4411-84f8-e434b7387894', $handshake->baseId);
        self::assertSame(2, $part->documentCount);
    }

    public function testInvoiceWithoutAmountOrWithFloatAmountIsRejected(): void
    {
        $invoice = $this->example('package_part')->documents[0];
        unset($invoice->amount);
        self::assertFalse($this->validate($invoice, 'invoice'));

        $invoice->amount = 1000.5;
        self::assertFalse($this->validate($invoice, 'invoice'));
    }

    public function testPartWithoutSnapshotAtIsRejected(): void
    {
        $part = $this->example('package_part');
        unset($part->snapshot_at);

        self::assertFalse($this->validate($part, 'package_part'));
    }

    private function example(string $name): \stdClass
    {
        $data = json_decode((string) file_get_contents(self::DIR.'/examples/'.$name.'.json'), false, 64, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $data);

        return $data;
    }

    private function validate(mixed $data, string $def): bool
    {
        $validator = new Validator();
        $validator->resolver()?->registerFile(self::ID, self::DIR.'/schema.json');

        return $validator->validate($data, self::ID.'#/$defs/'.$def)->isValid();
    }
}
