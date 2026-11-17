<?php

declare(strict_types=1);

namespace Agente\Attachments;

use Agente\Mail\MailAttachment;
use Agente\Support\Config;
use Agente\Support\Logger;
use Agente\Support\Proc;
use Smalot\PdfParser\Parser;
use thiagoalessio\TesseractOCR\TesseractOCR;

/**
 * Lee un adjunto SIN IA: texto de PDF (smalot/pdfparser) y, si es escaneado o es imagen, OCR (Tesseract).
 * Si el texto pasa la validación (TextSignals), se enviará solo texto a la IA; si no, el adjunto original.
 */
final class AttachmentReader
{
    private ?bool $hasTesseract = null;
    private ?bool $hasPdftoppm = null;

    public function __construct(private readonly Config $config, private readonly Logger $log)
    {
    }

    public function read(MailAttachment $att): AttachmentResult
    {
        try {
            $res = $this->readText($att);
            // Si el texto no basta, se conserva el original para que la IA pueda verlo SOLO si lo pide.
            $max = $this->config->int('ATTACH_MAX_BYTES', 15_000_000);
            if (!$res->valid && ($att->isPdf() || $att->isImage()) && $att->size > 0 && $att->size <= $max) {
                $path = $this->storeOriginal($att);
                if ($path !== null) {
                    $res = $res->withStoredPath($path);
                }
            }

            return $res;
        } finally {
            $att->free();
        }
    }

    private function readText(MailAttachment $att): AttachmentResult
    {
        $maxBytes = $this->config->int('ATTACH_MAX_BYTES', 15_000_000);
        if ($att->size > $maxBytes) {
            return $this->none('demasiado_grande');
        }

        try {
            if ($att->isPdf()) {
                return $this->readPdf($att);
            }
            if ($att->isImage()) {
                return $this->readImage($att->content(), $att->filename);
            }
            if (str_starts_with($att->mime, 'text/') || preg_match('/\.(txt|csv)$/i', $att->filename) === 1) {
                return $this->result('texto', $att->content());
            }
        } catch (\Throwable $e) {
            $this->log->warn('Adjunto ' . $att->filename . ': ' . $e->getMessage());

            return $this->none('error_lectura');
        }

        return $this->none('tipo_no_soportado');
    }

    private function storeOriginal(MailAttachment $att): ?string
    {
        try {
            $bytes = $att->content();
            $dir = $this->config->basePath('storage/attachments');
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                return null;
            }
            $ext = strtolower(pathinfo($att->filename, PATHINFO_EXTENSION));
            $ext = preg_match('/^[a-z0-9]{2,5}$/', $ext) === 1 ? $ext : 'bin';
            $path = $dir . '/' . sha1($bytes) . '.' . $ext;
            if (!is_file($path) && file_put_contents($path, $bytes) === false) {
                return null;
            }

            return $path;
        } catch (\Throwable $e) {
            $this->log->warn('No se pudo guardar el original de ' . $att->filename . ': ' . $e->getMessage());

            return null;
        }
    }

    private function readPdf(MailAttachment $att): AttachmentResult
    {
        $bytes = $att->content();
        $maxPages = max(1, $this->config->int('ATTACH_MAX_PDF_PAGES', 2));

        $text = '';
        try {
            $pdf = (new Parser())->parseContent($bytes);
            foreach (array_slice($pdf->getPages(), 0, $maxPages) as $page) {
                $text .= $page->getText() . "\n";
            }
        } catch (\Throwable $e) {
            $this->log->warn('PDF sin texto legible (' . $att->filename . '): ' . $e->getMessage());
        }

        if (mb_strlen(trim($text)) >= 80) {
            return $this->result('texto_pdf', $text);
        }

        // PDF escaneado: PDF -> imagen -> OCR
        if (!$this->ocrEnabled() || !$this->pdftoppm()) {
            return $this->none('pdf_escaneado_sin_ocr');
        }
        $tmp = $this->tmpFile('pdf');
        file_put_contents($tmp, $bytes);
        $prefix = $tmp . '_p';
        $run = Proc::run(['pdftoppm', '-r', '200', '-f', '1', '-l', (string) $maxPages, '-png', $tmp, $prefix], 90);
        @unlink($tmp);
        $ocr = '';
        foreach (glob($prefix . '*.png') ?: [] as $png) {
            $ocr .= $this->ocrFile($png) . "\n";
            @unlink($png);
        }
        if ($run['code'] !== 0 && $ocr === '') {
            return $this->none('pdf_no_convertible');
        }

        return $this->result('ocr', $ocr);
    }

    private function readImage(string $bytes, string $filename): AttachmentResult
    {
        if (!$this->ocrEnabled()) {
            return $this->none('ocr_desactivado');
        }
        $tmp = $this->tmpFile('img');
        file_put_contents($tmp, $bytes);
        $text = $this->ocrFile($tmp);
        @unlink($tmp);

        return $this->result('ocr', $text);
    }

    private function ocrFile(string $path): string
    {
        $langs = explode('+', $this->config->get('OCR_LANGS', 'spa+eng') ?? 'spa+eng');

        return (new TesseractOCR($path))->lang(...$langs)->run();
    }

    private function result(string $method, string $text): AttachmentResult
    {
        $signals = TextSignals::analyze($text);

        return new AttachmentResult($method, trim($text), $signals, $signals['valid']);
    }

    private function none(string $reason): AttachmentResult
    {
        return new AttachmentResult('ninguno', '', ['valid' => false, 'reasons' => [$reason]], false);
    }

    private function ocrEnabled(): bool
    {
        if (!$this->config->bool('OCR_ENABLED', true)) {
            return false;
        }

        return $this->hasTesseract ??= Proc::exists('tesseract');
    }

    private function pdftoppm(): bool
    {
        return $this->hasPdftoppm ??= Proc::exists('pdftoppm');
    }

    private function tmpFile(string $ext): string
    {
        $dir = $this->config->basePath('storage/tmp');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $f = tempnam($dir, 'att_');
        rename($f, $f . '.' . $ext);

        return $f . '.' . $ext;
    }
}
