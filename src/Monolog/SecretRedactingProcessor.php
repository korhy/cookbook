<?php

declare(strict_types=1);

namespace App\Monolog;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Strips the MCP write token out of every log record, wherever it came from.
 *
 * `McpWriteGuard` is careful to log only a fingerprint, but it is not the only thing that sees the
 * token: `symfony/mcp-bundle` logs the raw JSON-RPC message and the decoded tool arguments on the
 * `mcp` channel, and `token` is one of those arguments. In production those records are `info` and
 * `debug`, so the `fingers_crossed` handler normally discards them — but it buffers 50 records and
 * flushes the lot whenever *any* error occurs in the same request, which would write the token to
 * the logs in plaintext.
 *
 * Fixing it at the channel level would only hold until the next thing decides to log arguments.
 * This runs on every record instead, so the guarantee does not depend on knowing who logs what.
 */
final class SecretRedactingProcessor implements ProcessorInterface
{
    private const REPLACEMENT = '<redacted>';

    /**
     * Below this length a "secret" is more likely to be an empty or placeholder value whose
     * blind replacement would corrupt unrelated log lines.
     */
    private const MINIMUM_SECRET_LENGTH = 16;

    /** @var string[] */
    private readonly array $secrets;

    public function __construct(
        #[Autowire(env: 'MCP_WRITE_TOKEN')]
        string $writeToken,
    ) {
        $secrets = [];

        foreach ([$writeToken] as $secret) {
            if (\strlen($secret) >= self::MINIMUM_SECRET_LENGTH) {
                $secrets[] = $secret;
            }
        }

        $this->secrets = $secrets;
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        if ([] === $this->secrets) {
            return $record;
        }

        return $record->with(
            message: $this->redact($record->message),
            context: $this->redactAll($record->context),
            extra: $this->redactAll($record->extra),
        );
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    private function redactAll(array $values): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = match (true) {
                \is_string($value) => $this->redact($value),
                \is_array($value) => $this->redactAll($value),
                \is_object($value) => $this->redactObject($value),
                default => $value,
            };
        }

        return $values;
    }

    /**
     * Monolog normalises an object into a string *after* processors run, so an object carrying the
     * secret would slip past. `symfony/mcp-bundle` does exactly that: it logs the CallToolRequest
     * object, whose arguments include the token.
     *
     * The object is encoded once to look for the secret and returned untouched when it is absent,
     * which is the overwhelmingly common case — only a record that genuinely carries the token is
     * replaced by its redacted array form.
     */
    private function redactObject(object $value): mixed
    {
        $encoded = @json_encode($value);

        if (false === $encoded || !$this->containsSecret($encoded)) {
            return $value;
        }

        $decoded = json_decode($this->redact($encoded), true);

        return \is_array($decoded) ? $decoded : $this->redact($encoded);
    }

    private function containsSecret(string $value): bool
    {
        foreach ($this->secrets as $secret) {
            if (str_contains($value, $secret)) {
                return true;
            }
        }

        return false;
    }

    private function redact(string $value): string
    {
        return str_replace($this->secrets, self::REPLACEMENT, $value);
    }
}
