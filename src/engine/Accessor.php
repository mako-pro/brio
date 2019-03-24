<?php

namespace placer\brio\engine;

use LogicException;
use RuntimeException;

use placer\brio\engine\error\UnexpectedTokenException;
use placer\brio\engine\Render;
use placer\brio\Brio;

class Accessor
{
    /**
     * Global variables
     * @var array
     */
    public static $vars = [
        'get'     => '$_GET',
        'post'    => '$_POST',
        'session' => '$_SESSION',
        'cookie'  => '$_COOKIE',
        'request' => '$_REQUEST',
        'files'   => '$_FILES',
        'globals' => '$GLOBALS',
        'server'  => '$_SERVER',
        'env'     => '$_ENV'
    ];

    /**
     * @param string $var variable expression on PHP ('App::get("storage")->user')
     * @param Tokenizer $tokens
     * @param Template $tpl
     * @param boolean $isvar
     * @return string
     */
    public static function parserVar(string $var, Tokenizer $tokens, Template $tpl, &$isvar)
    {
        $isvar = true;

        return $tpl->parseVariable($tokens, $var);
    }

    /**
     * @param string $call method name expression on PHP ('App::get("storage")->getUser')
     * @param Tokenizer $tokens
     * @param Template $tpl
     * @return string
     */
    public static function parserCall(string $call, Tokenizer $tokens, Template $tpl)
    {
        return $call . $tpl->parseArgs($tokens);
    }

    /**
     * @param string $prop Brio's property name
     * @param Tokenizer $tokens
     * @param Template $tpl
     * @param $isvar
     * @return string
     */
    public static function parserProperty(string $prop, Tokenizer $tokens, Template $tpl, &$isvar)
    {
        return static::parserVar('$tpl->getStorage()->' . $prop, $tokens, $tpl, $isvar);
    }

    /**
     * @param string $method Brio's method name
     * @param Tokenizer $tokens
     * @param Template $tpl
     * @return string
     */
    public static function parserMethod(string $method, Tokenizer $tokens, Template $tpl)
    {
        return static::parserCall('$tpl->getStorage()->' . $method, $tokens, $tpl);
    }

    /**
     * Accessor for global variables
     *
     * @param Tokenizer $tokens
     * @param Template $tpl
     * @return string
     */
    public static function getVar(Tokenizer $tokens, Template $tpl)
    {
        $name = $tokens->prev[Tokenizer::TEXT];

        if (isset(static::$vars[$name]))
        {
            $var = $tpl->parseVariable($tokens, static::$vars[$name]);
            return "(isset($var) ? $var : null)";
        }
        throw new UnexpectedTokenException($tokens->back());
    }

    /**
     * Accessor for template information
     *
     * @param Tokenizer $tokens
     * @return string
     */
    public static function tpl(Tokenizer $tokens)
    {
        $method = $tokens->skip('.')->need(T_STRING)->getAndNext();

        if (method_exists('Render', 'get' . $method))
            return '$tpl->get' . ucfirst($method) . '()';

        throw new UnexpectedTokenException($tokens->back());
    }

    /**
     * @param Tokenizer $tokens
     * @return string
     */
    public static function constant(Tokenizer $tokens)
    {
        $const = [$tokens->skip('.')->need(Tokenizer::MACRO_STRING)->getAndNext()];

        while ($tokens->is('.'))
        {
            $const[] = $tokens->next()->need(Tokenizer::MACRO_STRING)->getAndNext();
        }

        $const = implode('\\', $const);

        if ($tokens->is(T_DOUBLE_COLON))
        {
            $const .= '::' . $tokens->next()->need(Tokenizer::MACRO_STRING)->getAndNext();
        }
        return '@constant(' . var_export($const, true) . ')';
    }

    /**
     * @param Tokenizer $tokens
     * @param Template $tpl
     * @return string
     */
    public static function call(Tokenizer $tokens, Template $tpl)
    {
        $callable = [$tokens->skip('.')->need(Tokenizer::MACRO_STRING)->getAndNext()];

        while ($tokens->is('.'))
        {
            $callable[] = $tokens->next()->need(Tokenizer::MACRO_STRING)->getAndNext();
        }

        $callable = implode('\\', $callable);

        if ($tokens->is(T_DOUBLE_COLON))
        {
            $callable .= '::' . $tokens->next()->need(Tokenizer::MACRO_STRING)->getAndNext();
        }

        if (! is_callable($callable))
        {
            throw new RuntimeException("PHP method " . str_replace('\\', '.', $callable) . ' does not exists . ');
        }

        if ($tokens->is('('))
        {
            $arguments = 'array' . $tpl->parseArgs($tokens) . '';
        }
        else
        {
            $arguments = 'array()';
        }

        return 'call_user_func_array(' . var_export($callable, true) . ', ' . $arguments . ')';
    }

    /**
     * Accessor {$.fetch(...)}
     * @param Tokenizer $tokens
     * @param Template $tpl
     * @return string
     */
    public static function fetch(Tokenizer $tokens, Template $tpl)
    {
        $tokens->skip('(');
        $name = $tpl->parsePlainArg($tokens, $static);

        if ($static)
        {
            if (! $tpl->getStorage()->templateExists($static))
                throw new \RuntimeException("Template $static not found");
        }

        if ($tokens->is(','))
        {
            $tokens->skip()->need('[');
            $vars = $tpl->parseArray($tokens) . ' + $var';
        }
        else
        {
            $vars = '$var';
        }

        $tokens->skip(')');
        return '$tpl->getStorage()->fetch(' . $name . ', ' . $vars . ')';
    }

    /**
     * Accessor {$.block.NAME}
     * @param Tokenizer $tokens
     * @param Template $tpl
     * @return mixed
     */
    public static function block(Tokenizer $tokens, Template $tpl)
    {
        if ($tokens->is('.'))
        {
            $name = $tokens->next()->get(Tokenizer::MACRO_STRING);
            $tokens->next();
            return isset($tpl->blocks[$name]) ? 'true' : 'false';
        }
        return "array(" . implode(",", array_keys($tpl->blocks)) . ")";
    }

}
