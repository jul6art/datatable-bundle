<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Functional;

use Jul6Art\DatatableBundle\Translation\DeclaredTranslationKeys;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The first test to write, and the one that keeps paying: a real container, built with the bundle
 * registered.
 *
 * It catches what no unit test can — a services.yaml that does not parse, a reference to a service
 * that does not exist, a configuration node the extension reads under another name. Every one of
 * those is invisible until something boots.
 */
#[CoversNothing]
final class ContainerTest extends AbstractFunctionalTestCase
{
    public function testTheBundleBoots(): void
    {
        self::assertTrue($this->boot()->getParameter('datatable.enabled'));
    }

    /**
     * `enabled: false` must leave the bundle installed and inert — an application should be able
     * to switch it off without uninstalling it, and without its optional dependencies becoming
     * required.
     */
    public function testItCanBeDisabled(): void
    {
        self::assertFalse($this->boot('test', ['enabled' => false])->hasParameter('datatable.enabled'));
    }

    /**
     * Les cartes d'énumérations arrivent bien au service qui les DÉCLARE.
     *
     * ⚠️ Elles étaient transportées par `datatable_status_map()` jusqu'à un attribut HTML ; c'est
     * maintenant le garde de traduction du projet qui les lit, pour savoir que
     * `datatable.quote_status.draft` est vivante alors qu'aucun littéral du JavaScript ne la nomme
     * — le rendu de badge la construit par interpolation.
     */
    public function testTheStatusMapsReachTheDeclaredKeysService(): void
    {
        $container = $this->boot(bundleConfig: [
            'status_maps' => [
                'quote_status' => ['keys' => ['draft', 'sent']],
                'expense_status' => ['key_prefix' => 'sirh.expense.status.', 'keys' => ['paid']],
            ],
        ]);

        $declared = $container->get(DeclaredTranslationKeys::class);
        self::assertInstanceOf(DeclaredTranslationKeys::class, $declared);

        $keys = $declared->keys();

        self::assertContains('datatable.quote_status.draft', $keys);
        self::assertContains('datatable.quote_status.sent', $keys);
        // Le préfixe explicite l'emporte sur le nom de la carte : c'est la clé du CATALOGUE.
        self::assertContains('sirh.expense.status.paid', $keys);
    }

    /**
     * Le service existe même sans Twig : c'est un service de test autant que de rendu, et la
     * moitié PHP du bundle sert à une application qui rend ses tableaux autrement.
     */
    public function testTheDeclaredKeysServiceDoesNotNeedTwig(): void
    {
        self::assertTrue($this->boot()->has(DeclaredTranslationKeys::class));
    }
}
