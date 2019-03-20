<?php

namespace placer\brio;

use Exception;
use RuntimeException;
use FilesystemIterator;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

use placer\brio\engine\Template;
use placer\brio\engine\error\CompileException;
use mako\view\renderers\RendererInterface;

class Brio implements RendererInterface
{
    /**
     * Actions
     */
    const INLINE_COMPILER = 1;
    const BLOCK_COMPILER  = 5;
    const INLINE_FUNCTION = 2;
    const BLOCK_FUNCTION  = 7;

    /**
     * Options
     */
    const DENY_ACCESSOR     = 0x8;
    const DENY_METHODS      = 0x10;
    const DENY_NATIVE_FUNCS = 0x20;
    const FORCE_INCLUDE     = 0x40;
    const AUTO_RELOAD       = 0x80;
    const FORCE_COMPILE     = 0x100;
    const AUTO_ESCAPE       = 0x200;
    const DISABLE_CACHE     = 0x400;
    const FORCE_VERIFY      = 0x800;
    const AUTO_TRIM         = 0x1000;
    const AUTO_STRIP        = 0x4000;

    /**
     * Default parsers
     */
    const DEFAULT_CLOSE_COMPILER = 'placer\brio\engine\Compiler::stdClose';
    const DEFAULT_FUNC_PARSER    = 'placer\brio\engine\Compiler::stdFuncParser';
    const DEFAULT_FUNC_OPEN      = 'placer\brio\engine\Compiler::stdFuncOpen';
    const DEFAULT_FUNC_CLOSE     = 'placer\brio\engine\Compiler::stdFuncClose';
    const SMART_FUNC_PARSER      = 'placer\brio\engine\Compiler::smartFuncParser';

    const MAX_MACRO_RECURSIVE = 32;

    /**
     * Accessors
     */
    const ACCESSOR_CUSTOM   = null;
    const ACCESSOR_VAR      = 'placer\brio\engine\Accessor::parserVar';
    const ACCESSOR_CALL     = 'placer\brio\engine\Accessor::parserCall';
    const ACCESSOR_PROPERTY = 'placer\brio\engine\Accessor::parserProperty';
    const ACCESSOR_METHOD   = 'placer\brio\engine\Accessor::parserMethod';
    const ACCESSOR_CHAIN    = 'placer\brio\engine\Accessor::parserChain';

    /**
     * Brio options
     * @var array
     */
    private static $defaultOptions = [
        "disable_accessor" => self::DENY_ACCESSOR,
        "disable_methods"  => self::DENY_METHODS,
        "disable_funcs"    => self::DENY_NATIVE_FUNCS,
        "disable_cache"    => self::DISABLE_CACHE,
        "force_compile"    => self::FORCE_COMPILE,
        "auto_reload"      => self::AUTO_RELOAD,
        "force_include"    => self::FORCE_INCLUDE,
        "auto_escape"      => self::AUTO_ESCAPE,
        "force_verify"     => self::FORCE_VERIFY,
        "auto_trim"        => self::AUTO_TRIM,
        "strip"            => self::AUTO_STRIP,
    ];

    /**
     * Define charset
     * @var string
     */
    public static $charset = 'UTF-8';

    /**
     * Templates storage
     * @var array
     */
    protected $templates = [];

    /**
     * Masked options
     * @var int
     */
    protected $options = 0;

    /**
     * Modifiers list
     * @var array
     */
    protected $modifiers = [
        "upper"       => 'strtoupper',
        "up"          => 'strtoupper',
        "lower"       => 'strtolower',
        "low"         => 'strtolower',
        "date_format" => 'placer\brio\engine\Modifier::dateFormat',
        "date"        => 'placer\brio\engine\Modifier::date',
        "truncate"    => 'placer\brio\engine\Modifier::truncate',
        "escape"      => 'placer\brio\engine\Modifier::escape',
        "e"           => 'placer\brio\engine\Modifier::escape',
        "unescape"    => 'placer\brio\engine\Modifier::unescape',
        "strip"       => 'placer\brio\engine\Modifier::strip',
        "length"      => 'placer\brio\engine\Modifier::length',
        "iterable"    => 'placer\brio\engine\Modifier::isIterable',
        "replace"     => 'placer\brio\engine\Modifier::replace',
        "ereplace"    => 'placer\brio\engine\Modifier::ereplace',
        "match"       => 'placer\brio\engine\Modifier::match',
        "ematch"      => 'placer\brio\engine\Modifier::ematch',
        "split"       => 'placer\brio\engine\Modifier::split',
        "esplit"      => 'placer\brio\engine\Modifier::esplit',
        "join"        => 'placer\brio\engine\Modifier::join',
        "in"          => 'placer\brio\engine\Modifier::in',
        "range"       => 'placer\brio\engine\Modifier::range',
    ];

