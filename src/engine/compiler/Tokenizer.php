<?php

namespace placer\brio\engine\compiler;

use placer\brio\engine\compiler\Parser;
use placer\brio\engine\BrioException;
use placer\brio\engine\Extension;
use placer\brio\engine\Compiler;

class Tokenizer
{
    const IN_NONE    = 0;
    const IN_HTML    = 1;
    const IN_TAG     = 2;
    const IN_ECHO    = 3;

    /**
     * Keywords
     * @var array
     */
    protected static $keywords = [
        'AND'           => Parser::T_AND,
        'FALSE'         => Parser::T_FALSE,
        'NOT'           => Parser::T_NOT,
        'OR'            => Parser::T_OR,
        'TRUE'          => Parser::T_TRUE,
        '_('            => Parser::T_INTL,
        'as'            => Parser::T_AS,
        'autoescape'    => Parser::T_AUTOESCAPE,
        'block'         => Parser::T_BLOCK,
        'by'            => Parser::T_BY,
        'else'          => Parser::T_ELSE,
        'empty'         => Parser::T_EMPTY,
        'extends'       => Parser::T_EXTENDS,
        'filter'        => Parser::T_FILTER,
        'for'           => Parser::T_FOR,
        'if'            => Parser::T_IF,
        'ifchanged'     => Parser::T_IFCHANGED,
        'ifequal'       => Parser::T_IFEQUAL,
        'ifnotequal'    => Parser::T_IFNOTEQUAL,
        'in'            => Parser::T_IN,
        'include'       => Parser::T_INCLUDE,
        'load'          => Parser::T_LOAD,
        'not'           => Parser::T_NOT,
        'regroup'       => Parser::T_REGROUP,
        'set'           => Parser::T_SET,
        'spacefull'     => Parser::T_SPACEFULL,
        'step'          => Parser::T_STEP,
        'with'          => Parser::T_WITH,
    ];

    /**
     * Single operators
     * @var array
     */
    protected static $operatorsSingle = [
        '!'     => Parser::T_NOT,
        '%'     => Parser::T_MOD,
        '&'     => Parser::T_BITWISE,
        '('     => Parser::T_LPARENT,
        ')'     => Parser::T_RPARENT,
        '*'     => Parser::T_TIMES,
        '+'     => Parser::T_PLUS,
        ','     => Parser::T_COMMA,
        '-'     => Parser::T_MINUS,
        '.'     => Parser::T_DOT,
        '/'     => Parser::T_DIV,
        ':'     => Parser::T_COLON,
        '<'     => Parser::T_LT,
        '='     => Parser::T_ASSIGN,
        '>'     => Parser::T_GT,
        '?'     => Parser::T_QUESTION,
        '['     => Parser::T_BRACKETS_OPEN,
        ']'     => Parser::T_BRACKETS_CLOSE,
        '|'     => Parser::T_FILTER_PIPE,
    ];

    /**
     * Common operators
     * @var array
     */
    protected static $operators = [
        '!=='   => Parser::T_NE,
        '!='    => Parser::T_NE,
        '&&'    => Parser::T_AND,
        '->'    => Parser::T_OBJ,
        '..'    => Parser::T_DOTDOT,
        '::'    => Parser::T_CLASS,
        '<<'    => Parser::T_BITWISE,
        '<='    => Parser::T_LE,
        '==='   => Parser::T_EQ,
        '=='    => Parser::T_EQ,
        '>='    => Parser::T_GE,
        '>>'    => Parser::T_BITWISE,
        '||'    => Parser::T_PIPE,
    ];

    protected static $closeTags = [];

    protected static $openTag     = "{%";
    protected static $endTag      = "%}";
    protected static $openComment = "{#";
    protected static $endComment  = "#}";
    protected static $openPrint   = "{{";
    protected static $endPrint    = "}}";

    public $openTags;
    public $value;
    public $token;
    public $status = self::IN_NONE;

    protected $echoFirstToken = false;
    protected $compiler;
    protected $length;
    protected $data;
    protected $line;
    protected $cnt;

    /**
     * Constructor
     * @param Compiler $compiler
     * @param string   $data
     */
    function __construct(Compiler $compiler, string $data)
    {
        $this->compiler = $compiler;
        $this->data     = $data;
        $this->line     = 1;
        $this->cnt      = 0;
        $this->length   = strlen($data);

        self::$closeTags = [
            self::$endTag   => Parser::T_TAG_CLOSE,
            self::$endPrint => Parser::T_PRINT_CLOSE,
        ];

        $this->openTags = [
            self::$openTag     => Parser::T_TAG_OPEN,
            self::$openPrint   => Parser::T_PRINT_OPEN,
            self::$openComment => Parser::T_COMMENT,
        ];
    }

