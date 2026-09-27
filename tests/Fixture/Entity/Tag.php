<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An entity whose class records nothing: the listener does not look at its entities.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cqrs_test_tag')]
class Tag
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $id,
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $title,
    ) {
    }

    public function rename(string $title): void
    {
        $this->title = $title;
    }

    public function title(): string
    {
        return $this->title;
    }
}
