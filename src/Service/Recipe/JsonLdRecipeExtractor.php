<?php

declare(strict_types=1);

namespace App\Service\Recipe;

use App\Exception\Mcp\UrlFetchRejectedException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Pulls a schema.org/Recipe out of a page's JSON-LD.
 *
 * Deterministic on purpose. The alternative — scraping the HTML, or handing the page to a model to
 * "understand" — would either break on every site redesign or turn arbitrary page content into
 * model input, which is the prompt-injection surface this project is built to avoid. If a page has
 * no JSON-LD Recipe, that is a clean "not found", not a cue to start guessing.
 *
 * Nothing here parses quantities out of "200 g flour": ingredient lines are returned as the raw
 * strings the page published, for the calling model to structure into recipe_create arguments.
 */
final class JsonLdRecipeExtractor
{
    private const MAX_ITEMS = 50;

    /**
     * @return array{
     *     title: ?string,
     *     description: ?string,
     *     duration: ?int,
     *     category: ?string,
     *     ingredients: string[],
     *     instructions: string[]
     * }
     *
     * @throws UrlFetchRejectedException when the page carries no schema.org Recipe
     */
    public function extract(string $html): array
    {
        $recipe = $this->findRecipeNode($html);

        if (null === $recipe) {
            throw new UrlFetchRejectedException('That page does not publish a schema.org Recipe in JSON-LD, so nothing could be imported from it.');
        }

        return [
            'title' => $this->string($recipe['name'] ?? null),
            'description' => $this->string($recipe['description'] ?? null),
            'duration' => $this->minutes($recipe['totalTime'] ?? $recipe['cookTime'] ?? null),
            'category' => $this->string($this->first($recipe['recipeCategory'] ?? null)),
            'ingredients' => $this->strings($recipe['recipeIngredient'] ?? $recipe['ingredients'] ?? null),
            'instructions' => $this->instructions($recipe['recipeInstructions'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRecipeNode(string $html): ?array
    {
        $crawler = new Crawler($html);

        foreach ($crawler->filter('script[type="application/ld+json"]') as $node) {
            $decoded = json_decode((string) $node->textContent, true);

            if (!\is_array($decoded)) {
                // A single malformed block must not abort the search: pages often carry several.
                continue;
            }

            $found = $this->searchForRecipe($decoded);

            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * JSON-LD in the wild is a top-level object, a list, or an @graph of nodes — walk all three.
     *
     * @param array<mixed> $node
     *
     * @return array<string, mixed>|null
     */
    private function searchForRecipe(array $node, int $depth = 0): ?array
    {
        if ($depth > 5) {
            return null;
        }

        $types = $node['@type'] ?? null;
        $types = \is_array($types) ? $types : [$types];

        foreach ($types as $type) {
            if (\is_string($type) && 'recipe' === mb_strtolower($type)) {
                /* @var array<string, mixed> $node */
                return $node;
            }
        }

        foreach ($node as $value) {
            if (\is_array($value)) {
                $found = $this->searchForRecipe($value, $depth + 1);

                if (null !== $found) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function instructions(mixed $value): array
    {
        if (\is_string($value)) {
            // Some sites publish one blob; split on newlines rather than inventing sentences.
            $lines = preg_split('/\R+/u', $value) ?: [];

            return $this->strings($lines);
        }

        if (!\is_array($value)) {
            return [];
        }

        $steps = [];

        foreach ($value as $entry) {
            if (\is_string($entry)) {
                $steps[] = $entry;

                continue;
            }

            if (!\is_array($entry)) {
                continue;
            }

            // HowToStep, or a HowToSection wrapping more steps.
            if (isset($entry['itemListElement']) && \is_array($entry['itemListElement'])) {
                $steps = [...$steps, ...$this->instructions($entry['itemListElement'])];

                continue;
            }

            $text = $entry['text'] ?? $entry['name'] ?? null;

            if (\is_string($text)) {
                $steps[] = $text;
            }
        }

        return $this->strings($steps);
    }

    /**
     * @return string[]
     */
    private function strings(mixed $value): array
    {
        if (\is_string($value)) {
            $value = [$value];
        }

        if (!\is_array($value)) {
            return [];
        }

        $clean = [];

        foreach ($value as $entry) {
            if (!\is_string($entry)) {
                continue;
            }

            $entry = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($entry)));

            if ('' !== $entry) {
                $clean[] = $entry;
            }
        }

        return \array_slice($clean, 0, self::MAX_ITEMS);
    }

    private function string(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($value)));

        return '' === $value ? null : $value;
    }

    private function first(mixed $value): mixed
    {
        return \is_array($value) ? ($value[0] ?? null) : $value;
    }

    /**
     * ISO 8601 duration (PT1H30M) to minutes.
     */
    private function minutes(mixed $value): ?int
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        try {
            $interval = new \DateInterval($value);
        } catch (\Exception) {
            return null;
        }

        $minutes = ($interval->d * 24 * 60) + ($interval->h * 60) + $interval->i;

        return $minutes > 0 ? $minutes : null;
    }
}
