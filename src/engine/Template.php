<?php

namespace placer\brio\engine;

use Throwable;
use Exception;
use LogicException;
use RuntimeException;

use placer\brio\Brio;
use placer\brio\engine\error\CompileException;
use placer\brio\engine\error\SecurityException;
use placer\brio\engine\error\TokenizeException;
use placer\brio\engine\error\InvalidUsageException;
use placer\brio\engine\error\UnexpectedTokenException;

class Template extends Render
{
    const VAR_NAME = '$var';
    const TPL_NAME = '$tpl';

    const COMPILE_STAGE_LOADED        = 1;
    const COMPILE_STAGE_PRE_FILTERED  = 2;
    const COMPILE_STAGE_PARSED        = 3;
    const COMPILE_STAGE_PROCESSED     = 4;
    const COMPILE_STAGE_POST_FILTERED = 5;

    /**
     * Disable array parser.
     */
    const DENY_ARRAY = 1;

    /**
     * Disable modifier parser.
     */
    const DENY_MODS = 2;

    /**
     * Allow parse modifiers with term
     */
    const TERM_MODS = 1;

    /**
     * Allow parse range with term
     */
    const TERM_RANGE = 1;

    /**
     * @var int shared counter
     */
    public $i = 1;

    /**
     * @var array of macros
     */
    public $macros = [];

    /**
     * @var array of blocks
     */
    public $blocks = [];

    /**
     * @var string|null
     */
    public $extends;

    /**
     * @var string|null
     */
    public $extended;

    /**
     * Stack of extended templates
     * @var array
     */
    public $extStack = [];

    /**
     * @var boolean
     */
    public $extendBody = false;

    /**
     * Parent template
     * @var Template
     */
    public $parent;

    /**
     * Template PHP code
     * @var string
     */
    private $body;

    /**
     * Compile stage
     * @var integer
     */
    private $compileStage = 0;

    /**
     * Call stack
     * @var array
     */
    private $callStack = [];

    /**
     * Template source
     * @var string
     */
    private $source;

    /**
     * @var int
     */
    private $line = 1;

    /**
     * @var array
     */
    private $postCompiles = [];

    /**
     * Ignore tag
     * @var bool|string
     */
    private $ignore = false;

    /**
     * @var array
     */
    private $before = [];

    /**
     * Constructor
     *
     * @param Brio $brio
     * @param int $options
     * @param Template $parent
     */
    public function __construct(Brio $brio, $options, Template $parent = null)
    {
        $this->parent  = $parent;
        $this->brio    = $brio;
        $this->options = $options;
    }

    /**
     * Get tag stack size
     *
     * @return int
     */
    public function getStackSize()
    {
        return count($this->callStack);
    }

    /**
     * Get parent scope
     *
     * @param string $tag
     * @return bool|Tag
     */
    public function getParentScope(string $tag)
    {
        for ($i = count($this->callStack) - 1; $i >= 0; $i--)
        {
            if ($this->callStack[$i]->name == $tag)
            {
                return $this->callStack[$i];
            }
        }

        return false;
    }

    /**
     * Load template source
     *
     * @param string $template
     * @param bool $compile
     * @return $this
     */
    public function load(string $template, $compile = true)
    {
        $this->baseName = $this->brio->getRealTemplatePath($template);

        $this->source = $this->brio->getTemplateSource($this->baseName, $this->cmplTimestamp);

        $this->compileStage = self::COMPILE_STAGE_LOADED;

        if ($compile === true)
        {
            $this->compile();
        }

        return $this;
    }

    /**
     * Load custom source
     *
     * @param string $name template name
     * @param string $src template source
     * @param bool $compile
     * @return $this
     */
    public function source(string $name, string $src, $compile = true)
    {
        $this->baseName = $name;
        $this->source   = $src;

        if ($compile === true)
        {
            $this->compile();
        }

        return $this;
    }

    /**
     * Convert template to PHP code
     *
     * @throws CompileException
     * @return void
     */
    public function compile()
    {
        $end = $pos = 0;

        $this->compileStage = self::COMPILE_STAGE_PRE_FILTERED;

        while (($start = strpos($this->source, '{', $pos)) !== false)
        {
            switch (substr($this->source, $start + 1, 1))
            {
                case "\n":
                case "\r":
                case "\t":
                case " ":
                case "}":
                    $this->appendText(substr($this->source, $pos, $start - $pos + 2));
                    $end = $start + 1;
                    break;
                case "*":
                    $end = strpos($this->source, '*}', $start);
                    if ($end === false)
                    {
                        throw new CompileException(
                            "Unclosed comment block in line {$this->line}", 0, 1, $this->baseName, $this->line
                        );
                    }
                    $end++;
                    $this->appendText(substr($this->source, $pos, $start - $pos));
                    $comment = substr($this->source, $start, $end - $start);
                    $this->line += substr_count($comment, "\n");
                    unset($comment);
                    break;
                default:
                    $this->appendText(substr($this->source, $pos, $start - $pos));
                    $end = $start + 1;
                    do
                    {
                        $needMore = false;
                        $end = strpos($this->source, '}', $end + 1);
                        if ($end === false)
                        {
                            throw new CompileException(
                                "Unclosed tag in line {$this->line}", 0, 1, $this->baseName, $this->line
                            );
                        }
                        $tag = substr($this->source, $start + 1, $end - $start - 1);
                        if ($this->ignore)
                        {
                            if ($tag === '/' . $this->ignore)
                            {
                                $this->ignore = false;
                            }
                            else
                            {
                                $this->appendText('{' . $tag . '}');
                                continue;
                            }
                        }
                        $tokens = new Tokenizer($tag);
                        if ($tokens->isIncomplete())
                        {
                            $needMore = true;
                        }
                        else
                        {
                            $this->appendCode($this->parseTag($tokens), '{' . $tag . '}');
                            if ($tokens->key())
                            {
                                throw new CompileException(
                                    "Unexpected token '" . $tokens->current() . "' in {$this} line {$this->line}, near '{" . $tokens->getSnippetAsString(0, 0) . "' <- there", 0, E_ERROR, $this->baseName, $this->line
                                );
                            }
                        }
                    }
                    while ($needMore);
                    unset($tag);
                    break;
            }
            $pos = $end + 1;
        }
        $this->compileStage = self::COMPILE_STAGE_PARSED;

        gc_collect_cycles();

        $this->appendText(substr($this->source, $end ? $end + 1 : 0));

        if ($this->callStack)
        {
            $names = [];
            foreach ($this->callStack as $scope)
            {
                $names[] = '{' . $scope->name . '} opened on line ' . $scope->line;
            }

            $message = "Unclosed tag" . (count($names) > 1 ? "s" : "") . ": " . implode(", ", $names);
            throw new CompileException($message, 0, 1, $this->baseName, $scope->line);
        }

        $this->source = "";

        if ($this->postCompiles)
        {
            foreach ($this->postCompiles as $cb)
            {
                call_user_func_array($cb, [$this, &$this->body]);
            }
        }

        $this->compileStage = self::COMPILE_STAGE_PROCESSED;
        $this->addDepend($this);
        $this->compileStage = self::COMPILE_STAGE_POST_FILTERED;
    }

