<?php

namespace placer\brio\engine\extension\filter;

class Charlimiter_filter
{
    /**
     * Chars Limiter
     *
     * @param string  $str
     * @param integer $limit
     * @return string
     */
    public static function main(string $str, int $limit = 100): string
    {
        if (mb_strlen($str) < $limit)
            return $str;

        $str = preg_replace('/ {2,}/', ' ', str_replace(["\r", "\n", "\t", "\x0B", "\x0C"], ' ', $str));

        if (mb_strlen($str) <= $limit)
            return $str;

        $result = '';

        foreach (explode(' ', trim($str)) as $val)
        {
            $result .= $val . ' ';

            if (mb_strlen($result) >= $limit)
            {
                $result = trim($result);
                break;
            }
        }
        return (mb_strlen($result) === mb_strlen($str)) ? $result : $result . '...';
    }

}
