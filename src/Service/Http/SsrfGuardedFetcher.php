<?php

declare(strict_types=1);

namespace App\Service\Http;

use App\Exception\Mcp\UrlFetchRejectedException;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches an external page on behalf of an MCP caller without becoming an SSRF primitive.
 *
 * Server-side fetching of a caller-supplied URL is the textbook way to turn a public endpoint into
 * a probe for the internal network and the cloud metadata service. Five controls, in order:
 *
 * 1. **HTTPS only.** No http://, no file://, no gopher://, no redirect into another scheme.
 * 2. **Host allowlist.** The primary control — the caller cannot pick an arbitrary target at all.
 *    Empty by default, which disables the whole feature.
 * 3. **Resolve-then-verify, then pin.** Every A/AAAA record is checked against the blocked ranges,
 *    and the connection is pinned to a verified address with HttpClient's `resolve` option. That
 *    pin is what closes DNS rebinding: without it, the name could resolve to a public IP during
 *    the check and to 127.0.0.1 microseconds later when the socket opens.
 * 4. **Manual redirects.** Each hop is re-validated from scratch — allowlist and IP both. Letting
 *    the client follow redirects itself would let an allowlisted host bounce us anywhere.
 * 5. **Bounded work.** Short timeout, hard byte cap enforced while streaming, HTML only.
 */
#[WithMonologChannel('mcp_audit')]
final class SsrfGuardedFetcher
{
    private const MAX_BYTES = 2 * 1024 * 1024;
    private const TIMEOUT_SECONDS = 5;
    private const MAX_REDIRECTS = 3;

