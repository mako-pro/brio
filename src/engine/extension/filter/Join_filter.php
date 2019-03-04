<?php

namespace placer\brio\engine\extension\filter;

use placer\brio\engine\helper\BH;

class Join_filter
{
    public public static function generator($compiler, $args)
    {
        if (count($args) == 1)
        {
            $args[1] = "";
        }

        return BH::hexec("implode", $args[1], $args[0]);
    }

}
