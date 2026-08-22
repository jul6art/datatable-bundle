<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\DependencyInjection;

use Jul6Art\DatatableBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Exception\InvalidTypeException;
use Symfony\Component\Config\Definition\Processor;

/**
 * The configuration tree is public API: an application writes against it and a rename breaks
 * someone's deployment. Assert the **whole** processed shape rather than one key at a time — that
 * is what makes an accidental addition or a changed default visible in a diff.
 */
#[CoversClass(Configuration::class)]
final class ConfigurationTest extends TestCase
{
    public function testItsRootNodeIsTheBundleAlias(): void
    {
        self::assertSame('datatable', new Configuration()->getConfigTreeBuilder()->buildTree()->getName());
    }

    public function testItAppliesItsDefaults(): void
    {
        self::assertSame([
            'enabled' => true,
            'stimulus_identifier' => 'datatable',
            'csrf' => ['single' => 'datatable_action', 'bulk' => 'bulk_action'],
            'bulk_actions' => [],
            'status_maps' => [],
            'tenant' => [
                // Vide à dessein : une application mono-tenant ne déclare pas un endpoint qu'elle
                // n'expose pas, et la colonne inter-tenants n'est alors jamais demandée.
                'endpoint' => '',
                'label_key' => 'datatable.col.organization',
                'label_domain' => 'messages',
            ],
        ], $this->process([]));
    }

    public function testLaterConfigsOverrideEarlierOnes(): void
    {
        self::assertFalse($this->process([['enabled' => true], ['enabled' => false]])['enabled']);
    }

    /**
     * Deux fichiers de configuration qui déclarent chacun des types les **cumulent** : une liste de
     * scalaires se concatène à la fusion. Ce qui ne se cumule pas, c'est la valeur par **défaut**
     * du nœud — dès que le projet écrit quoi que ce soit, elle est remplacée. C'est pour cette
     * raison que les treize types du bundle vivent dans une constante refusionnée par
     * `DatatableExtension`, et pas dans un `defaultValue()` : sinon déclarer `invite` les
     * effacerait tous. `TwigExtensionTest::testDeclaringATypeDoesNotEraseTheDefaults()` le vérifie
     * de l'autre côté.
     */
    public function testTwoConfigsDeclaringActionTypesAccumulate(): void
    {
        self::assertSame(['a', 'b'], $this->process([['bulk_actions' => ['a']], ['bulk_actions' => ['b']]])['bulk_actions']);
    }

    public function testAStatusMapRequiresAtLeastOneKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([['status_maps' => ['quote' => ['keys' => []]]]]);
    }

    public function testAStatusMapFillsInItsPathAndDomain(): void
    {
        $maps = $this->process([['status_maps' => ['quote' => ['keys' => ['draft']]]]])['status_maps'];
        self::assertIsArray($maps);

        // Comparaison insensible à l'ordre des clés : celui-ci suit l'ordre de déclaration dans
        // le fichier de configuration, pas celui de l'arbre, et n'engage rien.
        self::assertEqualsCanonicalizing([
            'path' => [],
            'domain' => 'messages',
            'key_prefix' => null,
            'keys' => ['draft'],
        ], $maps['quote'], 'Le nœud laisse path et key_prefix vides ; c\'est l\'extension qui les dérive du nom.');
    }

    /**
     * A `booleanNode` refuses anything but a boolean, which is what you want — and the reason an
     * env var cannot gate service registration.
     */
    #[DataProvider('nonBooleanValues')]
    public function testItRejectsNonBooleanValues(mixed $value): void
    {
        $this->expectException(InvalidTypeException::class);

        $this->process([['enabled' => $value]]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonBooleanValues(): iterable
    {
        yield 'string' => ['yes'];
        yield 'int' => [0];
        yield 'array' => [[]];
    }

    /**
     * @param array<int, array<string, mixed>> $configs
     *
     * @return array<array-key, mixed>
     */
    private function process(array $configs): array
    {
        return new Processor()->processConfiguration(new Configuration(), $configs);
    }
}
