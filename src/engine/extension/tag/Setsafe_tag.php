<?php

namespace placer\brio\engine\extension\tag;

use placer\brio\engine\helper\AST;

class SetSafe_tag
{
    public $isBlock = false;

    public static function generator($cmp, $args)
    {
        foreach ($args as $arg)
        {
            if (AST::isVar($arg))
            {
                $cmp->setSafe($arg['var']);
            }
        }

        return new AST;
    }

}
