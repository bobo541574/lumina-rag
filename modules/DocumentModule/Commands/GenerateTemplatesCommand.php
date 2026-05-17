<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Commands;

use Illuminate\Console\Command;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html;

/**
 * Generate Templates Command
 *
 * Artisan command that converts Markdown template files (.md) into formatted
 * .docx files using PhpWord. Templates are stored in public/templates/ and
 * include report structures for various roles (software developer, project
 * coordinator, customer service, finance, general). Existing .docx files are
 * skipped unless the --force flag is provided.
 *
 * @throws \RuntimeException If a template file cannot be read or PhpWord writer fails
 */
class GenerateTemplatesCommand extends Command
{
    protected $signature = 'rag:generate-templates
        {--force : Regenerate existing .docx files}';

    protected $description = 'Generate .docx template files from .md templates using PhpWord';

    private const TEMPLATE_DIR = 'public/templates';

    private const TEMPLATE_FILES = [
        'software-developer-report.md',
        'project-coordinator-report.md',
        'customer-service-report.md',
        'finance-report.md',
        'general-report.md',
    ];

    /**
     * Execute the command
     *
     * Iterates over all template files in TEMPLATE_FILES. For each .md file,
     * checks if the corresponding .docx already exists (skips unless --force).
     * Calls generateDocx to perform the conversion. Reports generated, skipped,
     * and failed counts.
     *
     * @return int Command exit code: self::SUCCESS or self::FAILURE.
     *             Example: 0 for success, 1 for failure
     *
     * @throws \RuntimeException If the template directory does not exist
     */
    public function handle(): int
    {
        $baseDir = base_path(self::TEMPLATE_DIR);
        $force = (bool) $this->option('force');

        if (! is_dir($baseDir)) {
            $this->error("Template directory not found: {$baseDir}");

            return self::FAILURE;
        }

        $generated = 0;
        $skipped = 0;

        foreach (self::TEMPLATE_FILES as $mdFile) {
            $mdPath = "{$baseDir}/{$mdFile}";
            $docxFile = str_replace('.md', '.docx', $mdFile);
            $docxPath = "{$baseDir}/{$docxFile}";

            if (! file_exists($mdPath)) {
                $this->warn("Source not found: {$mdPath}");

                continue;
            }

            if (file_exists($docxPath) && ! $force) {
                $this->line("  Skipped {$docxFile} (exists, use --force to regenerate)");
                $skipped++;

                continue;
            }

            try {
                $this->generateDocx($mdPath, $docxPath);
                $this->info("  Generated {$docxFile}");
                $generated++;
            } catch (\Throwable $e) {
                $this->error("  Failed {$docxFile}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Done — {$generated} generated, {$skipped} skipped.");

        return self::SUCCESS;
    }

    /**
     * Convert a single Markdown file to DOCX
     *
     * Creates a new PhpWord document with DejaVu Sans font (11pt), 1-inch margins.
     * Parses the Markdown line-by-line and maps elements to PhpWord equivalents:
     * - # headings → bold 18pt
     * - ## headings → bold 13pt
     * - ### headings → bold 11.5pt
     * - Pipe tables → PhpWord tables with borders
     * - List items (-, *) → PhpWord list items
     * - Numbered items → PhpWord list items
     * - Regular text → paragraphs
     * - "---" separators → text breaks
     * - HTML comments → skipped
     *
     * @param  string  $mdPath  Absolute path to the .md source file. Example: "/var/www/public/templates/general-report.md"
     * @param  string  $docxPath  Absolute path to write the .docx output. Example: "/var/www/public/templates/general-report.docx"
     *
     * @throws \RuntimeException If the Markdown file cannot be read or PhpWord writer fails
     *                           Example: $this->generateDocx("/nonexistent.md", "/out.docx") → RuntimeException("Cannot read file: ...")
     */
    private function generateDocx(string $mdPath, string $docxPath): void
    {
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('DejaVu Sans');
        $phpWord->setDefaultFontSize(11);

        $section = $phpWord->addSection([
            'marginLeft' => 1440,
            'marginRight' => 1440,
            'marginTop' => 1440,
            'marginBottom' => 1440,
        ]);

        $lines = file($mdPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \RuntimeException("Cannot read file: {$mdPath}");
        }

        $i = 0;
        while ($i < count($lines)) {
            $line = $lines[$i];
            $trimmed = trim($line);

            // Skip separator lines
            if ($trimmed === '---') {
                $section->addTextBreak(0.5);
                $i++;

                continue;
            }

            // HTML comment (metadata instructions)
            if (str_starts_with($trimmed, '<!--') && str_ends_with($trimmed, '-->')) {
                $i++;

                continue;
            }

            // Heading 1 (# Title)
            if (str_starts_with($trimmed, '# ') && ! str_starts_with($trimmed, '## ')) {
                $text = trim(substr($trimmed, 2));
                $section->addText($text, ['bold' => true, 'size' => 18, 'color' => '1F2937']);
                $section->addTextBreak(0.5);
                $i++;

                continue;
            }

            // Heading 2 (## Title)
            if (str_starts_with($trimmed, '## ')) {
                $text = trim(substr($trimmed, 3));
                $section->addText($text, ['bold' => true, 'size' => 13, 'color' => '374151']);
                $section->addTextBreak(0.3);
                $i++;

                continue;
            }

            // Heading 3 (### Title)
            if (str_starts_with($trimmed, '### ')) {
                $text = trim(substr($trimmed, 4));
                $section->addText($text, ['bold' => true, 'size' => 11.5, 'color' => '4B5563']);
                $section->addTextBreak(0.2);
                $i++;

                continue;
            }

            // Table rows (starts and ends with |)
            if (str_starts_with($trimmed, '|') && str_ends_with($trimmed, '|')) {
                $rows = [];
                $headerRow = null;
                while ($i < count($lines)) {
                    $tableLine = trim($lines[$i]);
                    if (! str_starts_with($tableLine, '|') || ! str_ends_with($tableLine, '|')) {
                        break;
                    }
                    // Skip separator rows (|---|)
                    if (preg_match('/^\|[-:\s]+\|$/', $tableLine)) {
                        $i++;

                        continue;
                    }
                    $cells = array_map('trim', explode('|', trim($tableLine, '|')));
                    if ($headerRow === null) {
                        $headerRow = $cells;
                    } else {
                        $rows[] = $cells;
                    }
                    $i++;
                }

                if ($headerRow !== null) {
                    $table = $section->addTable([
                        'borderSize' => 6,
                        'borderColor' => 'D1D5DB',
                        'cellMargin' => 80,
                    ]);

                    // Header row
                    $table->addRow();
                    foreach ($headerRow as $cell) {
                        $table->addCell(2000)->addText(
                            $cell,
                            ['bold' => true, 'size' => 10],
                            ['spaceAfter' => 0, 'spaceBefore' => 0]
                        );
                    }

                    // Data rows
                    foreach ($rows as $row) {
                        $table->addRow();
                        foreach ($row as $cell) {
                            $table->addCell(2000)->addText(
                                $cell,
                                ['size' => 10],
                                ['spaceAfter' => 0, 'spaceBefore' => 0]
                            );
                        }
                    }

                    $section->addTextBreak(0.5);
                }

                continue;
            }

            // List items (- item)
            if (str_starts_with($trimmed, '- ') || str_starts_with($trimmed, '* ')) {
                $text = ltrim(substr($trimmed, 2));
                $section->addListItem($text, 0, ['size' => 11]);
                $i++;

                continue;
            }

            // Numbered list (1. item)
            if (preg_match('/^\d+\.\s/', $trimmed)) {
                $text = preg_replace('/^\d+\.\s+/', '', $trimmed);
                $section->addListItem($text, 0, ['size' => 11]);
                $i++;

                continue;
            }

            // Bold markers **text**
            $trimmed = preg_replace('/\*\*(.+?)\*\*/', '\1', $trimmed);

            // Regular paragraph
            if ($trimmed !== '') {
                $section->addText($trimmed, ['size' => 11]);
                $i++;
            } else {
                $section->addTextBreak(0.5);
                $i++;
            }
        }

        $objWriter = IOFactory::createWriter($phpWord);
        $objWriter->save($docxPath);
    }
}
