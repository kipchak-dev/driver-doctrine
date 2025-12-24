<?php

use Psr\Container\ContainerInterface;
use Kipchak\Driver\Doctrine\Driver;

/**
 * @var $container ContainerInterface
 */

Driver::initialise($container);
Driver::set();
