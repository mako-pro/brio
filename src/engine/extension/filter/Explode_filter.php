<?php

namespace placer\brio\engine\extension\filter;

use placer\brio\engine\helper\BH;

class Explode_filter
{
    public static function generator($compiler, $args)
    {
        if (count($args) == 1 || $args[1] == "")
        {
            return BH::hexec("str_split", $args[0]);
        }

        return BH::hexec("explode", $args[1], $args[0]);
    }

}
