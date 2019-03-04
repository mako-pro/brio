<?php

namespace placer\brio\engine\extension\filter;

use placer\brio\engine\helper\BH;

class Substr_filter
{
    public static function generator($cmp, $args)
    {
        if (count($args) != 2)
        {
            $cmp->error("Substr parameter must have one param");
        }

        if (! isset($args[1]['string']))
        {
            $cmp->error("Substr parameter must be a string");
        }

        list($start, $end) = explode(",", $args[1]['string']);

        return BH::hexec('substr', $args[0], (int) $start, (int) $end);
    }

}
