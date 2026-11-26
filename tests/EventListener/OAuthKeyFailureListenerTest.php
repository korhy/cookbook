<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\OAuthKeyFailureListener;
use App\OAuth\McpResourceUri;
use League\OAuth2\Server\CryptKey;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * `POST /oauth2/token` answering a bare 500 when the signing key cannot be read.
 *
 * `League\OAuth2\Server\CryptKey` reads *and decrypts* the key in its constructor, not when it
 * signs, so a missing file or a wrong `OAUTH_PASSPHRASE` fails while the `AuthorizationServer`
 * service is being instantiated — before any controller code runs. The client gets Symfony's
 * generic error page instead of the `{"error": …}` envelope it parses for every other failure
 * from this endpoint, and learns nothing about whether to retry.
 */
final class OAuthKeyFailureListenerTest extends TestCase
{
    private const RESOURCE_URI = 'https://api.example.test/api/v1/mcp-authoring';

    private TestHandler $logHandler;

    protected function setUp(): void
    {
        $this->logHandler = new TestHandler();
    }

    public function testAKeyFailureOnTheTokenEndpointBecomesAnOAuthServerError(): void
    {
        $event = $this->event('/oauth2/token', $this->keyFailure());

        ($this->listener())($event);

        $response = $event->getResponse();

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));

        $payload = json_decode((string) $response->getContent(), true);

        // league's own code for this, so a client sees the envelope it already parses.
        self::assertSame('server_error', $payload['error']);
    }

    /**
     * The same constructor sits behind the resource server's public key, so the authoring endpoint
     * fails the same way — and its callers are machines too.
     */
    public function testTheAuthoringEndpointIsCoveredToo(): void
    {
        $event = $this->event('/api/v1/mcp-authoring', $this->keyFailure());

        ($this->listener())($event);

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $event->getResponse()?->getStatusCode());
    }

    /**
     * league's message carries the key path. Not a secret, but internal shape — and the operator
     * who needs it has the error log, which is where it goes.
     */
    public function testThePathIsLoggedAndNotReturned(): void
    {
        $event = $this->event('/oauth2/token', $this->keyFailure());

        ($this->listener())($event);

        self::assertStringNotContainsString('/nowhere/', (string) $event->getResponse()?->getContent());
        self::assertTrue($this->logHandler->hasCriticalThatContains('OAuth keypair could not be read'));
    }

    /**
     * Wrapped is the normal case: the failure happens while the container instantiates the
     * authorization server, so what reaches the kernel is a wrapper around it.
     */
    public function testAWrappedKeyFailureIsStillRecognised(): void
    {
        $wrapped = new \RuntimeException('Cannot autowire service.', 0, $this->keyFailure());
        $event = $this->event('/oauth2/token', $wrapped);

        ($this->listener())($event);

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $event->getResponse()?->getStatusCode());
    }

    /**
     * Everything that is not a key failure keeps Symfony's error handling, including the profiler
     * and the stack trace a developer wants.
     */
    public function testAnUnrelatedExceptionIsLeftAlone(): void
    {
        $event = $this->event('/oauth2/token', new \RuntimeException('Something else entirely.'));

        ($this->listener())($event);

        self::assertNull($event->getResponse());
    }

    /**
     * The consent screen and the back-office are read by a browser, which wants Symfony's error
     * page rather than a JSON envelope.
     */
    public function testABrowserFacingPathIsLeftAlone(): void
    {
        $event = $this->event('/admin/recipe', $this->keyFailure());

        ($this->listener())($event);

        self::assertNull($event->getResponse());
    }

    /**
     * The real exception, raised the way production raises it, so the detection is not tested
     * against a hand-made stand-in. It is recognised by the file it was thrown from, resolved by
     * reflection on CryptKey — not by its message, whose wording is a vendor's to change.
     */
    private function keyFailure(): \Throwable
    {
        try {
            new CryptKey('file:///nowhere/private.pem');
        } catch (\Throwable $e) {
            return $e;
        }

        self::fail('CryptKey accepted a path that does not exist.');
    }

    private function listener(): OAuthKeyFailureListener
    {
        return new OAuthKeyFailureListener(
            new McpResourceUri(self::RESOURCE_URI),
            new Logger('test', [$this->logHandler]),
        );
    }

    private function event(string $path, \Throwable $throwable): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create($path, 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
    }
}
