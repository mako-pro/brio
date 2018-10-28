<?php

namespace placer\brio;

use mako\view\renderers\RendererInterface;

class BrioRenderer implements RendererInterface
{
    /**
     * Brio instance.
     *
     * @var Brio
     */
    private $brio;

    /**
     * Constructor
     *
     * @param Brio $brio Brio instance
     */
    public function __construct(Brio $brio)
    {
        $this->brio = $brio;
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

        return (string) $this->brio->load($view, $variables);
    }

}
