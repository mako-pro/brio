<?php

namespace placer\brio;

use placer\brio\engine\compiler\RuntimeCompiler;
use placer\brio\engine\BrioException;
use placer\brio\engine\Compiler;

class Brio
{
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
     * File extension
     *
     * @var string
     */
    protected static $fileExtension = '.tpl';

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
        if (! is_file($view))
        {
            if (! $view = static::getTemplatePath($view))
            {
                throw new BrioException(vsprintf('View file [ %s ] does not exist!', [$view]));
            }
        }

        $caheDir = static::$caheDir;

        if (empty($caheDir))
        {
            throw new BrioException(vsprintf('Cache dir [ %s ] is missing!', ['defined (?) in config']));
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
            static::checkCacheDirectory();
        }

        $file = fopen($cacheFile, "a+");

        if (! flock($file, LOCK_EX | LOCK_NB))
        {
            fclose($file);

            if (is_file($cacheFile))
            {
                require $cacheFile;

                if (is_callable($callback))
                {
                    return $callback($vars, $return, $blocks);
                }
            }
            unset($file);
        }

        // Recompiling

        $compiler = static::getCompiler();

        if (static::$debug)
        {
            $compiler->setDebug($cacheFile . '.dump');
        }

        try
        {
            $code = $compiler->compileFile($view, false, $vars);
        }
        catch (Exception $e)
        {
            if (isset($file))
            {
                touch($cacheFile, 300, 300);
                chmod($cacheFile, 0777);
            }
            throw $e->getMessage();
        }

        if (isset($file))
        {
            ftruncate($file, 0);
            fwrite($file, '<?php' . $code);
            flock($file, LOCK_UN);
            fclose($file);
        }
        else
        {
            eval($code);
        }

        if (! is_callable($callback))
        {
            require $cacheFile;

            if (! is_callable($callback))
            {
                // Really weird case...

                touch($cacheFile, 300, 300);
                chmod($cacheFile, 0777);

                // Compile temporarily

                $compiler = static::getCompiler();

                $code = $compiler->compileFile($view, false, $vars);

                eval($code);

                return $callback($vars, $return, $blocks);
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
        if (strpos($file, static::$fileExtension) !== false)
        {
            return $file;
        }

        $viewDir = static::$viewDir;

        if (empty($viewDir))
        {
            throw new BrioException(vsprintf('View directory [ %s ] is missing!', ['defined (?) in config']));
        }

        if (strpos($file, '::') !== false)
        {
            list($package, $file) = explode('::', $file);

            $subPath = 'vendor' . DIRECTORY_SEPARATOR . 'placer' . DIRECTORY_SEPARATOR . $package;

            $viewDir = str_replace('app', $subPath, $viewDir);
        }

        $file = str_replace('.', DIRECTORY_SEPARATOR, $file);

        $file .= static::$fileExtension;

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
    protected static function checkCacheDirectory()
    {
        $directory = static::$caheDir;

        if (! is_dir($directory))
        {
            $old = umask(0);

            if (! mkdir($directory, 0777, true))
            {
                throw new BrioException(vsprintf('Directory [ %s ] is not a valid directory!', [$directory]));
            }
            umask($old);
        }

        if (! is_writable($directory))
        {
            throw new BrioException(vsprintf('Directory [ %s ] not writable!', [$directory]));
        }
    }

    /**
     *  Get RuntimeCompiler instance
     *  The instance is already set up properly and resetted
     *
     *  @param bool  $checkdir
     *
     *  @return placer\brio\engine\compiler\RuntimeCompiler
     */
    protected static function getCompiler($checkdir = true)
    {
        if ($checkdir)
        {
            static::checkCacheDirectory();
        }

        $reuntimeCompiler = new RuntimeCompiler;

        if (! empty(static::$compilerOptions))
        {
            foreach (static::$compilerOptions as $key => $val)
            {
                Compiler::setOption($key, $val);
            }
        }

        $reuntimeCompiler->reset();

        return $reuntimeCompiler;
    }

}
