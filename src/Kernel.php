<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    private ?string $customCacheDir = null;

    public function __construct(string $environment, bool $debug, ?string $cacheDir = null)
    {
        $this->customCacheDir = $cacheDir;
        parent::__construct($environment, $debug);
    }

    public function getCacheDir(): string
    {
        if ($this->customCacheDir) {
            return $this->customCacheDir . '/' . $this->environment;
        }

        return parent::getCacheDir();
    }

    public function getLogDir(): string
    {
        if ($this->customCacheDir) {
            return dirname($this->customCacheDir) . '/fega-logs';
        }

        return parent::getLogDir();
    }
}
