<?php

namespace placer\brio\engine;

use Closure;
use Exception;
use ArrayObject;
use RuntimeException;
use BadMethodCallException;
use placer\brio\Brio;

class Render extends ArrayObject
{
    /**
     * Template properties
     * @var array
     */
    private static $propertyList = [
        'name'    => 'runtime',
        'time'    => 0,
        'depends' => [],
        'macros'  => []
    ];

    /**
     * Callable code
     * @var Closure
     */
    protected $callableCode;

    /**
     * Template base name
     * @var string
     */
    protected $baseName;

    /**
     * Template storage
     * @var Brio
     */
    protected $brio;

    /**
     * Compilation timestamp
     * @var float
     */
    protected $cmplTimestamp = 0.0;

    /**
     * Template dependencies
     * @var array
     */
    protected $dependencies = [];

    /**
     * Template options
     * @var int
     */
    protected $options = 0;

    /**
     * Macros
     * @var array
     */
    protected $internalMacros = [];

    /**
     * Constructor
     *
     * @param Brio $brio
     * @param callable $code template body
     * @param array $props
     */
    public function __construct(Brio $brio, Closure $code, array $props = [])
    {
        $props += static::$propertyList;

        $this->brio           = $brio;
        $this->callableCode   = $code;
        $this->baseName       = $props["name"];
        $this->cmplTimestamp  = $props["time"];
        $this->dependencies   = $props["depends"];
        $this->internalMacros = $props["macros"];

    }

    /**
     * Get template storage
     *
     * @return Brio
     */
    public function getStorage()
    {
        return $this->brio;
    }

    /**
     * Get parse options
     *
     * @return int
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Get template name
     *
     * @return string
     */
    public function getName()
    {
        return $this->baseName;
    }

    /**
     * Get compilation timestamp
     *
     * @return float
     */
    public function getTime()
    {
        return $this->cmplTimestamp;
    }

    /**
     * Get internal macro
     *
     * @param string $name
     * @throws RuntimeException
     * @return mixed
     */
    public function getMacro(string $name)
    {
        if (empty($this->internalMacros[$name]))
            throw new RuntimeException("Not found macro named: $name");

        return $this->internalMacros[$name];
    }

    /**
     * Verify template dependencies
     *
     * @return bool
     */
    public function verifyMtime()
    {
        foreach ($this->dependencies as $template => $mtime)
        {
            if ($this->brio->getLastModified($template) !== $this->cmplTimestamp)
                return false;
        }
        return true;
    }

    /**
     * Execute template and write into output
     *
     * @param array $values for template
     * @return Render
     */
    public function display(array $values)
    {
        $this->callableCode->__invoke($values, $this);
    }

    /**
     * Execute template and return result as string
     *
     * @param array $values for template
     * @return string
     * @throws Exception
     */
    public function fetch(array $values)
    {
        ob_start();
        try
        {
            $this->display($values);
            return ob_get_clean();
        }
        catch (Exception $e)
        {
            ob_end_clean();
            throw $e;
        }
    }

    public function __toString()
    {
        return $this->baseName;
    }

    public function __call($method, $args)
    {
        throw new BadMethodCallException("Unknown method named $method");
    }

    public function __get($name)
    {
        return $this->$name = null;
    }

}
