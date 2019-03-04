<?php

namespace placer\brio\engine\extension\filter;

use placer\brio\engine\helper\BH;

class Cut_filter
{
    public static function generator($compiler, $args)
    {
        return BH::hexec('str_replace', $args[1], "", $args[0]);
    }
}
