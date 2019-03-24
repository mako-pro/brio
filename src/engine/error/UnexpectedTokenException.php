<?php

namespace placer\brio\engine\error;

use RuntimeException;
use placer\brio\engine\Tokenizer;

class UnexpectedTokenException extends RuntimeException
{
    public function __construct(Tokenizer $tokens, $expect = null, $where = null)
    {
        if ($expect && count($expect) == 1 && is_string($expect[0]))
        {
            $expect = ", expect '" . $expect[0] . "'";
        }
        else
        {
            $expect = "";
        }

        if (! $tokens->curr)
        {
            $this->message = "Unexpected end of " . ($where ? : "expression") . "$expect";
        }
        else
        {
            $this->message = "Unexpected token '" . $tokens->current() . "' in " . ($where ? : "expression") . "$expect";
        }
    }

}
