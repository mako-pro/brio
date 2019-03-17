<?php

namespace placer\brio\engine\extension\filter;

class Wordlimiter_filter
{
    /**
     * Word Limiter
     *
     * @param string  $str
     * @param integer $limit
     * @return string
     */
    public static function main(string $str, int $limit = 100): string
    {
        if (trim($str) === '')
            return $str;

        preg_match('/^\s*+(?:\S++\s*+){1,' . $limit . '}/', $str, $matches);

        if (mb_strlen($str) === mb_strlen($matches[0]))
            return rtrim($matches[0]);

        return rtrim($matches[0]) . '...';
    }

}