    /**
     * Check stage dones
     *
     * @param  int $stageNum
     * @return boolean
     */
    public function isStageDone(int $stageNum)
    {
        return $this->compileStage >= $stageNum;
    }

    /**
     * Set or unset the option
     *
     * @param int $option
     * @param bool $value
     * @return void
     */
    public function setOption(int $option, bool $value)
    {
        if ($value)
        {
            $this->options |= $option;
        }
        else
        {
            $this->options &= ~$option;
        }
    }

    /**
     * Execute some code at loading cache
     *
     * @param string $code
     * @return void
     */
    public function before(string $code)
    {
        $this->before[] = $code;
    }

    /**
     * Generate temporary template variable name
     *
     * @return string
     */
    public function tmpVar()
    {
        return sprintf('$t%x_%x', mt_rand(0, 0x7FFFFFFF), $this->i++);
    }

    /**
     * Append plain text to template body
     *
     * @param string $text
     * @return  void
     */
    private function appendText(string $text)
    {
        $this->line += substr_count($text, "\n");

        $strip = $this->options & Brio::AUTO_STRIP;

        $text = str_replace("<?", '<?php echo "<?"; ?>' . ($strip ? '' : PHP_EOL), $text);

        if ($strip)
        {
            $text = preg_replace('/\s+/uS', ' ', str_replace(["\r", "\n"], " ", $text));
            $text = str_replace("> <", "><", $text);
        }

        $this->body .= $text;
    }

    /**
     * Append PHP code to template body
     *
     * @param string $code
     * @param string $source
     * @return void
     */
    private function appendCode(string $code = null, string $source)
    {
        if (! $code)
            return;

        $this->line += substr_count($source, "\n");
        $this->body .= "<?php\n/* {$this->baseName}:{$this->line}: {$source} */\n $code ?>";

    }

    /**
     * Ignoring tag
     *
     * @param $tagName
     * @return void
     */
    public function ignore(string $name)
    {
        $this->ignore = $name;
    }

    /**
     * Add post compile
     *
     * @param callable[] $cb
     * @return void
     */
    public function addPostCompile(string $cb)
    {
        $this->postCompiles[] = $cb;
    }

    /**
     * Get the PHP template code
     *
     * @return string
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * Get PHP code for saving to file
     *
     * @return string
     */
    public function getTemplateCode()
    {
        $before = $this->before ? implode("\n", $this->before) . "\n" : "";

        $code = "<?php \n" .
        "/** Brio template '" . $this->baseName . "' compiled at " . date('Y-m-d H:i:s') . " */\n" .
        $before .
        "return new placer\\brio\\engine\\Render(\$brio, " . $this->getClosureSource() . ", array(\n" .
        "\t'options' => {$this->options},\n" .
        "\t'name'    => " . var_export($this->baseName, true) . ",\n" .
        "\t'time'    => {$this->cmplTimestamp},\n" .
        "\t'depends' => " . var_export($this->dependencies, true) . ",\n" .
        "\t'macros'  => " . $this->getMacrosArray() . ",\n ));\n";

        return $code;
    }

    /**
     * Make array with macros code
     *
     * @return string
     */
    private function getMacrosArray()
    {
        if ($this->macros)
        {
            $macros = [];

            foreach ($this->macros as $name => $m)
            {
                if ($m["recursive"])
                {
                    $macros[] = "\t\t'" . $name . "' => function (\$var, \$tpl) {\n?>" . $m["body"] . "<?php\n}";
                }
            }
            return "array(\n" . implode(",\n", $macros) . ")";
        }
        return 'array()';
    }

    /**
     * Get closure code
     *
     * @return string
     */
    private function getClosureSource()
    {
        return "function (\$var, \$tpl) {\n?>{$this->body}<?php\n}";
    }

    /**
     * Runtime execute template
     *
     * @param array $values input values
     * @throws CompileException
     * @return Render
     */
    public function display(array $values)
    {
        if (! $this->callableCode)
        {
            eval(
                "\$this->callableCode = " . $this->getClosureSource() . ";
                \n\$this->internalMacros = " . $this->getMacrosArray() . ';'
            );

            if (! $this->callableCode)
                throw new CompileException("Fatal error while creating the template");
        }
        return parent::display($values);
    }

    /**
     * Add dependency
     *
     * @param Render $tpl
     */
    public function addDepend(Render $tpl)
    {
        $this->dependencies[$tpl->getName()] = $tpl->getTime();
    }

    /**
     * Output the value
     *
     * @param string $data
     * @param null|bool $escape
     * @return string
     */
    public function out(string $data, $escape = null)
    {
        if ($escape === null)
        {
            $escape = $this->options & Brio::AUTO_ESCAPE;
        }

        if ($escape)
            return "echo htmlspecialchars($data, ENT_COMPAT, " . var_export(Brio::$charset, true) . ");";

        return "echo $data;";
    }

