<?php

namespace placer\brio\engine;

use placer\brio\engine\generator\PHP as PhpGenerator;

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
     * Subtemplate
     *
     * @var string
     */
    protected $subtemplate;

    /**
     * Template name
     *
     * @var string
     */
    protected $templateName;

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
     * File
     *
     * @var string
     */
    protected $file;

    /**
     * Line
     *
     * @var integer
     */
    protected $line = 0;

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
     * Debug
     *
     * @var string
     */
    protected $debug;

    /**
     * Safe variables
     *
     * @var array
     */
    protected $safes = [];

    /**
     * Flag the current variable as safe
     *
     * @var boolean
     */
    public $safeVariable = false;

    /**
     * Block
     *
     * @var string
     */
    protected static $blockVar;

    // Compiler options

    protected static $autoescape      = true;
    protected static $ifEmpty         = true;
    protected static $dotObject       = true;
    protected static $stripWhitespace = false;
    protected static $allowExec       = false;
    protected static $globalContext   = [];
    protected static $echoConcat      = '.';
    protected static $enableLoad      = true;

    /**
     * Constructor
     *
     */
    function __construct()
    {
        $this->generator = new PhpGenerator;

        static::$blockVar = '{{block.' . sha1(time()) . '}}';
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
        if (! is_readable($file))
        {
            throw new BrioException(vsprintf('Cannot read view file [ %s ]', [$file]));
        }

        $this->setTemplateName($file);

        $this->file           = realpath($file);
        $this->line           = 0;
        $this->checkFunction  = $safe;
        $this->context        = $context;

        $content = file_get_contents($file);

        return $this->compile($content, $file);
    }

    /**
     * Set template name
     *
     * @param string $path Path to template file
     *
     * @return void
     */
    protected function setTemplateName($path)
    {
        $this->templateName = $path;
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
