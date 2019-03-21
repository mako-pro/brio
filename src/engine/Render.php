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
    private static $templateProps = [
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
     * Template name
     * @var string
     */
    protected $templateName;

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
    protected $templateMacros = [];

    /**
     * Constructor
     *
     * @param Brio $brio
     * @param callable $code template body
     * @param array $props
     */
    public function __construct(Brio $brio, Closure $code, array $props = [])
    {
        $props += static::$templateProps;

        $this->brio             = $brio;
        $this->callableCode     = $code;
        $this->templateName     = $props["name"];
        $this->cmplTimestamp    = $props["time"];
        $this->dependencies     = $props["depends"];
        $this->templateMacros   = $props["macros"];

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
     * Get template string
     *
     * @return string
     */
    public function __toString()
    {
        return $this->templateName;
    }

    /**
     * Get template name
     *
     * @return string
     */
    public function getName()
    {
        return $this->templateName;
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
     * Get internal macro
     *
     * @param string $name
     * @throws RuntimeException
     * @return mixed
     */
    public function getMacro(string $name)
    {
        if (empty($this->templateMacros[$name]))
            throw new RuntimeException("Not found macro named: $name");

        return $this->templateMacros[$name];
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

    /**
     * Stub
     *
     * @param $method
     * @param $args
     * @throws BadMethodCallException
     */
    public function __call($method, $args)
    {
        throw new BadMethodCallException("Unknown method named $method");
    }

    public function __get($name)
    {
        return $this->$name = null;
    }

}
