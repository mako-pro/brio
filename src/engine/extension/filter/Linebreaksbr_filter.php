<?php

namespace placer\brio\engine\extension\filter;

use placer\brio\engine\helper\BH;

class Linebreaksbr_filter
{
    public static function generator($compiler, $args)
    {
    	$compiler->safeVariable = true;

        return BH::hexec('preg_replace', "/\r\n|\r|\n/", "<br>\n", $args[0]);
    }

}
