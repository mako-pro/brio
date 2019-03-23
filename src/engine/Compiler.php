<?php

namespace placer\brio\engine;

use Traversable;
use LogicException;
use RuntimeException;
use ReflectionFunction;

use placer\brio\Brio;
use placer\brio\engine\error\CompileException;
use placer\brio\engine\error\InvalidUsageException;
use placer\brio\engine\error\UnexpectedTokenException;

class Compiler
{
    /**
     * Tag {include ...}
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @throws LogicException
     * @return string
     */
    public static function tagInclude(Tokenizer $tokens, Tag $tag)
    {
        $tpl   = $tag->tpl;
        $name  = false;
        $cname = $tpl->parsePlainArg($tokens, $name);
        $pp    = $tpl->parseParams($tokens);

        if ($name)
        {
            if ($tpl->getStorage()->getOptions() & Brio::FORCE_INCLUDE)
            {
                $template = $tpl;
                $recursion = false;

                while ($template->parent)
                {
                    if ($template->parent->getName() == $name)
                    {
                        $recursion = true;
                    }
                    $template = $template->parent;
                }

                if (! $recursion)
                {
                    $inc = $tpl->getStorage()->getRawTemplate($tpl);
                    $inc->load($name, true);
                    $tpl->addDepend($inc);
                    $var = $tpl->tmpVar();

                    if ($pp)
                        return $var . ' = $var; $var = ' . static::toArray($pp) . ' + $var; ?>' . $inc->getBody() . '<?php $var = ' . $var . '; unset(' . $var . ');';

                    return $var . ' = $var; ?>' . $inc->getBody() . '<?php $var = ' . $var . '; unset(' . $var . ');';

                }
            }
            elseif (! $tpl->getStorage()->templateExists($name))
            {
                throw new LogicException("Template $name not found");
            }
        }

        if ($pp)
            return '$tpl->getStorage()->getTemplate(' . $cname . ')->display(' . static::toArray($pp) . ' + $var);';

        return '$tpl->getStorage()->getTemplate(' . $cname . ')->display($var);';
    }

    /**
     * Tag {insert ...}
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @throws InvalidUsageException
     * @return string
     */
    public static function tagInsert(Tokenizer $tokens, Tag $tag)
    {
        $tpl = $tag->tpl;
        $tpl->parsePlainArg($tokens, $name);

        if (! $name)
            throw new InvalidUsageException("Tag {insert} accept only static template name");

        $inc = $tpl->getStorage()->compile($name, false);
        $tpl->addDepend($inc);

        return '?>' . $inc->getBody() . '<?php';
    }


    /**
     * Open tag {if ...}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function ifOpen(Tokenizer $tokens, Tag $scope)
    {
        $scope["else"] = false;

        return 'if (' . $scope->tpl->parseExpr($tokens) . ') {';
    }

    /**
     * Tag {elseif ...}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @throws InvalidUsageException
     * @return string
     */
    public static function tagElseIf(Tokenizer $tokens, Tag $scope)
    {
        if ($scope["else"])
            throw new InvalidUsageException('Incorrect use of the tag {elseif}');

        return '} elseif (' . $scope->tpl->parseExpr($tokens) . ') {';
    }

