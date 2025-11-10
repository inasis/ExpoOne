<?php
declare(strict_types=1);

require_once 'Parser.php';

class Engine
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = new Parser();
    }

    /**
     * HTML 템플릿 파일을 
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
     * HTML 템플릿 문자열을 파싱하고 렌더링
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
     * 템플릿 파일을 파싱하여 PHP 파일로 저장
     */
    public function compile(string $templatePath, string $outputPath): void
    {
        $rendered = $this->parseFile($templatePath);
        file_put_contents($outputPath, $rendered);
    }
}

// 사용 예시
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $filename = './index.html';

    try {
        $engine = new Engine();
        if (file_exists($filename)) {
            echo $engine->parseFile($filename);
        } else {
            echo "Template file '".$filename."' not found.";
        }
    } catch (ParseException $e) {
        echo "Parse error: " . $e->getMessage();
    }
}