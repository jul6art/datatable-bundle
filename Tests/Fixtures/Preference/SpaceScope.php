<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Fixtures\Preference;

use Jul6Art\DatatableBundle\Preference\DatatablePreferenceScopeInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** One arrangement per account and per space. */
final class SpaceScope implements DatatablePreferenceScopeInterface
{
    #[\Override]
    public function criteria(UserInterface $user): array
    {
        return $user instanceof Account ? ['space' => $user->getSpace()] : [];
    }
}
