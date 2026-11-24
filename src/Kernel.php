<?php

declare(strict_types=1);

namespace App;

use App\DependencyInjection\Compiler\OAuthDiscoveryPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        // Reads the enabled grants and the available scopes back out of the OAuth bundle's own
        // definitions, so the published RFC 8414 document describes the server as configured
        // rather than as documented. See the pass for why neither value is a parameter already.
        $container->addCompilerPass(new OAuthDiscoveryPass());
    }
}
