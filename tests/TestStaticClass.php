<?php

namespace placer\brio\tests;

/**
 * "config/application.php":
 *
 * 'class_aliases' =>
 * [
 *      'TestStaticClass' => '\placer\brio\tests\TestStaticClass',
 * ],
 */

class  TestStaticClass
{
    /**
     * Static data
     * @var array
     */
    public static $fooBar = ['foo','bar'];

    /**
     * Bar property
     * @var string
     */
    public $bar = 'PublicBar';

    /**
     * Foo property
     * @var string
     */
    protected $foo = 'ProtectedFoo';

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
     * Get Foo
     * @return string
     */
    public function getFoo(): string
    {
        return $this->foo;
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
