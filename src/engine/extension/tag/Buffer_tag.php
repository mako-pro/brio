<?php

namespace placer\brio\engine\extension\tag;

use placer\brio\engine\helper\AST;

class Buffer_tag
{
    public $isBlock = true;

    public static function generator($cmp, $args, $redirected)
    {
        if (count($args) != 2)
        {
            $cmp->error("Buffer filter must have one parameter");
        }

        $code = new AST;
        $code->decl($args[1], $args[0]);
        $code->doesPrint = true;
        $cmp->setSafe($args[1]['var']);

        return $code;
    }

}
