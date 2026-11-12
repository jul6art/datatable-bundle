<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Fixtures\Preference;

use Doctrine\ORM\Mapping as ORM;
use Jul6Art\DatatableBundle\Preference\Doctrine\AbstractDatatablePreference;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * An application's concrete entity: its table and unique constraint, and the space a multi-tenant
 * application adds — set from the account, as cegeta sets its organization.
 */
#[ORM\Entity]
#[ORM\Table(name: 'datatable_preference')]
#[ORM\UniqueConstraint(name: 'uniq_datatable_preference_owner_key', columns: ['owner_id', 'space', 'datatable_key'])]
class TablePreference extends AbstractDatatablePreference
{
    #[ORM\Column(nullable: true)]
    private ?string $space;

    public function __construct(UserInterface $owner, string $datatableKey, string $payload)
    {
        parent::__construct($owner, $datatableKey, $payload);
        $this->space = $owner instanceof Account ? $owner->getSpace() : null;
    }

    public function getSpace(): ?string
    {
        return $this->space;
    }
}
