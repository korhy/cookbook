<?php

declare(strict_types=1);

namespace App\Exception\Mcp;

/**
 * Thrown when a proposed recipe draft cannot be accepted.
 *
 * Carries a list of caller-facing reasons. Unlike {@see McpWriteDeniedException}, being specific
 * here is safe and useful: the caller has already proved the write token, and every message
 * describes the input it just sent rather than anything about the system.
 */
final class RecipeDraftRejectedException extends \RuntimeException
{
    /**
     * @param string[] $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
