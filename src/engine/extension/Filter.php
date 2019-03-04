<?php

namespace placer\brio\engine\extension;

use placer\brio\engine\Extension;

class Filter extends Extension
{
    final function isValid(string $filter)
    {
        static $cache = [];

        $filter = strtolower($filter);

        if (! isset($cache[$filter]))
        {
            $className = $this->getClassName($filter);

            if (class_exists($className))
            {
                $cache[$filter] = true;
            }
            else
            {
                $cache[$filter] = false;
            }
        }

        return $cache[$filter];
    }

    final function getClassName(string $filter)
    {
        $filter = str_replace("_", "", ucfirst($filter));

        return "placer\\brio\\engine\\extension\\filter\\{$filter}_filter";
    }

}
