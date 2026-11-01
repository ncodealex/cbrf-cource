<?php

declare(strict_types=1);

// FPM запускается от app; root/preload_user не нужны. CLI OPcache выключен.
require dirname(__DIR__).'/var/cache/prod/App_KernelProdContainer.preload.php';
