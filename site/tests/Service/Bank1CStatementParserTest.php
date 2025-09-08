<?php

namespace App\Tests\Service;

use App\Service\Bank1C\Bank1CStatementParser;
use PHPUnit\Framework\TestCase;

class Bank1CStatementParserTest extends TestCase
{
    public function testParse(): void
    {
        $raw = <<<TXT
1CClientBankExchange
ВерсияФормата=1.02
Кодировка=Windows
Отправитель=Test
Получатель=Me

СекцияРасчСчет
НомерСчета=40702810726140001479
КонецРасчСчет

СекцияДокумент=Платежное поручение
Номер=649764
Дата=29.08.2025
Сумма=101449.51
ПлательщикСчет=30302810100180000000
Плательщик=ООО "РВБ"
ПолучательСчет=40702810726140001479
ДатаПоступило=29.08.2025
НазначениеПлатежа=Оплата услуг
КонецДокумента

СекцияДокумент=Платежное поручение
Номер=384
Дата=29.08.2025
Сумма=24000.00
ПлательщикСчет=40702810726140001479
ДатаСписано=29.08.2025
Получатель=ООО "Получатель"
ПолучательСчет=40802810426140004223
НазначениеПлатежа=Оплата аренды
КонецДокумента
КонецФайла
TXT;

        $parser = new Bank1CStatementParser();
        $stmt = $parser->parse($raw);
        $this->assertGreaterThanOrEqual(2, count($stmt->documents));
        $in = $stmt->documents[0];
        $this->assertSame('Платежное поручение', $in->type);
        $this->assertSame('101449.51', $in->amount);
        $this->assertSame('30302810100180000000', $in->payerAccount);
        $this->assertSame('40702810726140001479', $in->payeeAccount);
        $this->assertSame('29.08.2025', $in->dateCredited);
        $this->assertSame('Оплата услуг', $in->purpose);
        $out = $stmt->documents[1];
        $this->assertSame('24000.00', $out->amount);
        $this->assertSame('40702810726140001479', $out->payerAccount);
        $this->assertSame('29.08.2025', $out->dateDebited);
        $this->assertSame('Оплата аренды', $out->purpose);
    }
}