    /**
     * Allowed PHP functions
     * @var array
     */
    protected $allowedFuncs = [
        "count"       => 1,
        "is_string"   => 1,
        "is_array"    => 1,
        "is_numeric"  => 1,
        "is_int"      => 1,
        'constant'    => 1,
        "is_object"   => 1,
        "strtotime"   => 1,
        "gettype"     => 1,
        "is_double"   => 1,
        "json_encode" => 1,
        "json_decode" => 1,
        "ip2long"     => 1,
        "long2ip"     => 1,
        "strip_tags"  => 1,
        "nl2br"       => 1,
        "explode"     => 1,
        "implode"     => 1
    ];

    /**
     * Compiler actions
     * @var array
     */
    protected $actions = [
        'foreach' => [
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'placer\brio\engine\Compiler::foreachOpen',
            'close' => 'placer\brio\engine\Compiler::foreachClose',
            'tags'  => [
                'foreachelse' => 'placer\brio\engine\Compiler::foreachElse',
                'break'       => 'placer\brio\engine\Compiler::tagBreak',
                'continue'    => 'placer\brio\engine\Compiler::tagContinue',
            ],
            'float_tags' => ['break' => 1, 'continue' => 1]
        ],
        'if' => [
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'placer\brio\engine\Compiler::ifOpen',
            'close' => 'placer\brio\engine\Compiler::stdClose',
            'tags'  => [
                'elseif' => 'placer\brio\engine\Compiler::tagElseIf',
                'else'   => 'placer\brio\engine\Compiler::tagElse'
            ]
        ],
        'switch' => [
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'placer\brio\engine\Compiler::switchOpen',
            'close' => 'placer\brio\engine\Compiler::switchClose',
            'tags'  => [
                'case'    => 'placer\brio\engine\Compiler::tagCase',
                'default' => 'placer\brio\engine\Compiler::tagDefault'
            ],
            'float_tags' => ['break' => 1]
        ],
        'for' => [
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'placer\brio\engine\Compiler::forOpen',
            'close' => 'placer\brio\engine\Compiler::forClose',
            'tags'  => [
                'forelse'  => 'placer\brio\engine\Compiler::forElse',
                'break'    => 'placer\brio\engine\Compiler::tagBreak',
                'continue' => 'placer\brio\engine\Compiler::tagContinue',
            ],
            'float_tags' => ['break' => 1, 'continue' => 1]
        ],
        'while' => [
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'placer\brio\engine\Compiler::whileOpen',
            'close' => 'placer\brio\engine\Compiler::stdClose',
            'tags'  => [
                'break'    => 'placer\brio\engine\Compiler::tagBreak',
                'continue' => 'placer\brio\engine\Compiler::tagContinue',
            ],
            'float_tags' => ['break' => 1, 'continue' => 1]
        ],
        'include' => [
            'type'   => self::INLINE_COMPILER,
            'parser' => 'placer\brio\engine\Compiler::tagInclude'
        ],
        'insert' => [
            'type'   => self::INLINE_COMPILER,
            'parser' => 'placer\brio\engine\Compiler::tagInsert'
        ],
        'var' => [
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'placer\brio\engine\Compiler::setOpen',
            'close' => 'placer\brio\engine\Compiler::setClose'
        ],
        'set' => [
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'placer\brio\engine\Compiler::setOpen',
            'close' => 'placer\brio\engine\Compiler::setClose'
        ],
        'add' => [
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'placer\brio\engine\Compiler::setOpen',
            'close' => 'placer\brio\engine\Compiler::setClose'
        ],
        'do' => [
            'type'   => self::INLINE_COMPILER,
            'parser' => 'placer\brio\engine\Compiler::tagDo'
        ],
        'block' => [
            'type'       => self::BLOCK_COMPILER,
            'open'       => 'placer\brio\engine\Compiler::tagBlockOpen',
            'close'      => 'placer\brio\engine\Compiler::tagBlockClose',
            'tags'       => array('parent' => 'placer\brio\engine\Compiler::tagParent'),
            'float_tags' => array('parent' => 1)
        ],
        'extends' => [
            'type'   => self::INLINE_COMPILER,
            'parser' => 'placer\brio\engine\Compiler::tagExtends'
        ],
        'use' => [
            'type'   => self::INLINE_COMPILER,
            'parser' => 'placer\brio\engine\Compiler::tagUse'
        ],
        'filter' => [
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'placer\brio\engine\Compiler::filterOpen',
            'close' => 'placer\brio\engine\Compiler::filterClose'
        ],
        'macro' => [
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'placer\brio\engine\Compiler::macroOpen',
            'close' => 'placer\brio\engine\Compiler::macroClose'
        ],
        'import' => [
            'type'   => self::INLINE_COMPILER,
            'parser' => 'placer\brio\engine\Compiler::tagImport'
        ],
        'cycle' => [
            'type'   => self::INLINE_COMPILER,
            'parser' => 'placer\brio\engine\Compiler::tagCycle'
        ],
        'raw' => [
            'type'   => self::INLINE_COMPILER,
            'parser' => 'placer\brio\engine\Compiler::tagRaw'
        ],
        'autoescape' => [
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'placer\brio\engine\Compiler::escapeOpen',
            'close' => 'placer\brio\engine\Compiler::nope'
        ],
        'escape' => [
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'placer\brio\engine\Compiler::escapeOpen',
            'close' => 'placer\brio\engine\Compiler::nope'
        ],
        'strip' => [
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'placer\brio\engine\Compiler::stripOpen',
            'close' => 'placer\brio\engine\Compiler::nope'
        ],
        'ignore' => [
            'type'  => self::BLOCK_COMPILER,
            'open'  => 'placer\brio\engine\Compiler::ignoreOpen',
            'close' => 'placer\brio\engine\Compiler::nope'
        ],
        'unset' => [
            'type'   => self::INLINE_COMPILER,
            'parser' => 'placer\brio\engine\Compiler::tagUnset'
        ],
        'paste' => [
            'type'   => self::INLINE_COMPILER,
            'parser' => 'placer\brio\engine\Compiler::tagPaste'
        ],
    ];

