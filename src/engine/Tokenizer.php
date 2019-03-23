<?php

namespace placer\brio\engine;

use placer\brio\engine\error\UnexpectedTokenException;

class Tokenizer
{
    const TOKEN      = 0;
    const TEXT       = 1;
    const WHITESPACE = 2;
    const LINE       = 3;

    /**
     * Some text value: foo, bar, new, class ...
     */
    const MACRO_STRING = 1000;

    /**
     * Unary operation: ~, !, ^
     */
    const MACRO_UNARY = 1001;

    /**
     * Binary operation (operation between two values): +, -, *, /, &&, or , ||, >=, !=, ...
     */
    const MACRO_BINARY = 1002;

    /**
     * Equal operation
     */
    const MACRO_EQUALS = 1003;

    /**
     * Scalar values (such as int, float, escaped strings): 2, 0.5, "foo", 'bar\'s'
     */
    const MACRO_SCALAR = 1004;

    /**
     * Increment or decrement: ++ --
     */
    const MACRO_INCDEC = 1005;

    /**
     * Boolean operations: &&, ||, or, xor, and
     */
    const MACRO_BOOLEAN = 1006;

    /**
     * Math operation
     */
    const MACRO_MATH = 1007;

    /**
     * Condition operation
     */
    const MACRO_COND = 1008;

    public $tokens = [];
    public $pos    = 0;
    public $quotes = 0;
    private $max   = 0;
    private $last  = 0;

    /**
     * Groups of tokens
     * @var array
     */
    public static $macros = [
        self::MACRO_STRING  => [
            \T_ABSTRACT      => 1, \T_ARRAY         => 1, \T_AS            => 1, \T_BREAK         => 1,
            \T_CASE          => 1, \T_CATCH         => 1, \T_CLASS         => 1,
            \T_CLASS_C       => 1, \T_CLONE         => 1, \T_CONST         => 1, \T_CONTINUE      => 1,
            \T_DECLARE       => 1, \T_DEFAULT       => 1, \T_DIR           => 1, \T_DO            => 1,
            \T_ECHO          => 1, \T_ELSE          => 1, \T_ELSEIF        => 1, \T_EMPTY         => 1,
            \T_ENDDECLARE    => 1, \T_ENDFOR        => 1, \T_ENDFOREACH    => 1, \T_ENDIF         => 1,
            \T_ENDSWITCH     => 1, \T_ENDWHILE      => 1, \T_EVAL          => 1, \T_EXIT          => 1,
            \T_EXTENDS       => 1, \T_FILE          => 1, \T_FINAL         => 1, \T_FOR           => 1,
            \T_FOREACH       => 1, \T_FUNCTION      => 1, \T_FUNC_C        => 1, \T_GLOBAL        => 1,
            \T_GOTO          => 1, \T_HALT_COMPILER => 1, \T_IF            => 1, \T_IMPLEMENTS    => 1,
            \T_INCLUDE       => 1, \T_INCLUDE_ONCE  => 1, \T_INSTANCEOF    => 1, 341 /* T_INSTEADOF */ => 1,
            \T_INTERFACE     => 1, \T_ISSET         => 1, \T_LINE          => 1, \T_LIST          => 1,
            \T_LOGICAL_AND   => 1, \T_LOGICAL_OR    => 1, \T_LOGICAL_XOR   => 1, \T_METHOD_C      => 1,
            \T_NAMESPACE     => 1, \T_NS_C          => 1, \T_NEW           => 1, \T_PRINT         => 1,
            \T_PRIVATE       => 1, \T_PUBLIC        => 1, \T_PROTECTED     => 1, \T_REQUIRE       => 1,
            \T_REQUIRE_ONCE  => 1, \T_RETURN        => 1, \T_RETURN        => 1, \T_STRING        => 1,
            \T_SWITCH        => 1, \T_THROW         => 1, 355 /* T_TRAIT */   => 1, 365 /* T_TRAIT_C */ => 1,
            \T_TRY           => 1, \T_UNSET         => 1, \T_USE           => 1, \T_VAR           => 1,
            \T_WHILE         => 1, 267 /* T_YIELD */ => 1
        ],
        self::MACRO_INCDEC  => [
            \T_INC => 1, \T_DEC => 1
        ],
        self::MACRO_UNARY   => [
            "!" => 1, "~" => 1, "-" => 1
        ],
        self::MACRO_BINARY  => [
            \T_BOOLEAN_AND         => 1, \T_BOOLEAN_OR          => 1, \T_IS_GREATER_OR_EQUAL => 1,
            \T_IS_EQUAL            => 1, \T_IS_IDENTICAL        => 1, \T_IS_NOT_EQUAL        => 1,
            \T_IS_NOT_IDENTICAL    => 1, \T_IS_SMALLER_OR_EQUAL => 1, \T_LOGICAL_AND         => 1,
            \T_LOGICAL_OR          => 1, \T_LOGICAL_XOR         => 1, \T_SL                  => 1,
            \T_SR                  => 1, "+"                    => 1, "-"                    => 1,
            "*"                    => 1, "/"                    => 1, ">"                    => 1,
            "<"                    => 1, "^"                    => 1, "%"                    => 1,
            "&"                    => 1
        ],
        self::MACRO_BOOLEAN => [
            \T_LOGICAL_OR  => 1, \T_LOGICAL_XOR => 1,
            \T_BOOLEAN_AND => 1, \T_BOOLEAN_OR  => 1,
            \T_LOGICAL_AND => 1
        ],
        self::MACRO_MATH    => [
            "+" => 1, "-" => 1, "*" => 1,
            "/" => 1, "^" => 1, "%" => 1,
            "&" => 1, "|" => 1
        ],
        self::MACRO_COND    => [
            \T_IS_EQUAL            => 1, \T_IS_IDENTICAL        => 1, ">"                    => 1,
            "<"                    => 1, \T_SL                  => 1, \T_SR                  => 1,
            \T_IS_NOT_EQUAL        => 1, \T_IS_NOT_IDENTICAL    => 1, \T_IS_SMALLER_OR_EQUAL => 1,
        ],
        self::MACRO_EQUALS  => [
            \T_AND_EQUAL   => 1, \T_DIV_EQUAL   => 1, \T_MINUS_EQUAL => 1,
            \T_MOD_EQUAL   => 1, \T_MUL_EQUAL   => 1, \T_OR_EQUAL    => 1,
            \T_PLUS_EQUAL  => 1, \T_SL_EQUAL    => 1, \T_SR_EQUAL    => 1,
            \T_XOR_EQUAL   => 1, '='            => 1,
        ],
        self::MACRO_SCALAR  => [
            \T_LNUMBER                  => 1,
            \T_DNUMBER                  => 1,
            \T_CONSTANT_ENCAPSED_STRING => 1
        ]
    ];

