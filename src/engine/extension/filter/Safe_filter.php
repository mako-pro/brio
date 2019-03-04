<?php

namespace placer\brio\engine\extension\filter;

class Safe_filter
{
    public static function generator($compiler, $args)
    {
        $compiler->safeVariable = true;

        return current($args);
    }

}
