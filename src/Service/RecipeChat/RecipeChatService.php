<?php

namespace App\Service\RecipeChat;

use App\Entity\Recipe;
use App\Tool\RecipeSearchTool;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Serializer\SerializerInterface;

class RecipeChatService
{
    private const TTL = 3600; // 1h

    public function __construct(
        #[Autowire(service: 'ai.agent.recipe')]
        private readonly AgentInterface $recipeAgent,
        private readonly RecipeSearchTool $recipeSearchTool,
        private readonly CacheItemPoolInterface $cache,
        private readonly SerializerInterface $serializer,
    ) {}

    public function handle(string $query, ?string $conversationId): array
    {
        $conversationId ??= Uuid::v4()->toRfc4122();
        $cacheKey = 'recipe_chat_' . str_replace('-', '_', $conversationId);

        $item = $this->cache->getItem($cacheKey);
        $history = $item->isHit() ? $item->get() : [];

        $history[] = Message::ofUser($query);

        $messageBag = new MessageBag(...$history);
        $response = $this->recipeAgent->call($messageBag);

        $history[] = Message::ofAssistant($response->getContent());

        $item->set($history)->expiresAfter(self::TTL);
        $this->cache->save($item);

        $foundRecipes = $this->recipeSearchTool->getRecipes();

        if (!empty($foundRecipes)) {
            return [
                'conversationId' => $conversationId,
                'source' => 'database',
                'recipes' => array_map(
                    fn(Recipe $r) => $this->serializer->normalize($r, 'json', ['groups' => ['recipe:read']]),
                    $foundRecipes
                ),
            ];
        }

        $decoded = json_decode($response->getContent(), true);

        return [
            'conversationId' => $conversationId,
            'source' => 'generated',
            'recipe' => $decoded ?? $response->getContent(),
        ];
    }

    public function clearHistory(string $conversationId): void
    {
        $cacheKey = 'recipe_chat_' . str_replace('-', '_', $conversationId);
        $this->cache->deleteItem($cacheKey);
    }
}
