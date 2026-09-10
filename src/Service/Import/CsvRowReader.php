<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Reads a CSV file row by row without holding it in memory.
 *
 * The import files run to six figures of rows, which is why this is a generator and why the
 * importer clears the entity manager as it goes.
 */
final class CsvRowReader
{
    /**
     * @return \Generator<int, string[]>
     *
     * @throws \RuntimeException when the file is missing, unreadable, or cannot be opened
     */
    public function rows(string $path, string $delimiter, bool $skipHeader): \Generator
    {
        if (!file_exists($path) || !is_readable($path)) {
            throw new \RuntimeException(\sprintf('File not found or not readable: %s', $path));
        }

        $handle = fopen($path, 'r');

        if (false === $handle) {
            throw new \RuntimeException(\sprintf('Unable to open %s', $path));
        }

        try {
            // The escape argument is passed explicitly on every call: relying on the default is
            // deprecated in PHP 8.4, and the suite fails on deprecations.
            if ($skipHeader) {
                fgetcsv($handle, 0, $delimiter, '"', '\\');
            }

            while (false !== ($row = fgetcsv($handle, 0, $delimiter, '"', '\\'))) {
                yield $row;
            }
        } finally {
            fclose($handle);
        }
    }
}
