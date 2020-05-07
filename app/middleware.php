<?php
declare(strict_types=1);

use App\Application\Middleware\ValidateMiddleware;
use Slim\App;

return function (App $app) {
    $app->add(ValidateMiddleware::class);
};
