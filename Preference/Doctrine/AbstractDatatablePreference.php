<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Preference\Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Jul6Art\CoreBundle\Entity\Traits\IdTrait;
use Jul6Art\CoreBundle\Entity\Traits\TimestampableTrait;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One account's arrangement of one table, as the browser sends it: opaque JSON, stored as is.
 *
 * The application extends it with its concrete entity, which carries only what is its own: the table
 * (`#[ORM\Table(name: 'datatable_preference')]`) and its unique constraint on the owner and the key —
 * a mapped superclass cannot declare table-level constraints — plus, in a multi-tenant application,
 * the space it belongs to ({@see \Jul6Art\DatatableBundle\Preference\DatatablePreferenceScopeInterface}).
 *
 * `owner` targets Symfony's `UserInterface`, which the bundle has Doctrine resolve to the
 * application's account class (`datatable.preferences.storage.user_class`): the application's `User`
 * does not change. The columns are those the applications' copies had — `owner_id`, `datatable_key`,
 * `payload`, timestamps — so adopting this class changes no schema.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractDatatablePreference
{
    use IdTrait;
    use TimestampableTrait;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: UserInterface::class)]
        #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
        protected UserInterface $owner,
        #[ORM\Column(name: 'datatable_key', length: 64)]
        #[Assert\NotBlank]
        #[Assert\Length(max: 64)]
        protected string $datatableKey,
        #[ORM\Column(type: Types::TEXT)]
        #[Assert\NotBlank]
        protected string $payload
    ) {
    }

    public function getOwner(): UserInterface
    {
        return $this->owner;
    }

    public function getDatatableKey(): string
    {
        return $this->datatableKey;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function setPayload(string $payload): static
    {
        $this->payload = $payload;

        return $this;
    }
}
