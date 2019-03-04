<?php

namespace placer\brio\engine\extension\tag;

use placer\brio\engine\helper\AST;
use placer\brio\engine\helper\BH;

class Trans_tag
{
    public $isBlock = false;

    public static function generator($cmp, $args, $redirect)
    {
        $code = new AST;

        $exec = BH::hexec('_', $args[0]);

        if (count($args) > 1)
        {
            $exec = BH::hexec('sprintf', $exec);

            foreach ($args as $id => $arg)
            {
                if ($id !== 0)
                {
                    $exec->param($arg);
                }
            }
        }

        if ($redirect)
        {
            $code->decl($redirect, $exec);
        }
        else
        {
            $cmp->doPrint($code, $exec);
        }

        return $code;
    }

}
