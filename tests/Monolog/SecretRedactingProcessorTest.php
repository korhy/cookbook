<?php

declare(strict_types=1);

namespace App\Tests\Monolog;

use App\Monolog\SecretRedactingProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * Regression cover for a real leak: symfony/mcp-bundle logs the raw JSON-RPC message and the
 * decoded tool arguments, and `token` is one of those arguments.
 */
final class SecretRedactingProcessorTest extends TestCase
{
    private const TOKEN = 'c2f4a1b8e07d46339a5c1e8b7f20d64a91c3e5b7d8a06f24913b7c5e0a2d4f68';

    public function testRedactsTheTokenFromTheMessage(): void
    {
        $record = $this->process($this->record('Received message. {"token": "'.self::TOKEN.'"}'));

        $this->assertStringNotContainsString(self::TOKEN, $record->message);
        $this->assertStringContainsString('<redacted>', $record->message);
    }

    public function testRedactsTheTokenFromNestedContext(): void
    {
        $record = $this->process($this->record('Executing tool', [
            'name' => 'recipe_create',
            'arguments' => ['title' => 'Cake', 'token' => self::TOKEN],
        ]));

        $this->assertSame('<redacted>', $record->context['arguments']['token']);
        $this->assertSame('Cake', $record->context['arguments']['title']);
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
     * The case that actually leaked: mcp-bundle logs the CallToolRequest *object*, and Monolog
     * normalises an object to a string only after processors have run.
     */
    public function testRedactsTheTokenInsideAnObjectContextValue(): void
    {
        $request = new \stdClass();
        $request->method = 'tools/call';
        $request->params = ['name' => 'recipe_create', 'arguments' => ['token' => self::TOKEN]];

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
     * With no token configured there is nothing to redact, and a blind replacement of the empty
     * string would corrupt every record.
     */
    public function testDoesNothingWhenNoTokenIsConfigured(): void
    {
        $processor = new SecretRedactingProcessor('');
        $record = $processor($this->record('Received message. {"token": ""}'));

        $this->assertSame('Received message. {"token": ""}', $record->message);
    }

    public function testIgnoresAShortPlaceholderValue(): void
    {
        $processor = new SecretRedactingProcessor('abc');
        $record = $processor($this->record('abc is a common substring'));

        $this->assertSame('abc is a common substring', $record->message);
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
        return (new SecretRedactingProcessor(self::TOKEN))($record);
    }
}
