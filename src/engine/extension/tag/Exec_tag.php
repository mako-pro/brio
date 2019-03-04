<?php

namespace placer\brio\engine\extension\tag;

use placer\brio\engine\helper\AST;
use placer\brio\engine\helper\BH;

class Exec_tag
{
    public $isBlock = false;

    public static function generator($cmp, $args, $assign=null)
    {
        if (! $cmp::getOption('allowExec'))
        {
            $cmp->error("Tag exec is disabled for security reasons");
        }

        $code = new AST;

        if (AST::isVar($args[0]))
        {
            $args[0] = $args[0]['var'];
        }
        elseif (AST::isStr($args[0]))
        {
            $args[0] = $args[0]['string'];
        }
        else
        {
            $cmp->error("invalid param");
        }

        if (is_array($args[0]))
        {
            $end = end($args[0]);

            if (isset($end['class']))
            {
                $args[0][ key($args[0]) ]['class'] = substr($end['class'], 1);
            }
        }

        $exec = BH::hexec($args[0]);

        for ($i=1; $i < count($args); $i++)
        {
            $exec->param($args[$i]);
        }

        $exec->end();

        if ($assign)
        {
            $code->decl($assign, $exec);

            $code->decl($cmp->getScopeVariable($assign), BH::hvar($assign));
        }
        else
        {
            $cmp->doPrint($code, $exec);
        }

        return $code;
    }

}