    /**
     * Descriptions
     * @var array
     */
    public static $description = [
        self::MACRO_STRING  => 'string',
        self::MACRO_INCDEC  => 'increment/decrement operator',
        self::MACRO_UNARY   => 'unary operator',
        self::MACRO_BINARY  => 'binary operator',
        self::MACRO_BOOLEAN => 'boolean operator',
        self::MACRO_MATH    => 'math operator',
        self::MACRO_COND    => 'conditional operator',
        self::MACRO_EQUALS  => 'equal operator',
        self::MACRO_SCALAR  => 'scalar value'
    ];

    /**
     * Special tokens
     * @var array
     */
    private static $spec = [
        'true'  => 1,
        'false' => 1,
        'null'  => 1,
        'TRUE'  => 1,
        'FALSE' => 1,
        'NULL'  => 1
    ];

    /**
     * Constructor
     *
     * @param string $query
     */
    public function __construct(string $query)
    {
        $tokens  = [-1 => [\T_WHITESPACE, '', '', 1]];
        $_tokens = token_get_all("<?php " . $query);
        $line    = 1;

        array_shift($_tokens);

        $i = 0;
        foreach ($_tokens as $token)
        {
            if (is_string($token))
            {
                if ($token === '"' || $token === "'" || $token === "`")
                {
                    $this->quotes++;
                }

                $token = [
                    $token,
                    $token,
                    $line,
                ];
            }
            elseif ($token[0] === \T_WHITESPACE)
            {
                $tokens[$i - 1][2] = $token[1];
                continue;
            }
            elseif ($token[0] === \T_DNUMBER)
            {
                if (strpos($token[1], '.') === 0)
                {
                    $tokens[] = [
                        '.',
                        '.',
                        "",
                        $line = $token[2]
                    ];

                    $token = [
                        T_LNUMBER,
                        ltrim($token[1], '.'),
                        $line = $token[2]
                    ];
                }
                elseif (strpos($token[1], '.') === strlen($token[1]) - 1)
                {
                    $tokens[] = [
                        T_LNUMBER,
                        rtrim($token[1], '.'),
                        "",
                        $line = $token[2]
                    ];

                    $token = [
                        '.',
                        '.',
                        $line = $token[2]
                    ];
                }
            }

            $tokens[] = [
                $token[0],
                $token[1],
                "",
                $line = $token[2]
            ];

            $i++;
        }

        unset($tokens[-1]);
        $this->tokens = $tokens;
        $this->max = count($this->tokens) - 1;
        $this->last = $this->tokens[$this->max][3];
    }