    /**
     * Import block from another template
     *
     * @param string $tpl
     */
    public function importBlocks(string $tpl)
    {
        $dependency = $this->brio->compile($tpl, false);

        foreach ($dependency->blocks as $name => $body)
        {
            if (! isset($this->blocks[$name]))
            {
                $body['import'] = $this->getName();
                $this->blocks[$name] = $body;
            }
        }
        $this->addDepend($dependency);
    }

    /**
     * Extends the template
     *
     * @param string $tpl
     * @return Template parent
     */
    public function extend(string $tpl)
    {
        if (! $this->isStageDone(self::COMPILE_STAGE_PARSED))
        {
            $this->compile();
        }

        $parent = $this->brio->getRawTemplate()->load($tpl, false);

        $parent->blocks   = &$this->blocks;
        $parent->macros   = &$this->macros;
        $parent->before   = &$this->before;
        $parent->extended = $this->getName();

        if (! $this->extStack)
        {
            $this->extStack[] = $this->getName();
        }

        $this->extStack[] = $parent->getName();
        $parent->options  = $this->options;
        $parent->extStack = $this->extStack;

        $parent->compile();

        $this->body = $parent->body;
        $this->source = $parent->source;
        $this->addDepend($parent);

        return $parent;
    }

    /**
     * Tag router
     *
     * @param Tokenizer $tokens
     * @throws SecurityException
     * @throws CompileException
     * @return string executable PHP code
     */
    public function parseTag(Tokenizer $tokens)
    {
        try
        {
            if ($tokens->is(Tokenizer::MACRO_STRING))
                return $this->parseAct($tokens);

            if ($tokens->is('/'))
                return $this->parseEndTag($tokens);

            return $this->out($this->parseExpr($tokens));
        }
        catch (InvalidUsageException $e)
        {
            throw new CompileException(
                $e->getMessage() . " in {$this->baseName} line {$this->line}", 0, E_ERROR, $this->baseName, $this->line, $e
            );
        }
        catch (LogicException $e)
        {
            throw new SecurityException(
                $e->getMessage() . " in {$this->baseName} line {$this->line}, near '{" . $tokens->getSnippetAsString(0, 0) . "' <- there", 0, E_ERROR, $this->baseName, $this->line, $e
            );
        }
        catch (Exception $e)
        {
            throw new CompileException(
                $e->getMessage() . " in {$this->baseName} line {$this->line}, near '{" . $tokens->getSnippetAsString(0, 0) . "' <- there", 0, E_ERROR, $this->baseName, $this->line, $e
            );
        }
        catch (Throwable $e)
        {
            throw new CompileException(
                $e->getMessage() . " in {$this->baseName} line {$this->line}, near '{" . $tokens->getSnippetAsString(0, 0) . "' <- there", 0, E_ERROR, $this->baseName, $this->line, $e
            );
        }
    }

    /**
     * Close tag handler
     *
     * @param Tokenizer $tokens
     * @return string
     * @throws TokenizeException
     */
    public function parseEndTag(Tokenizer $tokens)
    {
        $name = $tokens->getNext(Tokenizer::MACRO_STRING);

        $tokens->next();

        if (! $this->callStack)
        {
            throw new TokenizeException(
                "Unexpected closing of the tag '$name', the tag hasn't been opened"
            );
        }

        $tag = array_pop($this->callStack);

        if ($tag->name !== $name)
        {
            throw new TokenizeException(
                "Unexpected closing of the tag '$name' (expecting closing of the tag {$tag->name}, opened in line {$tag->line})"
            );
        }
        return $tag->end($tokens);
    }

    /**
     * Parse action {action ...} or {action(...) ...}
     *
     * @param Tokenizer $tokens
     * @throws LogicException
     * @throws RuntimeException
     * @throws TokenizeException
     * @return string
     */
    public function parseAct(Tokenizer $tokens)
    {
        $action = $tokens->get(Tokenizer::MACRO_STRING);

        $tokens->next();

        if ($tokens->is("(", T_DOUBLE_COLON, T_NS_SEPARATOR) && !$tokens->isWhiteSpaced())
        {
            $tokens->back();

            return $this->out($this->parseExpr($tokens));
        }
        elseif ($tokens->is('.'))
        {
            $name = $tokens->skip()->get(Tokenizer::MACRO_STRING);

            if ($action !== "macro")
            {
                $name = $action . "." . $name;
            }
            return $this->parseMacroCall($tokens, $name);
        }

        if ($info = $this->brio->getTag($action, $this))
        {
            $tag = new Tag($action, $this, $info, $this->body);

            if ($tokens->is(':'))
            {
                do
                {
                    $tag->tagOption($tokens->next()->need(T_STRING)->getAndNext());
                }
                while ($tokens->is(':'));
            }

            $code = $tag->start($tokens);

            if ($tag->isClosed())
            {
                $tag->restoreAll();
            }
            else
            {
                array_push($this->callStack, $tag);
            }
            return $code;
        }

        for ($j = $i = count($this->callStack) - 1; $i >= 0; $i--)
        {
            if ($this->callStack[$i]->hasTag($action, $j - $i))
                return $this->callStack[$i]->tag($action, $tokens);
        }

        if ($tags = $this->brio->getTagKeys($action))
        {
            throw new TokenizeException(
                "Unexpected tag '$action' (this tag can be used with '" . implode("', '", $tags) . "')"
            );
        }
        else
        {
            throw new TokenizeException("Unexpected tag '$action'");
        }
    }

    /**
     * Get current template line
     *
     * @return int
     */
    public function getLine()
    {
        return $this->line;
    }

