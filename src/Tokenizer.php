<?php
declare(strict_types=1);

namespace ExpoOne;

/**
 * Splits an HTML string into tokens.  
 * This class performs pure parsing only and does not interpret or render tokens.
 */
class Tokenizer
{
    /**
     * Converts an HTML string into an array of tokens.
     *
     * Each token is an associative array with a `type` and `value` key, and optionally `parsed` data for tags.
     *
     * @param string $html The input HTML string.
     * @return array<int, array<string, mixed>> List of tokens.
     */
    public function tokenize(string $html): array
    {
        $tokens = [];
        $len = strlen($html);
        $i = 0;
        $buffer = '';
        $inTag = false;
        $inQuote = false;
        $quoteChar = '';

        while ($i < $len) {
            // Detect PHP block: {@ ... }
            if (!$inTag && !$inQuote && substr($html, $i, 2) === '{@') {
                $this->flushTextBuffer($tokens, $buffer);
                $i = $this->tokenizePhpBlock($html, $i, $len, $tokens);
                continue;
            }

            // Detect comment
            if (!$inTag && !$inQuote && $this->isCommentStart($html, $i)) {
                $this->flushTextBuffer($tokens, $buffer);
                $i = $this->tokenizeComment($html, $i, $len, $tokens);
                continue;
            }

            $ch = $html[$i];

            // Tag start
            if (!$inTag && $ch === '<' && !$inQuote) {
                $this->flushTextBuffer($tokens, $buffer);
                $inTag = true;
                $inQuote = false;
                $quoteChar = '';
                $buffer = $ch;
                $i++;
                continue;
            }

            // Inside tag
            if ($inTag) {
                $buffer .= $ch;

                // Handle quoted attributes
                if (($ch === '"' || $ch === "'") && ($i === 0 || $html[$i - 1] !== '\\')) {
                    if ($inQuote && $ch === $quoteChar) {
                        $inQuote = false;
                        $quoteChar = '';
                    } elseif (!$inQuote) {
                        $inQuote = true;
                        $quoteChar = $ch;
                    }
                }

                // Tag end
                if ($ch === '>' && !$inQuote) {
                    $parsed = $this->parseTag($buffer);
                    $tokens[] = [
                        'type' => 'tag',
                        'value' => $buffer,
                        'parsed' => $parsed
                    ];
                    $buffer = '';
                    $inTag = false;
                }

                $i++;
                continue;
            }

            // Plain text
            $buffer .= $ch;
            $i++;
        }

        // Process any remaining buffer
        $this->flushRemainingBuffer($tokens, $buffer, $inTag);

        return $tokens;
    }

    /**
     * Tokenizes a PHP block: {@ ... }
     *
     * Everything inside the block is treated as raw text.
     *
     * @param string $html The full HTML string.
     * @param int $start The starting position of the block.
     * @param int $len The total string length.
     * @param array<int, array<string, mixed>> &$tokens The token list.
     * @return int The new position after processing the block.
     */
    private function tokenizePhpBlock(string $html, int $start, int $len, array &$tokens): int
    {
        // We have not yet implemented functionality to handle nested braces here.
        $depth = 1;
        $i = $start + 2; // Skip '{@'
        $content = '';

        while ($i < $len && $depth > 0) {
            $ch = $html[$i];

            if ($ch === '{' && $i + 1 < $len && $html[$i + 1] === '@') {
                $depth++;
                $content .= '{@';
                $i += 2;
                continue;
            }

            if ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
                $content .= $ch;
                $i++;
                continue;
            }

            $content .= $ch;
            $i++;
        }

        $tokens[] = [
            'type' => 'text',
            'value' => '{@' . $content . '}'
        ];