    /**
     * Tag {else}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function tagElse($tokens, Tag $scope)
    {
        $scope["else"] = true;

        return '} else {';
    }

    /**
     * Open tag {foreach ...}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @throws UnexpectedTokenException
     * @throws InvalidUsageException
     * @return string
     */
    public static function foreachOpen(Tokenizer $tokens, Tag $scope)
    {
        $scope["else"]    = false;
        $scope["key"]     = null;
        $scope["prepend"] = "";
        $scope["before"]  = [];
        $scope["after"]   = [];
        $scope["body"]    = [];

        if ($tokens->is('['))
        {
            $count = 0;
            $scope['from']    = $scope->tpl->parseArray($tokens, $count);
            $scope['check']   = $count;
            $scope["var"]     = $scope->tpl->tmpVar();
            $scope['prepend'] = $scope["var"].' = '.$scope['from'].';';
            $scope['from']    = $scope["var"];
        }
        else
        {
            $scope['from'] = $scope->tpl->parseExpr($tokens, $isvar);

            if ($isvar)
            {
                $scope['check'] = '!empty('.$scope['from'].') && (is_array('.$scope['from'].') || '.$scope['from'].' instanceof Traversable)';
            }
            else
            {
                $scope["var"]     = $scope->tpl->tmpVar();
                $scope['prepend'] = $scope["var"].' = '.$scope['from'].';';
                $scope['from']    = $scope["var"];
                $scope['check']   = 'is_array('.$scope['from'].') && count('.$scope['from'].') || ('.$scope['from'].' instanceof Traversable)';
            }
        }

        if ($tokens->is(T_AS))
        {
            $tokens->next();
            $value = $scope->tpl->parseVariable($tokens);

            if ($tokens->is(T_DOUBLE_ARROW))
            {
                $tokens->next();
                $scope["key"]   = $value;
                $scope["value"] = $scope->tpl->parseVariable($tokens);
            }
            else
            {
                $scope["value"] = $value;
            }
        }
        else
        {
            $scope["value"] = '$_un';
        }

        while ($token = $tokens->key())
        {
            $param = $tokens->get(T_STRING);
            $varname = static::foreachProp($scope, $param);
            $tokens->getNext("=");
            $tokens->next();
            $scope['before'][] = $scope->tpl->parseVariable($tokens)." = &". $varname;
        }

        return '';
    }

    /**
     * Tag {foreachelse}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function foreachElse(Tokenizer $tokens, Tag $scope)
    {
        $scope["no-break"] = $scope["no-continue"] = $scope["else"] = true;
        $after = $scope["after"]  ? implode("; ", $scope["after"]) . ";" : "";

        return " {$after} } } else {";
    }

    /**
     * Foreach property
     *
     * @param Tag $scope
     * @param string $prop
     * @throws CompileException
     * @return string
     */
    public static function foreachProp(Tag $scope, string $prop)
    {
        if (empty($scope["props"][$prop]))
        {
            $var_name = $scope["props"][$prop] = $scope->tpl->tmpVar()."_".$prop;

            switch($prop)
            {
                case "index":
                    $scope["before"][] = $var_name . ' = 0';
                    $scope["after"][]  = $var_name . '++';
                    break;
                case "first":
                    $scope["before"][] = $var_name . ' = true';
                    $scope["after"][]  = $var_name . ' && (' . $var_name . ' = false )';
                    break;
                case "last":
                    $scope["before"][] = $var_name . ' = false';
                    $scope["uid"]      = $scope->tpl->tmpVar();
                    $scope["before"][] = $scope["uid"] . " = count({$scope["from"]})";
                    $scope["body"][]   = 'if(!--' . $scope["uid"] . ') ' . $var_name . ' = true';
                    break;
                default:
                    throw new CompileException("Unknown foreach property '$prop'");
            }
        }
        return $scope["props"][$prop];
    }

