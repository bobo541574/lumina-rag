<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Services;

use Modules\DocumentModule\Contracts\TextExtractionServiceInterface;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser;

class TextExtractionService implements TextExtractionServiceInterface
{
    public function extract(string $filePath, string $mimeType): string
    {
        return match ($mimeType) {
            'text/plain', 'text/csv' => $this->extractText($filePath),
            'text/markdown' => $this->extractMarkdown($filePath),
            'application/pdf' => $this->extractPdf($filePath),
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->extractDocx($filePath),
            default => throw new \InvalidArgumentException("Unsupported mime type: {$mimeType}"),
        };
    }

    private function extractText(string $filePath): string
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new \RuntimeException("Failed to read file: {$filePath}");
        }

        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);

        return $encoding !== false && $encoding !== 'UTF-8'
            ? mb_convert_encoding($content, 'UTF-8', $encoding)
            : $content;
    }

    private function extractMarkdown(string $filePath): string
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new \RuntimeException("Failed to read file: {$filePath}");
        }

        $content = preg_replace('/!\[.*?\]\(.*?\)/', '', $content);
        $content = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $content);
        $content = preg_replace('/^#{1,6}\s+/m', '', $content);
        $content = preg_replace('/[*_~`]/', '', $content);

        return trim($content);
    }

    private function extractPdf(string $filePath): string
    {
        if (! class_exists('\Smalot\PdfParser\Parser')) {
            throw new \RuntimeException('PDF parser library not installed. Run: composer require smalot/pdfparser');
        }

        $parser = new Parser;
        $pdf = $parser->parseFile($filePath);
        $text = $pdf->getText();

        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    private function extractDocx(string $filePath): string
    {
        if (! class_exists('\PhpOffice\PhpWord\IOFactory')) {
            throw new \RuntimeException('DOCX parser library not installed. Run: composer require phpoffice/phpword');
        }

        $phpWord = IOFactory::load($filePath);
        $text = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $text .= $element->getText()."\n";
                } elseif (method_exists($element, 'getElements')) {
                    foreach ($element->getElements() as $child) {
                        if (method_exists($child, 'getText')) {
                            $text .= $child->getText()."\n";
                        }
                    }
                }
            }
        }

        return trim($text);
    }
}
