<?php

namespace Meanbee\Magedbm2\Service\Anonymiser;

class Eav
{
    const VALUE_TYPES = ['datetime', 'decimal', 'int', 'text', 'varchar'];

    /**
     * Gets tht entity name from a table.
     *
     * @param $table
     * @return mixed|null
     */
    public static function getEntityFromTable($table)
    {
        if (strpos($table, '_entity') === false) {
            return null;
        }

        list($entity, $valueTable) = self::getEavParts($table);

        $entity = str_replace('_entity', '', $entity);

        return $entity;
    }

    /**
     * Establish whether or not a given table looks like an EAV value table.
     *
     * @param $table
     * @return bool
     */
    public static function isValueTable($table)
    {
        list($entity, $valueTable) = self::getEavParts($table);

        return in_array($valueTable, self::VALUE_TYPES, true);
    }

    private static function getEavParts($table)
    {
        $parts = explode('_entity_', $table);

        if (count($parts) > 1) {
            return [$parts[0], $parts[1]];
        }

        return [$parts[0], null];
    }
}
