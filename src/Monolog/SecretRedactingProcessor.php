<?php

declare(strict_types=1);

namespace App\Monolog;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Strips the caller's bearer credential out of every log record, wherever it came from.
 *
 * **What this used to guard, and what it guards now.** It was built for `MCP_WRITE_TOKEN`: the
 * write tools took the shared secret as a tool *argument*, and `symfony/mcp-bundle` logs the raw
 * JSON-RPC message and the decoded arguments on the `mcp` channel. The secret is gone, but the
 * credential did not disappear — it moved to the `Authorization` header of the MCP authoring
 * endpoint, which is if anything the easier of the two to log by accident: a header lands in
 * exception contexts, in request dumps and in whatever a future bundle decides is useful.
 *
 * In production those records are `info` and `debug`, so the `fingers_crossed` handler normally
 * discards them — but it buffers 50 records and flushes the lot whenever *any* error occurs in the
 * same request, which is how a credential reaches a log file in plaintext.
 *
 * Fixing it at the channel level would only hold until the next thing decides to log a request.
 * This runs on every record instead, so the guarantee does not depend on knowing who logs what.
 */
final class SecretRedactingProcessor implements ProcessorInterface
{
    private const REPLACEMENT = '<redacted>';

    /**
     * Below this length a "secret" is more likely to be an empty or placeholder value whose
     * blind replacement would corrupt unrelated log lines. A signed JWT is never close to this
     * short.
     */
    private const MINIMUM_SECRET_LENGTH = 16;

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $secret = $this->presentedCredential();

        if (null === $secret) {
            return $record;
        }

        return $record->with(
            message: $this->redact($record->message, $secret),
            context: $this->redactAll($record->context, $secret),
            extra: $this->redactAll($record->extra, $secret),
        );
    }

    /**
     * The credential the current request presented, if any.
     *
     * Read from the request rather than injected, because unlike the shared secret it is
     * different on every call. Taking the whole header value after `Bearer ` means an expired or
     * malformed token is redacted too — those are the records most likely to be logged, since
     * they are the ones that fail.
     *
     * @return non-empty-string|null
     */
    private function presentedCredential(): ?string
    {
        $header = (string) $this->requestStack->getCurrentRequest()?->headers->get('Authorization', '');
        $credential = trim((string) preg_replace('/^\s*Bearer\s/i', '', $header));

        return \strlen($credential) >= self::MINIMUM_SECRET_LENGTH ? $credential : null;
    }

    /**
     * @param array<mixed>     $values
     * @param non-empty-string $secret
     *
     * @return array<mixed>
     */
    private function redactAll(array $values, string $secret): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = match (true) {
                \is_string($value) => $this->redact($value, $secret),
                \is_array($value) => $this->redactAll($value, $secret),
                \is_object($value) => $this->redactObject($value, $secret),
                default => $value,
            };
        }

        return $values;
    }

    /**
     * Monolog normalises an object into a string *after* processors run, so an object carrying the
     * secret would slip past. `symfony/mcp-bundle` does exactly that: it logs request objects
     * whose payload a caller controls.
     *
     * The object is encoded once to look for the secret and returned untouched when it is absent,
     * which is the overwhelmingly common case — only a record that genuinely carries the
     * credential is replaced by its redacted array form.
     *
     * @param non-empty-string $secret
     */
    private function redactObject(object $value, string $secret): mixed
    {
        $encoded = @json_encode($value);

        if (false === $encoded || !str_contains($encoded, $secret)) {
            return $value;
        }

        $decoded = json_decode($this->redact($encoded, $secret), true);

        return \is_array($decoded) ? $decoded : $this->redact($encoded, $secret);
    }

    /**
     * @param non-empty-string $secret
     */
    private function redact(string $value, string $secret): string
    {
        return str_replace($secret, self::REPLACEMENT, $value);
    }
}
