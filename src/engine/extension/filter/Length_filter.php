<?php

namespace placer\brio\engine\extension\filter;

use placer\brio\engine\helper\AST;
use placer\brio\engine\helper\BH;

class Length_filter
{
    public $isSafe = true;

    public static function generator($compiler, $args)
    {
        $count  = BH::hexec('count', $args[0]);
        $strlen = BH::hexec('strlen', $args[0]);
        $guess  = BH::hexprCond(
        	BH::hexec('is_array', $args[0]),
        	BH::hexec('count', $args[0]),
        	BH::hexec('strlen', $args[0])
        );

        if (AST::isVar($args[0]))
        {
            $value = $compiler->getContext($args[0]['var']);

            if (is_array($value))
            {
                return $count;
            }

            if (is_string($value))
            {
                return $strlen;
            }

            return $guess;
        }

        if (AST::isStr($args[0]))
        {
            return $strlen;
        }

        return $guess;
    }

}
