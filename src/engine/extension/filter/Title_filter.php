<?php

namespace placer\brio\engine\extension\filter;

use placer\brio\engine\helper\BH;

class Title_filter
{
    static function generator($compiler, $args)
    {
        if (count($args) != 1)
        {
            $compiler->error("Title filter only needs one parameter");
        }

        return BH::hexec('ucwords', BH::hexec('strtolower', $args[0]));
    }

}
