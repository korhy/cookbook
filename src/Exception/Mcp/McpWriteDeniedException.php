<?php

declare(strict_types=1);

namespace App\Exception\Mcp;

/**
 * Thrown by {@see \App\Service\Mcp\McpWriteGuard} when a write must not proceed.
 *
 * The message is deliberately the *public* one — it is handed straight back to an unauthenticated
 * caller, so it must never distinguish "the feature is disabled" from "your token is wrong": that
 * difference tells an attacker whether a write surface exists at all. The detailed reason is
 * logged server-side by the guard instead.
 */
final class McpWriteDeniedException extends \RuntimeException
{
}