    /**
     * Parse expressions
     *
     * @param Tokenizer $tokens
     * @param bool $isvar
     * @throws Exception
     * @return string
     */
    public function parseExpr(Tokenizer $tokens, &$isvar = false)
    {
        $exp  = [];
        $var  = false;
        $op   = false;
        $cond = false;

        while ($tokens->valid())
        {
            $term = $this->parseTerm($tokens, $var, -1);

            if ($term !== false)
            {
                if ($tokens->is('?', '!'))
                {
                    if ($cond)
                    {
                        $term = array_pop($exp) . ' ' . $term;
                        $term = '(' . array_pop($exp) . ' ' . $term . ')';
                        $var  = false;
                    }
                    $term = $this->parseTernary($tokens, $term, $var);
                    $var  = false;
                }
                $exp[] = $term;
                $op    = false;
            }
            else
            {
                break;
            }

            if (! $tokens->valid())
            {
                break;
            }

            if ($tokens->is(Tokenizer::MACRO_BINARY))
            {
                if ($tokens->is(Tokenizer::MACRO_COND))
                {
                    if ($cond)
                    {
                        break;
                    }
                    $cond = true;
                }
                elseif ($tokens->is(Tokenizer::MACRO_BOOLEAN))
                {
                    $cond = false;
                }
                $op = $tokens->getAndNext();
            }
            elseif ($tokens->is(Tokenizer::MACRO_EQUALS, '['))
            {
                if (! $var)
                {
                    break;
                }

                $op = $tokens->getAndNext();

                if ($op == '[')
                {
                    $tokens->need(']')->next()->need('=')->next();
                    $op = '[]=';
                }
            }
            elseif ($tokens->is(T_STRING))
            {
                if (! $exp)
                {
                    break;
                }

                $operator = $tokens->current();

                if ($operator == "is")
                {
                    $item  = array_pop($exp);
                    $exp[] = $this->parseIs($tokens, $item, $var);
                }
                elseif ($operator == "in" || ($operator == "not" && $tokens->isNextToken("in")))
                {
                    $item  = array_pop($exp);
                    $exp[] = $this->parseIn($tokens, $item);
                }
                else
                {
                    break;
                }
            }
            elseif ($tokens->is('~'))
            {
                if ($tokens->isNext('='))
                {
                    $exp[] = ".=";
                    $tokens->next()->next();
                }
                else
                {
                    $concat = [array_pop($exp)];

                    while ($tokens->is('~'))
                    {
                        $tokens->next();

                        if ($tokens->is(T_LNUMBER, T_DNUMBER))
                        {
                            $concat[] = "strval(" . $this->parseTerm($tokens) . ")";
                        }
                        else
                        {
                            if ($tokens->is('~'))
                            {
                                $tokens->next();
                                $concat[] = "' '";
                            }

                            if (! $term2 = "strval(" . $this->parseTerm($tokens) . ")")
                            {
                                throw new UnexpectedTokenException($tokens);
                            }
                            $concat[] = $term2;
                        }
                    }
                    $exp[] = "(" . implode(".", $concat) . ")";
                }
            }
            else
            {
                break;
            }

            if ($op)
            {
                $exp[] = $op;
            }
        }

        if ($op || ! $exp)
        {
            throw new UnexpectedTokenException($tokens);
        }

        if (count($exp) == 1 && $var)
        {
            $isvar = true;
        }

        return implode(' ', $exp);
    }

    /**
     * Parse any term of expression: -2, ++$var, 'adf'
     *
     * @param Tokenizer $tokens
     * @param bool $isvar is parsed term - plain variable
     * @param int $allows
     * @throws Exception
     * @return bool|string
     */
    public function parseTerm(Tokenizer $tokens, &$isvar = false, $allows = -1)
    {
        $isvar = false;

        if ($tokens->is(Tokenizer::MACRO_UNARY))
        {
            $unary = $tokens->getAndNext();
        }
        else
        {
            $unary = "";
        }

        switch ($tokens->key())
        {
            case T_LNUMBER:
            case T_DNUMBER:
                $code = $unary . $this->parseScalar($tokens);
                break;
            case T_CONSTANT_ENCAPSED_STRING:
            case '"':
            case T_ENCAPSED_AND_WHITESPACE:
                if ($unary)
                {
                    throw new UnexpectedTokenException($tokens->back());
                }
                $code = $this->parseScalar($tokens);
                break;
            case '$':
                $code = $this->parseAccessor($tokens, $isvar);
                if (! $isvar)
                {
                    if ($tokens->is(T_OBJECT_OPERATOR))
                    {
                        if ($this->options & Brio::DENY_METHODS)
                        {
                            throw new LogicException("Forbidden to call methods");
                        }
                        $code = $unary . $this->parseChain($tokens, $code);
                    }
                    else
                    {
                        $code = $unary . $code;
                    }
                    break;
                }
            case T_VARIABLE:
                if (! isset($code))
                {
                    $code = $this->parseVariable($tokens);
                }
                if ($tokens->is("(") && $tokens->hasBackList(T_STRING, T_OBJECT_OPERATOR))
                {
                    if ($this->options & Brio::DENY_METHODS)
                    {
                        throw new LogicException("Forbidden to call methods");
                    }
                    $code = $unary . $this->parseChain($tokens, $code);
                }
                elseif ($tokens->is(Tokenizer::MACRO_INCDEC))
                {
                    if ($this->options & Brio::FORCE_VERIFY)
                    {
                        $code = $unary . '(isset(' . $code . ') ? ' . $code . $tokens->getAndNext() . ' : null)';
                    }
                    else
                    {
                        $code = $unary . $code . $tokens->getAndNext();
                    }
                }
                else
                {
                    if ($this->options & Brio::FORCE_VERIFY)
                    {
                        $code = $unary . '(isset(' . $code . ') ? ' . $code . ' : null)';
                    }
                    else
                    {
                        $isvar = true;
                        $code  = $unary . $code;
                    }
                }
                break;
            case T_DEC:
            case T_INC:
                if ($this->options & Brio::FORCE_VERIFY)
                {
                    $var  = $this->parseVariable($tokens);
                    $code = $unary . '(isset(' . $var . ') ? ' . $tokens->getAndNext() . $this->parseVariable($tokens) . ' : null)';
                }
                else
                {
                    $code = $unary . $tokens->getAndNext() . $this->parseVariable($tokens);
                }
                break;
            case '(':
                $tokens->next();
                $code = $unary . "(" . $this->parseExpr($tokens) . ")";
                $tokens->need(")")->next();
                break;
            case T_STRING:
                if ($tokens->isSpecialVal())
                {
                    $code = $unary . $tokens->getAndNext();
                }
                elseif ($tokens->isNext("(") && ! $tokens->getWhitespace())
                {
                    $func = $this->brio->getModifier($modifier = $tokens->current(), $this);
                    if (! $func) {
                        throw new Exception("Function " . $tokens->getAndNext() . " not found");
                    }
                    if (! is_string($func))
                    {
                        $call = 'call_user_func_array($tpl->getStorage()->getModifier("' . $modifier . '"), array  ' . $this->parseArgs($tokens->next()) . ')';
                    }
                    else
                    {
                        $call = $func . $this->parseArgs($tokens->next());
                    }
                    $code = $unary . $this->parseChain($tokens, $call);
                }
                elseif ($tokens->isNext(T_NS_SEPARATOR, T_DOUBLE_COLON))
                {
                    $method = $this->parseStatic($tokens);
                    $args   = $this->parseArgs($tokens);
                    $code   = $unary . $this->parseChain($tokens, $method . $args);
                }
                else
                {
                    return false;
                }
                break;
            case T_ISSET:
            case T_EMPTY:
                $func = $tokens->getAndNext();
                if ($tokens->is("(") && $tokens->isNext(T_VARIABLE))
                {
                    $code = $unary . $func . "(" . $this->parseVariable($tokens->next()) . ")";
                    $tokens->need(')')->next();
                }
                else
                {
                    throw new TokenizeException(
                        "Unexpected token " . $tokens->getNext() . ", isset() and empty() accept only variables"
                    );
                }
                break;
            case '[':
                if ($unary)
                {
                    throw new UnexpectedTokenException($tokens->back());
                }
                $code = $this->parseArray($tokens);
                break;
            default:
                if ($unary)
                {
                    throw new UnexpectedTokenException($tokens->back());
                }
                else
                {
                    return false;
                }
        }

        if (($allows & self::TERM_MODS) && $tokens->is('|'))
        {
            $code  = $this->parseModifier($tokens, $code);
            $isvar = false;
        }

        if (($allows & self::TERM_RANGE) && $tokens->is('.') && $tokens->isNext('.'))
        {
            $tokens->next()->next();
            $code  = '(new \placer\brio\engine\RangeIterator(' . $code . ', ' . $this->parseTerm($tokens, $var, self::TERM_MODS) . '))';
            $isvar = false;
        }
        return $code;
    }

