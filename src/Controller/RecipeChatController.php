<?php

namespace App\Controller;

use App\Service\RecipeChat\RecipeChatService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class RecipeChatController extends AbstractController
{
    public function __construct(
        private readonly RecipeChatService $recipeChatService,
    ) {}

    #[Route('/api/v1/recipe-chat', name: 'api_recipe_chat', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        set_time_limit(0);

        $data = json_decode($request->getContent(), true);
        $query = trim($data['query'] ?? '');

        if (empty($query)) {
            return $this->json(['error' => 'Le champ "query" est requis.'], 400);
        }

        return $this->json(
            $this->recipeChatService->handle($query, $data['conversationId'] ?? null)
        );
    }

    #[Route('/api/v1/recipe-chat/{conversationId}', name: 'api_recipe_chat_reset', methods: ['DELETE'])]
    public function reset(string $conversationId): JsonResponse
    {
        $this->recipeChatService->clearHistory($conversationId);
        return $this->json(['message' => 'Conversation effacée.']);
    }
}
