<?php

namespace placer\brio\engine\extension\tag;

use placer\brio\engine\helper\AST;
use placer\brio\Brio;

class Inline_tag
{
    public static function generator($cmp, $args, $redirected)
    {
        if (count($args) != 1)
        {
            $cmp->error("inline needs one argument");
        }

        if ($redirected)
        {
            $cmp->error("inline can't be redirected to one variable");
        }

        if (! AST::isStr($args[0]))
        {
            $cmp->error("The argument to inline must be an string");
        }

        $body = new AST;
        $file = $args[0]['string'];
        $file = Brio::getTemplatePath($file);

        $cmp->compileCode($file, $body);

        return $body;
    }

}