    /**
     * Parse call-chunks: $var->func()->func()->prop->func()->...
     *
     * @param Tokenizer $tokens
     * @param string $code start point (it is $var)
     * @return string
     */
    public function parseChain(Tokenizer $tokens, string $code)
    {
        do
        {
            if ($tokens->is('('))
            {
                $code .= $this->parseArgs($tokens);
            }

            if ($tokens->is(T_OBJECT_OPERATOR) && $tokens->isNext(T_STRING))
            {
                $code .= '->' . $tokens->next()->getAndNext();
            }
        }
        while ($tokens->is('(', T_OBJECT_OPERATOR));

        return $code;
    }

    /**
     * Parse variable name: $a, $a.b, $a.b['c'], $a:index
     *
     * @param Tokenizer $tokens
     * @param $var
     * @return string
     * @throws UnexpectedTokenException
     */
    public function parseVariable(Tokenizer $tokens, string $var = null)
    {
        if (! $var)
        {
            if ($tokens->isNext('@'))
            {
                $prop = $tokens->next()->next()->get(T_STRING);

                if ($tag = $this->getParentScope("foreach"))
                {
                    $tokens->next();
                    return Compiler::foreachProp($tag, $prop);
                }
                else
                {
                    throw new UnexpectedTokenException($tokens);
                }
            }
            else
            {
                $var = '$var["' . substr($tokens->get(T_VARIABLE), 1) . '"]';
                $tokens->next();
            }
        }

        while ($t = $tokens->key())
        {
            if ($t === ".")
            {
                $tokens->next();

                if ($tokens->is(T_VARIABLE))
                {
                    $key = '[ $var["' . substr($tokens->getAndNext(), 1) . '"] ]';
                }
                elseif ($tokens->is(Tokenizer::MACRO_STRING))
                {
                    $key = '["' . $tokens->getAndNext() . '"]';
                }
                elseif ($tokens->is(Tokenizer::MACRO_SCALAR))
                {
                    $key = "[" . $tokens->getAndNext() . "]";
                }
                elseif ($tokens->is('"'))
                {
                    $key = "[" . $this->parseQuote($tokens) . "]";
                }
                elseif ($tokens->is('.'))
                {
                    $tokens->back();
                    break;
                }
                else
                {
                    throw new UnexpectedTokenException($tokens);
                }

                $var .= $key;
            }
            elseif ($t === "[")
            {
                if ($tokens->isNext(']'))
                {
                    break;
                }

                $tokens->next();

                if ($tokens->is(Tokenizer::MACRO_STRING))
                {
                    if ($tokens->isNext("("))
                    {
                        $key = "[" . $this->parseExpr($tokens) . "]";
                    }
                    else
                    {
                        $key = '["' . $tokens->current() . '"]';
                        $tokens->next();
                    }
                }
                else
                {
                    $key = "[" . $this->parseExpr($tokens) . "]";
                }

                $tokens->get("]");
                $tokens->next();
                $var .= $key;

            }
            elseif ($t === T_DNUMBER)
            {
                $var .= '[' . substr($tokens->getAndNext(), 1) . ']';
            }
            elseif ($t === T_OBJECT_OPERATOR)
            {
                $var .= "->" . $tokens->getNext(T_STRING);
                $tokens->next();
            }
            else
            {
                break;
            }
        }

        return $var;
    }

