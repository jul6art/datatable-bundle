<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle;

use Jul6Art\DatatableBundle\DependencyInjection\Compiler\PreferenceControllerPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony datatable bundle.
 *
 * Registering a compiler pass? Override `build()` here — a pass is how you check that a service
 * the application may or may not have actually exists, which an extension cannot do (extensions
 * run before the other bundles have had their say):
 *
 * ```php
 * #[\Override]
 * public function build(ContainerBuilder $container): void
 * {
 *     parent::build($container);
 *
 *     $container->addCompilerPass(new SomethingOptionalPass());
 * }
 * ```
 */
class DatatableBundle extends Bundle
{
    #[\Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // ⚠️ La priorité n'est pas décorative. `RegisterControllerArgumentLocatorsPass` de
        // FrameworkBundle tourne dans la même phase et, à priorité égale, avant celui-ci puisque
        // son bundle est enregistré en premier : il aurait déjà construit le locator d'arguments
        // du contrôleur, et le retirer ensuite fait échouer la compilation sur un service
        // « inexistant » que plus personne ne réclame explicitement. La leçon est celle
        // d'`AppearanceControllerPass` dans `admin-bundle`, payée une fois.
        $container->addCompilerPass(new PreferenceControllerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 100);
    }
}
