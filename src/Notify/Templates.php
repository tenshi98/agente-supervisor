<?php

declare(strict_types=1);

namespace Agente\Notify;

use InvalidArgumentException;
use RuntimeException;

/**
 * Motor mínimo de plantillas de correo (sin dependencias). Carpeta `plantillas/`:
 *   <nombre>.html  <nombre>.txt  <nombre>.subject.txt  y _layout.html (envuelve el html con {{{content}}}).
 *
 * Sintaxis:
 *   {{var}}                  valor escapado (HTML en .html y layout; sin escapar en .txt y asunto)
 *   {{{var}}}                valor crudo (solo para HTML ya armado y escapado por el código)
 *   {{a.b}}  {{this}}  {{this.campo}}  {{@index}}  {{@index1}}
 *   {{#if x}}..{{else}}..{{/if}}       {{#each lista}}..{{/each}}      (anidables)
 *
 * Una variable ausente se muestra vacía y se anota en missing(). La condición de un #if nunca cuenta como
 * ausente (es la forma de declarar datos opcionales).
 */
final class Templates
{
    /** @var array<string,mixed> */
    private array $globals;
    /** @var array<string,true> */
    private array $missing = [];
    private string $stop = '';

    /** @param array<string,mixed> $globals brand_name, brand_footer, dry_run, base_url */
    public function __construct(private readonly string $dir, array $globals = [])
    {
        $this->globals = $globals + ['brand_name' => '', 'brand_footer' => '', 'dry_run' => false, 'base_url' => ''];
    }

    /**
     * @param array<string,mixed> $vars
     * @return array{subject:string,html:string,text:string}
     */
    public function render(string $name, array $vars): array
    {
        if (preg_match('/^[a-z0-9_]+$/i', $name) !== 1) {
            throw new InvalidArgumentException("Nombre de plantilla inválido: {$name}");
        }
        $this->missing = [];
        $vars += $this->globals;

        $subject = trim((string) preg_replace('/\s+/u', ' ', $this->file($name . '.subject.txt', $vars, false)));
        $vars['subject'] = $subject;
        $content = $this->file($name . '.html', $vars, true);
        $html = $this->file('_layout.html', $vars + ['content' => $content], true);
        $text = rtrim($this->file($name . '.txt', $vars, false)) . "\n";

        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    /** Variables que se usaron en {{}} y no existían, desde el último render(). @return list<string> */
    public function missing(): array
    {
        return array_keys($this->missing);
    }

    /**
     * @param array<string,mixed> $vars
     */
    private function file(string $file, array $vars, bool $html): string
    {
        $path = rtrim($this->dir, '/') . '/' . $file;
        if (!is_file($path)) {
            throw new RuntimeException("Falta la plantilla: {$path}");
        }
        $parts = preg_split('/(\{\{\{.*?\}\}\}|\{\{.*?\}\})/s', (string) file_get_contents($path), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $i = 0;
        $nodes = $this->build($parts, $i, false);

        return $this->run($nodes, [['item' => $vars, 'i' => -1]], $html);
    }

    /**
     * @param list<string> $parts
     * @return list<array<int,mixed>>
     */
    private function build(array $parts, int &$i, bool $nested): array
    {
        $nodes = [];
        $n = count($parts);
        for (; $i < $n; $i++) {
            $p = $parts[$i];
            if ($i % 2 === 0) {
                if ($p !== '') {
                    $nodes[] = ['t', $p];
                }
                continue;
            }
            if (str_starts_with($p, '{{{')) {
                $nodes[] = ['v', trim(substr($p, 3, -3)), true];
                continue;
            }
            $tag = trim(substr($p, 2, -2));
            if ($tag === 'else' || $tag === '/if' || $tag === '/each') {
                if (!$nested) {
                    throw new RuntimeException("Etiqueta {{{$tag}}} sin apertura");
                }
                $this->stop = $tag;

                return $nodes;
            }
            if (str_starts_with($tag, '#if ')) {
                $i++;
                $then = $this->build($parts, $i, true);
                $else = [];
                if ($this->stop === 'else') {
                    $i++;
                    $else = $this->build($parts, $i, true);
                }
                if ($this->stop !== '/if') {
                    throw new RuntimeException('Falta {{/if}}');
                }
                $nodes[] = ['if', trim(substr($tag, 4)), $then, $else];
            } elseif (str_starts_with($tag, '#each ')) {
                $i++;
                $body = $this->build($parts, $i, true);
                if ($this->stop !== '/each') {
                    throw new RuntimeException('Falta {{/each}}');
                }
                $nodes[] = ['each', trim(substr($tag, 6)), $body];
            } else {
                $nodes[] = ['v', $tag, false];
            }
        }
        if ($nested) {
            throw new RuntimeException('Bloque sin cerrar en la plantilla');
        }

        return $nodes;
    }

    /**
     * @param list<array<int,mixed>> $nodes
     * @param list<array{item:mixed,i:int}> $frames
     */
    private function run(array $nodes, array $frames, bool $html): string
    {
        $out = '';
        foreach ($nodes as $nd) {
            switch ($nd[0]) {
                case 't':
                    $out .= $nd[1];
                    break;
                case 'v':
                    [$found, $v] = $this->lookup($nd[1], $frames);
                    if (!$found) {
                        $this->missing[$nd[1]] = true;
                        break;
                    }
                    $s = $this->str($v);
                    $out .= ($html && !$nd[2]) ? htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $s;
                    break;
                case 'if':
                    [$found, $v] = $this->lookup($nd[1], $frames);
                    $out .= $this->run($found && self::truthy($v) ? $nd[2] : $nd[3], $frames, $html);
                    break;
                case 'each':
                    [$found, $v] = $this->lookup($nd[1], $frames);
                    if ($found && is_array($v)) {
                        $k = 0;
                        foreach ($v as $item) {
                            $out .= $this->run($nd[2], [...$frames, ['item' => $item, 'i' => $k]], $html);
                            $k++;
                        }
                    }
                    break;
            }
        }

        return $out;
    }

    /**
     * @param list<array{item:mixed,i:int}> $frames
     * @return array{0:bool,1:mixed}
     */
    private function lookup(string $path, array $frames): array
    {
        if ($path === '@index' || $path === '@index1') {
            for ($f = count($frames) - 1; $f >= 0; $f--) {
                if ($frames[$f]['i'] >= 0) {
                    return [true, $frames[$f]['i'] + ($path === '@index1' ? 1 : 0)];
                }
            }

            return [false, null];
        }
        $seg = explode('.', $path);
        $first = array_shift($seg);
        $base = null;
        $found = false;
        if ($first === 'this') {
            $base = $frames[count($frames) - 1]['item'];
            $found = true;
        } else {
            for ($f = count($frames) - 1; $f >= 0; $f--) {
                $it = $frames[$f]['item'];
                if (is_array($it) && array_key_exists($first, $it)) {
                    $base = $it[$first];
                    $found = true;
                    break;
                }
            }
        }
        if (!$found) {
            return [false, null];
        }
        foreach ($seg as $s) {
            if (!is_array($base) || !array_key_exists($s, $base)) {
                return [false, null];
            }
            $base = $base[$s];
        }

        return [$base !== null, $base];
    }

    private function str(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? '1' : '';
        }
        if (is_scalar($v)) {
            return (string) $v;
        }

        return '';
    }

    private static function truthy(mixed $v): bool
    {
        return !($v === null || $v === false || $v === '' || $v === 0 || $v === '0' || $v === [] || $v === 0.0);
    }
}
