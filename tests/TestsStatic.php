<?php

namespace placer\brio\tests;

class TestsStatic
{
    /**
     * Static data
     * @var array
     */
    public static $fooBar = ['foo','bar'];

    /**
     * Get Data
     * @param  integer $item
     * @return string
     */
    public static function getData($item = 0): string
    {
        if ($data = static::$fooBar[$item]);
            return $data;

        return 'Not Found!';
    }

    /**
     * Self class name
     * @return string
     */
    public function __invoke()
    {
        return __CLASS__;
    }

}