    /**
     * Is incomplete mean some string not closed
     *
     * @return int
     */
    public function isIncomplete()
    {
        return ($this->quotes % 2) || ($this->tokens[$this->max][0] === T_ENCAPSED_AND_WHITESPACE);
    }

    /**
     * Get current element
     *
     * @return mixed Can return any type
     */
    public function current()
    {
        return $this->curr[1];
    }

    /**
     * Move forward to next element
     *
     * @return $this
     */
    public function next()
    {
        if ($this->pos > $this->max)
            return $this;

        $this->pos++;
        unset($this->prev, $this->curr, $this->next);
        return $this;
    }

    /**
     * Check token type
     * If token type is one of expected types return true
     * Otherwise return false
     *
     * @param array $expects
     * @param string|int $token
     * @return bool
     */
    private function isValid(array $expects, $token)
    {
        foreach ($expects as $expect)
        {
            if (is_string($expect) || $expect < 1000)
            {
                if ($expect === $token)
                    return true;
            }
            else
            {
                if (isset(self::$macros[$expect][$token]))
                    return true;
            }
        }
        return false;
    }

    /**
     * If the next token is a valid one
     * Move the position of cursor one step forward
     * Otherwise throws an exception
     *
     * @param array $tokens
     * @throws UnexpectedTokenException
     * @return mixed
     */
    public function nextStep(array $tokens)
    {
        $this->next();

        if (! $this->curr)
            throw new UnexpectedTokenException($this, $tokens);

        if ($tokens)
        {
            if ($this->isValid($tokens, $this->key()))
                return;
        }
        else
        {
            return;
        }
        throw new UnexpectedTokenException($this, $tokens);
    }

    /**
     * Fetch next specified token or throw an exception
     *
     * @return mixed
     */
    public function getNext( /*int|string $token1, int|string $token2, ... */)
    {
        $this->nextStep(func_get_args());
        return $this->current();
    }

    /**
     * Check the next token
     *
     * @param string $token
     * @return bool
     */
    public function isNextToken(string $token)
    {
        return $this->next ? $this->next[1] == $token : false;
    }

    /**
     * Get token and move pointer
     *
     * @return mixed
     * @throws UnexpectedTokenException
     */
    public function getAndNext( /* $token1, ... */)
    {
        if ($this->curr)
        {
            $cur = $this->curr[1];
            $this->next();
            return $cur;
        }
        throw new UnexpectedTokenException($this, func_get_args());
    }

    /**
     * Check if the next token is one of the specified
     *
     * @param string|int $token1
     * @return bool
     */
    public function isNext($token1 /*, ...*/)
    {
        return $this->next && $this->isValid(func_get_args(), $this->next[0]);
    }

    /**
     * Check if the current token is one of the specified
     * @param string|int $token1
     * @return bool
     */
    public function is($token1 /*, ...*/)
    {
        return $this->curr && $this->isValid(func_get_args(), $this->curr[0]);
    }

    /**
     * Check if the previous token is one of the specified
     *
     * @param string|int $token1
     * @return bool
     */
    public function isPrev($token1 /*, ...*/)
    {
        return $this->prev && $this->isValid(func_get_args(), $this->prev[0]);
    }

    /**
     * Get specified token
     *
     * @param string|int $token1
     * @throws UnexpectedTokenException
     * @return mixed
     */
    public function get($token1 /*, $token2 ...*/)
    {
        if ($this->curr && $this->isValid(func_get_args(), $this->curr[0]))
            return $this->curr[1];

        throw new UnexpectedTokenException($this, func_get_args());
    }

    /**
     * Step back
     *
     * @return $this
     */
    public function back()
    {
        if ($this->pos === 0)
            return $this;

        $this->pos--;
        unset($this->prev, $this->curr, $this->next);
        return $this;
    }

    /**
     * Check if has back list
     *
     * @param string|int $token1
     * @return bool
     */
    public function hasBackList($token1 /*, $token2 ...*/)
    {
        $tokens = func_get_args();
        $steps  = $this->pos;

        foreach ($tokens as $token)
        {
            $steps--;
            if ($steps < 0 || $this->tokens[$steps][0] !== $token)
            {
                return false;
            }
        }
        return true;
    }

    /**
     * Lazy load properties
     *
     * @param string $key
     * @return mixed
     */
    public function __get(string $key)
    {
        switch ($key)
        {
            case 'curr':
                return $this->curr = ($this->pos <= $this->max) ? $this->tokens[$this->pos] : null;
            case 'next':
                return $this->next = ($this->pos + 1 <= $this->max) ? $this->tokens[$this->pos + 1] : null;
            case 'prev':
                return $this->prev = $this->pos ? $this->tokens[$this->pos - 1] : null;
            default:
                return $this->$key = null;
        }
    }

