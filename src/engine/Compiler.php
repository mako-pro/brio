<?php

namespace placer\brio\engine;

use Iterator;
use ArrayAccess;
use mako\utility\Str;
use placer\brio\engine\generator\PHP as PhpGenerator;
use placer\brio\engine\compiler\CompilerException;
use placer\brio\engine\compiler\Tokenizer;
use placer\brio\engine\helper\AST;
use placer\brio\engine\helper\BH;

class Compiler
{
    /**
     * Parent block super placeholder
     *
     * @var string
     */
    const BLOCK_VAR = 'block_super';

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
            if ($key != 'generator')
            {
                $this->$key = null;
            }
        }
    }

   /**
    * Set the variable context
    *
    * @param string $varname
    * @param mixed $value
    *
    * @return void
    */
    public function setContext(string $varname, $value = null)
    {
        $this->context[$varname] = $value;
    }

    /**
     * Get variables context
     *
     * @param  string|array $variable
     *
     * @return mixed
     */
    public function getContext($variable)
    {
        $variable = (array) $variable;

        $varName = $variable[0];

        if (isset($this->context[$varName]))
        {
            if (count($variable) === 1)
            {
                return $this->context[$varName];
            }

            $var = &$this->context[$varName];

            foreach ($variable as $id => $part)
            {
                if ($id !== 0)
                {
                    if (is_array($part) && isset($part['object']))
                    {
                        if (is_array($part['object']) && isset($part['object']['var']))
                        {
                            $name = $part['object']['var'];
                            $name = $this->getContext($name);

                            if (! isset($var->$name))
                            {
                                return null;
                            }
                            $var = &$var->$name;
                        }
                        else
                        {
                            if (! isset($var->{$part['object']}))
                            {
                                return null;
                            }
                            $var = &$var->{$part['object']};
                        }
                    }
                    elseif (is_object($var))
                    {
                        if (! isset($var->$part))
                        {
                            return null;
                        }
                        $var = &$var->$part;
                    }
                    else
                    {
                        if (! is_scalar($part) || empty($part) || ! isset($var[$part]))
                        {
                            return null;
                        }
                        $var = &$var[$part];
                    }
                }
            }
            return $var;
        }
        return null;
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
     *  Compile the $body to operation code
     *
     *  @param string $file Path to compiled file
     *  @param AST   $body
     *
     *  @return void
     */
    public function compileCode(string $file, AST $body)
    {
        $parsed = Tokenizer::init($this, file_get_contents($file));

        $this->generateOperationCode($parsed, $body);
    }

    /**
     * Set variable to safe
     *
     * @param string $name Var name
     *
     * @return  void
     */
    public function setSafe(string $name)
    {
        if (! AST::isVar($name))
        {
            $name = BH::hvar($name)->getArray();
        }

        $this->safes[serialize($name)] = true;
    }

    /**
     * Get variable name
     *
     * @param  string|array  $variable
     * @param  boolean      $special
     *
     * @return array
     */
    public function generateVariableName($variable, $special = true)
    {
        if (is_array($variable))
        {
            switch ($variable[0])
            {
                case 'forloop':
                    if (! $special)
                    {
                        return ['var' => $variable];
                    }
                    if (! $this->forid)
                    {
                        throw new CompilerException("Invalid forloop reference outside of a loop");
                    }
                    switch ($variable[1]['object'])
                    {
                        case 'counter':
                            $this->forloop[$this->forid]['counter'] = true;
                            $variable = 'forcounter1_' . $this->forid;
                            break;
                        case 'counter0':
                            $this->forloop[$this->forid]['counter0'] = true;
                            $variable = 'forcounter0_' . $this->forid;
                            break;
                        case 'last':
                            $this->forloop[$this->forid]['counter'] = true;
                            $this->forloop[$this->forid]['last'] = true;
                            $variable = 'islast_' . $this->forid;
                            break;
                        case 'first':
                            $this->forloop[$this->forid]['first'] = true;
                            $variable = 'isfirst_' . $this->forid;
                            break;
                        case 'revcounter':
                            $this->forloop[$this->forid]['revcounter'] = true;
                            $variable = 'revcount_' . $this->forid;
                            break;
                        case 'revcounter0':
                            $this->forloop[$this->forid]['revcounter0'] = true;
                            $variable = 'revcount0_' . $this->forid;
                            break;
                        case 'parentloop':
                            unset($variable[1]);
                            $this->forid--;
                            $variable = $this->generateVariableName(array_values($variable));
                            $variable = $variable['var'];
                            $this->forid++;
                            break;
                        default:
                            throw new CompilerException("Unexpected forloop.{$variable[1]}");
                    }
                $this->safeVariable = true;
                break;
            case 'block':
                if (! $special)
                {
                    return ['var' => $variable];
                }
                if ($this->numBlocks == 0)
                {
                    throw new CompilerException("Can't use block.super outside a block");
                }
                if (! $this->baseTemplate)
                {
                    throw new CompilerException("Only subtemplates can call block.super");
                }
                $this->safeVariable = true;
                return AST::str(self::BLOCK_VAR);
                break;
            default:
                if ($special)
                {
                    return ['var' => $variable];
                }
                for ($i=1; $i < count($variable); $i++)
                {
                    $varPart = array_slice($variable, 0, $i);
                    $defArr  = true;

                    if (is_array($variable[$i]))
                    {
                        if (isset($variable[$i]['class']))
                        {
                            continue;
                        }
                        if (isset($variable[$i]['object']))
                        {
                            $defArr = false;
                        }
                        if (! AST::isVar($variable[$i]))
                        {
                            $variable[$i] = current($variable[$i]);
                        }
                        else
                        {
                            $variable[$i] = $this->generateVariableName($variable[$i]['var']);
                        }
                    }

                    $isObj = $this->varIsObject($varPart, 'unknown');

                    if ( $isObj === true || ($isObj == 'unknown' && ! $defArr)) {
                        $variable[$i] = ['object' => $variable[$i]];
                    }
                }
                break;
            }
        }
        elseif (isset($this->varAliases[$variable]))
        {
            $variable = $this->varAliases[$variable]['var'];
        }
        return BH::hvar($variable)->getArray();
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
     * Generate operation "Set"
     *
     * @param  array $structure
     * @param  AST  &$body
     *
     * @return void
     */
    protected function generateOperationSet(array $structure, &$body)
    {
        $var = $this->generateVariableName($structure['var']);

        $this->checkExpr($structure['expr']);

        $body->declRaw($var, $structure['expr']);

        $body->decl($this->getScopeVariable($var['var']), $var);
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

        $declare = BH::hexprCond(
            BH::hexec('isset', $blockName),
            BH::hexprCond(
                BH::hexpr(BH::hexec('strpos', $blockName, self::BLOCK_VAR), '===', false),
                $blockName,
                BH::hexec('str_replace', self::BLOCK_VAR, $buffer, $blockName)
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
     * Generate operation "Loop"
     *
     * @param  array $structure
     * @param  AST  &$body
     *
     * @return void
     */
    protected function generateOperationLoop(array $structure, &$body)
    {
        if (isset($structure['empty']))
        {
            $body->doIf(BH::hexpr(BH::hexec('count', BH::hvar($structure['array'])), '==', 0));
            $this->generateOperationCode($structure['empty'], $body);
            $body->doElse();
        }

        $oldID = $this->forid;
        $this->forid = $oldID+1;

        $this->forloop[$this->forid] = [];

        if (isset($structure['range']))
        {
            $this->setSafe($structure['variable']);
        }
        else
        {
            $var = &$structure['array'][0];

            if (is_string($var) && $this->varIsObject([$var], false))
            {
                $body->decl($var . '_arr', BH::hexec('get_object_vars', BH::hvar($var)));
                $var .= '_arr';
            }

            $variables = $this->getFilteredVar($structure['array']);

            if ($variables Instanceof AST)
            {
                $tmp = BH::hvar('tmp' . ($oldID+1));
                $body->decl($tmp, $variables);
                $variables = $tmp;
            }

            $structure['array'] = $variables;
        }

        $forBody = new AST;

        $this->generateOperationCode($structure['body'], $forBody);

        $forID = $this->forid;
        $size  = BH::hvar('psize_' . $forID);

        if (isset($this->forloop[$forID]['counter']))
        {
            $var = BH::hvar('forcounter1_' . $forID);
            $body->decl($var, 1);
            $forBody->decl($var, BH::hexpr($var, '+', 1));
        }

        if (isset($this->forloop[$forID]['counter0']))
        {
            $var = BH::hvar('forcounter0_' . $forID);
            $body->decl($var, 0);
            $forBody->decl($var, BH::hexpr($var, '+', 1));
        }

        if (isset($this->forloop[$forID]['last']))
        {
            if (! isset($cnt))
            {
                $body->decl('psize_' . $forID, BH::hexec('count', BH::hvarEx($structure['array'])));
                $cnt = true;
            }

            $var = 'islast_' . $forID;
            $body->decl($var, BH::hexpr(BH::hvar('forcounter1_' . $forID), '==', $size));
            $forBody->decl($var, BH::hexpr(BH::hvar('forcounter1_' . $forID), '==', $size));
        }

        if (isset($this->forloop[$forID]['first']))
        {
            $var = BH::hvar('isfirst_' . $forID);
            $body->decl($var, true);
            $forBody->decl($var, false);
        }

        if (isset($this->forloop[$forID]['revcounter']))
        {
            if (! isset($cnt))
            {
                $body->decl('psize_' . $forID, BH::hexec('count', BH::hvarEx($structure['array'])));
                $cnt = true;
            }

            $var = BH::hvar('revcount_' . $forID);
            $body->decl($var, $size);
            $forBody->decl($var, BH::hexpr($var, '-', 1));
        }

        if (isset($this->forloop[$forID]['revcounter0']))
        {
            if (! isset($cnt))
            {
                $body->decl('psize_' . $forID, BH::hexec('count', BH::hvarEx($structure['array'])));
                $cnt = true;
            }

            $var = BH::hvar('revcount0_' . $forID);
            $body->decl($var, BH::hexpr($size, "-", 1));
            $forBody->decl($var, BH::hexpr($var, '-', 1));
        }

        $this->forid = $oldID;

        if (! isset($structure['range']))
        {
            $body->doForeach($variables, $structure['variable'], $structure['index'], $forBody);
        }
        else
        {
            for ($i=0; $i<2; $i++)
            {
                if (AST::isVar($structure['range'][$i]))
                {
                    $structure['range'][$i] = $this->generateVariableName($structure['range'][$i]['var']);
                }
            }

            if (AST::isVar($structure['step']))
            {
                $structure['step'] = $this->generateVariableName($structure['step']['var']);
            }

            $body->doFor($structure['variable'], $structure['range'][0], $structure['range'][1], $structure['step'], $forBody);
            $this->setUnsafe(BH::hvar($structure['variable']));
        }

        if (isset($structure['empty']))
        {
            $body->doEndif();
        }
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
     * Generate operation "Loop"
     *
     * @param  array $structure
     * @param  AST  &$body
     *
     * @return void
     */
    protected function generateOperationIfchanged(array $structure, &$body)
    {
        static $ifchanged = 0;

        $ifchanged++;

        $varChanged = 'ifchanged' . $ifchanged;

        if (! isset($structure['check']))
        {
            $this->obStart($body);

            $varBuffer = BH::hvar('buffer_' . $this->obstartNum);

            $this->generateOperationCode($structure['body'], $body);

            $this->obstartNum--;

            $body->doIf(BH::hexpr(
                BH::hexec('isset', BH::hvar($varChanged)),
                '==', false, '||', BH::hvar($varChanged),
                '!=', $varBuffer
            ));

            $this->doPrint($body, $varBuffer);

            $body->decl($varChanged, $varBuffer);
        }
        else
        {
            foreach ($structure['check'] as $id => $type)
            {
                if (! AST::isVar($type))
                {
                    throw new CompilerException("Unexpected string {$type['string']}, expected a varabile");
                }

                $thisExpr = BH::hexpr(BH::hexpr(
                    BH::hexec('isset', BH::hvar($varChanged, $id)), '==', false,'||',
                    BH::hvar($varChanged, $id), '!=', $type
                ));

                if (isset($expr))
                {
                    $thisExpr = BH::hexpr($expr, '||', $thisExpr);
                }

                $expr = $thisExpr;
            }

            $body->doIf($expr);

            $this->generateOperationCode($structure['body'], $body);

            $body->decl($varChanged, $structure['check']);
        }

        if (isset($structure['else']))
        {
            $body->doElse();
            $this->generateOperationCode($structure['else'], $body);
        }

        $body->doEndif();
    }

    /**
     * Generate operation "Autoescape"
     *
     * @param  array $structure
     * @param  AST  &$body
     *
     * @return void
     */
    protected function generateOperationAutoescape(array $structure, &$body)
    {
        $autoescape = static::$autoescape;

        static::$autoescape = strtolower($structure['value']) == 'on';

        $this->generateOperationCode($structure['body'], $body);

        static::$autoescape = $autoescape;
    }

    /**
     * Generate operation "Spacefull"
     * Set the stripWhitespace compiler option to off
     *
     * @param  array $structure
     * @param  AST  &$body
     *
     * @return void
     */
    protected function generateOperationSpacefull(array $structure, &$body)
    {
        $stripWhitespace = static::$stripWhitespace;

        static::$stripWhitespace = false;

        $this->generateOperationCode($structure['body'], $body);

        static::$stripWhitespace = $stripWhitespace;
    }

    /**
     * Generate operation "Alias"
     * With <variable> as <var>
     *
     * @param  array $structure
     * @param  AST  &$body
     *
     * @return void
     */
    protected function generateOperationAlias(array $structure, &$body)
    {
        $this->varAliases[$structure['as']] = $this->generateVariableName($structure['var']);

        $this->generateOperationCode($structure['body'], $body);

        unset($this->varAliases[$structure['as']]);
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
     * Generate operation "Custom Tag"
     *
     * @param  array $structure
     * @param  AST  &$body
     *
     * @return void
     */
    protected function generateOperationCustomTag(array $structure, &$body)
    {
        $tag = Extension::getInstance('Tag');

        foreach ($structure['list'] as $id => $arg)
        {
            if (AST::isVar($arg))
            {
                $structure['list'][$id] = $this->generateVariableName($arg['var']);
            }
        }

        $tagName = $structure['name'];

        $tagFunction = $tag->getFunctionAlias($tagName);

        if (! $tagFunction && ! $tag->hasGenerator($tagName))
        {
            $functionName = $this->getCustomTag($tagName);
        }
        else
        {
            $functionName = $tagFunction;
        }

        if (isset($structure['body']))
        {
            $this->obStart($body);

            $this->generateOperationCode($structure['body'], $body);

            $target = BH::hvar('buffer_'.$this->obstartNum);

            if ($tag->hasGenerator($tagName))
            {
                $args = array_merge([$target], $structure['list']);
                $exec = $tag->generator($tagName, $this, $args);

                if (! $exec InstanceOf AST)
                {
                    throw new CompilerException("Invalid output of custom filter {$tagName}");
                }

                if ($exec->stackSize() >= 2 || $exec->doesPrint)
                {
                    $body->appendAST($exec);
                    $this->obstartNum--;
                    return;
                }
            }
            else
            {
                $exec = BH::hexec($functionName, $target);
            }

            $this->obstartNum--;
            $this->doPrint($body, $exec);
            return;
        }

        $var = isset($structure['as']) ? $structure['as'] : null;
        $args = array_merge([$functionName], $structure['list']);

        if ($tag->hasGenerator($tagName))
        {
            $exec = $tag->generator($tagName, $this, $structure['list'], $var);

            if ($exec InstanceOf AST)
            {
                if ($exec->stackSize() >= 2 || $exec->doesPrint || $var !== null)
                {
                    $body->appendAST($exec);
                    return;
                }
            }
            else
            {
                throw new CompilerException("Invalid output of the custom tag {$tagName}");
            }
        }
        else
        {
            $fnc = array_shift($args);
            $exec = BH::hexec($fnc);

            foreach ($args as $arg)
            {
                $exec->param($arg);
            }
        }

        if ($var)
        {
            $body->decl($var, $exec);
        }
        else
        {
            $this->doPrint($body, $exec);
        }
    }

    /**
     * Get custom tag
     *
     * @param  string $name Tag name
     *
     * @return string Calling Class::method
     */
    protected function getCustomTag(string $name)
    {
        $tag = Extension::getInstance('Tag');

        return $tag->getClassName($name) . "::main";
    }

    /**
     * Generate operation "Filter"
     *
     * @param  array $structure
     * @param  AST  &$body
     *
     * @return void
     */
    protected function generateOperationFilter(array $structure, &$body)
    {
        $this->obStart($body);

        $this->generateOperationCode($structure['body'], $body);

        $target = BH::hvar('buffer_' . $this->obstartNum);

        foreach ($structure['functions'] as $item)
        {
            $param = (isset($exec) ? $exec : $target);
            $exec  = $this->doFiltering($item, [$param]);
        }

        $this->obstartNum--;

        $this->doPrint($body, $exec);
    }

    /**
     * Get custom filter
     *
     * @param  string $name Filter name
     *
     * @return string Calling Class::method
     */
    protected function getCustomFilter(string $name)
    {
        $filter = Extension::getInstance('Filter');

        return $filter->getClassName($name) . "::main";
    }

    /**
     * Do filtering
     *
     * @param  string|array $name
     * @param  array $args
     *
     * @return object AST
     */
    protected function doFiltering($name, array $args)
    {
        static $filter;

        if (! $filter)
        {
            $filter = Extension::getInstance('Filter');
        }

        if (is_array($name))
        {
            $args = array_merge($args, $name['args']);

            $name = $name[0];
        }

        if (! $filter->isValid($name))
        {
            throw new CompilerException("{$name} is an invalid filter");
        }

        if ($filter->isSafe($name))
        {
            $this->safeVariable = true;
        }

        if ($filter->hasGenerator($name))
        {
            return $filter->generator($name, $this, $args);
        }

        if (! $fnc = $filter->getFunctionAlias($name))
        {
            $fnc = $this->getCustomFilter($name);
        }

        $args = array_merge([$fnc], $args);

        $exec = call_user_func_array(['placer\brio\engine\helper\BH', 'hexec'], $args);

        return $exec;
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
     * Check the current expr
     *
     * @param  array &$expr
     *
     * @return void
     */
    protected function checkExpr(&$expr)
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
                        BH::hexprCond(
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
     * Check if variable is object
     *
     * @param   array  $variable Variable
     * @param  string $default  Default returns
     *
     * @return boolean
     */
    protected function varIsObject(array $variable, $default = null)
    {
        $varname = $variable[0];

        switch ($varname)
        {
            case 'GLOBALS':
            case '_SERVER':
            case '_GET':
            case '_POST':
            case '_FILES':
            case '_COOKIE':
            case '_SESSION':
            case '_REQUEST':
            case '_ENV':
            case 'forloop':
            case 'block':
                return false;
        }

        $variable = $this->getContext($variable);

        if (is_array($variable) || is_object($variable))
        {
            return $default ? is_object($variable) :
                is_object($variable) &&
                ! $variable InstanceOf Iterator &&
                ! $variable Instanceof ArrayAccess;
        }

        return $default === null ? static::$dotObject : $default;
    }

    /**
     * Set variable to unsafe
     *
     * @param  object  $name \placer\brio\engine\helper\AST
     *
     * @return  viod
     */
    protected function setUnsafe($name)
    {
        if (! AST::isVar($name))
        {
            $name = BH::hvar($name)->getArray();
        }

        unset($this->safes[serialize($name)]);
    }

    /**
     * Check if variable is safe
     *
     * @param  object|array  $name Variable
     *
     * @return boolean
     */
    protected function isSafe($name)
    {
        if ($this->safeVariable)
        {
            return true;
        }

        if (isset($this->safes[serialize($name)]))
        {
            return true;
        }

        return false;
    }

    /**
     * Start a new buffering
     *
     * @param  object &$body AST
     *
     * @return void
     */
    protected function obStart(AST &$body)
    {
        $this->obstartNum++;

        $body->decl('buffer_' . $this->obstartNum, '');
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
