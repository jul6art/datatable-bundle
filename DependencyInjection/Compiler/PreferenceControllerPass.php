<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\DependencyInjection\Compiler;

use Jul6Art\DatatableBundle\Controller\DatatablePreferenceController;
use Jul6Art\DatatableBundle\Preference\DatatablePreferenceStoreInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Removes the preferences endpoint when two things the bundle cannot provide are missing.
 *
 * **A store.** It is a port: this bundle interprets preferences, the project persists them. Until a
 * project registers an implementation, autowiring the controller fails the whole build with "no
 * service for DatatablePreferenceStoreInterface" — pointing at a controller the project never
 * asked for, on the day it merely upgraded the bundle.
 *
 * **A token storage.** `symfony/security-core` is a `require`, so `interface_exists()` is always
 * true and the extension cannot tell the difference; but the *service* comes from SecurityBundle,
 * which this bundle does not require. A back office without a firewall is unusual, not impossible,
 * and it must not fail to compile because of an endpoint that would have nobody to answer for.
 *
 * Both are questions an extension cannot ask: it runs before the other bundles have configured
 * anything, so `$container->has()` there always answers false. Hence a pass.
 */
final class PreferenceControllerPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(DatatablePreferenceController::class)) {
            return;
        }

        if ($container->has(DatatablePreferenceStoreInterface::class) && $container->has('security.token_storage')) {
            return;
        }

        $container->removeDefinition(DatatablePreferenceController::class);
    }
}
