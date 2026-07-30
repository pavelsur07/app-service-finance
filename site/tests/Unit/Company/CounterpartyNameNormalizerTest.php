<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company;

use App\Company\Domain\Service\CounterpartyNameNormalizer;
use App\Company\Domain\ValueObject\CounterpartyName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CounterpartyNameNormalizerTest extends TestCase
{
    private CounterpartyNameNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new CounterpartyNameNormalizer();
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function romashkaProvider(): iterable
    {
        yield 'ОПФ префиксом' => ['ООО "Ромашка"'];
        yield 'ОПФ суффиксом' => ['"Ромашка" ООО'];
        yield 'нижний регистр' => ['ромашка ооо'];
        yield 'ёлочки' => ['ООО «Ромашка»'];
        yield 'полная форма' => ['Общество с ограниченной ответственностью "Ромашка"'];
        yield 'точки в ОПФ' => ['О.О.О. "Ромашка"'];
        yield 'лишние пробелы' => ['  ООО    «Ромашка»   '];
    }

    /**
     * Любой порядок и любое написание ОПФ дают один core и один хинт — иначе
     * «ООО "Ромашка"» и «"Ромашка" ООО» так и останутся двумя контрагентами.
     */
    #[DataProvider('romashkaProvider')]
    public function testAllRomashkaSpellingsGiveSameCoreAndHint(string $rawName): void
    {
        // When
        $name = $this->normalizer->normalize($rawName);

        // Then
        self::assertSame('РОМАШКА', $name->core);
        self::assertSame('ООО', $name->legalFormHint);
    }

    public function testDisplayKeepsUserInput(): void
    {
        // When
        $name = $this->normalizer->normalize('  ООО "Ромашка"  ');

        // Then
        self::assertSame('ООО "Ромашка"', $name->display);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function legalFormProvider(): iterable
    {
        yield 'АО' => ['АО "Балтийский лизинг"', 'АО', 'БАЛТИЙСКИЙ ЛИЗИНГ'];
        yield 'АО суффиксом' => ['"Балтийский лизинг" АО', 'АО', 'БАЛТИЙСКИЙ ЛИЗИНГ'];
        yield 'АО полной формой' => ['Акционерное общество "Балтийский лизинг"', 'АО', 'БАЛТИЙСКИЙ ЛИЗИНГ'];
        yield 'ПАО' => ['ПАО "Сбербанк"', 'ПАО', 'СБЕРБАНК'];
        yield 'ПАО полной формой' => ['Публичное акционерное общество "Сбербанк"', 'ПАО', 'СБЕРБАНК'];
        yield 'ЗАО' => ['ЗАО "Ромашка"', 'ЗАО', 'РОМАШКА'];
        yield 'ОАО' => ['ОАО "Ромашка"', 'ОАО', 'РОМАШКА'];
        yield 'ИП префиксом' => ['ИП Кулешова Анастасия Владимировна', 'ИП', 'КУЛЕШОВА АНАСТАСИЯ ВЛАДИМИРОВНА'];
        yield 'ИП полной формой' => ['Индивидуальный предприниматель Иванов Иван Петрович', 'ИП', 'ИВАНОВ ИВАН ПЕТРОВИЧ'];
    }

    #[DataProvider('legalFormProvider')]
    public function testLegalFormIsRecognized(string $rawName, string $expectedHint, string $expectedCore): void
    {
        // When
        $name = $this->normalizer->normalize($rawName);

        // Then
        self::assertSame($expectedHint, $name->legalFormHint);
        self::assertSame($expectedCore, $name->core);
    }

    /**
     * Блокирующая группа: присвоить «ИП» физлицу дороже, чем не распознать ИП.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function falsePositiveIpProvider(): iterable
    {
        yield 'инициалы слитно' => ['Иванов И.П.'];
        yield 'инициалы с пробелом' => ['Иванов И. П.'];
        yield 'фамилия на ИП' => ['Ипатов Сергей Иванович'];
        yield 'ФИО без ОПФ' => ['Шереметова Татьяна Васильевна'];
        yield 'ИП суффиксом' => ['Иванов Иван Петрович ИП'];
    }

    #[DataProvider('falsePositiveIpProvider')]
    public function testIpIsNotDetectedOutsidePrefix(string $rawName): void
    {
        // When
        $name = $this->normalizer->normalize($rawName);

        // Then
        self::assertNull($name->legalFormHint);
    }

    public function testInitialsSurviveAsPartOfCore(): void
    {
        // When
        $name = $this->normalizer->normalize('Иванов И.П.');

        // Then
        self::assertSame('ИВАНОВ И П', $name->core);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function neverStrippedProvider(): iterable
    {
        yield 'УФК' => ['УФК по Ростовской области (ОСФР по Ростовской области, л/с 04584Ф58010)'];
        yield 'ФГБУ' => ['ФГБУ "Ромашка"'];
        yield 'ГБУ' => ['ГБУ "Ромашка"'];
        yield 'МУП' => ['МУП "Водоканал"'];
        yield 'ГУП' => ['ГУП "Водоканал"'];
        yield 'Казначейство' => ['Казначейство России'];
    }

    #[DataProvider('neverStrippedProvider')]
    public function testAmbiguousFormsStayInsideCore(string $rawName): void
    {
        // When
        $name = $this->normalizer->normalize($rawName);

        // Then
        self::assertNull($name->legalFormHint);
        self::assertNotSame('', $name->core);
    }

    public function testUfkKeepsAccountNumberInCore(): void
    {
        // When
        $name = $this->normalizer->normalize('УФК по Ростовской области (ОСФР по Ростовской области, л/с 04584Ф58010)');

        // Then
        self::assertStringContainsString('04584Ф58010', $name->core);
        self::assertStringContainsString('УФК', $name->core);
    }

    /**
     * Разные ОПФ при одинаковом названии — разные юрлица (реальный случай PROD:
     * ООО и АО «Балтийский лизинг»). core совпадает, хинт обязан различаться.
     */
    public function testSameCoreDifferentLegalFormIsDistinguishable(): void
    {
        // When
        $ooo = $this->normalizer->normalize('ООО "Балтийский лизинг"');
        $ao = $this->normalizer->normalize('АО "Балтийский лизинг"');

        // Then
        self::assertSame($ooo->core, $ao->core);
        self::assertNotSame($ooo->legalFormHint, $ao->legalFormHint);
    }

    public function testOnlyLegalFormGivesNonEmptyCoreWithoutHint(): void
    {
        // When
        $name = $this->normalizer->normalize('ООО');

        // Then
        self::assertSame('ООО', $name->core);
        self::assertNull($name->legalFormHint);
    }

    public function testOnlyLegalFormWithQuotesGivesNonEmptyCore(): void
    {
        // When
        $name = $this->normalizer->normalize('ООО ""');

        // Then
        self::assertSame('ООО', $name->core);
        self::assertNull($name->legalFormHint);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function idempotencyProvider(): iterable
    {
        yield 'ОПФ префиксом' => ['ООО "Ромашка"'];
        yield 'ОПФ суффиксом' => ['"Ромашка" ООО'];
        yield 'ИП' => ['ИП Кулешова Анастасия Владимировна'];
        yield 'инициалы' => ['Иванов И.П.'];
        yield 'УФК' => ['УФК по Ростовской области (л/с 04584Ф58010)'];
        yield 'только ОПФ' => ['ООО'];
    }

    #[DataProvider('idempotencyProvider')]
    public function testNormalizationIsIdempotent(string $rawName): void
    {
        // Given
        $once = $this->normalizer->normalize($rawName);

        // When
        $twice = $this->normalizer->normalize($once->display);

        // Then
        self::assertSame($once->core, $twice->core);
        self::assertSame($once->legalFormHint, $twice->legalFormHint);
    }

    public function testEmptyNameThrows(): void
    {
        // Then
        $this->expectException(\InvalidArgumentException::class);

        // When
        $this->normalizer->normalize('   ');
    }

    public function testValueObjectCannotBeConstructedDirectly(): void
    {
        // Given
        $constructor = (new \ReflectionClass(CounterpartyName::class))->getConstructor();

        // Then
        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate(), 'Название должно создаваться только через нормализатор.');
    }
}