    /**
     * Close tag {/foreach}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function foreachClose(Tokenizer $tokens, Tag $scope)
    {
        $before = $scope["before"] ? implode("; ", $scope["before"]) . ";" : "";
        $head   = $scope["body"]   ? implode("; ", $scope["body"]) . ";" : "";
        $body   = $scope->getContent();

        if ($scope["key"])
        {
            $code = "<?php {$scope["prepend"]} if({$scope["check"]}) {\n $before foreach({$scope["from"]} as {$scope["key"]} => {$scope["value"]}) { $head?>$body";
        }
        else
        {
            $code = "<?php {$scope["prepend"]} if({$scope["check"]}) {\n $before foreach({$scope["from"]} as {$scope["value"]}) { $head?>$body";
        }

        $scope->replaceContent($code);

        if ($scope["else"])
        {
            return '}';
        }
        else
        {
            $after = $scope["after"]  ? implode("; ", $scope["after"]) . ";" : "";
            return " {$after} } }";
        }
    }

    /**
     * Open tag {while ...}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function whileOpen(Tokenizer $tokens, Tag $scope)
    {
        return 'while (' . $scope->tpl->parseExpr($tokens) . ') {';
    }

    /**
     * Open tag {switch}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function switchOpen(Tokenizer $tokens, Tag $scope)
    {
        $expr             = $scope->tpl->parseExpr($tokens);
        $scope["case"]    = [];
        $scope["last"]    = [];
        $scope["default"] = '';
        $scope["var"]     = $scope->tpl->tmpVar();
        $scope["expr"]    = $scope["var"] . ' = strval(' . $expr . ')';

        return '';
    }

    /**
     * Tag {case ...}
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @return string
     */
    public static function tagCase(Tokenizer $tokens, Tag $tag)
    {
        static::caseResort($tag);

        do
        {
            if ($tokens->is(T_DEFAULT))
            {
                $tag["last"][] = false;
                $tokens->next();
            }
            else
            {
                $tag["last"][] = $tag->tpl->parseScalar($tokens);
            }

            if ($tokens->is(','))
            {
                $tokens->next();
            }
            else
            {
                break;
            }
        }
        while (true);

        return '';
    }


    /**
     * Tag {default}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function tagDefault(Tokenizer $tokens, Tag $scope)
    {
        static::caseResort($scope);
        $scope["last"][] = false;

        return '';
    }

    /**
     * Close tag {switch}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function switchClose(Tokenizer $tokens, Tag $scope)
    {
        static::caseResort($scope);

        $expr    = $scope["var"];
        $code    = $scope["expr"] . ";\n";
        $default = $scope["default"];

        foreach ($scope["case"] as $case => $content)
        {
            if (is_numeric($case))
            {
                $case = "'$case'";
            }
            $code .= "if($expr == $case) {\n?>$content<?php\n} else";
        }
        $code .= " {\n?>$default<?php\n}\nunset(" . $scope["var"] . ")";

        return $code;
    }

    /**
     * Resort cases for {switch}
     *
     * @param Tag $scope
     * @return void
     */
    protected static function caseResort(Tag $scope)
    {
        $content = $scope->cutContent();

        foreach ($scope["last"] as $case)
        {
            if ($case === false)
            {
                $scope["default"] .= $content;
            }
            else
            {
                if (! isset($scope["case"][$case]))
                {
                    $scope["case"][$case] = "";
                }
                $scope["case"][$case] .= $content;
            }
        }
        $scope["last"] = [];
    }

    /**
     * Tag {continue}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @throws InvalidUsageException
     * @return string
     */
    public static function tagContinue(Tokenizer $tokens, Tag $scope)
    {
        if (empty($scope["no-continue"]))
            return 'continue;';

        throw new InvalidUsageException("Improper usage of the tag {continue}");
    }

    /**
     * Tag {break}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @throws InvalidUsageException
     * @return string
     */
    public static function tagBreak(Tokenizer $tokens, Tag $scope)
    {
        if (empty($scope["no-break"]))
            return 'break;';

        throw new InvalidUsageException("Improper usage of the tag {break}");
    }

    /**
     * Dispatch {extends} tag
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @throws InvalidUsageException
     * @return string
     */
    public static function tagExtends(Tokenizer $tokens, Tag $tag)
    {
        $tpl = $tag->tpl;

        if ($tpl->extends)
            throw new InvalidUsageException("Only one {extends} allowed");

        if ($tpl->getStackSize())
            throw new InvalidUsageException("Tag {extends} can not be nested");

        $cname = $tpl->parsePlainArg($tokens, $name);

        if ($name)
        {
            $tpl->extends = $name;
        }
        else
        {
            $tpl->dynamic_extends = $cname;
        }

        if (! $tpl->extendBody)
        {
            $tpl->addPostCompile(__CLASS__ . "::extendBody");
            $tpl->extendBody = true;
        }
    }

