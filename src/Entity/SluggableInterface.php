<?php

declare(strict_types=1);

namespace App\Entity;

interface SluggableInterface
{
    public function getTitle(): ?string;

    public function setSlug(string $slug): static;
}
