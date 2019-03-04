<?php

namespace placer\brio\engine\extension\filter;

use placer\brio\engine\helper\AST;
use placer\brio\engine\helper\BH;

class Pluralize_filter
{
    public static function generator($compiler, $args)
    {
        if (count($args) > 1)
        {
            if (! AST::isStr($args[1]))
            {
                $compiler->error("pluralize: First parameter must be an string");
            }

            $parts    = explode(",", $args[1]['string']);
            $singular = "";

            if (count($parts) == 1)
            {
                $plural = $parts[0];
            }
            else
            {
                $singular = $parts[0];
                $plural   = $parts[1];
            }
        }
        else
        {
            $singular = "";
            $plural   = "s";
        }

        return BH::hexprCond(BH::hexpr($args[0], '<=', 1), $singular, $plural);
    }

}
