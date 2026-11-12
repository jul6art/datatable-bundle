<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Fixtures\Preference;

use Doctrine\ORM\Mapping as ORM;
use Jul6Art\CoreBundle\Entity\Traits\IdTrait;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * An application's account, as the Doctrine store sees one: an entity implementing `UserInterface`,
 * working in a space — the multi-tenant case a scope serves.
 */
#[ORM\Entity]
#[ORM\Table(name: 'account')]
class Account implements UserInterface
{
    use IdTrait;

    #[ORM\Column(nullable: true)]
    private ?string $space = null;

    public function __construct(
        #[ORM\Column]
        private string $email,
    ) {
    }

    public function getSpace(): ?string
    {
        return $this->space;
    }

    public function setSpace(?string $space): static
    {
        $this->space = $space;

        return $this;
    }

    #[\Override]
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    /**
     * Still required by Symfony 7.4's UserInterface, gone in 8.0: no `#[\Override]`, which would fail there.
     */
    public function eraseCredentials(): void
    {
    }

    #[\Override]
    public function getUserIdentifier(): string
    {
        return '' === $this->email ? 'anonymous' : $this->email;
    }
}
