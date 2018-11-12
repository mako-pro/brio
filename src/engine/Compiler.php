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
     * @param  AST           $code
     * @param  array|object  $stmt
     *
     * @return void
     */
    public function doPrint(AST $ast, $stmt)
    {
        $ast->doesPrint = true;

        if (static::$stripWhitespace && AST::isStr($stmt))
        {
            $stmt['string'] = preg_replace('/\s+/', ' ', $stmt['string']);
        }

        if ($this->obstartNum == 0)
        {
            $ast->doEcho($stmt);
            return;
        }

        $buffer = BH::hvar('buffer_' . $this->obstartNum);

        $ast->append($buffer, $stmt);
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
     * Generate Operation Code
     *
     * @param  array  $parsed
     * @param  AST    &$body
     *
     * @return void
     */
    protected function generateOperationCode(array $parsed, AST &$body)
    {
        foreach ($parsed as $structure)
        {
            if (! is_array($structure))
            {
                continue;
            }

            if (! isset($structure['operation']))
            {
                throw new CompilerException("Invalid parsed data: " . print_r($structure, true));
            }

            if ($this->baseTemplate && $this->numBlocks == 0 && $structure['operation'] != 'block')
            {
                continue;
            }

            $method = 'generateOperation' . Str::underscored2camel($structure['operation']);

            if (! is_callable([$this, $method]))
            {
                throw new CompilerException("Missing Compiler method [ $method ]");
            }

            $this->$method($structure, $body);
        }
    }

    /**
     * Exception handler for operation "Base"
     *
     * @return CompilerException
     */
    protected function generateOperationBase()
    {
        throw new CompilerException("{% base %} can be only as first statement");
    }

    /**
     * Generate operation "Include"
     *
     * @param  array $structure
     * @param  AST  &$body
     *
     * @return void
     */
    protected function generateOperationInclude(array $structure, &$body)
    {
        $this->doPrint(
            $body,
            BH::hexec(
                'placer\brio\Brio::load',
                $structure[0],
                $this->getScopeVariable(),
                true,
                BH::hvar('blocks')
            )
        );
    }

    /**
     * Generate operation "Block"
     *
     * @param  array $structure
     * @param  AST  &$body
     *
     * @return void
     */
    protected function generateOperationBlock(array $structure, &$body)
    {
        $this->numBlocks++;

        $this->blocks[] = $structure['name'];

        $blockName = BH::hvar('blocks', $structure['name']);

        $this->obStart($body);

        $bufferVar = 'buffer_' . $this->obstartNum;

        $ast = new AST;

        $this->generateOperationCode($structure['body'], $ast);

        $body->appendAST($ast);

        $this->obstartNum--;

        $buffer = BH::hvar($bufferVar);

        $declare = BH::hexpr_cond(
            BH::hexec('isset', $blockName),
            BH::hexpr_cond(
                BH::hexpr(BH::hexec('strpos', $blockName, $this->blockVar), '===', false),
                $blockName,
                BH::hexec('str_replace', $this->blockVar, $buffer, $blockName)
            ),
            $buffer
        );

        if (! $this->baseTemplate)
        {
            $this->doPrint($body, $declare);
        }
        else
        {
            $body->decl($blockName, $declare);

            if ($this->numBlocks > 1)
            {
                $this->doPrint($body, $blockName);
            }
        }

        array_pop($this->blocks);

        $this->numBlocks--;
    }

    /**
     * Generate operation "If Equal"
     *
     * @param  array $structure
     * @param  AST  &$body
     *
     * @return void
     */
    protected function generateOperationIfequal(array $structure, &$body)
    {
        $structureIf['expr'] = BH::hexpr($structure[1], $structure['cmp'], $structure[2])->getArray();
        $structureIf['body'] = $structure['body'];

        if (isset($structure['else']))
        {
            $structureIf['else'] = $structure['else'];
        }
        $this->generateOperationIf($structureIf, $body);
    }


    /**
     * Generate operation "If"
     *
     * @param  array $structure
     * @param  AST  &$body
     *
     * @return void
     */
    protected function generateOperationIf(array $structure, &$body)
    {
        if (static::$ifEmpty && $this->varFilter($structure['expr']) && count($structure['expr']['var_filter']) == 1)
        {

            $expr = $structure['expr'];

            $expr['var_filter'][] = 'empty';

            $variable = $this->getFilteredVar($expr['var_filter']);

            $structure['expr'] = BH::hexpr($variable, '===', false)->getArray();
        }

        $this->checkExpr($structure['expr']);

        $expr = AST::fromArrayGetAST($structure['expr']);

        $body->doIf($expr);

        $this->generateOperationCode($structure['body'], $body);

        if (isset($structure['else']))
        {
            $body->doElse();

            $this->generateOperationCode($structure['else'], $body);
        }

        $body->doEndif();
    }

    /**
     * Handle HTML code
     *
     * @param  array $structure
     * @param  AST  &$body
     *
     * @return void
     */
    protected function generateOperationHtml(array $structure, &$body)
    {
        $string = AST::str($structure['html']);

        $this->doPrint($body, $string);
    }

    /**
     * Generate code to print a variable with its filters, if there is any
     *
     * @param  array $structure
     * @param  AST  &$body
     *
     * @return void
     */
    protected function generateOperationPrintVar(array $structure, &$body)
    {
        $expr = $structure['expr'];

        $this->checkExpr($expr);

        if (! $this->isSafe($expr) && static::$autoescape)
        {
            $args = [$expr];
            $expr = $this->doFiltering('escape', $args);
        }

        $this->doPrint($body, $expr);
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
        if (AST::isExpr($expr))
        {
            if ($expr['op_expr'] == 'in')
            {
                for ($id=0; $id<2; $id++)
                {
                    if ($this->varFilter($expr[$id]))
                    {
                        $expr[$id] = $this->getFilteredVar($expr[$id]['var_filter']);
                    }
                }

                if (AST::isStr($expr[1]))
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
                $expr = $this->getFilteredVar($expr['var_filter']);
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
     *  Handles all the filtered variables output in the parser
     *
     *  @param array $vars
     *
     *  @return expr
     *
     */
    protected function getFilteredVar(array $vars)
    {
        $this->safeVariable = false;

        if (($count = count($vars)) > 1)
        {
;           if (AST::isExec($vars[0]) || ($vars[0][0] === 'block' && isset($vars[0]['string'])))
            {
                $target = $vars[0];
            }
            else
            {
                $target = $this->generateVariableName($vars[0]);
            }

            for ($i=1; $i<$count; $i++)
            {
                $fName = $vars[$i];

                if ($fName == 'escape')
                {
                    $this->safeVariable = true;
                }

                $exec = $this->doFiltering($fName, [$target]);
            }

            $returns = $exec;
        }
        else
        {
            if (AST::isExec($vars[0]))
            {
                $returns = $vars[0];
            }
            else
            {
                $returns = $this->generateVariableName($vars[0]);
            }
        }

        return $returns;
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

        $this->generateOperationCode($parsed, $ast);

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