    /**
     * Check functions
     * @var array
     */
    protected $checkFunctions = [
        'integer'  => 'is_int(%s)',
        'int'      => 'is_int(%s)',
        'float'    => 'is_float(%s)',
        'double'   => 'is_float(%s)',
        'decimal'  => 'is_float(%s)',
        'string'   => 'is_string(%s)',
        'bool'     => 'is_bool(%s)',
        'boolean'  => 'is_bool(%s)',
        'number'   => 'is_numeric(%s)',
        'numeric'  => 'is_numeric(%s)',
        'scalar'   => 'is_scalar(%s)',
        'object'   => 'is_object(%s)',
        'callable' => 'is_callable(%s)',
        'callback' => 'is_callable(%s)',
        'array'    => 'is_array(%s)',
        'iterable' => 'placer\brio\engine\Modifier::isIterable(%s)',
        'const'    => 'defined(%s)',
        'template' => '$tpl->getStorage()->templateExists(%s)',
        'empty'    => 'empty(%s)',
        'set'      => 'isset(%s)',
        '_empty'   => '!%s',
        '_set'     => '(%s !== null)',
        'odd'      => '(%s & 1)',
        'even'     => '!(%s %% 2)',
        'third'    => '!(%s %% 3)'
    ];

    /**
     * Accessors
     * @var array
     */
    protected $accessors = [
        'get'     => 'placer\brio\engine\Accessor::getVar',
        'env'     => 'placer\brio\engine\Accessor::getVar',
        'post'    => 'placer\brio\engine\Accessor::getVar',
        'request' => 'placer\brio\engine\Accessor::getVar',
        'cookie'  => 'placer\brio\engine\Accessor::getVar',
        'globals' => 'placer\brio\engine\Accessor::getVar',
        'server'  => 'placer\brio\engine\Accessor::getVar',
        'session' => 'placer\brio\engine\Accessor::getVar',
        'files'   => 'placer\brio\engine\Accessor::getVar',
        'tpl'     => 'placer\brio\engine\Accessor::tpl',
        'const'   => 'placer\brio\engine\Accessor::constant',
        'php'     => 'placer\brio\engine\Accessor::call',
        'call'    => 'placer\brio\engine\Accessor::call',
        'tag'     => 'placer\brio\engine\Accessor::Tag',
        'fetch'   => 'placer\brio\engine\Accessor::fetch',
        'block'   => 'placer\brio\engine\Accessor::block',
    ];