    /**
     * Initialization
     * @param  Compiler $compiler
     * @param  string   $data
     * @return array
     */
    public static function init(Compiler $compiler, string $data)
    {
        $lexer  = new Tokenizer($compiler, $data);
        $parser = new Parser($lexer, $compiler);

        try
        {
            for ($i=0; ; $i++)
            {
                if  (! $lexer->yylex())
                {
                    break;
                }
                $parser->doParse($lexer->token, $lexer->value);
            }
        }
        catch (Exception $e)
        {
            try
            {
                $parser->doParse(0, 0);
            }
            catch (Exception $y) {}
            throw $e;
        }

        $parser->doParse(0, 0);

        return (array) $parser->body;
    }

    /**
     * Get line number
     * @return integer $this->line
     */
    public function getLine()
    {
        return $this->line;
    }

    private function yylex()
    {
        $this->token = null;

        if ($this->length == $this->cnt)
        {
            if ($this->status != self::IN_NONE && $this->status != self::IN_HTML)
            {
                $this->error("Unexpected end!");
            }
            return false;
        }

        if ($this->status == self::IN_NONE)
        {
            $i    = &$this->cnt;
            $data = substr($this->data, $i, 12);

            static $lenCache = [];

            foreach ($this->openTags as $value => $token)
            {
                if (! isset($lenCache[$value]))
                {
                    $lenCache[$value] = strlen($value);
                }

                $len = $lenCache[$value];

                if (strncmp($data, $value, $len) == 0)
                {
                    $this->value = $value;
                    $this->token = $token;
                    $i += $len;

                    switch ($this->token)
                    {
                        case Parser::T_TAG_OPEN:
                            $this->status = self::IN_TAG;
                            break;
                        case Parser::T_COMMENT:
                            $zdata = & $this->data;
                            if (($pos=strpos($zdata, self::$endComment, $i)) === false) {
                                $this->error("unexpected end");
                            }
                            $this->value  = substr($zdata, $i, $pos-2);
                            $this->status = self::IN_NONE;
                            $i = $pos + 2;
                            break;
                        case Parser::T_PRINT_OPEN:
                            $this->status = self::IN_ECHO;
                            $this->echoFirstToken = false;
                            break;
                    }
                    return true;
                }
            }
            $this->status = self::IN_HTML;
        }

        switch ($this->status)
        {
            case self::IN_TAG:
            case self::IN_ECHO:
                $this->yylexMain();
                break;
            default:
                $this->yylexHtml();
        }

        if (empty($this->token))
        {
            if ($this->status != self::IN_NONE && $this->status != self::IN_HTML)
            {
                $this->error("Unexpected end");
            }
            return false;
        }
        return true;
    }

    private function yylexHtml()
    {
        $data = &$this->data;
        $i    = &$this->cnt;

        foreach ($this->openTags as $value => $status)
        {
            $pos = strpos($data, $value, $i);

            if ($pos === false)
            {
                continue;
            }

            if (! isset($lowestPos) || $lowestPos > $pos)
            {
                $lowestPos = $pos;
            }
        }

        if (isset($lowestPos))
        {
            $this->value  = substr($data, $i, $lowestPos - $i);
            $this->token  = Parser::T_HTML;
            $this->status = self::IN_NONE;
            $i += $lowestPos - $i;
        }
        else
        {
            $this->value  = substr($data, $i);
            $this->token  = Parser::T_HTML;
            $i = $this->length;
        }
        $this->line += substr_count($this->value, "\n");
    }


    private function yylexMain()
    {
        $data = &$this->data;

        for ($i=&$this->cnt; is_null($this->token) && $i < $this->length; ++$i)
        {
            switch ($data[$i])
            {
                case '"':
                case "'":
                    $end   = $data[$i];
                    $value = "";
                    while ($data[++$i] != $end) {
                        switch ($data[$i]) {
                        case "\\":
                            switch ($data[++$i]) {
                            case "n":
                                $value .= "\n";
                                break;
                            case "t":
                                $value .= "\t";
                                break;
                            default:
                                $value .= $data[$i];
                            }
                            break;
                        case $end:
                            --$i;
                            break 2;
                        default:
                            if ($data[$i] == "\n") {
                                $this->line++;
                            }
                            $value .= $data[$i];
                        }
                        if (!isset($data[$i+1])) {
                            $this->error("Unclosed string!");
                        }
                    }
                    $this->value = $value;
                    $this->token = Parser::T_STRING;
                    break;

                case '0': case '1': case '2': case '3': case '4':
                case '5': case '6': case '7': case '8': case '9':
                    $value = "";
                    $dot   = false;
                    for ($e=0; $i < $this->length; ++$e, ++$i) {
                        switch ($data[$i]) {
                        case '0': case '1': case '2': case '3': case '4':
                        case '5': case '6': case '7': case '8': case '9':
                            $value .= $data[$i];
                            break;
                        case '.':
                            if (! $dot) {
                                $value .= ".";
                                $dot    = true;
                            } else {
                                $this->error("Invalid number!");
                            }
                            break;
                        default:
                            break 2;
                        }
                    }
                    if (! $this->isTokenEnd($data[$i]) &&
                        ! isset(self::$operatorsSingle[$data[$i]]) || $value[$e-1] == '.') {
                        $this->error("Unexpected '{$data[$i]}'");
                    }
                    $this->value = $value;
                    $this->token = Parser::T_NUMERIC;
                    break 2;

                case "\n": case " ": case "\t": case "\r": case "\f":
                    for (; is_null($this->token) && $i < $this->length; ++$i) {
                        switch ($data[$i]) {
                        case "\n":
                            $this->line++;
                        case " ": case "\t": case "\r": case "\f":
                            break;
                        case '.':
                            if ($data[$i+1] != '.') {
                                $this->token = Parser::T_CONCAT;
                                $this->value = '.';
                                $i++;
                                return;
                            }
                        default:
                            --$i;
                            break 2;
                        }
                    }
                    break;
                default:
                    if (! $this->getTag() && !$this->getOperator()) {
                        $alpha = $this->getAlpha();
                        if ($alpha === false) {
                            $this->error("error: unexpected ".substr($data, $i));
                        }
                        static $tag=null;
                        if (! $tag) {
                            $tag = Extension::getInstance('Tag');
                        }
                        if ($this->status == self::IN_ECHO && !$this->echoFirstToken) {
                            $this->token =  Parser::T_ALPHA;
                        } else {
                            $value = $tag->isValid($alpha);
                            $this->token = $value ? $value : Parser::T_ALPHA;
                        }
                        $this->value = $alpha;
                    }
                    break 2;
            }
        }

        if ($this->status == self::IN_ECHO)
        {
            $this->echoFirstToken = true;
        }

        if ($this->token == Parser::T_TAG_CLOSE || $this->token == Parser::T_PRINT_CLOSE)
        {
            $this->status = self::IN_NONE;
        }
    }

