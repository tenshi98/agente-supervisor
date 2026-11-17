<?php

declare(strict_types=1);

namespace Agente\Understanding;

use Agente\Support\Config;
use Agente\Support\Logger;
use Agente\Support\Proc;

/** Prepara el original de un adjunto ilegible para enviarlo a la IA (imagen o páginas de PDF como imagen). */
final class OriginalLoader
{
    private const MAX_IMAGE_BYTES = 4_500_000;
    private const MEDIA = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];

    public function __construct(private readonly Config $config, private readonly Logger $log)
    {
    }

    /** @return list<array<string,mixed>> bloques de contenido para la API (vacío si no se puede preparar) */
    public function blocks(string $path, string $filename, string $mime): array
    {
        if (!is_file($path)) {
            $this->log->warn("Original no disponible: {$filename}");

            return [];
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($mime === 'application/pdf' || $ext === 'pdf') {
            return $this->pdf($path, $filename);
        }
        $media = self::MEDIA[$ext] ?? (in_array($mime, self::MEDIA, true) ? $mime : null);
        if ($media === null) {
            $this->log->warn("Formato de imagen no soportado por la IA: {$filename}");

            return [];
        }
        $block = $this->image($path, $media);

        return $block === null ? [] : [['type' => 'text', 'text' => "ADJUNTO ORIGINAL: {$filename}"], $block];
    }

    /** @return list<array<string,mixed>> */
    private function pdf(string $path, string $filename): array
    {
        $pages = max(1, $this->config->int('ATTACH_MAX_PDF_PAGES', 2));
        if (!Proc::exists('pdftoppm')) {
            $this->log->warn("Sin pdftoppm: no se pueden enviar páginas de {$filename}");

            return [];
        }
        $prefix = tempnam($this->tmpDir(), 'orig_');
        if ($prefix === false) {
            return [];
        }
        @unlink($prefix);
        $run = Proc::run(['pdftoppm', '-r', '110', '-f', '1', '-l', (string) $pages, '-png', $path, $prefix], 90);
        $out = [];
        $files = glob($prefix . '*.png') ?: [];
        sort($files);
        foreach ($files as $i => $png) {
            $block = $this->image($png, 'image/png');
            @unlink($png);
            if ($block !== null) {
                $out[] = ['type' => 'text', 'text' => sprintf('ADJUNTO ORIGINAL: %s (pág. %d)', $filename, $i + 1)];
                $out[] = $block;
            }
        }
        if ($out === [] && $run['code'] !== 0) {
            $this->log->warn("pdftoppm falló con {$filename}: " . trim($run['err']));
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private function image(string $path, string $media): ?array
    {
        $size = filesize($path);
        if ($size === false || $size > self::MAX_IMAGE_BYTES) {
            $this->log->warn('Imagen demasiado grande para la IA: ' . basename($path));

            return null;
        }

        return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $media, 'data' => base64_encode((string) file_get_contents($path))]];
    }

    private function tmpDir(): string
    {
        $dir = $this->config->basePath('storage/tmp');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }
}
