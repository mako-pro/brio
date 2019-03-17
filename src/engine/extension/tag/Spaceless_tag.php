<?php

namespace placer\brio\engine\extension\tag;

use placer\brio\engine\helper\BH;

class Spaceless_tag
{
    public $isBlock  = true;

    public static function generator($compiler, $args)
    {
        $regex = ['/>[\s+]+</sU', '/^[\s+]+</sU', '/>[\s+]+$/sU'];
        $repl  = ['><', '<', '>'];

        return BH::hexec('preg_replace', $regex, $repl, $args[0]);
    }

}
