<?php

namespace placer\brio\engine\extension\filter;

use placer\brio\engine\helper\BH;

class UrlEncode_filter
{
    public static function generator($cmp, $args)
    {
        $cmp->safeVariable = true;

        return BH::hexec('urlencode', $args[0]);
    }

}
