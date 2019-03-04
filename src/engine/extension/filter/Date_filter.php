<?php

namespace placer\brio\engine\extension\filter;

use placer\brio\engine\helper\BH;

class Date_filter
{
    public static function generator($compiler, $args)
    {
        return BH::hexec('date', $args[1], $args[0]);
    }
}


