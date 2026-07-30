<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Tests\Fixtures;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

class NameFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if ($targetEntity->getName() !== Widget::class) {
            return '';
        }

        return $targetTableAlias . '.name = ' . $this->getParameter('name');
    }
}
