<?php

namespace App\EventSubscriber;

use App\Repository\RecipeRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

class TwigEventSubscriber implements EventSubscriberInterface
{
    private $twig;
    private $recipeRepository;

    public function __construct(Environment $twig, RecipeRepository $recipeRepository)
    {
        $this->twig = $twig;
        $this->recipeRepository = $recipeRepository;
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $this->twig->addGlobal('recipes', $this->recipeRepository->findAll());
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
        ];
    }
}
