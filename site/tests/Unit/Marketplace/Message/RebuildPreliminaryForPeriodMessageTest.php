<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Message;

use App\Marketplace\Message\RebuildPreliminaryForPeriodMessage;
use PHPUnit\Framework\TestCase;

final class RebuildPreliminaryForPeriodMessageTest extends TestCase
{
    public function testLegacyPayloadWithoutStagesDoesNotFail(): void
    {
        // Messenger сериализует нативно, а нативная десериализация конструктор не
        // выполняет: у сообщения, попавшего в очередь до деплоя, свойство stages
        // осталось бы неинициализированным. Прямое обращение упало бы Error ещё
        // до обработки, и старые задания ушли бы в failed через ретраи.
        $class = RebuildPreliminaryForPeriodMessage::class;
        $legacy = sprintf(
            'O:%d:"%s":5:{s:9:"companyId";s:4:"c-1x";s:11:"marketplace";s:4:"ozon";s:4:"year";i:2026;s:5:"month";i:9;s:11:"actorUserId";s:4:"u-1x";}',
            strlen($class),
            $class,
        );

        $message = unserialize($legacy);

        self::assertInstanceOf(RebuildPreliminaryForPeriodMessage::class, $message);
        self::assertNull($message->stages());
    }

    public function testStagesSurviveSerialization(): void
    {
        $message = new RebuildPreliminaryForPeriodMessage('c-1x', 'ozon', 2026, 9, 'u-1x', ['costs']);

        $restored = unserialize(serialize($message));

        self::assertInstanceOf(RebuildPreliminaryForPeriodMessage::class, $restored);
        self::assertSame(['costs'], $restored->stages());
    }
}