    /**
     * Parse accessor
     *
     * @param Tokenizer $tokens
     * @param bool $isvar
     * @return string
     */
    public function parseAccessor(Tokenizer $tokens, &$isvar = false)
    {
        $accessor = $tokens->need('$')->next()->need('.')->next()->current();
        $parser   = $this->getStorage()->getAccessor($accessor);
        $isvar    = false;

        if ($parser)
        {
            if (is_array($parser))
            {
                if (isset($parser['callback']))
                {
                    $tokens->next();
                    return 'call_user_func($tpl->getStorage()->getAccessor(' . var_export($accessor, true) .
                    ', "callback"), ' . var_export($accessor, true) . ', $tpl, $var)';
                }
                else
                {
                    return call_user_func_array(
                        $parser['parser'],
                        [
                            $parser['accessor'],
                            $tokens->next(),
                            $this,
                            &$isvar
                        ]
                    );
                }
            }
            else
            {
                return call_user_func_array($parser, [$tokens->next(), $this, &$isvar]);
            }
        }
        else
        {
            throw new RuntimeException("Unknown accessor '\$.$accessor'");
        }
    }

    /**
     * Parse ternary operator
     *
     * @param Tokenizer $tokens
     * @param string $var
     * @param bool $isvar
     * @return string
     * @throws UnexpectedTokenException
     */
    public function parseTernary(Tokenizer $tokens, string $var, bool $isvar)
    {
        $empty = $tokens->is('?');

        $tokens->next();

        if ($tokens->is(":", "?"))
        {
            $tokens->next();

            if ($empty)
            {
                if ($isvar)
                    return '(empty(' . $var . ') ? (' . $this->parseExpr($tokens) . ') : ' . $var . ')';

                return '(' . $var . ' ?: (' . $this->parseExpr($tokens) . '))';
            }
            else
            {
                if ($isvar)
                    return '(isset(' . $var . ') ? ' . $var . ' : (' . $this->parseExpr($tokens) . '))';

                return '((' . $var . ' !== null) ? ' . $var . ' : (' . $this->parseExpr($tokens) . '))';
            }
        }
        elseif ($tokens->is(Tokenizer::MACRO_BINARY, Tokenizer::MACRO_BOOLEAN,Tokenizer::MACRO_MATH) || ! $tokens->valid())
        {
            if ($empty)
            {
                if ($isvar)
                    return '!empty(' . $var . ')';

                return '(' . $var . ')';
            }
            else
            {
                if ($isvar)
                    return 'isset(' . $var . ')';

                return '(' . $var . ' !== null)';
            }
        }
        else
        {
            $expr1 = $this->parseExpr($tokens);
            $tokens->need(':')->skip();
            $expr2 = $this->parseExpr($tokens);

            if ($empty)
            {
                if ($isvar)
                    return '(empty(' . $var . ') ? ' . $expr2 . ' : ' . $expr1 . ')';

                return '(' . $var . ' ? ' . $expr1 . ' : ' . $expr2 . ')';
            }
            else
            {
                if ($isvar)
                    return '(isset(' . $var . ') ? ' . $expr1 . ' : ' . $expr2 . ')';

                return '((' . $var . ' !== null) ? ' . $expr1 . ' : ' . $expr2 . ')';
            }
        }
    }

    /**
     * Parse 'is' and 'is not' operators
     *
     * @param Tokenizer $tokens
     * @param string $value
     * @param bool $variable
     * @throws InvalidUsageException
     * @return string
     */
    public function parseIs(Tokenizer $tokens, string$value, $variable = false)
    {
        $tokens->next();

        if ($tokens->current() == 'not')
        {
            $invert = '!';
            $equal  = '!=';
            $tokens->next();
        }
        else
        {
            $invert = '';
            $equal  = '==';
        }

        if ($tokens->is(Tokenizer::MACRO_STRING))
        {
            $action = $tokens->current();

            if (! $variable && ($action == "set" || $action == "empty"))
            {
                $action = "_$action";
                $tokens->next();
                return $invert . sprintf($this->brio->getCheckFunctions($action), $value);
            }
            elseif ($test = $this->brio->getCheckFunctions($action))
            {
                $tokens->next();
                return $invert . sprintf($test, $value);
            }
            elseif ($tokens->isSpecialVal())
            {
                $tokens->next();
                return '(' . $value . ' ' . $equal . '= ' . $action . ')';
            }
            return $invert . '(' . $value . ' instanceof \\' . $this->parseName($tokens) . ')';
        }
        elseif ($tokens->is(T_VARIABLE, '[', Tokenizer::MACRO_SCALAR, '"'))
        {
            return '(' . $value . ' ' . $equal . '= ' . $this->parseTerm($tokens) . ')';
        }
        elseif ($tokens->is(T_NS_SEPARATOR)) {
            return $invert . '(' . $value . ' instanceof \\' . $this->parseName($tokens) . ')';
        }
        else
        {
            throw new InvalidUsageException("Unknown argument");
        }
    }

    /**
     * Parse 'in' and 'not in' operators
     *
     * @param Tokenizer $tokens
     * @param string $value
     * @throws InvalidUsageException
     * @throws UnexpectedTokenException
     * @return string
     */
    public function parseIn(Tokenizer $tokens, string $value)
    {
        $checkers = [
            "string" => 'is_int(strpos(%2$s, %1$s))',
            "list"   => "in_array(%s, %s)",
            "keys"   => "array_key_exists(%s, %s)",
            "auto"   => '\placer\brio\engine\Modifier::in(%s, %s)'
        ];

        $checker  = null;
        $invert   = '';

        if ($tokens->current() == 'not')
        {
            $invert = '!';
            $tokens->next();
        }

        if ($tokens->current() !== "in")
            throw new UnexpectedTokenException($tokens);

        $tokens->next();

        if ($tokens->is(Tokenizer::MACRO_STRING))
        {
            $checker = $tokens->current();

            if (! isset($checkers[$checker]))
                throw new UnexpectedTokenException($tokens);

            $tokens->next();
        }

        if ($tokens->is('['))
        {
            if ($checker == "string")
            {
                throw new InvalidUsageException("Can not use string operation for array");
            }
            elseif (! $checker)
            {
                $checker = "list";
            }
            return $invert . sprintf($checkers[$checker], $value, $this->parseArray($tokens));
        }
        elseif ($tokens->is('"', T_ENCAPSED_AND_WHITESPACE, T_CONSTANT_ENCAPSED_STRING))
        {
            if (! $checker) {
                $checker = "string";
            }
            elseif ($checker != "string")
            {
                throw new InvalidUsageException("Can not use array operation for string");
            }
            return $invert . sprintf($checkers[$checker], "strval($value)", $this->parseScalar($tokens));
        }
        elseif ($tokens->is(T_VARIABLE, Tokenizer::MACRO_INCDEC))
        {
            if (! $checker)
            {
                $checker = "auto";
            }
            return $invert . sprintf($checkers[$checker], $value, $this->parseTerm($tokens));
        }
        else
        {
            throw new UnexpectedTokenException($tokens);
        }
    }

