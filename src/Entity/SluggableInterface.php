<?php

namespace App\Entity;

interface SluggableInterface
{
    public function getTitle(): ?string;
    public function setSlug(string $slug): static;
}
