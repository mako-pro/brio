<?php

namespace placer\brio\engine;

use placer\brio\engine\generator\PHP as PhpGenerator;

class Compiler
{
    /**
     * Generator
     *
     * @var object
     */
    protected $generator;

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
     * Check function
     *
     * @var boolean
     */
    protected $checkFunction = false;

    /**
     * Blocks
     *
     * @var array
     */
    protected $blocks = [];

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

    // Compiler options here!!!

    /**
     * Constructor
     *
     */
    function __construct()
    {
        $this->generator = new PhpGenerator;

        self::$blockVar = '{{block.' . sha1(time()) . '}}';
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
        return '';
    }

}
