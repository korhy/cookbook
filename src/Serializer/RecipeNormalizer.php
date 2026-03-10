<?php

namespace App\Serializer;

use App\Entity\Recipe;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Vich\UploaderBundle\Storage\StorageInterface;

class RecipeNormalizer implements NormalizerInterface
{
    public function __construct(
        #[Autowire(service: 'serializer.normalizer.object')]
        private readonly NormalizerInterface $normalizer,
        private readonly StorageInterface $storage,
    ) {
    }

    public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $context[self::class] = true;
        $data = $this->normalizer->normalize($object, $format, $context);

        if (is_array($data) && $object->getThumbnail() !== null) {
            $data['thumbnail'] = $this->storage->resolveUri($object, 'thumbnailFile');
        }

        return $data;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Recipe && !isset($context[self::class]);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Recipe::class => false];
    }
}
