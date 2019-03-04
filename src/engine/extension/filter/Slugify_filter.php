<?php

namespace placer\brio\engine\extension\filter;

use placer\brio\engine\helper\BH;

class Slugify_filter
{
    public static function generator($compiler, $args)
    {
        if (count($args) != 1)
        {
            $compiler->error("Slugify filter only needs one parameter");
        }

        $arg = BH::hexec('strtolower', $args[0]);
        $arg = BH::hexec('str_replace', " ", "-", $arg);
        $arg = BH::hexec('preg_replace', "/[^\d\w-_]/", '', $arg);

        return $arg;
    }

}
