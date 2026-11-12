<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Preference;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * What else, besides the account and the table, a stored preference belongs to.
 *
 * A multi-tenant application keeps one arrangement per account AND per space: its concrete entity
 * carries the space, and a service implementing this interface tells the Doctrine store to look it up
 * by that space too. Services implementing it are found by autoconfiguration
 * (`datatable.preference_scope` tag); with none, the account and the table key are the whole key.
 */
interface DatatablePreferenceScopeInterface
{
    /**
     * Criteria added to `owner` and `datatableKey`, named after the concrete entity's fields — for
     * instance `['organization' => $user->getOrganization()]`.
     *
     * @return array<string, mixed>
     */
    public function criteria(UserInterface $user): array;
}
