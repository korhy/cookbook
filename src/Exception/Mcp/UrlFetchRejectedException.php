<?php

declare(strict_types=1);

namespace App\Exception\Mcp;

/**
 * Thrown when a URL may not be fetched, or the fetch failed.
 *
 * Messages here are caller-facing and must stay generic about *why* a target was refused: telling
 * an authenticated-but-hostile caller "that host resolved to 10.0.0.5" turns the guard into an
 * internal network scanner. The specific reason goes to the audit log instead.
 */
final class UrlFetchRejectedException extends \RuntimeException
{
}
