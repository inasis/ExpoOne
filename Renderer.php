<?php
declare(strict_types=1);

/**
 * Template renderer with filter support
 */
class Renderer
{
    private const COMPILED_REGEX = [
        'php_block' => '/\{@\s*([\s\S]*?)\s*\}/s',
        'variable' => '/\{\$([^}]+)\}/s'
    ];

    private array $voidElements;
    private array $cssFiles = [];
    private array $jsFiles = [];

    public function __construct()
    {
        $this->voidElements = array_flip([
            'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 
            'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'
        ]);
    }

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

    private function renderTextContent(string $content): string
    {
        // Handle PHP blocks with security validation
        // Pattern supports multi-line blocks with proper whitespace handling        
        $content = preg_replace_callback(self::COMPILED_REGEX['php_block'], function ($m) {
            $code = trim($m[1]);
            Validator::validatePhpCode($code);
            return "<?php\n" . $code . "\n?>";
        }, $content);

            // Handle variable interpolation with filters
        $content = preg_replace_callback(self::COMPILED_REGEX['variable'], function ($m) {
            $expr = trim($m[1]);
            $processedCode = Filter::parseVariableExpression($expr);
            return "<?= {$processedCode} ?>";
        }, $content);

        return $content;
    }

    private function renderComment(string $content): string
    {
        $cleanContent = preg_replace('/\/\/.*$/s', '', $content);
        return $cleanContent ? "<!--{$cleanContent}-->" : '';
    }

    private function renderElement(Node $node): string
    {
        $inner = implode('', array_map([$this, 'render'], $node->children));

        // Handle <load> tag - collect for batch output
        if (strtolower($node->tagName ?? '') === 'load') {
            return $this->collectLoadTag($node);
        }

        // Handle <unload> tag - generates comment for documentation
        if (strtolower($node->tagName ?? '') === 'unload') {
            return $this->renderUnloadTag($node);
        }

        // Handle <block> tag - render only children (tag wrapper removed)
        if (strtolower($node->tagName ?? '') === 'block') {
            return $this->renderBlockElement($node, $inner);
        }

        $attrs = $this->renderAttributes($node->attributes, $node->tagName ?? '');

        // Handle loop rendering (must be processed before cond)
        if (isset($node->attributes['loop'])) {
            return $this->renderLoopElement($node, $inner);
        }

        // Handle conditional rendering
        if (isset($node->attributes['cond'])) {
            $cond = trim($node->attributes['cond']);
            Validator::validatePhpCode($cond);
            $attrsWithoutCond = $node->attributes;
            unset($attrsWithoutCond['cond']);
            $attrs = $this->renderAttributes($attrsWithoutCond, $node->tagName ?? '');
            return "\n<?php if({$cond}): ?><{$node->tagName}{$attrs}>{$inner}</{$node->tagName}><?php endif; ?>\n";
        }

        // Handle void elements
        if (isset($this->voidElements[strtolower($node->tagName ?? '')])) {
            return "<{$node->tagName}{$attrs}>\n";
        }

        return "<{$node->tagName}{$attrs}>{$inner}</{$node->tagName}>";
    }

    /**
     * Collect <load> tag information to generate sorted output later
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
        
        // Return empty string - files will be injected later
        return '';
    }

    /**
     * Inject collected CSS and JS files into appropriate positions
     */
    public function injectAssets(string $html): string
    {
        // Generate CSS tags
        $cssOutput = $this->generateCssTags();
        
        // Generate JS tags
        $headJsOutput = $this->generateJsTags('head');
        $bodyJsOutput = $this->generateJsTags('body');
        
        // Inject CSS and head JS after <head> tag
        if (!empty($cssOutput) || !empty($headJsOutput)) {
            $headAssets = trim($cssOutput . "\n" . $headJsOutput);
            if ($headAssets) {
                $html = preg_replace(
                    '/(<\/head[^>]*>)/i',
                    $headAssets ."\n$1",
                    $html,
                    1
                );
            }
        }
        
        // Inject body JS before </body> tag
        if (!empty($bodyJsOutput)) {
            $html = preg_replace(
                '/(<\/body>)/i',
                $bodyJsOutput . "\n$1",
                $html,
                1
            );
        }
        
        return $html;
    }

    /**
     * Generate sorted CSS link tags
     */
    private function generateCssTags(): string
    {
        if (empty($this->cssFiles)) {
            return '';
        }
        
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
     * Generate sorted JS script tags
     */
    private function generateJsTags(string $type): string
    {
        if (!isset($this->jsFiles[$type]) || empty($this->jsFiles[$type])) {
            return '';
        }
        
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
     * Render <unload> tag - generates comment for documentation
     */
    private function renderUnloadTag(Node $node): string
    {
        if (!isset($node->attributes['target'])) {
            throw new ParseException("Missing 'target' attribute in <unload> tag");
        }

        $target = htmlspecialchars($node->attributes['target'], ENT_QUOTES, 'UTF-8');
        $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));

        if ($extension === 'css' || $extension === 'js') {
            return "<!-- Unload: {$target} -->";
        } else {
            throw new ParseException("Unsupported file type in <unload>: {$extension}");
        }
    }

