<?php

namespace App\Shared\User;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Hides other authors' rows from every query of a web request, so an unknown or foreign id is a 404.
 * Workers run without it; they scope by the article's user themselves.
 */
final class OwnerFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!$targetEntity->hasAssociation('user')) {
            return '';
        }

        return sprintf('%s.user_id = %s', $targetTableAlias, $this->getParameter('user'));
    }
}
