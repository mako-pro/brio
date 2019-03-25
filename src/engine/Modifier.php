<?php

namespace placer\brio\engine;

use Iterator;
use Countable;
use RangeIterator;

use placer\brio\Brio;

class Modifier
{
    /**
     * Returns string in the upper case
     *
     * @param string $str
     * @return string
     */
    public static function upper(string $str)
    {
        return mb_strtoupper($str, Brio::$charset);
    }

    /**
     * Returns string in the lower case
     *
     * @param string $str
     * @return string
     */
    public static function lower(string $str)
    {
        return mb_strtolower($str, Brio::$charset);
    }

    /**
     * Returns string with first char in the upper case
     *
     * @param string $str
     * @return string
     */
    public static function capfirst(string $str)
    {
        $charset = Brio::$charset;

        $first = mb_strtoupper(mb_substr($str, 0, 1, $charset), $charset);
        $next  = mb_strtolower(mb_substr($str, 1, mb_strlen($str), $charset), $charset);

        return $first . $next;
    }

    /**
     * Returns string in the title format
     *
     * @param string $str
     * @return string
     */
    public static function title(string $str)
    {
        return mb_convert_case($str, MB_CASE_TITLE, Brio::$charset);
    }

    /**
     * Date format
     *
     * @param string|int $date
     * @param string $format
     * @return string
     */
    public static function dateFormat($date, $format = "%b %e, %Y")
    {
        if (! is_numeric($date))
        {
            $date = strtotime($date);

            if (! $date)
            {
                $date = time();
            }
        }
        return strftime($format, $date);
    }

    /**
     * Date
     *
     * @param string $date
     * @param string $format
     * @return string
     */
    public static function date($date, $format = "Y m d")
    {
        if (! is_numeric($date))
        {
            $date = strtotime($date);

            if (! $date)
            {
                $date = time();
            }
        }
        return date($format, $date);
    }

    /**
     * Escape string
     *
     * @param string $text
     * @param string $type
     * @param string $charset
     * @return string
     */
    public static function escape($text, $type = 'html', $charset = null)
    {
        switch (strtolower($type))
        {
            case "url":
                return urlencode($text);
            case "html";
                return htmlspecialchars($text, ENT_COMPAT, $charset ? $charset : Brio::$charset);
            case "js":
                return json_encode($text, 64 | 256); // JSON_UNESCAPED_SLASHES = 64, JSON_UNESCAPED_UNICODE = 256
            default:
                return $text;
        }
    }

    /**
     * Unescape escaped string
     *
     * @param string $text
     * @param string $type
     * @return string
     */
    public static function unescape($text, $type = 'html')
    {
        switch (strtolower($type))
        {
            case "url":
                return urldecode($text);
            case "html";
                return htmlspecialchars_decode($text);
            default:
                return $text;
        }
    }

    /**
     * Crop string to specific length (support unicode)
     *
     * @param string $string text witch will be truncate
     * @param int $length maximum symbols of result string
     * @param string $etc place holder truncated symbols
     * @param bool $bywords
     * @param bool $middle
     * @return string
     */
    public static function truncate($string, $length = 80, $etc = '...', $bywords = false, $middle = false)
    {
        if ($middle !== false)
        {
            if (preg_match('#^(.{' . $length . '}).*?(.{' . $length . '})?$#usS', $string, $match))
            {
                if (count($match) == 3)
                {
                    if ($bywords !== false)
                        return preg_replace('#\s\S*$#usS', "", $match[1]) . $etc . preg_replace('#\S*\s#usS', "", $match[2]);

                    return $match[1] . $etc . $match[2];
                }
            }
        }
        else
        {
            if (preg_match('#^(.{' . $length . '})#usS', $string, $match))
            {
                if ($bywords !== false)
                    return preg_replace('#\s\S*$#usS', "", $match[1]) . $etc;

                return $match[1] . $etc;
            }
        }
        return $string;
    }

    /**
     * Strip spaces symbols on edge of string end multiple spaces in the string
     *
     * @param string $str
     * @param bool $to_line strip line ends
     * @return string
     */
    public static function strip($str, $toline = false)
    {
        $str = trim($str);

        if ($toline !== false)
            return preg_replace('#\s+#ms', ' ', $str);

        return preg_replace('#[ \t]{2,}#', ' ', $str);
    }

    /**
     * Return length of UTF8 string, array, countable object
     *
     * @param mixed $item
     * @return integer
     */
    public static function length($item)
    {
        if (is_string($item))
            return strlen(preg_replace('#[\x00-\x7F]|[\x80-\xDF][\x00-\xBF]|[\xE0-\xEF][\x00-\xBF]{2}#s', ' ', $item));

        if (is_array($item))
            return count($item);

        if ($item instanceof Countable)
            return $item->count();

        return 0;
    }

    /**
     * Check if array or string contains the seeked value
     *
     * @param mixed $value
     * @param mixed $haystack
     * @return boolean
     */
    public static function in($value, $haystack)
    {
        if (is_scalar($value))
        {
            if (is_array($haystack))
                return in_array($value, $haystack) || array_key_exists($value, $haystack);

            if (is_string($haystack))
                return strpos($haystack, $value) !== false;
        }
        return false;
    }

    /**
     * Check for iterable
     *
     * @param mixed $value
     * @return boolean
     */
    public static function isIterable($value)
    {
        return is_array($value) || ($value instanceof Iterator);
    }

    /**
     * Replace all occurrences of the search string with the replacement string
     *
     * @param string $value The string being searched and replaced on, otherwise known as the haystack.
     * @param string $search The value being searched for, otherwise known as the needle.
     * @param string $replace The replacement value that replaces found search
     * @return mixed
     */
    public static function replace($value, $search, $replace)
    {
        return str_replace($search, $replace, $value);
    }

    /**
     * Replacement by pattern
     *
     * @param string $value
     * @param string $pattern
     * @param string $replacement
     * @return mixed
     */
    public static function ereplace($value, $pattern, $replacement)
    {
        return preg_replace($pattern, $replacement, $value);
    }

    /**
     * Check matching by fnmatch pattern
     *
     * @param string $string
     * @param string $pattern
     * @return boolean
     */
    public static function match($string, $pattern)
    {
        return fnmatch($pattern, $string);
    }

    /**
     * Check matching by preg_match pattern
     *
     * @param string $string
     * @param string $pattern
     * @return integer
     */
    public static function ematch($string, $pattern)
    {
        return preg_match($pattern, $string);
    }

    /**
     * Explode string
     *
     * @param string $value
     * @param string $delimiter
     * @return array
     */
    public static function split($value, $delimiter = ",")
    {
        if (is_string($value))
            return explode($delimiter, $value);

        if (is_array($value))
            return $value;

        return [];
    }

    /**
     * Explode by pattern
     *
     * @param $value
     * @param string $pattern
     * @return array
     */
    public static function esplit($value, $pattern = '/,\s*/S')
    {
        if (is_string($value))
            return preg_split($pattern, $value);

        if (is_array($value))
            return $value;

        return [];
    }

    /**
     * Joins array items to string
     *
     * @param $value
     * @param string $glue
     * @return string
     */
    public static function join($value, $glue = ",")
    {
        if (is_array($value))
            return implode($glue, $value);

        if (is_string($value))
            return $value;

        return "";
    }

    /**
     * Make range
     *
     * @param string|int $from
     * @param string|int $to
     * @param int $step
     * @return RangeIterator
     */
    public static function range($from, $to, $step = 1)
    {
        if ($from instanceof RangeIterator)
            return $from->setStep($to);

        return new RangeIterator($from, $to, $step);
    }
}
