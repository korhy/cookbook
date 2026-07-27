<?php

namespace App\Mcp\Tool;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'category_list',
    description: 'List all available recipe categories.',
)]
class CategoryListTool
{
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
                $this->categoryRepository->getAllQueryBuilder()->getQuery()->getResult(),
            ),
        ];
    }
}
