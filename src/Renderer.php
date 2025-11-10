<?php
declare(strict_types=1);

namespace ExpoOne;

/**
 * Renderer is responsible for transforming a parsed Node tree
 * into executable PHP/HTML code.
 *
 * It supports:
 * - Dynamic variable interpolation with filters (`{$var|filter}`)
 * - Inline PHP blocks (`{@ code }`)
 * - Conditional rendering (`cond` attributes)
 * - Loop rendering (`loop` attributes)
 * - Asset management via <load> and <unload> tags
 * - Automatic escaping and HTML-safe output
 */
class Renderer
{
    /** @var array<string, string> Compiled regex patterns for variable and PHP block matching */
    private const COMPILED_REGEX = [
        'php_block' => '/\{@\s*([\s\S]*?)\s*\}/s',
        'variable' => '/\{\$([^}]+)\}/s'
    ];

    /** @var array<string, bool> HTML void elements (self-closing tags) */
    private array $voidElements;

    /** @var array<int, array<int, array<string, string>>> Collected CSS files grouped by index */
    private array $cssFiles = [];

    /** @var array<string, array<int, array<int, string>>> Collected JS files grouped by type (head/body) and index */
    private array $jsFiles = [];

    /**
     * Initialize renderer with void HTML elements
     */
    public function __construct()
    {
        $this->voidElements = array_flip([
            'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 
            'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'
        ]);
    }

    /**
     * Render a Node into PHP/HTML string
     *
     * @param Node $node The node to render
     * @return string Rendered HTML or PHP code
     */
    public function render(Node $node): string
    {
        switch ($node->type) {
            case 'text':
                return $this->renderTextContent($node->content ?? '');
            case 'rawphp':
                return $node->content ?? '';
            case 'comment':
                return $this->renderComment($node->content ?? '');
            case 'element':
                return $this->renderElement($node);
            default:
                return '';
        }
    }

    /**
     * Render plain text content, replacing inline PHP and variable filters
     *
     * @param string $content Text content to render
     * @return string PHP code after replacements
     * @throws ParseException If PHP code in block is invalid
     */
    private function renderTextContent(string $content): string
    {
        $content = preg_replace_callback(self::COMPILED_REGEX['php_block'], function ($m) {
            $code = trim($m[1]);
            Validator::validatePhpCode($code);
            return "<?php\n" . $code . "\n?>";
        }, $content);

        $content = preg_replace_callback(self::COMPILED_REGEX['variable'], function ($m) {
            $expr = trim($m[1]);
            $processedCode = Filter::parseVariableExpression($expr);
            return "<?= {$processedCode} ?>";
        }, $content);

        return $content;
    }

    /**
     * Render HTML comments, removing `//` style inline comments
     *
     * @param string $content Comment content
     * @return string Sanitized HTML comment
     */
    private function renderComment(string $content): string
    {
        $cleanContent = preg_replace('/\/\/.*$/s', '', $content);
        return $cleanContent ? "<!--{$cleanContent}-->" : '';
    }

    /**
     * Render an element node with full attribute, loop, and condition support
     *
     * @param Node $node
     * @return string Rendered HTML or PHP block
     * @throws ParseException If invalid loop/cond syntax or missing attributes
     */
    private function renderElement(Node $node): string
    {
        $inner = implode('', array_map([$this, 'render'], $node->children));

        if (strtolower($node->tagName ?? '') === 'load') {
            return $this->collectLoadTag($node);
        }

        if (strtolower($node->tagName ?? '') === 'unload') {
            return $this->renderUnloadTag($node);
        }

        if (strtolower($node->tagName ?? '') === 'block') {
            return $this->renderBlockElement($node, $inner);
        }

        $attrs = $this->renderAttributes($node->attributes, $node->tagName ?? '');

        if (isset($node->attributes['loop'])) {
            return $this->renderLoopElement($node, $inner);
        }

        if (isset($node->attributes['cond'])) {
            $cond = trim($node->attributes['cond']);
            Validator::validatePhpCode($cond);
            $attrsWithoutCond = $node->attributes;
            unset($attrsWithoutCond['cond']);
            $attrs = $this->renderAttributes($attrsWithoutCond, $node->tagName ?? '');
            return "\n<?php if({$cond}): ?><{$node->tagName}{$attrs}>{$inner}</{$node->tagName}><?php endif; ?>\n";
        }

        if (isset($this->voidElements[strtolower($node->tagName ?? '')])) {
            return "<{$node->tagName}{$attrs}>\n";
        }

        return "<{$node->tagName}{$attrs}>{$inner}</{$node->tagName}>";
    }

