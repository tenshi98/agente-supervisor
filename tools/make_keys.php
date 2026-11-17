<?php

declare(strict_types=1);

// Genera llaves aleatorias para pegar en config/.env. No escribe ningún archivo.
require dirname(__DIR__) . '/vendor/autoload.php';

echo 'AGENT_VAULT_KEY=' . \Agente\Support\Vault::generateKey() . PHP_EOL;
echo 'AGENT_LINK_SECRET=' . bin2hex(random_bytes(32)) . PHP_EOL;
