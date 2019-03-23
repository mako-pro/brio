<?php

namespace placer\brio\engine;

use ArrayObject;
use LogicException;
use RuntimeException;

use placer\brio\Brio;

class Tag extends ArrayObject
{
    const COMPILER = 1;
    const FUNC     = 2;
    const BLOCK    = 4;

    const LTRIM = 1;
    const RTRIM = 2;

    /**
     * Current template
     * @var Template
     */
    public $tpl;

    /**
     * Tag name
     * @var string
     */
    public $name;

    /**
     * Options
     * @var array
     */
    public $options = [];

    /**
     * Line number
     * @var integer
     */
    public $line = 0;

    /**
     * Level number
     * @var integer
     */
    public $level = 0;

    /**
     * Callback
     * @var string
     */
    public $callback;

    /**
     * Escape
     * @var integer
     */
    public $escape;

    /**
     * Offset
     * @var integer
     */
    private $offset = 0;

    /**
     * Closed
     * @var boolean
     */
    private $closed = true;

    /**
     * Body
     * @var string
     */
    private $body;

    /**
     * Type
     * @var integer
     */
    private $type = 0;

    /**
     * Open
     * @var boolean
     */
    private $open;

    /**
     * Close
     * @var boolean
     */
    private $close;

    /**
     * Tags
     * @var array
     */
    private $tags = [];

    /**
     * Floats
     * @var array
     */
    private $floats = [];

    /**
     * Changed
     * @var array
     */
    private $changed = [];

    /**
     * Create tag entity
     *
     * @param string $name the tag name
     * @param Template $tpl current template
     * @param array $info tag's information
     * @param string $body template's code
     */
    public function __construct(string $name, Template $tpl, array $info, &$body)
    {
        $this->tpl    = $tpl;
        $this->name   = $name;
        $this->line   = $tpl->getLine();
        $this->level  = $tpl->getStackSize();
        $this->body   = & $body;
        $this->offset = strlen($body);
        $this->type   = $info["type"];
        $this->escape = $tpl->getOptions() & Brio::AUTO_ESCAPE;

        if ($this->type & self::BLOCK)
        {
            $this->open   = $info["open"];
            $this->close  = $info["close"];
            $this->tags   = isset($info["tags"]) ? $info["tags"] : [];
            $this->floats = isset($info["float_tags"]) ? $info["float_tags"] : [];
            $this->closed = false;
        }
        else
        {
            $this->open = $info["parser"];
        }

        if ($this->type & self::FUNC)
        {
            $this->callback = $info["function"];
        }
    }

    /**
     * Set tag option
     *
     * @param string $option
     * @throws RuntimeException
     * @return void
     */
    public function tagOption(string $option)
    {
        if (method_exists($this, 'opt' . $option))
        {
            $this->options[] = $option;
        }
        else
        {
            throw new RuntimeException("Unknown tag option $option");
        }
    }

    /**
     * Rewrite template option for tag
     * When tag will be closed option will be reverted
     *
     * @param int $option option constant
     * @param bool $value true — add option, false — remove option
     * @return void
     */
    public function setOption(int $option, bool $value)
    {
        $actual = (bool)($this->tpl->getOptions() & $option);

        if ($actual != $value)
        {
            $this->changed[$option] = $actual;
            $this->tpl->setOption(Brio::AUTO_ESCAPE, $value);
        }
    }

    /**
     * Restore the option
     * @param int $option
     * @return void
     */
    public function restore(int $option)
    {
        if (isset($this->changed[$option]))
        {
            $this->tpl->setOption($option, $this->changed[$option]);
            unset($this->changed[$option]);
        }
    }

    /**
     * Restore all options
     *
     * @return void
     */
    public function restoreAll()
    {
        foreach ($this->changed as $option => $value)
        {
            $this->tpl->setOption($option, $this->changed[$option]);
            unset($this->changed[$option]);
        }
    }

    /**
     * Check, if the tag closed
     *
     * @return bool
     */
    public function isClosed()
    {
        return $this->closed;
    }

    /**
     * Open callback
     *
     * @param Tokenizer $tokenizer
     * @return mixed
     */
    public function start(Tokenizer $tokenizer)
    {
        foreach ($this->options as $option)
        {
            $option = 'opt' . $option;
            $this->$option();
        }

        return call_user_func($this->open, $tokenizer, $this);
    }

    /**
     * Check, has the block this tag
     *
     * @param string $tag
     * @param int $level
     * @return bool
     */
    public function hasTag(string $tag, int $level)
    {
        if (isset($this->tags[$tag]))
        {
            if ($level)
                return isset($this->floats[$tag]);

            return true;
        }
        return false;
    }


    /**
     * Call tag callback
     *
     * @param string $tag
     * @param Tokenizer $tokenizer
     * @throws LogicException
     * @return string
     */
    public function tag(string $tag, Tokenizer $tokenizer)
    {
        if (isset($this->tags[$tag]))
            return call_user_func($this->tags[$tag], $tokenizer, $this);

        throw new LogicException("The block tag {$this->name} no have tag {$tag}");
    }

    /**
     * Close callback
     *
     * @param Tokenizer $tokenizer
     * @throws LogicException
     * @return string
     */
    public function end(Tokenizer $tokenizer)
    {
        if ($this->closed)
            throw new LogicException("Tag {$this->name} already closed");

        if ($this->close)
        {
            foreach ($this->options as $option)
            {
                $option = 'opt' . $option . 'end';

                if (method_exists($this, $option))
                {
                    $this->$option();
                }
            }

            $code = call_user_func($this->close, $tokenizer, $this);
            $this->restoreAll();
            return $code;
        }
        throw new LogicException("Can not use a inline tag {$this->name} as a block");
    }

    /**
     * Forcefully close the tag
     *
     * @return void
     */
    public function close()
    {
        $this->closed = true;
    }

    /**
     * Returns tag's content
     *
     * @throws LogicException
     * @return string
     */
    public function getContent()
    {
        return substr($this->body, $this->offset);
    }

    /**
     * Cut tag's content
     *
     * @return string
     * @throws LogicException
     */
    public function cutContent()
    {
        $content = substr($this->body, $this->offset);
        $this->body = substr($this->body, 0, $this->offset);

        return $content;
    }

    /**
     * Replace tag's content
     *
     * @param string $content
     * @return void
     */
    public function replaceContent(string $content)
    {
        $this->cutContent();
        $this->body .= $content;
    }

    /**
     * Generate output code
     *
     * @param string $code
     * @return string
     */
    public function out(string $code)
    {
        return $this->tpl->out($code, $this->escape);
    }

    /**
     * Enable escape option for the tag
     *
     * @return void
     */
    public function optEscape()
    {
        $this->escape = true;
    }

    /**
     * Disable escape option for the tag
     *
     * @return void
     */
    public function optRaw()
    {
        $this->escape = false;
    }

    /**
     * Enable strip spaces option for the tag
     *
     * @return void
     */
    public function optStrip()
    {
        $this->setOption(Brio::AUTO_STRIP, true);
    }

    /**
     * Enable ignore for body of the tag
     *
     * @return void
     */
    public function optIgnore()
    {
        if (! $this->isClosed())
        {
            $this->tpl->ignore($this->name);
        }
    }

}
