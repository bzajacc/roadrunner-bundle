<?php

declare(strict_types=1);

namespace Tests\Baldinof\RoadRunnerBundle\Http;

use function Baldinof\RoadRunnerBundle\consumes;
use Baldinof\RoadRunnerBundle\Http\KernelHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

class KernelHandlerTest extends TestCase
{
    public function test_it_calls_the_kernel()
    {
        $kernel = $this->kernel(function (Request $request) {
            $this->assertSame('http://example.org/', $request->getUri());
            $this->assertSame('GET', $request->getMethod());

            return new Response('hello');
        });

        $handler = $this->createHandler($kernel);

        $gen = $handler->handle(Request::create('http://example.org/'));
        $response = $gen->current();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('hello', (string) $response->getContent());
        $this->assertFalse($kernel->terminateCalled);

        consumes($gen);

        $this->assertTrue($kernel->terminateCalled);
    }

    /**
     * @dataProvider provideBasicAuthTest
     */
    public function test_it_handles_basic_auth_header(array $headers, $expectedUser, $expectedPassword)
    {
        /** @var Request|null $collectedRequest */
        $collectedRequest = null;
        $kernel = $this->kernel(function (Request $request) use (&$collectedRequest) {
            $collectedRequest = $request;

            return new Response('hello');
        });

        $handler = $this->createHandler($kernel);

        $request = Request::create('http://example.org/');
        $request->headers->add($headers);

        consumes($handler->handle($request));

        $this->assertInstanceOf(Request::class, $collectedRequest);
        $this->assertEquals($expectedUser, $collectedRequest->getUser());
        $this->assertEquals($expectedPassword, $collectedRequest->getPassword());
    }

    public function provideBasicAuthTest()
    {
        yield 'no Authorization header' => [
            'headers' => [],
            'expectedUser' => null,
            'expectedPassword' => null,
        ];

        yield 'unsupported Authorization header' => [
            'headers' => ['Authorization' => 'Bearer token'],
            'expectedUser' => null,
            'expectedPassword' => null,
        ];

        yield 'wrongly encoded value' => [
            'headers' => ['Authorization' => 'Basic not-base64'],
            'expectedUser' => null,
            'expectedPassword' => null,
        ];

        yield 'only user' => [
            'headers' => ['Authorization' => sprintf('Basic %s', base64_encode('the-user'))],
            'expectedUser' => 'the-user',
            'expectedPassword' => null,
        ];

        yield 'user and password' => [
            'headers' => ['Authorization' => sprintf('Basic %s', base64_encode('the-user:the-password'))],
            'expectedUser' => 'the-user',
            'expectedPassword' => 'the-password',
        ];
    }

    private function createHandler(HttpKernelInterface $kernel): KernelHandler
    {
        return new KernelHandler($kernel);
    }

    private function kernel(callable $callback)
    {
        return new class($callback) implements HttpKernelInterface, TerminableInterface {
            private $callback;
            public $terminateCalled = false;

            public function __construct(callable $callback)
            {
                $this->callback = $callback;
            }

            public function handle(Request $request, $type = self::MASTER_REQUEST, $catch = true)
            {
                return ($this->callback)($request);
            }

            public function terminate(Request $request, Response $response)
            {
                $this->terminateCalled = true;
            }
        };
    }
}
