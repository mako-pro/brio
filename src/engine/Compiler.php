<?php

namespace placer\brio\engine;

use mako\utility\Str;
use placer\brio\engine\generator\PHP as PhpGenerator;
use placer\brio\engine\compiler\CompilerException;
use placer\brio\engine\compiler\Tokenizer;
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
     * Generator instance
     *
     * @var placer\brio\engine\generator\PHP
     */
    protected $generator;

    /**
     * AST instance
     *
     * @var placer\brio\engine\helper\AST
     */
    protected $ast;

    /**
     * Base template
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
    public function getTemplateFile()
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
        $this->ast           = new AST;

        return $this->compile();
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
     *  Set base template
     *
     *  @param array $base Base template as ['string' => 'template.name']
     *
     *  @return void
     */
    protected function setBaseTemplate($base = [])
    {
        $this->baseTemplate = $base;
    }

    /**
     * Generate code to call base template
     *
     * @return object AST
     */
    protected function exprCallBaseTemplate()
    {
        return BH::hexec(
            'placer\brio\Brio::load',
            $this->baseTemplate,
            $this->getScopeVariable(),
            true,
            BH::hvar('blocks')
        );
    }

    /**
     * Generate OpCode
     *
     * @param  array  $parsed
     * @param  AST    &$ast
     *
     * @return void
     */
    protected function generateOpCode(array $parsed, AST &$ast): void
    {

        foreach ($parsed as $op)
        {
            if (! is_array($op))
            {
                continue;
            }

            if (! isset($op['operation']))
            {
                throw new CompilerException("Invalid parsed data: " . print_r($op, true));
            }

            if ($this->baseTemplate && $this->numBlocks == 0 && $op['operation'] != 'block')
            {
                continue;
            }

            $method = 'generateOp' . Str::underscored2camel($op['operation']);

            if (! is_callable([$this, $method]))
            {
                throw new CompilerException("Missing Compiler method $method");
            }

            $this->$method($op, $ast);
        }
    }

    /**
     * Exception handler for Base Operation
     *
     * @return CompilerException
     */
    protected function generateOpBase()
    {
        throw new CompilerException("{% base %} can be only as first statement");
    }

    /**
     * Include file
     *
     * @param  array $details
     * @param  AST  &$body
     *
     * @return void
     */
    protected function generateOpInclude(array $details, &$body)
    {
        $this->doPrint(
            $body,
            BH::hexec(
                'placer\brio\Brio::load',
                $details[0],
                $this->getScopeVariable(),
                true,
                BH::hvar('blocks')
            )
        );
    }

    /**
     * Check the current expr
     *
     * @param  array &$expr
     *
     * @return void
     */
    protected function checkExpr(array &$expr)
    {
        if (AST::is_expr($expr))
        {
            if ($expr['op_expr'] == 'in')
            {
                for ($id=0; $id<2; $id++)
                {
                    if ($this->varFilter($expr[$id]))
                    {
                        $expr[$id] = $this->getFilteredVar($expr[$id]['var_filter'], $var);
                    }
                }

                if (AST::is_str($expr[1]))
                {
                    $expr = BH::hexpr(
                        BH::hexec(
                            'strpos',
                            $expr[1],
                            $expr[0]
                        ),
                        '!==',
                        false
                    );
                }
                else
                {
                    $expr = BH::hexpr(
                        BH::hexpr_cond(
                            BH::hexec('is_array', $expr[1]),
                            BH::hexec('array_search', $expr[0], $expr[1]),
                            BH::hexec('strpos', $expr[1], $expr[0])
                        ),
                        '!==',
                        false
                    );
                }
            }

            if (is_object($expr))
            {
                $expr = $expr->getArray();
            }

            $this->checkExpr($expr[0]);
            $this->checkExpr($expr[1]);

        }
        elseif (is_array($expr))
        {
            if ($this->varFilter($expr))
            {
                $expr = $this->getFilteredVar($expr['var_filter'], $var);
            }
            elseif (isset($expr['args']))
            {
                foreach ($expr['args'] as &$v)
                {
                    $this->checkExpr($v);
                }
                unset($v);
            }
            else if (isset($expr['expr_cond']))
            {
                $this->checkExpr($expr['expr_cond']);
                $this->checkExpr($expr['true']);
                $this->checkExpr($expr['false']);
            }
        }
    }

    /**
     * Check is set var filter
     *
     * @param  array $cmd
     *
     * @return bool
     */
    protected function varFilter(array $cmd)
    {
        return isset($cmd['var_filter']);
    }

    /**
     * Final compile
     *
     * @return string
     */
    private function compile()
    {
        $ast = $this->ast;

        $data = file_get_contents($this->templateFile);

        $parsed = Tokenizer::init($this, $data);

        if (isset($parsed[0]) && $parsed[0]['operation'] == 'base')
        {
            $base = $parsed[0][0];

            $this->setBaseTemplate($base);

            unset($parsed[0]);
        }

        $functionName = $this->getFunctionName($this->templateFile);

        if ($this->checkFunction)
        {
            $ast->doIf(BH::hexpr(BH::hexec('function_exists', $functionName), '===', false));
        }

        $ast->comment("Generated from " . $this->templateFile);
        $ast->declareFunction($functionName);
        $ast->doExec('extract', $this->getScopeVariable());
        $ast->doIf(BH::hexpr(BH::hvar('return'), '==', true));
        $ast->doExec('ob_start');
        $ast->doEndif();

        $this->generateOpCode($parsed, $ast);

        if ($this->baseTemplate)
        {
            $expr = $this->exprCallBaseTemplate();

            $this->doPrint($ast, $expr);
        }

        $ast->doIf(BH::hexpr(BH::hvar('return'), '==', true));
        $ast->doReturn(BH::hexec('ob_get_clean'));
        $ast->doEndif();
        $ast->doEndfunction();

        if ($this->checkFunction)
        {
            $ast->doEndif();
        }

        $opCode = $ast->getArray(true);

        $output = $this->generator->getCode($opCode, $this->getScopeVariable(null, true));

        if (! empty($this->debugFile))
        {
            $opCode['php'] = $output;

            file_put_contents($this->debugFile, print_r($opCode, true), LOCK_EX);
        }

        return $output;
    }

}
