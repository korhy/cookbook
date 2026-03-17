<?php

namespace App\Serializer;

use App\Entity\Recipe;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Vich\UploaderBundle\Storage\StorageInterface;

class RecipeNormalizer implements NormalizerInterface
{
    public function __construct(
        #[Autowire(service: 'serializer.normalizer.object')]
        private readonly NormalizerInterface $normalizer,
        private readonly StorageInterface $storage,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $context[self::class] = true;
        $data = $this->normalizer->normalize($object, $format, $context);

        if (is_array($data)) {
            if ($object->getThumbnail() !== null) {
                $request = $this->requestStack->getCurrentRequest();
                $baseUrl = $request ? $request->getSchemeAndHttpHost() : '';
                $data['thumbnail'] = $baseUrl . $this->storage->resolveUri($object, 'thumbnailFile');
            } else {
                $data['thumbnail'] = null;
            }
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
