<?php

namespace placer\brio\engine;

use Exception;
use ReflectionClass;
use placer\brio\engine\Compiler;

abstract class Extension
{
    /**
     * Extension instance
     *
     * @var Extension
     */
    private static $instance;

    /**
     * Constructor
     */
    private function __construct()
    {
    }

    /**
     * Get instance
     *
     * @param  string $name Extension name
     * @return Extension
     */
    public static function getInstance(string $name)
    {
        $className = 'placer\\brio\\engine\\extension\\' . $name;

        if (! class_exists($className))
        {
            throw new Exception("{$className} is not a class");
        }

        if (! is_subclass_of($className, __CLASS__))
        {
            throw new Exception("{$className} is not a sub-class of " . __CLASS__);
        }

        if (! isset(static::$instance[$className]))
        {
            static::$instance[$className] = new $className;
        }

        return static::$instance[$className];
    }

    /**
     * Validate extension name
     *
     * @param  string  $name
     * @return boolean
     */
    abstract function isValid(string $name);

    /**
     * Get class name
     *
     * @param  string $name
     * @return string
     */
    abstract function getClassName(string $name);

    /**
     * Get extension file path
     *
     * @param  string  $name
     * @param  boolean $rel
     * @param  string  $pref
     * @return string
     */
    final function getFilePath(string $name, $rel=true, $pref=null)
    {
        try
        {
            $reflection = new ReflectionClass($this->getClassName($name));
            $file = $reflection->getFileName();
        }
        catch (Exception $e)
        {
            $file = '';
        }
        return $file;
    }

    /**
     * Get function alias
     *
     * @param  string $name
     * @return string
     */
    final public function getFunctionAlias(string $name)
    {
        if (! $this->isValid($name))
        {
            return null;
        }

        $className = $this->getClassName($name);
        $properties = get_class_vars($className);

        if (isset($properties['phpAlias']))
        {
            return $properties['phpAlias'];
        }
        return null;
    }

    /**
     * Check is safe
     *
     * @param  string  $name
     * @return boolean
     */
    final public function isSafe(string $name)
    {
        if (! $this->isValid($name))
        {
            return null;
        }

        $className  = $this->getClassName($name);
        $properties = get_class_vars($className);

        return isset($properties['isSafe']) ? $properties['isSafe'] : false;
    }

    /**
     * Generate extension method
     *
     * @param string  $name
     * @param object  Compiler
     * @param array   Args
     * @param mixed   $extra  Extra params
     * @return array
     */
    public function generator(string $name, Compiler $compiler, array $args, $extra=null)
    {
        if (! $this->hasGenerator($name))
        {
            return [];
        }

        $className = $this->getClassName($name);

        return call_user_func([$className, 'generator'], $compiler, $args, $extra);
    }

    /**
     * Check if the extension has a generator method
     *
     * @param string $name Extension name
     * @return bool
     */
    public function hasGenerator(string $name)
    {
        if (! $this->isValid($name))
        {
            return null;
        }

        $className = $this->getClassName($name);

        return is_callable([$className, 'generator']);
    }

}
