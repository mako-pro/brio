<?php

namespace placer\brio;

use mako\application\Package;

class BrioPackage extends Package
{
    /**
     * Package name.
     *
     * @var string
     */
    protected $packageName = 'placer/brio';

    /**
     * Package namespace.
     *
     * @var string
     */
    protected $fileNamespace = 'brio';

    /**
     * {@inheritdoc}
     */
    protected function bootstrap()
    {
        $this->container->get('view')->extend('.tpl', function ()
        {
            $config = $this->container->get('config');

            return new Brio($config->get('brio::config'));
        });
    }

}
