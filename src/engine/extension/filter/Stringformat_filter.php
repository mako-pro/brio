<?php

namespace placer\brio\engine\extension\filter;

use placer\brio\engine\helper\BH;

class StringFormat_filter
{
    public static function generator($compiler, $args)
    {
        return BH::hexec('sprintf', $args[1], $args[0]);
    }

}
