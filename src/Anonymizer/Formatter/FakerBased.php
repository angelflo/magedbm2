<?php

namespace Meanbee\Magedbm2\Anonymizer\Formatter;

/**
 * @internal
 */
abstract class FakerBased
{
    private static $faker;

    public function __construct()
    {
        if (self::$faker === null) {
            self::$faker = \Faker\Factory::create();
        }
    }

    /**
     * @return \Faker\Generator
     */
    protected function getFaker()
    {
        return self::$faker;
    }
}
