<?php

namespace placer\brio;

use placer\brio\engine\compiler\RuntimeCompiler;
use placer\brio\engine\BrioException;
use placer\brio\engine\Compiler;

class Brio
{
    /**
     * File extension
     *
     * @var string
     */
    const EXT = '.html.twig';

    /**
     * Path to the views diretory
     *
     * @var string
     */
    protected static $viewDir;

    /**
     * Path to the cache directiry
     *
     * @var string
     */
    protected static $caheDir;

    /**
     * Compiler options
     *
     * @var array
     */
    protected static $compilerOptions = [];

    /**
     * Enable debug
     *
     * @var boolean
     */
    protected static $debug = false;

    /**
     * Constructor
     *
     * @param array $settings
     */
    public function __construct(array $settings = [])
    {
        foreach ($settings as $key => $val)
        {
            if (property_exists($this, $key))
            {
                static::${$key} = $val;
            }
        }
    }

    /**
     *  Creates and returns a rendered view
     *
     *  @param string $view
     *  @param array  $vars
     *  @param bool   $return
     *  @param array  $blocks
     *
     *  @return string|null
     */
    public static function load(string $view, array $vars = [], $return = false, $blocks = [])
    {
        $caheDir = static::$caheDir;

        if (empty($caheDir))
        {
            throw new BrioException('Cache view directory isn`t defined [ "brio::config.caheDir" ]');
        }

        if (! is_file($view))
        {
            $view = static::getTemplatePath($view);
        }

        $pathHash = sha1($view);

        $callback = "brio_" . $pathHash;

        $cacheFile = $caheDir . DIRECTORY_SEPARATOR . $pathHash . '.php';

        if (is_file($cacheFile) && (filemtime($view) <= filemtime($cacheFile)))
        {
            require $cacheFile;

            if (is_callable($callback))
            {
                return $callback($vars, $return, $blocks);
            }
        }

        if (! is_dir(dirname($cacheFile)))
        {
            static::createCacheDirectory();
        }

        $file = fopen($cacheFile, "a+");

        if (! flock($file, LOCK_EX | LOCK_NB))
        {
            fclose($file);
            unset($file);
        }

        // Recompiling

        $compiler = static::getCompiler();

        if (static::$debug)
        {
            $compiler->setDebug($cacheFile . '.dump');
        }

        $fileString = $compiler->compileFile($view, false, $vars);

        if (isset($file))
        {
            ftruncate($file, 0);
            fwrite($file, '<?php' . $fileString);
            flock($file, LOCK_UN);
            fclose($file);
        }
        else
        {
            eval($fileString);
        }

        if (! is_callable($callback))
        {
            require $cacheFile;

            if (! is_callable($callback))
            {
                touch($cacheFile, 100, 100);
                chmod($cacheFile, 0777);

                // Compile temporarily

                $fileString = $compiler->compileFile($view, false, $vars);

                eval($fileString);
            }
        }

        return $callback($vars, $return, $blocks);
    }

    /**
     * Returns path to the view file, including location in packages
     *
     * @param  string  $file  File path
     *
     * @return string        Path to view
     */
    public static function getTemplatePath($file)
    {
        $viewDir = static::$viewDir;

        if (empty($viewDir))
        {
            throw new BrioException('Views directory isn`t defined [ "brio::config.viewDir" ]');
        }

        if (strpos($file, '::') !== false)
        {
            list($package, $file) = explode('::', $file);

            $subPath = 'vendor' . DIRECTORY_SEPARATOR . 'placer' . DIRECTORY_SEPARATOR . $package;

            $viewDir = str_replace('app', $subPath, $viewDir);
        }

        $file = str_replace('.', DIRECTORY_SEPARATOR, $file) . static::EXT;

        $filePath = $viewDir . DIRECTORY_SEPARATOR . $file;

        if (is_file($filePath))
        {
            return realpath($filePath);
        }

        throw new BrioException(vsprintf('Cannot find view file [ %s ]', [$filePath]));
    }

    /**
     *  Check the cache directory
     *
     *  @param string $dir
     *
     *  @return void
     */
    protected static function createCacheDirectory()
    {
        $directory = static::$caheDir;

        if (! is_dir($directory))
        {
            $old = umask(0);

            if (! @mkdir($directory, 0777, true))
            {
                throw new BrioException(vsprintf('Failed to create cache directory [ %s ]', [$directory]));
            }
            umask($old);
        }

        if (! is_writable($directory))
        {
            throw new BrioException(vsprintf('Cache directory [ %s ] not writable!', [$directory]));
        }
    }

    /**
     *  Get RuntimeCompiler instance
     *  The instance is already set up properly and resetted
     *
     *  @return placer\brio\engine\compiler\RuntimeCompiler
     */
    protected static function getCompiler()
    {
        if (! empty(static::$compilerOptions))
        {
            foreach (static::$compilerOptions as $key => $val)
            {
                Compiler::setOption($key, $val);
            }
        }

        $compiler = new RuntimeCompiler;

        $compiler->reset();

        return $compiler;
    }

}
