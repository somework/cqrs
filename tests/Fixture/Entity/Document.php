<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The root of an inheritance hierarchy that records nothing, unlike its subclass Report.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cqrs_test_document')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'kind', type: Types::STRING, length: 20)]
#[ORM\DiscriminatorMap(['document' => Document::class, 'report' => Report::class])]
class Document
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 36)]
        protected string $id,
    ) {
    }
}
