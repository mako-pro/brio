<?php

namespace placer\brio\engine\extension\filter;

use placer\brio\engine\helper\BH;

class Reverse_filter
{
    public static function generator($compiler, $args)
    {
        if (count($args) != 1)
        {
            $compiler->error("Reverse only needs one parameter");
        }

        return BH::hexec('array_reverse', $args[0], true);
    }

}
