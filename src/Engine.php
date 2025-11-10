<?php
declare(strict_types=1);

namespace ExpoOne;

require __DIR__ . '/../vendor/autoload.php';

class Engine
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = new Parser();
    }

    /**
     * Reads an HTML template file.
     *
     * @param string $filepath The path including the file name.
     * @return string The parsed and rendered template content.
     * @throws ParseException If the file is not found or cannot be read.
     */
    public function parseFile(string $filepath): string
    {
        if (!file_exists($filepath)) {
            throw new ParseException("Template file not found: {$filepath}");
        }

        $content = file_get_contents($filepath);
        if ($content === false) {
            throw new ParseException("Failed to read template file: {$filepath}");
        }

        return $this->parseString($content);
    }

    /**
     * Parses and renders an HTML template string.
     *
     * @param string $html The HTML template content.
     * @return string The rendered output.
     */
    public function parseString(string $html): string
    {
        $root = $this->parser->parse($html);
        
        $output = '';
        foreach ($root->children as $node) {
            $output .= $node->toRender();
        }
        
        return $output;
    }

    /**
     * Parses a template file and saves it as a compiled PHP file.
     *
     * @param string $templatePath The path including the source HTML file name.
     * @param string $outputPath The path to save the compiled PHP file.
     * @return void
     * @throws ParseException If parsing fails.
     */
    public function compile(string $templatePath, string $outputPath): void
    {
        $rendered = $this->parseFile($templatePath);
        file_put_contents($outputPath, $rendered);
    }
}

