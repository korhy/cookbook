<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use Mcp\Capability\Attribute\McpTool;

/**
 * The taxonomy, for callers that need a valid category name before calling recipe_create — which
 * refuses an unknown one rather than minting it.
 */
#[McpTool(
    name: 'category_list',
    description: 'List the available recipe categories, alphabetically. Returns up to 50; the taxonomy is curated and small. Use this before recipe_create, which refuses a category that does not already exist.',
)]
final class CategoryListTool
{
    /**
     * The taxonomy is curated and currently well under this. The cap exists because the endpoint is
     * public and unauthenticated, so "small today" is not a bound.
     */
    private const MAX_RESULTS = 50;

    public function __construct(private readonly CategoryRepository $categoryRepository)
    {
    }

    /**
     * @return array{categories: array<int, array{id: ?int, name: ?string, slug: ?string}>}
     */
    public function __invoke(): array
    {
        return [
            'categories' => array_map(
                static fn (Category $category): array => [
                    'id' => $category->getId(),
                    'name' => $category->getName(),
                    'slug' => $category->getSlug(),
                ],
                $this->categoryRepository->findAllOrderedByName(self::MAX_RESULTS),
            ),
        ];
    }
}
