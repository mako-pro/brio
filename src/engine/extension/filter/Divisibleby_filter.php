<?php

namespace placer\brio\engine\extension\filter;

class Divisibleby_filter
{
    public static function main($number, $divisibleBy)
    {
       	return ($number % $divisibleBy) == 0;
    }

}
