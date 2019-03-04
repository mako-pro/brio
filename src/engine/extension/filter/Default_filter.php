<?php

namespace placer\brio\engine\extension\filter;

use placer\brio\engine\helper\BH;

class Default_filter
{
    public static function generator($compiler, $args)
    {
        return BH::hexprCond(BH::hexpr(BH::hexec('empty', $args[0]), '==', true), $args[1], $args[0]);
    }

}
