<?php

namespace placer\brio\engine;

use placer\brio\engine\generator\PHP as PhpGenerator;
use placer\brio\engine\helper\AST;
use placer\brio\engine\helper\BH;

class Compiler
{
    /**
     * Compiler instance
     *
     * @var placer\brio\engine\Compiler
     */
    private static $instance;

    /**
     * Generator
     *
     * @var object
     */
    protected $generator;

    /**
     * Base temlate
     *
     * @var array
     */
    protected $baseTemplate = [];

    /**
     * Blocks
     *
     * @var array
     */
    protected $blocks = [];

    /**
     * Forloops
     *
     * @var array
     */
    protected $forloop = [];

    /**
     * Forid
     *
     * @var integer
     */
    protected $forid = 0;

    /**
     * Check function
     *
     * @var boolean
     */
    protected $checkFunction = false;

    /**
     * Template file
     *
     * @var string
     */
    protected $templateFile;

    /**
     * Number of blocks
     *
     * @var integer
     */
    protected $numBlocks = 0;

    /**
     * Output buffer number
     *
     * @var integer
     */
    protected $obstartNum = 0;

    /**
     * Append
     *
     * @var string
     */
    protected $append;

    /**
     * Prepend OP
     *
     * @var object
     */
    protected $prependOP;

    /**
     * Context at compile time
     *
     * @var array
     */
    protected $context = [];

    /**
     * Variables aliases (defined in template)
     *
     * @var array
     */
    protected $varAliases = [];

    /**
     * Debug file
     *
     * @var string
     */
    protected $debugFile;

    /**
     * Safe variables
     *
     * @var array
     */
    protected $safes = [];

    /**
     * Block placeholder
     *
     * @var string
     */
    protected $blockVar;

    /**
     * Flag the current variable as safe
     *
     * @var boolean
     */
    public $safeVariable = false;

    // Compiler options

    protected static $autoescape      = true;
    protected static $ifEmpty         = true;
    protected static $dotObject       = true;
    protected static $stripWhitespace = false;
    protected static $allowExec       = false;
    protected static $enableLoad      = true;

    /**
     * Constructor
     */
    function __construct()
    {
        $this->generator = new PhpGenerator;

        $this->blockVar = 'block_' . uniqid();
    }

    /**
     * Get instance
     *
     * @return placer\brio\engine\Compiler
     */
    public static function getInstance()
    {
        if (static::$instance === null)
        {
            static::$instance = new static;
        }
        return static::$instance;
    }

    /**
     * Get option
     *
     * @param  string $option Option name
     *
     * @return mixed
     */
    public static function getOption(string $option)
    {
        if (isset(static::${$option}))
        {
            return static::${$option};
        }
        return null;
    }

    /**
     * Set compiler options
     *
     * @param array $options
     *
     * @return  void
     */
    public function setOptions(array $options = [])
    {
        foreach ($options as $key => $val)
        {
            if (property_exists($this, $key))
            {
                static::${$key} = $val;
            }
        }
    }

    /**
     * Set debug file
     *
     * @param string $file
     *
     * @return  void
     */
    public function setDebugFile(string $file)
    {
        $this->debugFile = $file;
    }

    /**
     * Reset the Compiler instance
     *
     * @return void
     */
    public function reset()
    {
        $varKeys = array_keys(get_object_vars($this));

        foreach ($varKeys as $key)
        {
            if ($key != 'generator' && $key != 'blockVar')
            {
                $this->$key = null;
            }
        }
    }

    /**
     * Get function name
     *
     * @param  string $name
     *
     * @return string
     */
    public function getFunctionName(string $name)
    {
        return "brio_" . sha1($name);
    }

    /**
     * Get scope variable
     *
     * @param  integer  $part
     * @param  boolean $string
     *
     * @return mixed
     */
    public function getScopeVariable($part = null, $string = false)
    {
        static $var = null;

        if ($var === null)
        {
            $var = 'vars_' . uniqid(true);
        }

        if ($string)
        {
            return $var;
        }

        if ($part !== null)
        {
            return BH::hvar($var, $part);
        }

        return BH::hvar($var);
    }

    /**
     * Do print
     *
     * @param  object         $code
     * @param  array|object  $stmt
     *
     * @return void
     */
    public function doPrint(AST $code, $stmt)
    {
        $code->doesPrint = true;

        if (self::$stripWhitespace && AST::is_str($stmt))
        {
            $stmt['string'] = preg_replace('/\s+/', ' ', $stmt['string']);
        }

        if ($this->obstartNum == 0)
        {
            $code->do_echo($stmt);
            return;
        }

        $buffer = BH::hvar('buffer_' . $this->obstartNum);

        $code->append($buffer, $stmt);
    }

    /**
     * Get template name
     *
     * @return string Path to template file
     */
    private function getTemplateName()
    {
        return $this->templateFile;
    }

    /**
     *  Compile a file
     *
     *  @param string $file      File path
     *  @param bool   $safe      Whether or not add check if the function is already defined
     *  @param array  $context   Vars
     *
     *  @return string           Generated PHP code
     */
    public function compileFile(string $file, $safe = false, $context = [])
    {
        $this->templateFile  = $file;
        $this->checkFunction = $safe;
        $this->context       = $context;

        $content = file_get_contents($file);

        return $this->compile($content, $file);
    }

    /**
     * Final compile
     *
     * @param  string $content
     * @param  string $file
     *
     * @return string
     */
    private function compile(string $content, string $file)
    {
        // ...

        return $content;
    }

}
