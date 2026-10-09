<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Une ligne ordonnée à la main : ce que le réordonnancement (2.9) réécrit.
 */
#[ORM\Entity]
class Lot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column]
        public string $name,
        #[ORM\Column]
        public int $position,
    ) {
    }
}
