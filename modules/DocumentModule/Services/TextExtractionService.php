<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Services;

use Modules\DocumentModule\Contracts\TextExtractionServiceInterface;
use PhpOffice\PhpWord\Element\Link;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
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
                $text .= $this->extractDocxElement($element);
            }
            $text .= "\n";
        }

        return trim($text);
    }

    private function extractDocxElement($element): string
    {
        if ($element instanceof Title) {
            return "\n".$element->getText()."\n";
        }

        if ($element instanceof ListItem) {
            return $this->extractListItemText($element)."\n";
        }

        if ($element instanceof ListItemRun) {
            return $this->extractContainerText($element)."\n";
        }

        if ($element instanceof TextRun) {
            return $this->extractContainerText($element)."\n";
        }

        if ($element instanceof Text) {
            return $element->getText()."\n";
        }

        if ($element instanceof Link) {
            return $element->getText()."\n";
        }

        if ($element instanceof Table) {
            return $this->extractTableText($element);
        }

        if ($element instanceof TextBreak) {
            return "\n";
        }

        return '';
    }

    private function extractContainerText($container): string
    {
        $text = '';
        foreach ($container->getElements() as $child) {
            if ($child instanceof Text) {
                $text .= $child->getText();
            } elseif ($child instanceof Link) {
                $text .= $child->getText();
            } elseif ($child instanceof TextBreak) {
                $text .= "\n";
            } elseif (method_exists($child, 'getText')) {
                $text .= $child->getText();
            }
        }

        return $text;
    }

    private function extractListItemText($element): string
    {
        $text = $element->getText();
        $depth = method_exists($element, 'getDepth') ? $element->getDepth() : 0;
        $prefix = str_repeat('  ', $depth).'- ';

        return $prefix.$text;
    }

    private function extractTableText($table): string
    {
        $text = "\n";
        foreach ($table->getRows() as $row) {
            $cells = [];
            foreach ($row->getCells() as $cell) {
                $cellText = '';
                foreach ($cell->getElements() as $cellElement) {
                    $cellText .= $this->extractDocxElement($cellElement);
                }
                $cells[] = trim($cellText);
            }
            $text .= '| '.implode(' | ', $cells)." |\n";
        }

        return $text."\n";
    }
}
