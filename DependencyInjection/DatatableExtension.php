<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\DependencyInjection;

use Jul6Art\DatatableBundle\DataTable\AdminDataTableConfig;
use Jul6Art\DatatableBundle\Twig\DataTableBulkExtension;
use Jul6Art\DatatableBundle\Twig\DataTableCsrfExtension;
use Jul6Art\DatatableBundle\Twig\DataTableStatusMapExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
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

        // Exposed as container parameters so an application can branch on them, and so
        // `debug:container --parameter` tells the truth about what is active.
        $container->setParameter('datatable.enabled', true);
        $container->setParameter('datatable.stimulus_identifier', $identifier);

        $csrf = \is_array($config['csrf'] ?? null) ? $config['csrf'] : [];
        $singleToken = self::asString($csrf['single'] ?? null, 'datatable_action');
        $bulkToken = self::asString($csrf['bulk'] ?? null, 'bulk_action');
        $container->setParameter('datatable.csrf.single', $singleToken);
        $container->setParameter('datatable.csrf.bulk', $bulkToken);

        $tenant = \is_array($config['tenant'] ?? null) ? $config['tenant'] : [];
        $container->getDefinition(AdminDataTableConfig::class)
            ->setArgument('$tenantEndpoint', self::asString($tenant['endpoint'] ?? null, ''))
            ->setArgument('$tenantLabelKey', self::asString($tenant['label_key'] ?? null, 'datatable.col.organization'))
            ->setArgument('$tenantLabelDomain', self::asString($tenant['label_domain'] ?? null, 'messages'));

        // `twig/twig` est un `suggest`. Sans lui, les trois extensions et les deux partials
        // n'ont pas de consommateur, et les enregistrer rendrait le conteneur inchargeable.
        if (!class_exists(Environment::class)) {
            return;
        }

        $loader->load('twig.yaml');

        // ⚠️ Fusion, pas substitution. `bulk_actions` est un nœud prototype : ce que le projet
        // déclare *remplace* la valeur par défaut. Reconstituer la table complète ici est la
        // seule façon d'obtenir « mes types en plus des vôtres », qui est ce que tout le monde
        // attend en lisant la clé.
        $container->getDefinition(DataTableBulkExtension::class)
            ->setArgument('$actionTypes', array_values(array_unique([
                ...DataTableBulkExtension::DEFAULT_ACTION_TYPES,
                ...self::stringList($config['bulk_actions'] ?? []),
            ])));

        $container->getDefinition(DataTableStatusMapExtension::class)
            ->setArgument('$maps', self::normalizeMaps($config['status_maps'] ?? []));

        $container->getDefinition(DataTableCsrfExtension::class)
            ->setArgument('$stimulusIdentifier', $identifier)
            ->setArgument('$singleTokenId', $singleToken)
            ->setArgument('$bulkTokenId', $bulkToken);
    }

    /**
     * Fills in the two defaults a map almost never states — where it is nested, and how its
     * translation keys are prefixed — so the rest of the code reads a complete shape.
     *
     * @return array<string, array{path: list<string>, domain: string, key_prefix: string, keys: list<string>}>
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

            $path = self::stringList($map['path'] ?? []);
            $keyPrefix = $map['key_prefix'] ?? null;

            $normalized[$name] = [
                'path' => [] === $path ? ['datatable', $name] : $path,
                'domain' => self::asString($map['domain'] ?? null, 'messages'),
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
