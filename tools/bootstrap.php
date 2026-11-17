<?php

declare(strict_types=1);

// Arranque común de las herramientas de línea de comandos.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

return \Agente\Support\Config::load(dirname(__DIR__));