    /**
     * Parse method, class or constant name
     *
     * @param Tokenizer $tokens
     * @return string
     */
    public function parseName(Tokenizer $tokens)
    {
        $tokens->skipIf(T_NS_SEPARATOR);

        $name = '';

        if ($tokens->is(T_STRING))
        {
            $name .= $tokens->getAndNext();

            while ($tokens->is(T_NS_SEPARATOR))
            {
                $name .= '\\' . $tokens->next()->get(T_STRING);
                $tokens->next();
            }
        }
        return $name;
    }

    /**
     * Parse scalar values
     *
     * @param Tokenizer $tokens
     * @throws UnexpectedTokenException
     * @return string
     */
    public function parseScalar(Tokenizer $tokens)
    {
        $token = $tokens->key();

        switch ($token)
        {
            case T_CONSTANT_ENCAPSED_STRING:
            case T_LNUMBER:
            case T_DNUMBER:
                return $tokens->getAndNext();
            case T_ENCAPSED_AND_WHITESPACE:
            case '"':
                return $this->parseQuote($tokens);
            default:
                throw new UnexpectedTokenException($tokens);
        }
    }

    /**
     * Parse string with or without variable
     *
     * @param Tokenizer $tokens
     * @throws UnexpectedTokenException
     * @return string
     */
    public function parseQuote(Tokenizer $tokens)
    {
        if ($tokens->is('"'))
        {
            $stop = $tokens->current();
            $str = '"';

            $tokens->next();

            while ($t = $tokens->key())
            {
                if ($t === T_ENCAPSED_AND_WHITESPACE)
                {
                    $str .= $tokens->current();
                    $tokens->next();
                }
                elseif ($t === T_VARIABLE)
                {
                    if (strlen($str) > 1)
                    {
                        $str .= '".';
                    }
                    else
                    {
                        $str = "";
                    }

                    $str .= '$var["' . substr($tokens->current(), 1) . '"]';

                    $tokens->next();

                    if ($tokens->is($stop))
                    {
                        $tokens->skip();
                        return $str;
                    }
                    else
                    {
                        $str .= '."';
                    }
                }
                elseif ($t === T_CURLY_OPEN)
                {
                    if (strlen($str) > 1)
                    {
                        $str .= '".';
                    }
                    else
                    {
                        $str = "";
                    }

                    $tokens->getNext(T_VARIABLE);

                    $str .= '(' . $this->parseExpr($tokens) . ')';

                    if ($tokens->is($stop))
                    {
                        $tokens->next();
                        return $str;
                    }
                    else
                    {
                        $str .= '."';
                    }
                }
                elseif ($t === "}")
                {
                    $tokens->next();
                }
                elseif ($t === $stop)
                {
                    $tokens->next();
                    return $str . '"';
                }
                else
                {
                    break;
                }
            }
            throw new UnexpectedTokenException($tokens);
        }
        elseif ($tokens->is(T_CONSTANT_ENCAPSED_STRING))
        {
            return $tokens->getAndNext();
        }
        elseif ($tokens->is(T_ENCAPSED_AND_WHITESPACE))
        {
            throw new UnexpectedTokenException($tokens);
        }
        else
        {
            return "";
        }
    }

    /**
     * Parse modifiers
     *
     * @param Tokenizer $tokens
     * @param string $value
     * @throws LogicException
     * @throws Exception
     * @return string
     */
    public function parseModifier(Tokenizer $tokens, string $value)
    {
        while ($tokens->is("|"))
        {
            $modifier = $tokens->getNext(Tokenizer::MACRO_STRING);

            if ($tokens->isNext(T_DOUBLE_COLON, T_NS_SEPARATOR))
            {
                $mods = $this->parseStatic($tokens);
            }
            else
            {
                $mods = $this->brio->getModifier($modifier, $this);

                if (! $mods)
                    throw new \Exception("Modifier " . $tokens->current() . " not found");

                $tokens->next();
            }

            $args = [];

            while ($tokens->is(":"))
            {
                if (($args[] = $this->parseTerm($tokens->next(), $isvar, 0)) === false)
                    throw new UnexpectedTokenException($tokens);
            }

            if (! is_string($mods))
            {
                $mods = 'call_user_func($tpl->getStorage()->getModifier("' . $modifier . '"), ';
            }
            else
            {
                $mods .= "(";
            }

            if ($args)
            {
                $value = $mods . $value . ', ' . implode(", ", $args) . ')';
            }
            else
            {
                $value = $mods . $value . ')';
            }
        }
        return $value;
    }

