<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    // Маршруты объявляются атрибутом #[Route] в контроллерах слоя Presentation.
    $routes->import('routing.controllers');

    if ('dev' === $routes->env()) {
        $routes->import('@FrameworkBundle/Resources/config/routing/errors.php')->prefix('/_error');
    }
};