    /**
     * Render <block> element - only outputs children, removes wrapper tag
     * Supports loop and cond attributes without rendering the block tag itself
     */
    private function renderBlockElement(Node $node, string $inner): string
    {
        // Handle loop on block
        if (isset($node->attributes['loop'])) {
            $loopExpr = trim($node->attributes['loop']);
            Validator::validatePhpCode($loopExpr);

            // Check for 'as' keyword or '=>' for foreach loop
            if (strpos($loopExpr, ' as ') !== false) {
                // Native PHP foreach syntax: array as $val or array as $key=>$val
                return "\n<?php foreach({$loopExpr}): ?>\n{$inner}\n<?php endforeach; ?>\n";
            } elseif (strpos($loopExpr, '=>') !== false) {
                // Custom syntax with =>: array=>$val or array=>$key,$val
                list($array, $vars) = array_map('trim', explode('=>', $loopExpr, 2));
                
                if (strpos($vars, ',') !== false) {
                    list($key, $val) = array_map('trim', explode(',', $vars, 2));
                    $phpLoop = "foreach({$array} as {$key} => {$val})";
                } else {
                    $phpLoop = "foreach({$array} as {$vars})";
                }
                
                return "\n<?php {$phpLoop}: ?>\n{$inner}\n<?php endforeach; ?>\n";
            } else {
                // for loop: $i=0;$i<10;$i++
                return "\n<?php for({$loopExpr}): ?>\n{$inner}\n<?php endfor; ?>\n";
            }
        }

        // Handle cond on block
        if (isset($node->attributes['cond'])) {
            $cond = trim($node->attributes['cond']);
            Validator::validatePhpCode($cond);
            return "\n<?php if({$cond}): ?>\n{$inner}\n<?php endif; ?>\n";
        }

        // No attributes - just return children
        return $inner;
    }
    
    /**
     * Render element with loop attribute
     * Supports:
     * - loop="array=>$val" (foreach without key)
     * - loop="array=>$key,$val" (foreach with key)
     * - loop="array as $val" (foreach without key)
     * - loop="array as $key=>$val" (foreach with key)
     * - loop="$i=0;$i<100;$i++" (for loop)
     */
    private function renderLoopElement(Node $node, string $inner): string
    {
        $loopExpr = trim($node->attributes['loop']);
        Validator::validatePhpCode($loopExpr);

        // Remove loop attribute from rendering
        $attrsWithoutLoop = $node->attributes;
        unset($attrsWithoutLoop['loop']);
        $attrs = $this->renderAttributes($attrsWithoutLoop, $node->tagName ?? '');

        // Detect loop type
        // Check for 'as' keyword or '=>' for foreach loop
        if (strpos($loopExpr, ' as ') !== false || strpos($loopExpr, '=>') !== false) {
            // foreach loop
            return $this->renderForeachLoop($node, $loopExpr, $attrs, $inner);
        } else {
            // for loop
            return $this->renderForLoop($node, $loopExpr, $attrs, $inner);
        }
    }

    /**
     * Render foreach loop
     */
    private function renderForeachLoop(Node $node, string $loopExpr, string $attrs, string $inner): string
    {
        list($array, $vars) = array_map('trim', explode('=>', $loopExpr, 2));

        // Check if key is included
        if (strpos($vars, ',') !== false) {
            // With key: array=>$key,$val
            list($key, $val) = array_map('trim', explode(',', $vars, 2));
            $phpLoop = "foreach({$array} as {$key} => {$val})";
        } else {
            // Without key: array=>$val
            $phpLoop = "foreach({$array} as {$vars})";
        }

        $isVoid = isset($this->voidElements[strtolower($node->tagName ?? '')]);
        
        if ($isVoid) {
            return "<?php {$phpLoop}: ?>\n<{$node->tagName}{$attrs}>\n<?php endforeach; ?>";
        }

        return "<?php {$phpLoop}: ?>\n<{$node->tagName}{$attrs}>{$inner}</{$node->tagName}>\n<?php endforeach; ?>";
    }

    /**
     * Render for loop
     */
    private function renderForLoop(Node $node, string $loopExpr, string $attrs, string $inner): string
    {
        $isVoid = isset($this->voidElements[strtolower($node->tagName ?? '')]);
        
        if ($isVoid) {
            return "<?php for({$loopExpr}): ?>\n<{$node->tagName}{$attrs}>\n<?php endfor; ?>";
        }

        return "<?php for({$loopExpr}): ?>\n<{$node->tagName}{$attrs}>{$inner}</{$node->tagName}>\n<?php endfor; ?>";
    }

    private function renderAttributes(array $attributes, string $tagName): string
    {
        if (empty($attributes)) return '';
        
        $result = '';
        $lowerTagName = strtolower($tagName);
        
        foreach ($attributes as $k => $v) {
            if (strtolower($k) === $lowerTagName) {
                continue;
            }

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
