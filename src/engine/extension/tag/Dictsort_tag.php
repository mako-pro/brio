<?php

namespace placer\brio\engine\extension\tag;

use placer\brio\engine\helper\AST;
use placer\brio\engine\helper\BH;

class Dictsort_tag
{
    public static function generator($cmp, $args, $redirected)
    {
        if (! $redirected)
        {
            $cmp->error("dictsort must be redirected to a variable using AS <varname>");
        }

        if (count($args) != 2)
        {
            $cmp->error("Dictsort must have two params");
        }

        if (! AST::isVar($args[0]))
        {
            $cmp->error("Dictsort: First parameter must be an array");
        }

        $var = $cmp->getContext($args[0]['var']);
        $cmp->setContext($redirected, $var);

        $redirected = BH::hvar($redirected);
        $field      = BH::hvar('field');
        $key        = BH::hvar('key');

        $code = new AST;
        $body = new AST;

        $body->decl(BH::hvar('field', $key), BH::hvar('item', $args[1]));

        $code->decl($redirected, $args[0]);
        $code->decl($field, []);
        $code->doForeach($redirected, 'item', $key, $body);
        $code->doExec('array_multisort', $field, BH::hconst('SORT_REGULAR'), $redirected);

        return $code;
    }

}
