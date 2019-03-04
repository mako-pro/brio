<?php

namespace placer\brio\engine\extension\tag;

use placer\brio\engine\helper\AST;
use placer\brio\engine\helper\BH;

class FirstOf_tag
{
    static function generator($cmp, $args)
    {
        $count = count($args);
        $args  = array_reverse($args);

        for ($i=0; $i < $count; $i++)
        {
            if (isset($expr) && AST::isVar($args[$i]))
            {
                $expr = BH::hexprCond(BH::hexpr(BH::hexec('empty', $args[$i]), '==', false), $args[$i], $expr);
            }
            else
            {
                $expr = $args[$i];
            }
        }

        return $expr;
    }

}