    /**
     * Collects CSS/JS <load> tags for later injection
     *
     * @param Node $node
     * @return string Always returns an empty string
     * @throws ParseException If required attributes are missing or file type is invalid
     */
    private function collectLoadTag(Node $node): string
    {
        if (!isset($node->attributes['target'])) {
            throw new ParseException("Missing 'target' attribute in <load> tag");
        }

        $target = $node->attributes['target'];
        $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));
        $index = isset($node->attributes['index']) ? intval($node->attributes['index']) : 999999;

        if ($extension === 'css') {
            $media = $node->attributes['media'] ?? 'all';
            $targetEscaped = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');
            $mediaEscaped = htmlspecialchars($media, ENT_QUOTES, 'UTF-8');
            
            $this->cssFiles[$index][] = [
                'target' => $targetEscaped,
                'media' => $mediaEscaped
            ];
        } elseif ($extension === 'js') {
            $type = $node->attributes['type'] ?? 'head';
            $targetEscaped = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');
            
            $this->jsFiles[$type][$index][] = $targetEscaped;
        } else {
            throw new ParseException("Unsupported file type in <load>: {$extension}. Only .css and .js are supported.");
        }
        
        return '';
    }

    /**
     * Injects all collected <load> assets (CSS/JS) into final HTML
     *
     * @param string $html The compiled HTML
     * @return string HTML with injected <link> and <script> tags
     */
    public function injectAssets(string $html): string
    {
        $cssOutput = $this->generateCssTags();
        $headJsOutput = $this->generateJsTags('head');
        $bodyJsOutput = $this->generateJsTags('body');
        
        if (!empty($cssOutput) || !empty($headJsOutput)) {
            $headAssets = trim($cssOutput . "\n" . $headJsOutput);
            if ($headAssets) {
                $html = preg_replace('/(<\/head[^>]*>)/i', $headAssets . "\n$1", $html, 1);
            }
        }
        
        if (!empty($bodyJsOutput)) {
            $html = preg_replace('/(<\/body>)/i', $bodyJsOutput . "\n$1", $html, 1);
        }
        
        return $html;
    }

    /**
     * Generates all CSS <link> tags sorted by index
     *
     * @return string Combined CSS link tags
     */
    private function generateCssTags(): string
    {
        if (empty($this->cssFiles)) return '';
        ksort($this->cssFiles);
        $output = [];
        
        foreach ($this->cssFiles as $files) {
            foreach ($files as $file) {
                $output[] = "<link rel=\"stylesheet\" href=\"{$file['target']}\" media=\"{$file['media']}\">\n";
            }
        }
        return implode("\n", $output);
    }

    /**
     * Generates all JS <script> tags sorted by index and type
     *
     * @param string $type Either 'head' or 'body'
     * @return string Combined JS script tags
     */
    private function generateJsTags(string $type): string
    {
        if (!isset($this->jsFiles[$type]) || empty($this->jsFiles[$type])) return '';
        ksort($this->jsFiles[$type]);
        $output = [];
        
        foreach ($this->jsFiles[$type] as $files) {
            foreach ($files as $file) {
                $output[] = "<script src=\"{$file}\"></script>";
            }
        }
        return implode("\n", $output);
    }

    /**
     * Render <unload> tags for documentation or debugging purposes
     *
     * @param Node $node
     * @return string HTML comment describing unloaded resource
     * @throws ParseException If unsupported file type is used
     */
    private function renderUnloadTag(Node $node): string
    {
        if (!isset($node->attributes['target'])) {
            throw new ParseException("Missing 'target' attribute in <unload> tag");
        }

        $target = htmlspecialchars($node->attributes['target'], ENT_QUOTES, 'UTF-8');
        $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));

        if (in_array($extension, ['css', 'js'], true)) {
            return "<!-- Unload: {$target} -->";
        }

        throw new ParseException("Unsupported file type in <unload>: {$extension}");
    }

    /**
     * Render <block> tag, which removes the wrapper and only outputs children
     * Supports `loop` and `cond` attributes for flow control.
     *
     * @param Node $node
     * @param string $inner Inner HTML to render
     * @return string PHP code with conditional/loop constructs
     * @throws ParseException If invalid loop or condition expression
     */
    private function renderBlockElement(Node $node, string $inner): string
    {
        if (isset($node->attributes['loop'])) {
            $loopExpr = trim($node->attributes['loop']);
            Validator::validatePhpCode($loopExpr);
            if (strpos($loopExpr, ' as ') !== false) {
                return "\n<?php foreach({$loopExpr}): ?>\n{$inner}\n<?php endforeach; ?>\n";
            } elseif (strpos($loopExpr, '=>') !== false) {
                list($array, $vars) = array_map('trim', explode('=>', $loopExpr, 2));
                if (strpos($vars, ',') !== false) {
                    list($key, $val) = array_map('trim', explode(',', $vars, 2));
                    $phpLoop = "foreach({$array} as {$key} => {$val})";
                } else {
                    $phpLoop = "foreach({$array} as {$vars})";
                }
                return "\n<?php {$phpLoop}: ?>\n{$inner}\n<?php endforeach; ?>\n";
            }
            return "\n<?php for({$loopExpr}): ?>\n{$inner}\n<?php endfor; ?>\n";
        }

        if (isset($node->attributes['cond'])) {
            $cond = trim($node->attributes['cond']);
            Validator::validatePhpCode($cond);
            return "\n<?php if({$cond}): ?>\n{$inner}\n<?php endif; ?>\n";
        }

        return $inner;
    }

    /**
     * Render looped HTML elements (for or foreach)
     *
     * @param Node $node
     * @param string $inner Rendered child HTML
     * @return string PHP code with loop structure
     * @throws ParseException If invalid PHP loop syntax
     */
    private function renderLoopElement(Node $node, string $inner): string
    {
        $loopExpr = trim($node->attributes['loop']);
        Validator::validatePhpCode($loopExpr);
        $attrsWithoutLoop = $node->attributes;
        unset($attrsWithoutLoop['loop']);
        $attrs = $this->renderAttributes($attrsWithoutLoop, $node->tagName ?? '');

        if (strpos($loopExpr, ' as ') !== false || strpos($loopExpr, '=>') !== false) {
            return $this->renderForeachLoop($node, $loopExpr, $attrs, $inner);
        }
        return $this->renderForLoop($node, $loopExpr, $attrs, $inner);
    }

    /**
     * Render foreach loop for elements
     *
     * @param Node $node
     * @param string $loopExpr The loop expression
     * @param string $attrs HTML attributes string
     * @param string $inner Inner HTML
     * @return string PHP foreach block
     */
    private function renderForeachLoop(Node $node, string $loopExpr, string $attrs, string $inner): string
    {
        list($array, $vars) = array_map('trim', explode(' as ', $loopExpr, 2));
        if (strpos($vars, '=>') !== false) {
            list($key, $val) = array_map('trim', explode('=>', $vars, 2));
            $phpLoop = "foreach({$array} as {$key} => {$val})";
        } else {
            $phpLoop = "foreach({$array} as {$vars})";
        }

        $isVoid = isset($this->voidElements[strtolower($node->tagName ?? '')]);
        return $isVoid
            ? "<?php {$phpLoop}: ?>\n<{$node->tagName}{$attrs}>\n<?php endforeach; ?>"
            : "<?php {$phpLoop}: ?>\n<{$node->tagName}{$attrs}>{$inner}</{$node->tagName}>\n<?php endforeach; ?>";
    }

    /**
     * Render for loop for elements
     *
     * @param Node $node
     * @param string $loopExpr The loop expression
     * @param string $attrs HTML attributes string
     * @param string $inner Inner HTML
     * @return string PHP for block
     */
    private function renderForLoop(Node $node, string $loopExpr, string $attrs, string $inner): string
    {
        $isVoid = isset($this->voidElements[strtolower($node->tagName ?? '')]);
        return $isVoid
            ? "<?php for({$loopExpr}): ?>\n<{$node->tagName}{$attrs}>\n<?php endfor; ?>"
            : "<?php for({$loopExpr}): ?>\n<{$node->tagName}{$attrs}>{$inner}</{$node->tagName}>\n<?php endfor; ?>";
    }

    /**
     * Render HTML attributes safely
     *
     * @param array<string, string|bool> $attributes
     * @param string $tagName Tag name for exclusion
     * @return string Rendered attributes as HTML
     */
    private function renderAttributes(array $attributes, string $tagName): string
    {
        if (empty($attributes)) return '';
        
        $result = '';
        $lowerTagName = strtolower($tagName);
        
        foreach ($attributes as $k => $v) {
            if (strtolower($k) === $lowerTagName) continue;
            if ($v === true) {
                $result .= " {$k}";
            } else {
                $escaped = htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $result .= " {$k}=\"{$escaped}\"";
            }
        }
        return $result;
    }
}
