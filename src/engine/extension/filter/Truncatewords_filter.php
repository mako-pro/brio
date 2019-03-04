<?php

namespace placer\brio\engine\extension\filter;

class Truncatewords_filter
{
    public static function main($text, $limit)
    {
        $words = explode(' ', $text, $limit+1);

        if (count($words) == $limit+1)
        {
            $words[$limit] = '...';
        }

        return implode(' ', $words);
    }

}
