<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use App\Repository\InstructionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: InstructionRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['instruction:read']],
    denormalizationContext: ['groups' => ['instruction:write']],
    order: ['position' => 'ASC'],
)]
class Instruction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['instruction:read', 'recipe:read'])]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['instruction:read', 'instruction:write', 'recipe:read', 'recipe:write'])]
    #[ApiProperty(example: 'Préchauffez le four à 180°C.')]
    private ?string $content = null;

    #[ORM\Column]
    #[Groups(['instruction:read', 'instruction:write', 'recipe:read', 'recipe:write'])]
    #[ApiProperty(example: 1)]
    private ?int $position = null;

    #[ORM\ManyToOne(inversedBy: 'instructions')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['instruction:read', 'instruction:write'])]
    private ?Recipe $recipe = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

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

    public function __toString(): string
    {
        return $this->content ? 'Position: ' . $this->position . ' | Content: ' . $this->content : '';
    }
}
