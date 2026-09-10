<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * The knobs a CSV import run is given, resolved once from the console input.
 */
final readonly class ImportOptions
{
    public function __construct(
        public string $dataDirectory,
        public string $delimiter = ',',
        public int $batchSize = 50,
        public bool $skipHeader = false,
        public bool $dryRun = false,
    ) {
    }

    public function pathFor(string $fileName): string
    {
        return rtrim($this->dataDirectory, '/').'/'.$fileName;
    }
}