    /**
     * Post compile action for {extends ...} tag
     *
     * @param Template $tpl
     * @param string $body
     * @return void
     */
    public static function extendBody(Template $tpl, &$body)
    {
        if ($tpl->dynamic_extends)
        {
            if (! $tpl->extStack)
            {
                $tpl->extStack[] = $tpl->getName();
            }

            foreach ($tpl->extStack as &$t)
            {
                $stack[] = "'$t'";
            }

            $stack[] = $tpl->dynamic_extends;
            $body    = '<?php $tpl->getStorage()->display(array(' . implode(', ', $stack) . '), $var); ?>';
        }
        else
        {
            $child = $tpl;

            while ($child && $child->extends)
            {
                $parent = $tpl->extend($child->extends);
                $child  = $parent->extends ? $parent : false;
            }
            $tpl->extends = false;
        }
        $tpl->extendBody = false;
    }

    /**
     * Tag {use ...}
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @throws InvalidUsageException
     * @return void
     */
    public static function tagUse(Tokenizer $tokens, Tag $tag)
    {
        $tpl = $tag->tpl;

        if ($tpl->getStackSize())
            throw new InvalidUsageException("Tag {use} can not be nested");

        $tpl->parsePlainArg($tokens, $name);

        if ($name)
            $tpl->importBlocks($name);

        throw new InvalidUsageException('Invalid template name for tag {use}');
    }

    /**
     * Open tag {block ...}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @throws RuntimeException
     * @return void
     */
    public static function tagBlockOpen(Tokenizer $tokens, Tag $scope)
    {
        $scope["cname"] = $scope->tpl->parsePlainArg($tokens, $name);

        if (! $name)
            throw new RuntimeException("Invalid block name");

        $scope["name"]       = $name;
        $scope["use_parent"] = false;
    }

    /**
     * Close tag {/block}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return void
     */
    public static function tagBlockClose(Tokenizer $tokens, Tag $scope): void
    {
        $tpl  = $scope->tpl;
        $name = $scope["name"];

        if (isset($tpl->blocks[$name]))
        {
            $block = & $tpl->blocks[$name];

            if ($block['use_parent'])
            {
                $parent = $scope->getContent();
                $block['block'] = str_replace($block['use_parent'] . " ?>", "?>" . $parent, $block['block']);
            }

            if (! $block["import"])
            {
                $scope->replaceContent($block["block"]);
                return;
            }
            elseif ($block["import"] != $tpl->getName())
            {
                $tpl->blocks[$scope["name"]]["import"] = false;
                $scope->replaceContent($block["block"]);
            }
        }

        $tpl->blocks[$scope["name"]] = [
            "from"       => $tpl->getName(),
            "import"     => false,
            "use_parent" => $scope["use_parent"],
            "block"      => $scope->getContent()
        ];
    }

