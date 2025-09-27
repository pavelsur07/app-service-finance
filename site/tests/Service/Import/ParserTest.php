<?php

namespace App\Tests\Integration\Import;

class ParserTest extends ClientBank1CImportServiceTestCase
{
    public function testParseHeaderAndDocuments(): void
    {
        $content = <<<TXT
1CClientBankExchange
ВерсияФормата=1.02
РасчСчет=40702810900000000002
СекцияДокумент=Платежное поручение
Номер=123
Дата=01.02.2024
Сумма=1000.50
КонецДокумента
КонецФайла
TXT;

        $parsed = $this->service->parseHeaderAndDocuments($content);

        self::assertArrayHasKey('header', $parsed);
        self::assertArrayHasKey('documents', $parsed);
        self::assertSame('1.02', $parsed['header']['ВерсияФормата']);
        self::assertSame('40702810900000000002', $parsed['header']['РасчСчет']);
        self::assertCount(1, $parsed['documents']);

        $document = $parsed['documents'][0];
        self::assertSame('Платежное поручение', $document['_doc_type']);
        self::assertSame('123', $document['Номер']);
        self::assertSame('01.02.2024', $document['Дата']);
        self::assertSame('1000.50', $document['Сумма']);
    }
}
