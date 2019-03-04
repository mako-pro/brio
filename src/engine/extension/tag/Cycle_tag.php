<?php

namespace placer\brio\engine\extension\tag;

use placer\brio\engine\helper\AST;
use placer\brio\engine\helper\BH;

class Cycle_tag
{
    public $isBlock = false;

    public static function generator($cmp, $args, $declared)
    {
        static $cycle = 0;

        if (! isset($cmp->cycle))
        {
            $cmp->cycle = [];
        }

        $code = new AST;

        $index = 'index_'.$cycle;
        $def   = 'def_cycle_'.$cycle;

        if (count($args) == 1 && AST::isVar($args[0]) && isset($cmp->cycle[$args[0]['var']]))
        {
            $id    = $cmp->cycle[$args[0]['var']];
            $index = 'index_'.$id;
            $def   = 'def_cycle_'.$id;
        }
        else
        {
            if (! $declared)
            {
                $code->doIf(BH::hexpr(BH::hexec('isset', BH::hvar($def)), '==', false));
            }

            $code->decl($def, $args);

            if (! $declared)
            {
                $code->doEndif();
            }
        }

        $expr = BH::hexpr(BH::hexec('isset', BH::hvar($index)), '==', false);
        $inc  = BH::hexpr(BH::hexpr(BH::hexpr(BH::hvar($index), '+', 1)), '%', BH::hexec('count', BH::hvar($def)));

        if (! $declared)
        {
            if (isset($id))
            {
                $code->decl($index, $inc);
            }
            else
            {
                $code->decl($index, BH::hexprCond($expr, 0, $inc));
            }

            $code->end();
            $var = BH::hvar($def, BH::hvar($index));
            $cmp->doPrint($code, $var);
        }
        else
        {
            $code->decl($index, -1);
            $cmp->cycle[$declared] = $cycle;
        }
        $cycle++;

        return $code;
    }

}