    /**
     * Tag {parent}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function tagParent(Tokenizer $tokens, Tag $scope)
    {
        $block_scope = $scope->tpl->getParentScope('block');

        if (! $block_scope['use_parent'])
        {
            $block_scope['use_parent'] = "/* %%parent#{$scope['name']}%% */";
        }

        return $block_scope['use_parent'];
    }

    /**
     * Common close tag {/...}
     *
     * @return string
     */
    public static function stdClose()
    {
        return '}';
    }

    /**
     * Standard function parser
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @return string
     */
    public static function stdFuncParser(Tokenizer $tokens, Tag $tag)
    {
        if (is_string($tag->callback))
            return $tag->out($tag->callback . "(" . static::toArray($tag->tpl->parseParams($tokens)) . ', $tpl, $var)');

        return '$info = $tpl->getStorage()->getTag('.var_export($tag->name, true).');'.PHP_EOL.
            $tag->out('call_user_func_array($info["function"], array('.static::toArray($tag->tpl->parseParams($tokens)).', $tpl, &$var))');
    }

    /**
     * Smart function parser
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @return string
     */
    public static function smartFuncParser(Tokenizer $tokens, Tag $tag)
    {
        if (strpos($tag->callback, "::") || is_array($tag->callback))
        {
            list($class, $method) = explode("::", $tag->callback, 2);
            $ref = new ReflectionMethod($class, $method);
        }
        else
        {
            $ref = new ReflectionFunction($tag->callback);
        }

        $args   = [];
        $params = $tag->tpl->parseParams($tokens);

        foreach ($ref->getParameters() as $param)
        {
            if (isset($params[$param->getName()]))
            {
                $args[] = $params[$param->getName()];
            }
            elseif (isset($params[$param->getPosition()]))
            {
                $args[] = $params[$param->getPosition()];
            }
            elseif ($param->isOptional())
            {
                $args[] = var_export($param->getDefaultValue(), true);
            }
        }
        return $tag->out($tag->callback . "(" . implode(", ", $args) . ')');
    }

    /**
     * Standard function open tag parser
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @return string
     */
    public static function stdFuncOpen(Tokenizer $tokens, Tag $tag)
    {
        $tag["params"] = static::toArray($tag->tpl->parseParams($tokens));
        $tag->setOption(Brio::AUTO_ESCAPE, false);

        return 'ob_start();';
    }

    /**
     * Standard function close tag parser
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @return string
     */
    public static function stdFuncClose(Tokenizer $tokens, Tag $tag)
    {
        $tag->restore(Brio::AUTO_ESCAPE);

        if (is_string($tag->callback))
            return $tag->out($tag->callback . "(" . $tag["params"] . ', ob_get_clean(), $tpl, $var)');

        return '$info = $tpl->getStorage()->getTag('.var_export($tag->name, true).');'.PHP_EOL.
            $tag->out('call_user_func_array($info["function"], array(' . $tag["params"] . ', ob_get_clean(), $tpl, &$var))');
    }

    /**
     * Convert array of code to string array
     *
     * @param $params
     * @return string
     */
    public static function toArray(array $params): string
    {
        $code = [];

        foreach ($params as $k => $v)
        {
            $code[] = '"' . $k . '" => ' . $v;
        }

        return 'array(' . implode(",", $code) . ')';
    }

    /**
     * Open tag {set ... }
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function setOpen(Tokenizer $tokens, Tag $scope)
    {
        if ($tokens->is(T_VARIABLE))
        {
            $var = $scope->tpl->parseVariable($tokens);
        }
        elseif ($tokens->is('$'))
        {
            $var = $scope->tpl->parseAccessor($tokens, $isvar);

            if (! $isvar)
            {
                throw new InvalidUsageException("Accessor is not writable");
            }
        }
        else
        {
            throw new InvalidUsageException("{set} and {add} accept only variable");
        }

        $before = $after = "";

        if ($scope->name == 'add')
        {
            $before = "if(!isset($var)) {\n";
            $after = "\n}";
        }

        if ($tokens->is(Tokenizer::MACRO_EQUALS, '['))
        {
            $equal = $tokens->getAndNext();

            if ($equal == '[')
            {
                $tokens->need(']')->next()->need('=')->next();
                $equal = '[]=';
            }

            $scope->close();

            if ($tokens->is("["))
            {
                return $before.$var . $equal . $scope->tpl->parseArray($tokens) . ';'.$after;
            } else {
                return $before.$var . $equal . $scope->tpl->parseExpr($tokens) . ';'.$after;
            }
        }
        else
        {
            $scope["name"] = $var;

            if ($tokens->is('|'))
            {
                $scope["value"] = $before . $scope->tpl->parseModifier($tokens, "ob_get_clean()").';'.$after;
            }
            else
            {
                $scope["value"] = $before . "ob_get_clean();" . $after;
            }
            return 'ob_start();';
        }
    }

    /**
     * Close tag {/set}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function setClose(Tokenizer $tokens, Tag $scope)
    {
        return $scope["name"] . '=' . $scope["value"] . ';';
    }

    /**
     * Tag {do ... }
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function tagDo(Tokenizer $tokens, Tag $scope)
    {
        return $scope->tpl->parseExpr($tokens).';';
    }


    /**
     * Open tag {filter ... }
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function filterOpen(Tokenizer $tokens, Tag $scope)
    {
        $scope["filter"] = $scope->tpl->parseModifier($tokens, "ob_get_clean()");

        return "ob_start();";
    }

    /**
     * Close tag {/filter}
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return string
     */
    public static function filterClose(Tokenizer $tokens, Tag $scope)
    {
        return "echo " . $scope["filter"] . ";";
    }

    /**
     * Tag {cycle}
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @throws InvalidUsageException
     * @return string
     */
    public static function tagCycle(Tokenizer $tokens, Tag $tag)
    {
        $tpl = $tag->tpl;

        if ($tokens->is("["))
        {
            $exp = $tpl->parseArray($tokens);
        }
        else
        {
            $exp = $tpl->parseExpr($tokens);
        }

        if ($tokens->valid())
        {
            $p = $tpl->parseParams($tokens);

            if (empty($p["index"]))
                throw new InvalidUsageException("Cycle may contain only index attribute");

            return 'echo ' . __CLASS__ . '::cycle(' . $exp . ', ' . $p["index"] . ')';
        }
        else
        {
            $var = $tpl->tmpVar();
            return 'echo ' . __CLASS__ . '::cycle(' . $exp . ", isset($var) ? ++$var : ($var = 0) )";
        }
    }

    /**
     * Runtime cycle callback
     *
     * @param mixed $vals
     * @param $index
     * @return mixed
     */
    public static function cycle($vals, $index)
    {
        return $vals[$index % count($vals)];
    }

    /**
     * Import macros from templates
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @throws UnexpectedTokenException
     * @throws InvalidUsageException
     * @return string
     */
    public static function tagImport(Tokenizer $tokens, Tag $tag)
    {
        $tpl = $tag->tpl;
        $import = [];

        if ($tokens->is('['))
        {
            $tokens->next();

            while ($tokens->valid())
            {
                if ($tokens->is(Tokenizer::MACRO_STRING))
                {
                    $import[$tokens->current()] = true;
                    $tokens->next();
                }
                elseif ($tokens->is(']'))
                {
                    $tokens->next();
                    break;
                }
                elseif ($tokens->is(','))
                {
                    $tokens->next();
                }
                else
                {
                    break;
                }
            }

            if ($tokens->current() != "from")
                throw new UnexpectedTokenException($tokens);

            $tokens->next();
        }

        $tpl->parsePlainArg($tokens, $name);

        if (! $name)
            throw new InvalidUsageException("Invalid template name");

        if ($tokens->is(T_AS))
        {
            $alias = $tokens->next()->get(Tokenizer::MACRO_STRING);

            if ($alias === "macro")
            {
                $alias = "";
            }
            $tokens->next();
        }
        else
        {
            $alias = "";
        }

        $dependency = $tpl->getStorage()->getRawTemplate()->load($name, true);

        if ($dependency->macros)
        {
            foreach ($dependency->macros as $name => $macro)
            {
                if ($p = strpos($name, "."))
                {
                    $name = substr($name, $p);
                }

                if ($import && !isset($import[$name]))
                {
                    continue;
                }

                if ($alias)
                {
                    $name = $alias . '.' . $name;
                }
                $tpl->macros[$name] = $macro;
            }
            $tpl->addDepend($dependency);
        }
        return '';
    }

    /**
     * Define macro
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @throws InvalidUsageException
     * @return void
     */
    public static function macroOpen(Tokenizer $tokens, Tag $scope)
    {
        $scope["name"]      = $tokens->get(Tokenizer::MACRO_STRING);
        $scope["recursive"] = false;
        $args               = [];
        $defaults           = [];

        if (! $tokens->valid())
            return;

        $tokens->next();

        if ($tokens->is('(') || !$tokens->isNext(')'))
        {
            $tokens->next();

            while ($tokens->is(Tokenizer::MACRO_STRING, T_VARIABLE))
            {
                $param = $tokens->current();

                if ($tokens->is(T_VARIABLE))
                {
                    $param = ltrim($param, '$');
                }

                $tokens->next();
                $args[] = $param;

                if ($tokens->is('='))
                {
                    $tokens->next();

                    if ($tokens->is(T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER) || $tokens->isSpecialVal())
                    {
                        $defaults[$param] = $tokens->getAndNext();
                    }
                    else
                    {
                        throw new InvalidUsageException("Macro parameters may have only scalar defaults");
                    }
                }
                $tokens->skipIf(',');
            }
            $tokens->skipIf(')');
        }
        $scope["macro"] = [
            "name"      => $scope["name"],
            "args"      => $args,
            "defaults"  => $defaults,
            "body"      => "",
            "recursive" => false
        ];
        return;
    }

    /**
     * Close macro
     *
     * @param Tokenizer $tokens
     * @param Tag $scope
     * @return void
     */
    public static function macroClose(Tokenizer $tokens, Tag $scope)
    {
        if ($scope["recursive"])
        {
            $scope["macro"]["recursive"] = true;
        }

        $scope["macro"]["body"] = $scope->cutContent();
        $scope->tpl->macros[$scope["name"]] = $scope["macro"];
    }

    /**
     * Output value as is, without escaping
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @return string
     */
    public static function tagRaw(Tokenizer $tokens, Tag $tag)
    {
        return 'echo ' . $tag->tpl->parseExpr($tokens);
    }

    /**
     * Tag {autoescape}
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @return void
     */
    public static function escapeOpen(Tokenizer $tokens, Tag $tag)
    {
        $expected = ($tokens->get(T_STRING) == "true" ? true : false);
        $tokens->next();
        $tag->setOption(Brio::AUTO_ESCAPE, $expected);
    }

    /**
     * Tag {autostrip}
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @return void
     */
    public static function stripOpen(Tokenizer $tokens, Tag $tag)
    {
        $expected = ($tokens->get(T_STRING) == "true" ? true : false);
        $tokens->next();
        $tag->setOption(Brio::AUTO_STRIP, $expected);
    }

    /**
     * Tag {ignore}
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @return void
     */
    public static function ignoreOpen($tokens, Tag $tag)
    {
        $tag->tpl->ignore('ignore');
    }

    /**
     * Tag {unset ...}
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @return string
     */
    public static function tagUnset(Tokenizer $tokens, Tag $tag)
    {
        $unset = [];

        while($tokens->valid())
        {
            $unset[] = $tag->tpl->parseVariable($tokens);
        }
        return 'unset('.implode(", ", $unset).')';
    }

    /**
     * Tag {paste}
     *
     * @param Tokenizer $tokens
     * @param Tag $tag
     * @return string
     */
    public static function tagPaste(Tokenizer $tokens, Tag $tag)
    {
        $name = $tokens->get(T_CONSTANT_ENCAPSED_STRING);
        $tokens->next();

        if (isset($tag->tpl->blocks[$name]))
            return "?>".substr($tag->tpl->blocks[$name]["block"], 1, -1)."<?php ";

        return "";
    }

    /**
     * Do nothing
     */
    public static function nope()
    {
    }

}
