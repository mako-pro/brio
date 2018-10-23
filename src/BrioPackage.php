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
        $config = $this->container->get('config')->get('brio::config');

        $this->container->get('view')->extend($config['fileExtension'], function() use ($config)
        {
			$brio = new Brio($config);

            return new BrioRenderer($brio);
        });
    }

}