        return $i + 1; // Skip closing '}'
    }

    /**
     * Checks whether the current position marks the start of a comment.
     *
     * @param string $html The HTML string.
     * @param int $pos Current index in the string.
     * @return bool True if a comment starts at this position.
     */
    private function isCommentStart(string $html, int $pos): bool
    {
        return substr($html, $pos, 4) === '<!--';
    }

    /**
     * Tokenizes an HTML comment and returns the next parsing position.
     *
     * @param string $html The HTML string.
     * @param int $start The starting index of the comment.
     * @param int $len The total string length.
     * @param array<int, array<string, mixed>> &$tokens The token list.
     * @return int The position after the comment ends.
     */
    private function tokenizeComment(string $html, int $start, int $len, array &$tokens): int
    {
        $commentEnd = strpos($html, '-->', $start + 4);
        
        if ($commentEnd === false) {
            // Unclosed comment
            $commentValue = substr($html, $start + 4);
            $tokens[] = [
                'type' => 'comment',
                'value' => $commentValue
            ];
            return $len;
        }
        
        // Properly closed comment
        $commentValue = substr($html, $start + 4, $commentEnd - $start - 4);
        $tokens[] = [
            'type' => 'comment',
            'value' => $commentValue
        ];
        return $commentEnd + 3;
    }

    /**
     * Flushes the text buffer into a token if it is not empty.
     *
     * @param array<int, array<string, mixed>> &$tokens The token list.
     * @param string &$buffer The text buffer.
     */
    private function flushTextBuffer(array &$tokens, string &$buffer): void
    {
        if ($buffer !== '') {
            $tokens[] = ['type' => 'text', 'value' => $buffer];
            $buffer = '';
        }
    }

    /**
     * Flushes any remaining buffer when the parsing loop ends.
     *
     * @param array<int, array<string, mixed>> &$tokens The token list.
     * @param string $buffer The remaining buffer content.
     * @param bool $inTag Whether the buffer is part of an unfinished tag.
     */
    private function flushRemainingBuffer(array &$tokens, string $buffer, bool $inTag): void
    {
        if ($buffer === '') {
            return;
        }

        if ($inTag) {
            $tokens[] = [
                'type' => 'tag',
                'value' => $buffer,
                'parsed' => $this->parseTag($buffer)
            ];
        } else {
            $tokens[] = ['type' => 'text', 'value' => $buffer];
        }
    }

    /**
     * Parses a tag and returns its components (tag name, attributes, etc.).
     *
     * @param string $tag The full tag string.
     * @return array<string, mixed> Parsed tag data.
     */
    private function parseTag(string $tag): array
    {
        $isClosing = (bool) preg_match('/^<\s*\//', $tag);
        $isSelfClosing = (bool) preg_match('/\/\s*>$/', $tag);

        // Extract tag name
        $tagName = $this->extractTagName($tag, $isClosing);

        // Extract attributes if applicable
        $attributes = [];
        if (!$isClosing) {
            $attributes = $this->extractAttributes($tag, $tagName);
        }

        return [
            'tagName' => $tagName,
            'isClosing' => $isClosing,
            'isSelfClosing' => $isSelfClosing,
            'attributes' => $attributes,
        ];
    }

    /**
     * Extracts the tag name from a tag string.
     *
     * @param string $tag The tag string.
     * @param bool $isClosing Whether the tag is a closing tag.
     * @return string The tag name.
     */
    private function extractTagName(string $tag, bool $isClosing): string
    {
        $pattern = $isClosing 
            ? '/<\s*\/\s*([a-zA-Z0-9:_-]+)/'
            : '/<\s*([a-zA-Z0-9:_-]+)/';
        
        if (preg_match($pattern, $tag, $m)) {
            return $m[1];
        }
        
        return '';
    }

    /**
     * Extracts attributes from a tag string.
     *
     * Handles double-quoted, single-quoted, and unquoted values.
     *
     * @param string $tag The full tag string.
     * @param string $tagName The already extracted tag name.
     * @return array<string, string|bool> Associative array of attributes.
     */
    private function extractAttributes(string $tag, string $tagName): array
    {
        $attributes = [];
        
        // Remove tag name and trailing angle brackets
        $afterTagName = preg_replace('/^<\s*' . preg_quote($tagName, '/') . '\s*/i', '', $tag);
        $afterTagName = preg_replace('/\/?\s*>$/', '', $afterTagName);
        
        $pattern = '/([a-zA-Z0-9:_-]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/';
        
        if (preg_match_all($pattern, $afterTagName, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = $match[1];
                
                if (isset($match[2]) && $match[2] !== '') {
                    $value = $match[2];
                } elseif (isset($match[3]) && $match[3] !== '') {
                    $value = $match[3];
                } elseif (isset($match[4]) && $match[4] !== '') {
                    $value = $match[4];
                } else {
                    $value = true;
                }
                
                $attributes[$name] = $value;
            }
        }
        
        return $attributes;
    }
}