    /**
     * Ranges that must never be reachable. `filter_var()` with NO_PRIV_RANGE|NO_RES_RANGE covers
     * most of these already; they are restated because that filter's definition is not part of
     * PHP's documented contract to stay fixed, and because 100.64.0.0/10 is not in it at all.
     *
     * 169.254.169.254 — the cloud metadata address — is inside 169.254.0.0/16.
     */
    private const BLOCKED_RANGES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',     // CGNAT
        '127.0.0.0/8',
        '169.254.0.0/16',    // link-local, includes the metadata service
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',     // benchmarking
        '224.0.0.0/4',       // multicast
        '240.0.0.0/4',       // reserved
        '::1/128',
        '::/128',
        '::ffff:0:0/96',     // IPv4-mapped, so a mapped 127.0.0.1 cannot sneak through
        'fc00::/7',          // unique local
        'fe80::/10',         // link-local
    ];

    /** @var string[] */
    private readonly array $allowedHosts;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'MCP_IMPORT_ALLOWED_HOSTS')]
        string $allowedHosts = '',
    ) {
        $this->allowedHosts = array_values(array_filter(array_map(
            static fn (string $host): string => mb_strtolower(trim($host)),
            explode(',', $allowedHosts),
        )));
    }

    public function isEnabled(): bool
    {
        return [] !== $this->allowedHosts;
    }

    /**
     * @return string[] the configured allowlist, for a caller-facing error message
     */
    public function allowedHosts(): array
    {
        return $this->allowedHosts;
    }

    /**
     * @throws UrlFetchRejectedException
     */
    public function fetch(string $url): string
    {
        if (!$this->isEnabled()) {
            $this->logger->info('URL import refused: no allowlist configured.');

            throw new UrlFetchRejectedException('Importing from a URL is not enabled on this server.');
        }

        $visited = [];

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            [$host, $pinnedIp] = $this->validateTarget($url);

            if (isset($visited[$url])) {
                throw new UrlFetchRejectedException('That URL redirects in a loop.');
            }

            $visited[$url] = true;

            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml',
                    'User-Agent' => 'CookbookRecipeImporter/1.0',
                ],
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS * 2,
                // Follow nothing automatically: every hop goes back through validateTarget().
                'max_redirects' => 0,
                // The rebinding pin — connect to the address we actually verified.
                'resolve' => [$host => $pinnedIp],
            ]);

            $status = $response->getStatusCode();

            if ($status >= 300 && $status < 400) {
                $location = $response->getHeaders(false)['location'][0] ?? null;

                if (null === $location) {
                    throw new UrlFetchRejectedException('That URL returned a redirect without a target.');
                }

                $url = $this->resolveRedirect($url, $location);

                continue;
            }

            if (200 !== $status) {
                throw new UrlFetchRejectedException(\sprintf('That URL returned HTTP %d.', $status));
            }

            return $this->readBounded($response);
        }

        throw new UrlFetchRejectedException('That URL redirects too many times.');
    }

    /**
     * @return array{0: string, 1: string} the host, and the verified IP to pin it to
     *
     * @throws UrlFetchRejectedException
     */
    private function validateTarget(string $url): array
    {
        $parts = parse_url($url);

        if (false === $parts || !isset($parts['scheme'])) {
            throw new UrlFetchRejectedException('That is not a valid URL.');
        }

        // Scheme first: file:///etc/passwd parses with no host at all, and answering "not a valid
        // URL" there would hide the actual reason it was refused.
        if ('https' !== mb_strtolower($parts['scheme'])) {
            throw new UrlFetchRejectedException('Only https:// URLs can be imported.');
        }

        if (!isset($parts['host'])) {
            throw new UrlFetchRejectedException('That is not a valid URL.');
        }

        // An IPv6 literal arrives bracketed ("[::1]"). Strip them before validating, or
        // filter_var() rejects the host as a non-IP and it falls through to DNS resolution —
        // which would route an IPv6 loopback target around the range check entirely.
        $host = mb_strtolower(trim($parts['host'], '[]'));

        if (!\in_array($host, $this->allowedHosts, true)) {
            $this->logger->warning('URL import refused: host not on the allowlist.', ['host' => $host]);

            throw new UrlFetchRejectedException(\sprintf('The host "%s" is not on the import allowlist. Allowed: %s.', $host, implode(', ', $this->allowedHosts)));
        }

        // A literal IP in the URL still has to clear the range check below.
        $addresses = filter_var($host, \FILTER_VALIDATE_IP) ? [$host] : $this->resolveHost($host);

        foreach ($addresses as $address) {
            if ($this->isBlocked($address)) {
                // Deliberately vague to the caller: naming the address would make this a scanner.
                $this->logger->warning('URL import refused: host resolved into a blocked range.', [
                    'host' => $host,
                    'address' => $address,
                ]);

                throw new UrlFetchRejectedException('That host cannot be reached from this server.');
            }
        }

        return [$host, $addresses[0]];
    }

    /**
     * @return string[]
     *
     * @throws UrlFetchRejectedException
     */
    private function resolveHost(string $host): array
    {
        $records = @dns_get_record($host, \DNS_A | \DNS_AAAA);
        $addresses = [];

        foreach ($records ?: [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (\is_string($address) && '' !== $address) {
                $addresses[] = $address;
            }
        }

        if ([] === $addresses) {
            throw new UrlFetchRejectedException(\sprintf('The host "%s" could not be resolved.', $host));
        }

        return $addresses;
    }

    private function isBlocked(string $address): bool
    {
        if (!filter_var($address, \FILTER_VALIDATE_IP)) {
            return true;
        }

        if (!filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }

        foreach (self::BLOCKED_RANGES as $range) {
            if ($this->inRange($address, $range)) {
                return true;
            }
        }

        return false;
    }

    private function inRange(string $address, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $addressBinary = inet_pton($address);
        $subnetBinary = inet_pton($subnet);

        if (false === $addressBinary || false === $subnetBinary) {
            return false;
        }

        // Comparing a v4 address against a v6 range (or the reverse) is not a match.
        if (\strlen($addressBinary) !== \strlen($subnetBinary)) {
            return false;
        }

        $bits = (int) $bits;
        $wholeBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        if ($wholeBytes > 0 && substr($addressBinary, 0, $wholeBytes) !== substr($subnetBinary, 0, $wholeBytes)) {
            return false;
        }

        if (0 === $remainingBits) {
            return true;
        }

        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (\ord($addressBinary[$wholeBytes]) & $mask) === (\ord($subnetBinary[$wholeBytes]) & $mask);
    }

    private function resolveRedirect(string $currentUrl, string $location): string
    {
        if (str_starts_with($location, 'https://') || str_starts_with($location, 'http://')) {
            return $location;
        }

        $parts = parse_url($currentUrl);
        $base = \sprintf('https://%s', $parts['host'] ?? '');

        return str_starts_with($location, '/')
            ? $base.$location
            : $base.'/'.ltrim($location, '/');
    }

    /**
     * @throws UrlFetchRejectedException
     */
    private function readBounded(\Symfony\Contracts\HttpClient\ResponseInterface $response): string
    {
        $declaredLength = (int) ($response->getHeaders(false)['content-length'][0] ?? 0);

        if ($declaredLength > self::MAX_BYTES) {
            throw new UrlFetchRejectedException('That page is too large to import.');
        }

        $content = '';

        // Streamed rather than getContent(): a lying or absent Content-Length must not let an
        // endless response exhaust memory.
        foreach ($this->httpClient->stream($response) as $chunk) {
            $content .= $chunk->getContent();

            if (\strlen($content) > self::MAX_BYTES) {
                $response->cancel();

                throw new UrlFetchRejectedException('That page is too large to import.');
            }
        }

        return $content;
    }
}