    /**
     * Clearing template files status cache
     * @var bool
     */
    protected $clearCache = false;

    /**
     * Template file extension
     * @var string
     */
    public $fileExtension;

    /**
     * Path to template files
     * @var string
     */
    protected $templatesDirectory;

    /**
     * Path to compiled files
     * @var string
     */
    protected $compileDirectory;

    /**
     * Constructor
     *
     * @param array $settings
     */
    public function __construct(array $settings = [])
    {
        $this->templatesDirectory   = $settings['templates'];
        $this->fileExtension        = $settings['extension'];
        $this->setCompileDirectory($settings['compilations']);

        if (isset($settings['options']) &&
            ($options = $settings['options']))
                $this->setOptions($options);
    }

    /**
     * Render the view
     *
     * @param  string $view      Path to view file
     * @param  array  $variables View variables
     * @return string
     */
    public function render(string $view, array $variables): string
    {
        unset($variables['__viewfactory__']);

        return (string) $this->display($view, $variables);
    }

    /**
     * Get modifier function
     *
     * @param string $modifier
     * @param Template $template
     * @return mixed
     */
    public function getModifier(string $modifier, Template $template = null)
    {
        if (isset($this->modifiers[$modifier]))
            return $this->modifiers[$modifier];

        if ($this->isAllowedFunction($modifier))
            return $modifier;

        return false;
    }

    /**
     * Check php-functions by alias
     *
     * @param string $name
     * @return string|bool
     */
    public function getCheckFunctions(string $name)
    {
        return isset($this->checkFunctions[$name])
                    ? $this->checkFunctions[$name]
                    : false;
    }

    /**
     * Get structure tag data
     *
     * @param string $tag
     * @param Template $template
     * @return string|bool
     */
    public function getTag(string $tag, Template $template = null)
    {
        if (isset($this->actions[$tag]))
            return $this->actions[$tag];

        return false;
    }

    /**
     * Check for unknown template tag
     *
     * @param string $tag
     * @return array
     */
    public function getTagKeys(string $tag)
    {
        $tags = [];

        foreach ($this->actions as $key => $values)
        {
            if (isset($values["tags"][$tag]))
            {
                $tags[] = $key;
            }
        }

        return $tags;
    }

    /**
     * Get options as bits
     *
     * @return int
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Get accessor
     *
     * @param string $name
     * @param string $key
     * @return callable|bool
     */
    public function getAccessor(string $name, string $key = null)
    {
        if (isset($this->accessors[$name]))
        {
            if ($key)
                return $this->accessors[$name][$key];

            return $this->accessors[$name];
        }

        return false;
    }

    /**
     * Get blank template
     *
     * @return Template
     */
    public function getRawTemplate(Template $parent = null)
    {
        return new Template($this, $this->options, $parent);
    }

    /**
     * Get template by name & options
     *
     * @param  string $template
     * @param  int $options
     * @return Template
     */
    public function getTemplate(string $template, int $options = 0)
    {
        $options |= $this->options;

        $tplHash = sha1($template);

        $key = $options . "@" . $tplHash;

        if (isset($this->templates[$key]))
        {
            $template = $this->templates[$key];

            if (($this->options & self::AUTO_RELOAD) && ! $template->isValid())
            {
                return $this->templates[$key] = $this->compile($template, true, $options);
            }
            return $template;
        }

        if ($this->options & (self::FORCE_COMPILE | self::DISABLE_CACHE))
        {
            return $this->compile($template, ! ($this->options & self::DISABLE_CACHE), $options);
        }

        $template = $this->templates[$key] = $this->load($template, $options);

        if (! $template->isValid())
        {
            return $this->compile($template, ! ($this->options & self::DISABLE_CACHE), $options);
        }
        return $template;
    }

