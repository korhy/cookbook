<?php

namespace App\EventListener;

use App\Entity\SluggableInterface;
use App\Service\SluggerService;
use Doctrine\Persistence\Event\LifecycleEventArgs;

class SlugListener
{
    public function __construct(
        private SluggerService $sluggerService
    ) {}

    public function prePersist(LifecycleEventArgs $args): void
    {
        $this->process($args->getObject());
    }

    public function preUpdate(LifecycleEventArgs $args): void
    {
        $this->process($args->getObject());
    }

    private function process(object $entity): void
    {
        if (!$entity instanceof SluggableInterface) {
            return;
        }

        if ($entity->getTitle()) {
            $entity->setSlug(
                $this->sluggerService->generateSlug($entity->getTitle())
            );
        }
    }
}