    /**
     * Parse array
     *
     * @param Tokenizer $tokens
     * @param int $count amount of elements
     * @throws UnexpectedTokenException
     * @return string
     */
    public function parseArray(Tokenizer $tokens, &$count = 0)
    {
        if ($tokens->is("["))
        {
            $arr = [];
            $tokens->next();

            while ($tokens->valid())
            {
                if ($tokens->is(']'))
                {
                    $tokens->next();
                    return '[' . implode(', ', $arr) . ']';
                }

                if ($tokens->is('['))
                {
                    $arr[] = $this->parseArray($tokens);
                    $count++;
                }
                else
                {
                    $expr = $this->parseExpr($tokens);

                    if ($tokens->is(T_DOUBLE_ARROW))
                    {
                        $tokens->next();
                        $arr[] = $expr . ' => ' . $this->parseExpr($tokens);
                    }
                    else
                    {
                        $arr[] = $expr;
                    }
                    $count++;
                }

                if ($tokens->is(','))
                {
                    $tokens->next();
                }
            }
        }

        throw new UnexpectedTokenException($tokens);
    }

    /**
     * Parse macro
     *
     * @param Tokenizer $tokens
     * @param string $name
     * @return string
     * @throws InvalidUsageException
     */
    public function parseMacroCall(Tokenizer $tokens, string $name)
    {
        $recursive = false;
        $macro     = false;

        if (isset($this->macros[$name]))
        {
            $macro     = $this->macros[$name];
            $recursive = $macro['recursive'];
        }
        else
        {
            foreach ($this->callStack as $scope)
            {
                if ($scope->name == 'macro' && $scope['name'] == $name)
                {
                    $recursive = $scope;
                    $macro     = $scope['macro'];
                    break;
                }
            }

            if (! $macro)
                throw new InvalidUsageException("Undefined macro '$name'");
        }

        $tokens->next();

        $params = $this->parseParams($tokens);
        $args   = [];

        foreach ($macro['args'] as $arg)
        {
            if (isset($params[$arg]))
            {
                $args[$arg] = $params[$arg];
            }
            elseif (isset($macro['defaults'][$arg]))
            {
                $args[$arg] = $macro['defaults'][$arg];
            }
            else
            {
                throw new InvalidUsageException("Macro '$name' require '$arg' argument");
            }
        }

        if ($recursive)
        {
            if ($recursive instanceof Tag)
            {
                $recursive['recursive'] = true;
            }
            return '$tpl->getMacro("' . $name . '")->__invoke(' . Compiler::toArray($args) . ', $tpl);';
        }
        else
        {
            $vars = $this->tmpVar();

            return $vars . ' = $var; $var = ' . Compiler::toArray($args) . ';' . PHP_EOL . '?>' .
                $macro["body"] . '<?php' . PHP_EOL . '$var = ' . $vars . '; unset(' . $vars . ');';
        }
    }

    /**
     * Parse static
     *
     * @param Tokenizer $tokens
     * @throws LogicException
     * @throws RuntimeException
     * @return string
     */
    public function parseStatic(Tokenizer $tokens)
    {
        $tokens->skipIf(T_NS_SEPARATOR);

        $name = '';

        if ($tokens->is(T_STRING))
        {
            $name .= $tokens->getAndNext();

            while ($tokens->is(T_NS_SEPARATOR))
            {
                $name .= '\\' . $tokens->next()->get(T_STRING);
                $tokens->next();
            }
        }

        $tokens->need(T_DOUBLE_COLON)->next()->need(T_STRING);

        $static = $name . "::" . $tokens->getAndNext();

        if (! is_callable($static))
            throw new RuntimeException("Method $static doesn't exist");

        return $static;
    }

    /**
     * Parse argument list
     *
     * @param Tokenizer $tokens
     * @return string
     */
    public function parseArgs(Tokenizer $tokens)
    {
        $args = "(";
        $tokens->next();
        $arg = $colon = false;

        while ($tokens->valid())
        {
            if (! $arg &&
                $tokens->is(
                    T_VARIABLE, T_STRING, "$", "(", Tokenizer::MACRO_SCALAR, '"', Tokenizer::MACRO_UNARY, Tokenizer::MACRO_INCDEC
                ))
            {
                $args .= $this->parseExpr($tokens);
                $arg   = true;
                $colon = false;
            }
            elseif (! $arg && $tokens->is('['))
            {
                $args .= $this->parseArray($tokens);
                $arg   = true;
                $colon = false;
            }
            elseif ($arg && $tokens->is(','))
            {
                $args .= $tokens->getAndNext() . ' ';
                $arg   = false;
                $colon = true;
            }
            elseif (! $colon && $tokens->is(')'))
            {
                $tokens->next();
                return $args . ')';
            }
            else
            {
                break;
            }
        }

        throw new TokenizeException(
            "Unexpected token '" . $tokens->current() . "' in argument list"
        );
    }

    /**
     * Parse first unnamed argument
     *
     * @param Tokenizer $tokens
     * @param string $static
     * @return mixed|string
     */
    public function parsePlainArg(Tokenizer $tokens, &$static)
    {
        if ($tokens->is(T_CONSTANT_ENCAPSED_STRING))
        {
            if ($tokens->isNext('|'))
                return $this->parseExpr($tokens);

            $str = $tokens->getAndNext();
            $static = stripslashes(substr($str, 1, -1));

            return $str;

        }
        return $this->parseExpr($tokens);
    }


    /**
     * Parse parameters as $key=$value
     *
     * @param Tokenizer $tokens
     * @param array $defaults
     * @throws Exception
     * @return array
     */
    public function parseParams(Tokenizer $tokens, array $defaults = [])
    {
        $params = [];

        while ($tokens->valid())
        {
            if ($tokens->is(Tokenizer::MACRO_STRING))
            {
                $key = $tokens->getAndNext();

                if ($defaults && ! isset($defaults[$key]))
                    throw new InvalidUsageException("Unknown parameter '$key'");

                if ($tokens->is("="))
                {
                    $tokens->next();
                    $params[$key] = $this->parseExpr($tokens);
                }
                else
                {
                    throw new InvalidUsageException("Invalid value for parameter '$key'");
                }
            }
            elseif ($tokens->is(Tokenizer::MACRO_SCALAR, '"', T_VARIABLE, "[", '('))
            {
                $params[] = $this->parseExpr($tokens);
            }
            else
            {
                break;
            }
        }

        if ($defaults)
        {
            $params += $defaults;
        }

        return $params;
    }

}
