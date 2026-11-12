<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Fixtures;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Jul6Art\DatatableBundle\DatatableBundle;
use Jul6Art\DatatableBundle\Preference\DatatablePreferenceStoreInterface;
use Jul6Art\DatatableBundle\Tests\Fixtures\Preference\Account;
use Jul6Art\DatatableBundle\Tests\Fixtures\Preference\SpaceScope;
use Jul6Art\DatatableBundle\Tests\Fixtures\Preference\TablePreference;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

/**
 * An application that stores its table preferences with the bundle's Doctrine store: an account
 * entity, a concrete preference entity, SQLite in memory — and, when asked, a space scope.
 */
final class DoctrinePreferenceKernel extends Kernel
{
    public function __construct(
        private readonly bool $withScope = false,
        private readonly bool $withStorage = true,
    ) {
        parent::__construct('test', false);
    }

    /**
     * @return iterable<BundleInterface>
     */
    #[\Override]
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new DatatableBundle();
    }

    #[\Override]
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'jul6art-datatable-bundle-tests',
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
                'router' => ['resource' => __DIR__.'/routes.yaml', 'utf8' => true],
            ]);
            $container->loadFromExtension('doctrine', [
                'dbal' => ['url' => 'sqlite:///:memory:'],
                'orm' => [
                    'mappings' => [
                        'Fixtures' => ['type' => 'attribute', 'is_bundle' => false, 'dir' => __DIR__.'/Preference', 'prefix' => 'Jul6Art\\DatatableBundle\\Tests\\Fixtures\\Preference'],
                    ],
                ],
            ]);
            $container->loadFromExtension('datatable', $this->withStorage
                ? ['preferences' => ['storage' => ['entity_class' => TablePreference::class, 'user_class' => Account::class]]]
                : []);

            // What SecurityBundle would register: the controller reads the account on the token.
            $container->register('security.token_storage', TokenStorage::class)->setPublic(true);
            if ($this->withScope) {
                $container->register(SpaceScope::class, SpaceScope::class)->setAutoconfigured(true);
            }
        });
    }

    #[\Override]
    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class implements CompilerPassInterface {
            #[\Override]
            public function process(ContainerBuilder $container): void
            {
                foreach (['doctrine', 'doctrine.orm.default_entity_manager', 'router'] as $id) {
                    if ($container->hasDefinition($id)) {
                        $container->getDefinition($id)->setPublic(true);
                    } elseif ($container->hasAlias($id)) {
                        $container->getAlias($id)->setPublic(true);
                    }
                }
                if ($container->hasAlias(DatatablePreferenceStoreInterface::class)) {
                    $container->getAlias(DatatablePreferenceStoreInterface::class)->setPublic(true);
                }
            }
        }, PassConfig::TYPE_BEFORE_REMOVING);
    }

    #[\Override]
    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    #[\Override]
    public function getCacheDir(): string
    {
        return \sprintf('%s/jul6art-datatable-bundle-tests/doctrine-%s-%s/cache', sys_get_temp_dir(), $this->withScope ? 'scope' : 'plain', $this->withStorage ? 'store' : 'none');
    }

    #[\Override]
    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/jul6art-datatable-bundle-tests/doctrine/log';
    }
}
