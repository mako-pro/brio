<?php

namespace placer\brio\engine\extension\filter;

use placer\brio\engine\helper\BH;

class Hostname_filter
{
    public static function generator($cmp, $args)
    {
        return BH::hexec('parse_url', $args[0], BH::hconst('PHP_URL_HOST'));
    }

}
