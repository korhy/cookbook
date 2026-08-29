<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Exception\Mcp\McpWriteDeniedException;
use App\Exception\Mcp\UrlFetchRejectedException;
use App\Service\Http\SsrfGuardedFetcher;
use App\Service\Mcp\McpWriteGuard;
use App\Service\Recipe\JsonLdRecipeExtractor;
use Mcp\Capability\Attribute\McpTool;

/**
 * Reads a recipe off an allowlisted page and hands it back as structured data.
 *
 * **This tool does not write.** Splitting fetch from create is deliberate: the component that
 * talks to the outside world has no database access, and the component that writes to the database
 * makes no outbound requests. The caller reviews what comes back and then calls recipe_create with
 * it — which also means a human-in-the-loop client can show the extracted recipe before anything
 * is stored.
 */
#[McpTool(
    name: 'recipe_import_from_url',
    description: <<<'TEXT'
        Extract a recipe from a web page so it can be reviewed and then passed to recipe_create.

        This tool only reads: it stores nothing. It requires the same write token as recipe_create,
        because it makes this server fetch an external page.

        Only https:// URLs on the server's import allowlist can be fetched, and only pages that
        publish a schema.org/Recipe as JSON-LD can be read — there is no HTML scraping fallback.
        Ingredient lines come back as raw text ("200 g flour"); convert them into recipe_create's
        {name, quantity, unit} objects yourself.
        TEXT,
)]
final class RecipeImportFromUrlTool
{
    public function __construct(
        private readonly McpWriteGuard $guard,
        private readonly SsrfGuardedFetcher $fetcher,
        private readonly JsonLdRecipeExtractor $extractor,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $token, string $url): array
    {
        try {
            $this->guard->assertMayFetch($token, 'recipe_import_from_url');
        } catch (McpWriteDeniedException $e) {
            return ['error' => $e->getMessage()];
        }

        try {
            $recipe = $this->extractor->extract($this->fetcher->fetch($url));
        } catch (UrlFetchRejectedException $e) {
            return ['error' => $e->getMessage()];
        }

        return [
            ...$recipe,
            'source_url' => $url,
            'message' => 'Nothing has been stored. Review this, convert the ingredient lines into {name, quantity, unit} objects, and call recipe_create to save it as a draft.',
        ];
    }
}
