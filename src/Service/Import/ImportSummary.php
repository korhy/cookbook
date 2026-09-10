<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * What one dataset's import did.
 *
 * Warnings are collected rather than printed: the importer has no console, and a caller that is not
 * a command (a test, say) still needs to see which rows were skipped and why.
 */
final class ImportSummary
{
    /**
     * @var string[]
     */
    private array $warnings = [];

    private int $imported = 0;

    private int $skipped = 0;

    public function recordImported(): void
    {
        ++$this->imported;
    }

    public function recordSkipped(string $reason): void
    {
        ++$this->skipped;
        $this->warnings[] = $reason;
    }

    public function imported(): int
    {
        return $this->imported;
    }

    public function skipped(): int
    {
        return $this->skipped;
    }

    /**
     * @return string[]
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
