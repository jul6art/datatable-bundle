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
            'translation_domain' => 'messages',
            'stimulus_identifier' => 'datatable',
            'csrf' => ['single' => 'datatable_action', 'bulk' => 'bulk_action', 'preferences' => 'datatable_preferences'],
            'status_maps' => [],
            'tenant' => [
                // Vide à dessein : une application mono-tenant ne déclare pas un endpoint qu'elle
                // n'expose pas, et la colonne inter-tenants n'est alors jamais demandée.
                'endpoint' => '',
                'label_key' => 'datatable.col.organization',
                // Null, pas 'messages' : c'est l'extension qui retombe sur `translation_domain`,
                // pour qu'un projet qui a déplacé son catalogue n'ait pas à le répéter ici.
                'label_domain' => null,
            ],
        ], $this->process([]));
    }

    public function testLaterConfigsOverrideEarlierOnes(): void
    {
        self::assertFalse($this->process([['enabled' => true], ['enabled' => false]])['enabled']);
    }

    public function testAStatusMapRequiresAtLeastOneKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([['status_maps' => ['quote' => ['keys' => []]]]]);
    }

    /**
     * ⚠️ `path` et `domain` ont disparu du nœud avec l'arbre JSON qu'ils décrivaient. Une carte
     * était TRANSPORTÉE — traduite côté serveur, imbriquée sous `path`, postée dans un attribut —
     * et `superp` en avait dont le `path` et le `key_prefix` différaient vraiment. Le navigateur a
     * le catalogue : le préfixe EST la clé.
     */
    public function testAStatusMapOnlyDeclaresItsPrefixAndItsCases(): void
    {
        $maps = $this->process([['status_maps' => ['quote' => ['keys' => ['draft']]]]])['status_maps'];
        self::assertIsArray($maps);

        self::assertEqualsCanonicalizing([
            'key_prefix' => null,
            'keys' => ['draft'],
        ], $maps['quote'], 'Le nœud laisse key_prefix vide ; c\'est l\'extension qui le dérive du nom.');
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
