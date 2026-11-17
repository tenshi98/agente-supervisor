<?php

declare(strict_types=1);

/**
 * Carga las equivalencias semilla de reglas/lugares.json (equivalencias_semilla + reglas_de_direccion) como
 * equivalencias del tenant (source=semilla). No pisa las que ya existen. Es repetible.
 *   php tools/seed_equivalences.php --tenant=1
 */

use Agente\Persistence\Aliases;
use Agente\Rules\RuleSet;
use Agente\Support\Db;

$config = require __DIR__ . '/bootstrap.php';
require __DIR__ . '/args.php';
$a = cli_args($argv);
$tenant = (int) ($a['tenant'] ?? 1);

$rules = RuleSet::load($config->get('RULES_DIR') ?? dirname(__DIR__) . '/reglas');
$map = $rules->seedAliases() + $rules->addressRules();
$n = (new Aliases(Db::agent($config)))->seed($tenant, $map);
echo "+ {$n} equivalencias nuevas de " . count($map) . " en la semilla (tenant {$tenant})\n";