    /**
     * Get max
     *
     * @return integer
     */
    public function count()
    {
        return $this->max;
    }

    /**
     * Get key of the current element
     *
     * @return mixed scalar on success, or null on failure.
     */
    public function key()
    {
        return $this->curr ? $this->curr[0] : null;
    }

    /**
     * Checks if current position is valid
     *
     * @return boolean The return value will be casted to boolean and then evaluated.
     *       Returns true on success or false on failure.
     */
    public function valid()
    {
        return (bool)$this->curr;
    }

    /**
     * Get token name
     *
     * @param int|string $token
     * @return string
     */
    public static function getName($token)
    {
        if (is_string($token))
            return $token;

        if (is_integer($token))
            return token_name($token);

        if (is_array($token))
            return token_name($token[0]);

        return null;
    }

    /**
     * Skip specific token or throw an exception
     *
     * @throws UnexpectedTokenException
     * @return $this
     */
    public function skip( /*$token1, $token2, ...*/)
    {
        if (func_num_args())
        {
            if ($this->isValid(func_get_args(), $this->curr[0]))
            {
                $this->next();
                return $this;
            }
            throw new UnexpectedTokenException($this, func_get_args());
        }
        else
        {
            $this->next();
            return $this;
        }
    }

    /**
     * Skip specific token or do nothing
     *
     * @param int|string $token1
     * @return $this
     */
    public function skipIf($token1 /*, $token2, ...*/)
    {
        if ($this->isValid(func_get_args(), $this->curr[0]))
        {
            $this->next();
        }
        return $this;
    }

    /**
     * Check current token's type
     *
     * @param int|string $token1
     * @throws UnexpectedTokenException
     * @return $this
     */
    public function need($token1 /*, $token2, ...*/)
    {
        if ($this->isValid(func_get_args(), $this->curr[0]))
            return $this;

        throw new UnexpectedTokenException($this, func_get_args());
    }

    /**
     * Get tokens near current position
     *
     * @param int $before count tokens before current token
     * @param int $after count tokens after current token
     * @return array
     */
    public function getSnippet($before = 0, $after = 0)
    {
        $from = 0;
        $to = $this->pos;

        if ($before > 0)
        {
            if ($before > $this->pos)
            {
                $from = $this->pos;
            }
            else
            {
                $from = $before;
            }
        }
        elseif ($before < 0)
        {
            $from = $this->pos + $before;

            if ($from < 0)
            {
                $from = 0;
            }
        }

        if ($after > 0)
        {
            $to = $this->pos + $after;

            if ($to > $this->max)
            {
                $to = $this->max;
            }
        }
        elseif ($after < 0)
        {
            $to = $this->max + $after;

            if ($to < $this->pos)
            {
                $to = $this->pos;
            }
        }
        elseif ($this->pos > $this->max)
        {
            $to = $this->max;
        }

        $code = [];

        for ($i = $from; $i <= $to; $i++)
        {
            $code[] = $this->tokens[$i];
        }
        return $code;
    }

    /**
     * Return snippet as string
     *
     * @param int $before
     * @param int $after
     * @return string
     */
    public function getSnippetAsString($before = 0, $after = 0)
    {
        $str = "";

        foreach ($this->getSnippet($before, $after) as $token)
        {
            $str .= $token[1] . $token[2];
        }
        return trim(str_replace("\n", '↵', $str));
    }

    /**
     * Check if current is special value: true, TRUE, false, FALSE, null, NULL
     *
     * @return bool
     */
    public function isSpecialVal()
    {
        return isset(self::$spec[$this->current()]);
    }

    /**
     * Check if the token is last
     *
     * @return bool
     */
    public function isLast()
    {
        return $this->pos === $this->max;
    }

    /**
     * Move pointer to the end
     *
     * @return $this
     */
    public function end()
    {
        $this->pos = $this->max;
        unset($this->prev, $this->curr, $this->next);
        return $this;
    }

    /**
     * Return line number of the current token
     *
     * @return mixed
     */
    public function getLine()
    {
        return $this->curr ? $this->curr[3] : $this->last;
    }

    /**
     * Is current token whitespaced, means previous token has whitespace characters
     *
     * @return bool
     */
    public function isWhiteSpaced()
    {
        return $this->prev ? (bool)$this->prev[2] : false;
    }

    /**
     * Get whitespace
     *
     * @return mixed
     */
    public function getWhitespace()
    {
        return $this->curr ? $this->curr[2] : false;
    }

    /**
     * Seek to custom element
     *
     * @param int $p
     * @return $this
     */
    public function seek($p)
    {
        $this->pos = $p;
        unset($this->prev, $this->curr, $this->next);
        return $this;
    }

}