    private function getTag()
    {
        static $lenCache = [];

        $i    = &$this->cnt;
        $data = substr($this->data, $i, 12);

        foreach (self::$closeTags as $value => $token)
        {
            if (! isset($lenCache[$value]))
            {
                $lenCache[$value] = strlen($value);
            }

            $len = $lenCache[$value];

            if (strncmp($data, $value, $len) == 0)
            {
                $this->token = $token;
                $this->value = $value;
                $i += $len;
                return true;
            }
        }

        foreach (self::$keywords as $value => $token)
        {
            if (! isset($lenCache[$value]))
            {
                $lenCache[$value] = strlen($value);
            }

            $len = $lenCache[$value];

            switch (strncmp($data, $value, $len))
            {
                case -1:
                    break 2;
                case 0:
                    if (isset($data[$len]) && !$this->isTokenEnd($data[$len])) {
                        continue;
                    }
                    $this->token = $token;
                    $this->value = $value;
                    $i += $len;
                    return true;
            }
        }

        if (strncmp($data, "end", 3) == 0)
        {
            $this->value = $this->getAlpha();
            $this->token = Parser::T_CUSTOM_END;
            return true;
        }
        return false;
    }

    private function getOperator()
    {
        static $lenCache = [];

        $i    = &$this->cnt;
        $data = substr($this->data, $i, 12);

        foreach (self::$operators as $value => $token)
        {
            if (! isset($lenCache[$value]))
            {
                $lenCache[$value] = strlen($value);
            }

            $len = $lenCache[$value];

            switch (strncmp($data, $value, $len))
            {
                case -1:
                    if (strlen($data) == $len) {
                        break 2;
                    }
                    break;
                case 0:
                    $this->token = $token;
                    $this->value = $value;
                    $i += $len;
                    return true;
            }
        }

        $data = $this->data[$i];

        foreach (self::$operatorsSingle as $value => $token)
        {
            if ($value == $data)
            {
                $this->token = $token;
                $this->value = $value;
                $i += 1;
                return true;
            }
            elseif ($value > $data)
            {
                break;
            }
        }
        return false;
    }

    private function isTokenEnd($letter)
    {
        return ! (
            ('a' <= $letter && 'z' >= $letter) ||
            ('A' <= $letter && 'Z' >= $letter) ||
            ('0' <= $letter && '9' >= $letter) ||
            $letter == "_"
        );
    }

    private function getAlpha()
    {
        $i    = &$this->cnt;
        $data = &$this->data;

        if (! ('a' <= $data[$i] && 'z' >= $data[$i]) &&
            ! ('A' <= $data[$i] && 'Z' >= $data[$i]) && $data[$i] != '_')
        {
            return false;
        }

        $value  = "";
        for (; $i < $this->length; ++$i)
        {
            if (('a' <= $data[$i] && 'z' >= $data[$i]) ||
                ('A' <= $data[$i] && 'Z' >= $data[$i]) ||
                ('0' <= $data[$i] && '9' >= $data[$i]) ||
                $data[$i] == "_")
            {
                $value .= $data[$i];
            }
            else
            {
                break;
            }
        }
        return $value;
    }

    private function error(string $text)
    {
        throw new BrioException($text . " in " . $this->compiler->getTemplateFile() . ":" . $this->line);
    }

}
