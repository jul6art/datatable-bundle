<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\DependencyInjection;

use Jul6Art\DatatableBundle\Controller\DatatablePreferenceController;
use Jul6Art\DatatableBundle\DataTable\AdminDataTableConfig;
use Jul6Art\DatatableBundle\Translation\DeclaredTranslationKeys;
use Jul6Art\DatatableBundle\Twig\DataTableCsrfExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Wires the bundle's services and turns its configuration into something the services can read.
 *
 * Two rules this ecosystem arrived at the hard way:
 *
 * 1. **A brick whose dependency is optional is registered conditionally**, from here, guarded by
 *    `class_exists()` / `interface_exists()` — never by an attribute on the class. An
 *    `#[AsDecorator]` or `#[AsDoctrineListener]` on a vendor class is only honoured if the
 *    application autoconfigures `vendor/`, which it should not, and it makes the class
 *    unloadable when the package is absent.
 * 2. **A service that needs another *service* to exist is checked in a compiler pass**, not
 *    here: an extension runs before the other bundles have configured anything, so
 *    `$container->has('some.service')` is always false at this point.
 *
 * The three Twig extensions follow rule 1: `twig/twig` is a `suggest`, not a `require`, because
 * the PHP half of this bundle (the configuration providers) is useful to an application that
 * renders its tables some other way.
 */
class DatatableExtension extends Extension
{
    #[\Override]
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yaml');

        $config = $this->processConfiguration(new Configuration(), $configs);

        if (false === ($config['enabled'] ?? true)) {
            return;
        }

        $identifier = self::asString($config['stimulus_identifier'] ?? null, 'datatable');
        $domain = self::asString($config['translation_domain'] ?? null, 'messages');

        // Exposed as container parameters so an application can branch on them, and so
        // `debug:container --parameter` tells the truth about what is active.
        $container->setParameter('datatable.enabled', true);
        $container->setParameter('datatable.stimulus_identifier', $identifier);
        $container->setParameter('datatable.translation_domain', $domain);

        $csrf = \is_array($config['csrf'] ?? null) ? $config['csrf'] : [];
        $singleToken = self::asString($csrf['single'] ?? null, 'datatable_action');
        $bulkToken = self::asString($csrf['bulk'] ?? null, 'bulk_action');
        $preferencesToken = self::asString($csrf['preferences'] ?? null, 'datatable_preferences');
        $container->setParameter('datatable.csrf.single', $singleToken);
        $container->setParameter('datatable.csrf.bulk', $bulkToken);
        $container->setParameter('datatable.csrf.preferences', $preferencesToken);

        $this->registerPreferences($loader, $container, $preferencesToken);

        $tenant = \is_array($config['tenant'] ?? null) ? $config['tenant'] : [];
        $container->getDefinition(AdminDataTableConfig::class)
            ->setArgument('$tenantEndpoint', self::asString($tenant['endpoint'] ?? null, ''))
            ->setArgument('$tenantLabelKey', self::asString($tenant['label_key'] ?? null, 'datatable.col.organization'))
            // Falls back to the configured domain, not to `messages`: the tenant column's key is a
            // `datatable.*` key like the others, so it moves with them.
            ->setArgument('$tenantLabelDomain', self::asString($tenant['label_domain'] ?? null, $domain));

        // Déclaré même sans Twig : c'est un service de TEST autant que de rendu, et la moitié PHP
        // du bundle sert aussi à une application qui rend ses tableaux autrement.
        $container->getDefinition(DeclaredTranslationKeys::class)
            ->setArgument('$statusMaps', self::normalizeMaps($config['status_maps'] ?? []));

        // `twig/twig` est un `suggest`. Sans lui, l'extension et les partials n'ont pas de
        // consommateur, et les enregistrer rendrait le conteneur inchargeable.
        if (!class_exists(Environment::class)) {
            return;
        }

        $loader->load('twig.yaml');

        $container->getDefinition(DataTableCsrfExtension::class)
            ->setArgument('$stimulusIdentifier', $identifier)
            ->setArgument('$translationDomain', $domain)
            ->setArgument('$singleTokenId', $singleToken)
            ->setArgument('$bulkTokenId', $bulkToken)
            ->setArgument('$preferencesTokenId', $preferencesToken);
    }

    /**
     * The per-user preferences endpoint.
     *
     * Rule 1 of this class applies twice over. `symfony/routing` and `symfony/security-core` are
     * `require`d — a bundle that serves a route for the current user cannot pretend either is
     * optional — but `symfony/security-csrf` is a `suggest`, so the token manager is a reference
     * that tolerates absence. `NULL_ON_INVALID_REFERENCE` covers the case `interface_exists()`
     * cannot see: the class is in the tree (a dependency of a dependency) while the *service* was
     * never registered, because SecurityBundle is not enabled.
     *
     * Whether the endpoint survives at all is settled later, by
     * {@see Compiler\PreferenceControllerPass}: it needs a store, and only the project can
     * provide one.
     */
    private function registerPreferences(YamlFileLoader $loader, ContainerBuilder $container, string $tokenId): void
    {
        if (!class_exists(Route::class) || !interface_exists(TokenStorageInterface::class)) {
            return;
        }

        $loader->load('preferences.yaml');

        $container->getDefinition(DatatablePreferenceController::class)
            ->setArgument('$csrfTokenManager', interface_exists(CsrfTokenManagerInterface::class)
                ? new Reference('security.csrf.token_manager', ContainerInterface::NULL_ON_INVALID_REFERENCE)
                : null)
            ->setArgument('$csrfTokenId', $tokenId);
    }

    /**
     * Fills in the one default a map almost never states: how its cases are prefixed to form a
     * catalogue key.
     *
     * ⚠️ `path` and `domain` are gone with the JSON tree they described. A map used to be
     * transported — translated server-side, nested under `path`, posted into an HTML attribute —
     * and `superp` had maps whose `path` and `key_prefix` genuinely differed. The browser now
     * holds the catalogue, so the prefix is simply the key.
     *
     * @return array<string, array{key_prefix: string, keys: list<string>}>
     */
    private static function normalizeMaps(mixed $maps): array
    {
        if (!\is_array($maps)) {
            return [];
        }

        $normalized = [];
        foreach ($maps as $name => $map) {
            if (!\is_string($name) || !\is_array($map)) {
                continue;
            }

            $keyPrefix = $map['key_prefix'] ?? null;

            $normalized[$name] = [
                'key_prefix' => \is_string($keyPrefix) && '' !== $keyPrefix ? $keyPrefix : 'datatable.'.$name.'.',
                'keys' => self::stringList($map['keys'] ?? []),
            ];
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, \is_string(...)));
    }

    private static function asString(mixed $value, string $fallback): string
    {
        return \is_string($value) && '' !== $value ? $value : $fallback;
    }
}
