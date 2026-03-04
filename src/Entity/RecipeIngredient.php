<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use App\Repository\RecipeIngredientRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: RecipeIngredientRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['recipe_ingredient:read']],
    denormalizationContext: ['groups' => ['recipe_ingredient:write']],
)]
class RecipeIngredient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['recipe_ingredient:read', 'recipe:read'])]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['recipe_ingredient:read', 'recipe_ingredient:write', 'recipe:read', 'recipe:write'])]
    #[ApiProperty(example: 2)]
    private ?float $quantity = null;

    #[ORM\ManyToOne(inversedBy: 'recipeIngredients')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['recipe_ingredient:read', 'recipe_ingredient:write'])]
    private ?Recipe $recipe = null;

    #[ORM\ManyToOne(inversedBy: 'recipeIngredients')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['recipe_ingredient:read', 'recipe_ingredient:write', 'recipe:read', 'recipe:write'])]
    #[ApiProperty(example: '/api/ingredients/1')]
    private ?Ingredient $ingredient = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuantity(): ?float
    {
        return $this->quantity;
    }

    public function setQuantity(?float $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getRecipe(): ?Recipe
    {
        return $this->recipe;
    }

    public function setRecipe(?Recipe $recipe): static
    {
        $this->recipe = $recipe;

        return $this;
    }

    public function getIngredient(): ?Ingredient
    {
        return $this->ingredient;
    }

    public function setIngredient(?Ingredient $ingredient): static
    {
        $this->ingredient = $ingredient;

        return $this;
    }

    public function __toString(): string
    {
        return $this->ingredient ?'id: ' . $this->ingredient->getId() . ' | name: ' . $this->ingredient->getName() . ' | quantity: ' . $this->quantity : '';
    }
}
