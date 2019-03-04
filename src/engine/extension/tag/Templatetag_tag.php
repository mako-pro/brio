<?php

namespace placer\brio\engine\extension\tag;

use placer\brio\engine\helper\AST;

class Templatetag_tag
{
    public static function generator($compiler, $args)
    {
        if (count($args) != 1)
        {
            $compiler->error("templatetag only needs one parameter");
        }

        if (AST::isVar($args[0]))
        {
            $type = $args[0]['var'];

            if (! is_string($type))
            {
                $compiler->error("Invalid parameter");
            }
        }
        elseif (AST::isStr($args[0]))
        {
            $type = $args[0]['string'];
        }

        switch ($type)
        {
            case 'openblock':
                $str = '{%';
                break;
            case 'closeblock':
                $str = '%}';
                break;
            case 'openbrace':
                $str = '{';
                break;
            case 'closebrace':
                $str = '}';
                break;
            case 'openvariable':
                $str = '{{';
                break;
            case 'closevariable':
                $str = '}}';
                break;
            case 'opencomment':
                $str = '{#';
                break;
            case 'closecomment':
                $str = '#}';
                break;
            default:
                $compiler->error("Invalid parameter");
                break;
        }

        $code = new AST;
        $compiler->doPrint($code, AST::str($str));

        return $code;
    }

}