    /**
     * Check if template exists (calling from tpl body)
     *
     * @param string $template
     * @return bool
     */
    public function templateExists(string $template)
    {
        $key = $this->options . "@" . sha1($template);

        if (isset($this->templates[$key]))
            return true;

        return (bool) $this->getRealTemplatePath($template);
    }

    /**
     * Compile and save the template
     *
     * @param string $template
     * @param bool $store
     * @param int $options
     * @throws CompileException
     * @return Template
     */
    public function compile(string $template, bool $store = true, int $options = 0)
    {
        $template = $this->getRawTemplate()->load($template);

        if ($store)
        {
            $cacheName = $this->getCompileName($template, $options);

            $compilePath = $this->compileDirectory . '/' . $cacheName . '.' . mt_rand(0, 100000) . '.tmp';

            if (! file_put_contents($compilePath, $template->getTemplateCode()))
            {
                throw new CompileException(
                    "Can't to write to the file $compilePath. Directory " . $this->compileDirectory . " is not writable."
                );
            }

            $cachePath = $this->compileDirectory . '/' . $cacheName;

            if (! rename($compilePath, $cachePath))
            {
                unlink($compilePath);
                throw new CompileException("Can't to move the file $compilePath -> $cachePath");
            }
        }
        return $template;
    }

    /**
     * Remove all compiled templates
     * Flush templates in-memory-cache
     */
    public function clearAllCompiles()
    {
        $this->clean($this->compileDirectory);

        $this->templates = [];
    }

    /**
     * Get the origin view file
     *
     * @param string $template
     * @param int $time
     * @return string
     */
    public function getViewSource(string $template, &$time)
    {
        if (! realpath($template))
            throw new Exception("Template $template not found");

        if ($this->clearCache === true)
            clearstatcache(true, $template);

        // For use in included template body
        $time = filemtime($template);

        return file_get_contents($template);
    }

    /**
     * Get last modified time
     *
     * @param string $template
     * @return int
     */
    public function getLastModified(string $template)
    {
        if (! realpath($template))
            throw new Exception("Template $template not found");

        if ($this->clearCache === true)
            clearstatcache(true, $template);

        return filemtime($template);
    }

    /**
     * Verify templates (check mtime)
     *
     * @param array $templates [template_name => modified]
     * @return bool
     */
    public function verify(array $templates)
    {
        foreach ($templates as $template => $mtime)
        {
            if (! realpath($template))
                throw new Exception("Template $template not found");

            if ($this->clearCache === true)
                clearstatcache(true, $template);

            if (filemtime($template) !== $mtime)
                return false;
        }
        return true;
    }

    /**
     * Set the compile directory
     *
     * @param string $directory
     * @throws Exception
     * @return $this
     */
    protected function setCompileDirectory(string $directory)
    {
        if (! is_writable($directory))
            throw new Exception(
                "Cache directory $directory is not writable"
            );

        $this->compileDirectory = $directory;

        return $this;
    }

    /**
     * Set options
     *
     * @param int|array $options
     * @return $this
     */
    protected function setOptions($options)
    {
        if (is_array($options))
        {
            $options = self::makeMask(
                $options,
                self::$defaultOptions,
                $this->options
            );
        }

        $this->templates = [];
        $this->options = $options;

        return $this;
    }

    /**
     * Check if native php-function is allowed
     *
     * @param string $function
     * @return bool
     */
    protected function isAllowedFunction(string $function)
    {
        if ($this->options & self::DENY_NATIVE_FUNCS)
            return isset($this->allowedFuncs[$function]);

        return is_callable($function);
    }

    /**
     * Execute template and write result into stdout
     *
     * @param string $template
     * @param array $vars
     * @return Render
     */
    protected function display(string $template, array $vars = [])
    {
        return $this->getTemplate($template)->display($vars);
    }

