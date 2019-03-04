<?php

namespace placer\brio\engine\helper;

class BH
{
    public static function hexpr($term1, $op='expr', $term2=null, $op2=null)
    {
        $code = new AST;

        switch ($op2)
        {
            case '+':
            case '-':
            case '/':
            case '*':
            case '%':
            case '||':
            case '&&':
            case '<':
            case '>':
            case '<=':
            case '>=':
            case '==':
            case '!=':
                $args = func_get_args();
                $term2 = call_user_func_array(['placer\brio\engine\helper\BH', 'hexpr'], array_slice($args, 2));
                break;
        }

        return $code->expr($op, $term1, $term2);
    }

    public static function hexprCond($expr, $ifTrue, $ifFalse)
    {
        $code = new AST;

        $code->exprCond($expr, $ifTrue, $ifFalse);

        return $code;
    }

    public static function hexec()
    {
        $code = new AST;

        $args = func_get_args();

        return call_user_func_array([$code, 'exec'], $args);
    }

    public static function hconst($str)
    {
        return AST::constant($str);
    }

    public static function hvar()
    {
        $args = func_get_args();

        return static::hvarEx($args);
    }

    public static function hvarEx($args)
    {
        $code = new AST;

        if (is_object($args))
        {
            return $args->stack[0];
        }

        return call_user_func_array([$code, 'v'], $args);
    }

}
