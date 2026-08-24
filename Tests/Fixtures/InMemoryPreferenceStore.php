<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Fixtures;

use Jul6Art\DatatableBundle\Preference\DatatablePreferenceStoreInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The half of the feature a project owns, reduced to an array.
 *
 * It is representative on the two points that matter: the record is keyed on (user, table), and
 * `write()` replaces rather than appends — an implementation that inserted blindly would pass a
 * test written against a store that forgives it, and fail on the second save in production.
 */
final class InMemoryPreferenceStore implements DatatablePreferenceStoreInterface
{
    /** @var array<string, string> */
    public array $records = [];

    #[\Override]
    public function read(UserInterface $user, string $key): ?string
    {
        return $this->records[self::id($user, $key)] ?? null;
    }

    #[\Override]
    public function write(UserInterface $user, string $key, string $json): void
    {
        $this->records[self::id($user, $key)] = $json;
    }

    #[\Override]
    public function delete(UserInterface $user, string $key): void
    {
        unset($this->records[self::id($user, $key)]);
    }

    private static function id(UserInterface $user, string $key): string
    {
        return $user->getUserIdentifier().'#'.$key;
    }
}
