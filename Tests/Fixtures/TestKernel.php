<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Fixtures;

use Jul6Art\DatatableBundle\Controller\DatatablePreferenceController;
use Jul6Art\DatatableBundle\DataTable\AdminDataTableConfig;
use Jul6Art\DatatableBundle\DatatableBundle;
use Jul6Art\DatatableBundle\Preference\DatatablePreferenceStoreInterface;
use Jul6Art\DatatableBundle\Twig\DataTableBulkExtension;
use Jul6Art\DatatableBundle\Twig\DataTableCsrfExtension;
use Jul6Art\DatatableBundle\Twig\DataTableStatusMapExtension;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

/**
 * Minimal application kernel used by the functional tests.
 *
 * A bundle is only really proven by booting a container: half of what goes wrong in a bundle is
 * wiring, not logic — a service registered under the wrong condition, a decoration that does not
 * take, a tag Doctrine never sees. None of that shows up in a unit test.
 *
 * The optional pieces are flags rather than separate kernels so a test can ask for exactly the
 * environment its scenario needs, and no more: booting Doctrine to check a configuration node
 * costs a second per test for nothing.
 */
final class TestKernel extends Kernel
{
    /**
     * @param array<string, mixed> $bundleConfig    configuration for the "datatable" extension
     * @param bool                 $withPreferences registers what the preferences endpoint needs
     *                                              and the project owns: a store, a token storage
     *                                              and a route map. Off by default, so a test
     *                                              about anything else also proves the endpoint
     *                                              removes itself cleanly
     * @param string               $uniqueId        keys the build directory, so two scenarios never
     *                                              share a compiled container while identical ones
     *                                              still reuse the cache
     */
    public function __construct(
        string $environment,
        private readonly array $bundleConfig = [],
        private readonly bool $withPreferences = false,
        private readonly string $uniqueId = 'default',
    ) {
        // Debug mode installs Symfony's error handler and never removes it, which PHPUnit
        // rightly reports as leaking global state.
        parent::__construct($environment, false);
    }

    /**
     * @return iterable<BundleInterface>
     */
    #[\Override]
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();

        // Twig est chargé pour de vrai : la moitié de ce bundle est faite d'extensions et de
        // deux partials, et un partial ne se prouve qu'en le rendant. Un test qui n'inspecte
        // que le tableau rendu par une extension passe pendant qu'une balise mal fermée casse
        // toutes les tables du projet.
        yield new TwigBundle();

        yield new DatatableBundle();
    }

    #[\Override]
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load($this->configure(...));
    }

    #[\Override]
    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    #[\Override]
    public function getCacheDir(): string
    {
        return $this->buildDir().'/cache';
    }

    #[\Override]
    public function getLogDir(): string
    {
        return $this->buildDir().'/log';
    }

    /**
     * Marks the services the tests need to reach.
     *
     * Symfony inlines or removes private services, so `$container->get()` on one throws "has been
     * removed or inlined" — a message that reads like a bug in the bundle and is not. Listing them
     * here is the least intrusive fix; the alternative, making them public in the extension, would
     * change what the bundle exposes to real applications for the sake of a test.
     *
     * Beware: an id can change during compilation. A decorated service is renamed, so asserting on
     * `some.service` after decorating it tells you nothing — assert on what was *injected*
     * instead.
     */
    #[\Override]
    protected function build(ContainerBuilder $container): void
    {
        // Le journal du kernel est mis au silence.
        //
        // `ErrorListener` écrit « Uncaught PHP Exception … » par le service `logger`, et les tests
        // qui EXIGENT un 404 ou un 405 en provoquent forcément un. PHPUnit tourne ici avec
        // `beStrictAboutOutputDuringTests` et `failOnRisky` : cette ligne compte comme une sortie
        // inattendue, donc le test devient « risky », donc la suite échoue.
        //
        // ⚠️ Et cela ne se voit QU'EN CI : selon la version installée, le logger par défaut écrit
        // sur stderr (que PHPUnit ne capture pas) ou sur stdout. En local, en *highest*, les deux
        // tests passaient ; en *lowest*, ils tombaient. Le remplacement supprime la différence au
        // lieu de la subir.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            #[\Override]
            public function process(ContainerBuilder $container): void
            {
                if ($container->hasDefinition('logger')) {
                    $container->getDefinition('logger')->setClass(NullLogger::class)->setArguments([]);
                }
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION, 100);

        $container->addCompilerPass(new class implements CompilerPassInterface {
            #[\Override]
            public function process(ContainerBuilder $container): void
            {
                $exposed = [
                    'doctrine.orm.default_entity_manager',
                    'event_dispatcher',
                    'request_stack',
                    'security.token_storage',
                    'translator',
                    'twig',
                    AdminDataTableConfig::class,
                    DataTableBulkExtension::class,
                    DataTableCsrfExtension::class,
                    DataTableStatusMapExtension::class,
                    DatatablePreferenceController::class,
                    DatatablePreferenceStoreInterface::class,
                    'security.csrf.token_manager',
                    'security.token_storage',
                ];

                foreach ($container->getDefinitions() as $id => $definition) {
                    if (\in_array($id, $exposed, true)) {
                        $definition->setPublic(true);
                    }
                }

                foreach ($container->getAliases() as $id => $alias) {
                    if (\in_array($id, $exposed, true)) {
                        $alias->setPublic(true);
                    }
                }
            }
        }, PassConfig::TYPE_BEFORE_REMOVING, 100);
    }

    private function buildDir(): string
    {
        return \sprintf('%s/jul6art-datatable-bundle-tests/%s/%s', sys_get_temp_dir(), $this->uniqueId, $this->environment);
    }

    private function configure(ContainerBuilder $container): void
    {
        $container->loadFromExtension('framework', [
            'secret' => 'jul6art-datatable-bundle-tests',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            // La protection CSRF exige une session — et `csrf_token()` n'existe comme fonction
            // Twig que si elle est configurée. Le stockage est en mémoire : un test qui rend un
            // partial n'a pas besoin d'écrire un cookie, seulement d'un jeton qui se génère.
            'csrf_protection' => true,
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file', 'handler_id' => null],
            'default_locale' => 'en',
            'translator' => ['fallbacks' => ['en']],
        ]);

        $container->loadFromExtension('twig', [
            'strict_variables' => true,
        ]);

        $container->loadFromExtension('datatable', $this->bundleConfig);

        if ($this->withPreferences) {
            $this->configurePreferences($container);
        }
    }

    /**
     * The three things the endpoint needs and this bundle deliberately does not provide.
     *
     * `security.token_storage` under that exact id is not a shortcut: it is the id SecurityBundle
     * registers, the one `preferences.yaml` references and the one `PreferenceControllerPass`
     * looks for. Registering it here — with no SecurityBundle to conflict with — is what lets a
     * test drive a real request as a real user.
     */
    private function configurePreferences(ContainerBuilder $container): void
    {
        $container->loadFromExtension('framework', [
            'router' => ['resource' => __DIR__.'/routes.yaml', 'utf8' => true],
        ]);

        $container->register('security.token_storage', TokenStorage::class)->setPublic(true);
        $container->register(InMemoryPreferenceStore::class, InMemoryPreferenceStore::class)->setPublic(true);
        $container->setAlias(DatatablePreferenceStoreInterface::class, InMemoryPreferenceStore::class)->setPublic(true);
    }
}
