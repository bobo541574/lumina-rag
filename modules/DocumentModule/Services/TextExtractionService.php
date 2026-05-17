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

/**
 * Text Extraction Service
 *
 * Extracts plain text content from uploaded documents based on their MIME type.
 * Supports: PDF (via smalot/pdfparser), DOCX (via phpoffice/phpword), and
 * plain text formats (TXT, CSV, Markdown). For Markdown, strips formatting
 * (images, links, headings, bold/italic markers) while preserving link text.
 * For binary formats, validates that the required libraries are installed.
 *
 * @throws \RuntimeException If a required parsing library is not installed
 * @throws \InvalidArgumentException If the MIME type is unsupported
 */
class TextExtractionService implements TextExtractionServiceInterface
{
    /**
     * Extract text from a file
     *
     * Dispatches to the appropriate extraction method based on MIME type.
     * Supports text/plain, text/csv, text/markdown, application/pdf,
     * and application/vnd.openxmlformats-officedocument.wordprocessingml.document.
     *
     * @param  string  $filePath  Absolute path to the file on disk. Example: "/var/www/storage/documents/abc.pdf"
     * @param  string  $mimeType  The MIME type of the file. Example: "application/pdf"
     * @return string The extracted plain text content.
     *                Example: "Executive summary\n\nThe project achieved all milestones..."
     *
     * @throws \InvalidArgumentException If the MIME type is not supported
     *                                   Example: $service->extract("/file.exe", "application/x-msdownload") → InvalidArgumentException("Unsupported mime type: ...")
     */
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

    /**
     * Extract text from a plain text file (TXT, CSV)
     *
     * Reads the file content, detects its encoding (UTF-8, ISO-8859-1, Windows-1252),
     * and converts to UTF-8 if necessary.
     *
     * @param  string  $filePath  Absolute path to the text file. Example: "/var/www/storage/documents/report.txt"
     * @return string The text content encoded in UTF-8.
     *                Example: "Name,Value\nAlice,100\nBob,200"
     *
     * @throws \RuntimeException If the file cannot be read
     *                           Example: $this->extractText("/nonexistent.txt") → RuntimeException("Failed to read file: ...")
     */
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

    /**
     * Extract text from a Markdown file, stripping formatting
     *
     * Removes image tags, link markup (preserving link text), heading markers,
     * and bold/italic/backtick/strikethrough characters. Returns clean plain text.
     *
     * @param  string  $filePath  Absolute path to the Markdown file. Example: "/var/www/storage/documents/notes.md"
     * @return string The plain text content with Markdown syntax removed.
     *                Example: "Project Orion\n\nKey milestones were achieved in Q1."
     *
     * @throws \RuntimeException If the file cannot be read
     *                           Example: $this->extractMarkdown("/nonexistent.md") → RuntimeException("Failed to read file: ...")
     */
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

    /**
     * Extract text from a PDF file
     *
     * Uses smalot/pdfparser to parse the PDF and extract text content.
     * Collapses runs of 3+ newlines into double newlines for cleaner output.
     *
     * @param  string  $filePath  Absolute path to the PDF file. Example: "/var/www/storage/documents/report.pdf"
     * @return string The extracted text content.
     *                Example: "Page 1\n\nExecutive Summary\n\nThe project achieved..."
     *
     * @throws \RuntimeException If the PDF parser library is not installed
     *                           Example: $this->extractPdf("/report.pdf") → RuntimeException("PDF parser library not installed. ...")
     */
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

    /**
     * Extract text from a DOCX file
     *
     * Uses phpoffice/phpword to load the document and iterate over all sections
     * and elements (titles, paragraphs, lists, tables, links, text breaks).
     * Each element's text is extracted by extractDocxElement and accumulated.
     *
     * @param  string  $filePath  Absolute path to the DOCX file. Example: "/var/www/storage/documents/report.docx"
     * @return string The extracted text content with structure preserved.
     *                Example: "Title\n\nSection 1\n\nParagraph text here..."
     *
     * @throws \RuntimeException If the DOCX parser library is not installed
     *                           Example: $this->extractDocx("/report.docx") → RuntimeException("DOCX parser library not installed. ...")
     */
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

    /**
     * Extract text from a single DOCX element
     *
     * Dispatches based on element type: Title, ListItem, ListItemRun, TextRun,
     * Text, Link, Table, TextBreak. Unknown element types return empty string.
     *
     * @param  mixed  $element  A PhpWord element instance. Example: new Title("Section 1")
     * @return string The extracted text for this element.
     *                Example: "\nSection 1\n" for a Title element
     */
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

    /**
     * Extract text from a container element (TextRun, ListItemRun)
     *
     * Iterates over child elements (Text, Link, TextBreak) and concatenates
     * their text content. Falls back to getText() via method_exists for
     * unknown child types.
     *
     * @param  mixed  $container  A PhpWord container element. Example: new TextRun()
     * @return string The concatenated text from all children.
     *                Example: "Hello world" from a TextRun with two Text children
     */
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

    /**
     * Extract text from a list item with indentation
     *
     * Prepends a bullet marker ("- ") with depth-based indentation (2 spaces per level).
     *
     * @param  mixed  $element  A ListItem element. Example: new ListItem("Item text", 1)
     * @return string The indented list item text.
     *                Example: "  - Item text" (depth 1)
     */
    private function extractListItemText($element): string
    {
        $text = $element->getText();
        $depth = method_exists($element, 'getDepth') ? $element->getDepth() : 0;
        $prefix = str_repeat('  ', $depth).'- ';

        return $prefix.$text;
    }

    /**
     * Extract text from a DOCX table as pipe-delimited rows
     *
     * Converts each row into a Markdown-style pipe table line: "| cell1 | cell2 |".
     * Each cell's content is extracted recursively via extractDocxElement.
     *
     * @param  mixed  $table  A Table element from PhpWord. Example: new Table()
     * @return string The table as pipe-delimited text.
     *                Example: "\n| Name | Value |\n| Alice | 100 |\n"
     */
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
