<?php

namespace placer\brio\engine\extension\filter;

use placer\brio\engine\helper\BH;

class Title_filter
{
    public static function generator($compiler, $args)
    {
        if (count($args) != 1)
        {
            $compiler->error("Title filter only needs one parameter");
        }

        return BH::hexec(
            'mb_convert_case',
            BH::hexec('mb_strtolower', $args[0]),
            MB_CASE_TITLE
        );
    }

}
