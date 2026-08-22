<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * The bundle's configuration tree.
 *
 * Write an `->info()` on every node: it is what `config:dump-reference` shows, and it is the only
 * documentation a reader gets before opening the code.
 *
 * > ⚠️ **A node that decides something at compile time cannot be an env var.** `%env(bool:X)%`
 * > reaches a `booleanNode()` as the placeholder *string* and the config layer rejects it. Use a
 * > plain value for anything that gates service registration, and keep env vars for values passed
 * > through to a service at runtime (a `scalarNode` argument).
 *
 * > ⚠️ **`bulk_actions` and `status_maps` are prototype nodes, so what a project writes *replaces*
 * > the table rather than merging into it.** The defaults are re-merged in
 * > {@see DatatableExtension}; do not move them back here expecting Symfony to combine them. The
 * > same shape cost `ui-bundle` eleven icons once, silently.
 */
class Configuration implements ConfigurationInterface
{
    #[\Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('datatable');

        $treeBuilder->getRootNode()
            ->children()
                ->booleanNode('enabled')
                    ->info('Registers the bundle\'s services. false leaves it installed and inert.')
                    ->defaultTrue()
                ->end()
                ->scalarNode('stimulus_identifier')
                    ->info('The Stimulus identifier the table controller is registered under, which decides the data-attribute prefix the shipped partials emit. "datatable" gives data-datatable-*-value; an application whose build derives the identifier from a path sets its own, e.g. "core--datatable".')
                    ->defaultValue('datatable')
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('csrf')
                    ->addDefaultsIfNotSet()
                    ->info('CSRF token ids the shipped partials mint. The controller-side check must use the same ids.')
                    ->children()
                        ->scalarNode('single')
                            ->info('Token id for the per-row POST actions (delete, activate, restore…). Shared across a table: the JavaScript cannot mint one token per row.')
                            ->defaultValue('datatable_action')
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('bulk')
                            ->info('Token id for the /bulk-* endpoints. core-bundle\'s BulkActionRunner validates this one.')
                            ->defaultValue('bulk_action')
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('bulk_actions')
                    ->info('Extra bulk action types, merged over the bundle\'s defaults (delete, activate, publish…). Each needs modal.<type>.* and modal.bulk.<type>.* translation keys; a type without them is skipped rather than rendered raw.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('status_maps')
                    ->info('Named enum catalogues exposed to the JavaScript badge renderers through datatable_status_map(). Business vocabulary, hence configuration: the bundle ships none.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->arrayNode('path')
                                ->info('Where the dictionary is nested inside the translations value. Defaults to [datatable, <name>], which is what the renderers read.')
                                ->scalarPrototype()->end()
                                ->defaultValue([])
                            ->end()
                            ->scalarNode('domain')
                                ->info('Translation domain of the keys.')
                                ->defaultValue('messages')
                                ->cannotBeEmpty()
                            ->end()
                            ->scalarNode('key_prefix')
                                ->info('Prefix prepended to each key to form the translation key. Defaults to "datatable.<name>.".')
                                ->defaultNull()
                            ->end()
                            ->arrayNode('keys')
                                ->info('The enum cases, as they appear in the API payload.')
                                ->isRequired()
                                ->requiresAtLeastOneElement()
                                ->scalarPrototype()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('tenant')
                    ->info('Cross-tenant column and filter for super-admin tables (AdminDataTableConfig). Leave the endpoint empty in a single-tenant application: the service stays registered and is simply never asked.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('endpoint')
                            ->info('Collection endpoint the tenant autocomplete searches. Must expose a search filter under ?search= and return id and name.')
                            ->defaultValue('')
                        ->end()
                        ->scalarNode('label_key')
                            ->info('Translation key for the tenant column header and filter placeholder.')
                            ->defaultValue('datatable.col.organization')
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('label_domain')
                            ->info('Translation domain for label_key.')
                            ->defaultValue('messages')
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
