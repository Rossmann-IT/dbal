<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;

/**
 * Type that maps SQL BIT VARYING Type to a PHP integer
 */
class BitVaryingType extends IntegerType
{
    /**
     * {@inheritDoc}
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        $length = $column['length'] ?? 1;

        return 'BIT VARYING(' . $length . ')';
    }

    /**
     * Gets an array of database types that map to this Doctrine type.
     *
     * @return array<int, string>
     */
    public function getMappedDatabaseTypes(AbstractPlatform $platform): array
    {
        return ['varbit'];
    }
}
