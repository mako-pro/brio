<?php

namespace placer\brio\engine\extension;

use placer\brio\engine\compiler\Parser;
use placer\brio\engine\Extension;

class Tag extends Extension
{
    final function isValid(string $tag)
    {
        static $cache = [];

        $tag = strtolower($tag);

        if (! isset($cache[$tag]))
        {
            $className = $this->getClassName($tag);

            if (class_exists($className))
            {
                $properties = get_class_vars($className);

                $isBlock = false;

                if (isset($properties['is_block']))
                {
                    $isBlock = (bool)$properties['is_block'];
                }

                $cache[$tag] = $isBlock ? Parser::T_CUSTOM_BLOCK : Parser::T_CUSTOM_TAG;
            }

            if (! isset($cache[$tag]))
            {
                $cache[$tag] = false;
            }
        }

        return $cache[$tag];
    }

    final function getClassName(string $tag)
    {
        $tag = str_replace("_", "", ucfirst($tag));

        return "placer\\brio\\engine\\extension\\tag\\{$tag}_tag";
    }

}
