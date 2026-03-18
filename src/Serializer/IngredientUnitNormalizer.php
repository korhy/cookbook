<?php

namespace App\Serializer;

use App\Enum\IngredientUnit;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class IngredientUnitNormalizer implements NormalizerInterface
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function normalize(mixed $object, ?string $format = null, array $context = []): string
    {
        return $this->translator->trans($object->label());
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof IngredientUnit;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [IngredientUnit::class => true];
    }
}
