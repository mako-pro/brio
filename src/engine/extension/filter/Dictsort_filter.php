<?php

namespace placer\brio\engine\extension\filter;

class Dictsort_filter
{
    public static function main($arr, $sortBy)
    {
        $fields = [];

        foreach ($arr as $key => $item)
        {
            $fields[$key] = $item[$sortBy];
        }

        array_multisort($fields, SORT_REGULAR, $arr);

        return $arr;
    }

}
