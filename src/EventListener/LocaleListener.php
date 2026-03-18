<?php

namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\RequestEvent;

class LocaleListener
{
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        // Get the locale from the "Accept-Language" header, defaulting to 'fr' if not provided
        $locale = $request->headers->get('Accept-Language', 'fr');
        $request->setLocale(substr($locale, 0, 2));
    }
}