    /**
     * Fetching template
     *
     * @param string $template
     * @param array $vars
     * @return mixed
     */
    protected function fetch(string $template, array $vars = [])
    {
        return $this->getTemplate($template)->fetch($vars);
    }

    /**
     * Creates pipe-line of template's data to callback
     *
     * @param string   $template
     * @param callable $callback
     * @param array    $vars
     * @param float    $chunk    Bytes of chunk
     * @return array
     */
    protected function pipe(string $template, callable $callback, array $vars = [], $chunk = 1e6)
    {
        ob_start($callback, $chunk, PHP_OUTPUT_HANDLER_STDFLAGS);

        $data = $this->getTemplate($template)->display($vars);

        ob_end_flush();

        return $data;
    }

    /**
     * Load template from cache
     *
     * @param string $template
     * @param int $options
     * @return Render
     */
    protected function load(string $template, int $options)
    {
        $template = $this->getRealTemplatePath($template);

        $fileName = $this->getCompileName($template, $options);

        if (is_file($this->compileDirectory . "/" . $fileName))
        {
            // For included below template body
            $brio = $this;

            $templateFile = include($this->compileDirectory . "/" . $fileName);

            if (! ($this->options & self::AUTO_RELOAD)
                || ($this->options & self::AUTO_RELOAD)
                && $templateFile instanceof Render
                && $templateFile->isValid())
            {
                return $templateFile;
            }
        }
        return $this->compile($template, true, $options);
    }

    /**
     * Create unique name for compiled template
     *
     * @param string $template Template path
     * @param int $options Additional options
     * @return string
     */
    protected function getCompileName(string $template, int $options = 0)
    {
        $options = $this->options | $options;

        $templateHash = sha1($template);

        return $options . "@" . $templateHash . ".php";
    }

    /**
     * Get realpath of template
     *
     * @param string $template
     * @return bool|string
     */
    public function getRealTemplatePath(string $template)
    {
        if ($path = realpath($template))
            return $path;

        $tplDir = $this->templatesDirectory;

        if (strpos($template, '::') !== false)
        {
            list($package, $template) = explode('::', $template);

            $subPath = 'vendor/packages/' . $package;

            $tplDir = str_replace('app', $subPath, $tplDir);
        }

        $template = str_replace('.', '/', $template) . $this->fileExtension;

        $filePath = $tplDir . '/' . $template;

        return realpath($filePath);
    }

    /**
     * Get template path
     *
     * @param string $template
     * @return string
     * @throws Exception
     */
    protected function getTemplatePath($template)
    {
        if ($path = $this->getRealTemplatePath($template))
            return $path;

        throw new Exception("Template $template not found");
    }

    /**
     * Clean directory from files
     *
     * @param string $path
     */
    protected function clean(string $path)
    {
        if (is_file($path))
        {
            unlink($path);
        }
        elseif (is_dir($path))
        {
            $iterator = iterator_to_array(
                new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path,
                        FilesystemIterator::KEY_AS_PATHNAME |
                        FilesystemIterator::CURRENT_AS_FILEINFO |
                        FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                )
            );

            foreach ($iterator as $file)
            {
                if ($file->isFile())
                {
                    if (strpos($file->getBasename(), ".") !== 0)
                    {
                        unlink($file->getRealPath());
                    }
                }
                elseif ($file->isDir())
                {
                    rmdir($file->getRealPath());
                }
            }
        }
    }

    /**
     * Create bit-mask from associative array
     * Use fully associative array possible keys with bit values
     *
     * @param array $values custom assoc array, ["a" => true, "b" => false]
     * @param array $options possible values, ["a" => 0b001, "b" => 0b010, "c" => 0b100]
     * @param int $mask the initial value of the mask
     * @return int result, ( $mask | a ) & ~b
     * @throws RuntimeException if key from custom assoc doesn't exists into possible values
     */
    private static function makeMask(array $values, array $options, int $mask = 0)
    {
        foreach ($values as $key => $value)
        {
            if (isset($options[$key]))
            {
                if ($value)
                {
                    $mask |= $options[$key];
                }
                else
                {
                    $mask &= ~$options[$key];
                }
            }
            else
            {
                throw new RuntimeException("Undefined parameter $value");
            }
        }
        return $mask;
    }

}
