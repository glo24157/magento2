<?php
/**
 * Repro/verification module for github.com/magento/magento2/issues/40891.
 */
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Magz_Issue40891', __DIR__);
