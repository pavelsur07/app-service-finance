<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Controller;

use App\Cash\Http\CashFileImportPage;
use App\Cash\Service\Import\File\CashFileMappingValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class CashFileImportPageTest extends TestCase
{
    public function testMappingValidationDoesNotDependOnDisplayLabel(): void
    {
        $request = Request::create('/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = new RequestStack();
        $stack->push($request);
        $container = new Container();
        $container->set('request_stack', $stack);
        $page = new CashFileImportPage(new CashFileMappingValidator());
        $page->setContainer($container);
        self::assertNull($page->decodeJson('{"date":[]}', 'карты колонок', true));
        self::assertSame(['date' => []], $page->decodeJson('{"date":[]}', 'опций', false));
    }
}
