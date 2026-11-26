<?php

declare(strict_types=1);

namespace App\Tests\Monolog;

use App\Monolog\SecretRedactingProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Regression cover for a real leak. It was first found in the shared-secret era, where
 * symfony/mcp-bundle logged the decoded tool arguments and `token` was one of them. The secret is
 * now an OAuth access token in the `Authorization` header, which is the easier of the two to log
 * by accident — so the cover follows the credential rather than being retired with it.
 */
final class SecretRedactingProcessorTest extends TestCase
{
    /**
     * Shaped like a JWT, because that is what the header carries now; only its length and its
     * uniqueness matter to the processor.
     */
    private const TOKEN = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJqdGkiOiJjMmY0YTFiOGUwN2QiLCJhdWQiOlsiY29va2Jvb2stbWNwLWF1dGhvcmluZyJdfQ.c2f4a1b8e07d46339a5c1e8b7f20d64a';

    public function testRedactsTheTokenFromTheMessage(): void
    {
        $record = $this->process($this->record('Request headers: {"authorization": "Bearer '.self::TOKEN.'"}'));

        $this->assertStringNotContainsString(self::TOKEN, $record->message);
        $this->assertStringContainsString('<redacted>', $record->message);
    }

    public function testRedactsTheTokenFromNestedContext(): void
    {
        $record = $this->process($this->record('Handling request', [
            'name' => 'recipe_create',
            'request' => ['headers' => ['authorization' => 'Bearer '.self::TOKEN], 'title' => 'Cake'],
        ]));

        $this->assertStringNotContainsString(self::TOKEN, $record->context['request']['headers']['authorization']);
        $this->assertSame('Cake', $record->context['request']['title']);
    }

    public function testRedactsFromExtra(): void
    {
        $record = new LogRecord(
            new \DateTimeImmutable(),
            'mcp',
            Level::Info,
            'msg',
            [],
            ['body' => self::TOKEN],
        );

        $this->assertSame('<redacted>', $this->process($record)->extra['body']);
    }

    /**
     * The case that actually leaked: mcp-bundle logs a request *object*, and Monolog normalises an
     * object to a string only after processors have run — so an object carrying the credential
     * slips past anything that only looks at strings.
     */
    public function testRedactsTheTokenInsideAnObjectContextValue(): void
    {
        $request = new \stdClass();
        $request->method = 'tools/call';
        $request->headers = ['authorization' => 'Bearer '.self::TOKEN];

        $record = $this->process($this->record('Handling request.', ['request' => $request]));

        $this->assertStringNotContainsString(self::TOKEN, json_encode($record->context) ?: '');
    }

    public function testAnObjectWithoutTheSecretIsNotRewritten(): void
    {
        $object = new \stdClass();
        $object->harmless = 'value';

        $record = $this->process($this->record('msg', ['o' => $object]));

        // Identity, not equality: an object that carries no secret must be passed straight through.
        $this->assertSame($object, $record->context['o']);
    }

    public function testLeavesUnrelatedRecordsUntouched(): void
    {
        $record = $this->process($this->record('Nothing secret here', ['a' => 'b']));

        $this->assertSame('Nothing secret here', $record->message);
        $this->assertSame(['a' => 'b'], $record->context);
    }

    public function testNonStringScalarContextValuesSurvive(): void
    {
        $record = $this->process($this->record('msg', ['n' => 42, 'b' => true, 'null' => null]));

        $this->assertSame(42, $record->context['n']);
        $this->assertTrue($record->context['b']);
        $this->assertNull($record->context['null']);
    }

    /**
     * With no credential presented there is nothing to redact, and a blind replacement of the
     * empty string would corrupt every record. This is the common case — the public read
     * endpoint, the back-office, every console command.
     */
    public function testDoesNothingWhenTheRequestPresentsNoCredential(): void
    {
        $record = $this->processor(null)($this->record('Received message. {"token": ""}'));

        $this->assertSame('Received message. {"token": ""}', $record->message);
    }

    /**
     * A header too short to be a token is a placeholder or a stray value, and replacing it
     * blindly would corrupt unrelated log lines that happen to contain the same substring.
     */
    public function testIgnoresAShortPlaceholderValue(): void
    {
        $record = $this->processor('abc')($this->record('abc is a common substring'));

        $this->assertSame('abc is a common substring', $record->message);
    }

    /**
     * There is no request at all on the console, where the MCP read server also runs over stdio.
     */
    public function testDoesNothingOutsideARequest(): void
    {
        $processor = new SecretRedactingProcessor(new RequestStack());
        $record = $processor($this->record('Nothing to redact here'));

        $this->assertSame('Nothing to redact here', $record->message);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function record(string $message, array $context = []): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'mcp', Level::Info, $message, $context);
    }

    private function process(LogRecord $record): LogRecord
    {
        return $this->processor(self::TOKEN)($record);
    }

    /**
     * The credential is read off the current request, so the fixture is a request stack rather
     * than a constructor argument.
     */
    private function processor(?string $credential): SecretRedactingProcessor
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create(
            '/api/v1/mcp-authoring',
            'POST',
            server: null === $credential ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$credential],
        ));

        return new SecretRedactingProcessor($requestStack);
    }
}
