<?php
declare(strict_types=1);

namespace ExpoOne;

/**
 * Parser with integrated directive handler
 */
class Parser
{
    private Lexer $lexer;
    private int $maxNestingDepth;
    private const BLOCK_DIRECTIVES = ['if', 'foreach', 'while'];
    private const END_DIRECTIVES = ['end','endif', 'endforeach', 'endwhile'];
    private const DIRECTIVE_MAP = [
        'end' => 'if',
        'endforeach' => 'foreach',
        'endwhile' => 'while'
    ];

    public function __construct(int $maxNestingDepth = 100)
    {
        $this->lexer = new Lexer();
        $this->maxNestingDepth = $maxNestingDepth;
    }

    public function parse(string $html): Node
    {
        $tokens = $this->lexer->analyze($html);
        return $this->buildTreeWithValidation($tokens);
    }

    private function buildTreeWithValidation(array $tokens): Node
    {
        $root = new Node('element', 'root');
        $stack = [$root];
        $dirStack = [];

        foreach ($tokens as $idx => $token) {
            if (count($stack) > $this->maxNestingDepth) {
                throw new ParseException("Maximum nesting depth ({$this->maxNestingDepth}) exceeded", 0, 0, "token #{$idx}");
            }

            $current = &$stack[count($stack) - 1];

            switch ($token['type']) {
                case 'text':
                    if (trim($token['value']) !== '') {
                        $current->append(new Node('text', null, $token['value']));
                    }
                    break;

                case 'comment':
                    $this->handleComment($token, $current, $dirStack, $idx);
                    break;

                case 'tag':
                    $this->handleTag($token, $current, $stack, $idx);
                    break;
            }
        }

        $this->validateFinalState($stack, $dirStack);
        return $root;
    }

    private function handleComment(array $token, Node $current, array &$dirStack, int $idx): void
    {
        $raw = trim($token['value']);
        
        if (preg_match('/^@([a-zA-Z_]+)(\(.*\))?$/s', $raw, $m)) {
            $directive = $m[1];
            $params = $m[2] ?? null;

            if ($this->canHandleDirective($directive)) {
                $this->processDirective($directive, $params, $current, $dirStack, $idx);
            } else {
                $current->append(new Node('comment', null, $token['value']));
            }
        } else {
            $current->append(new Node('comment', null, $token['value']));
        }
    }

    private function processDirective(string $directive, ?string $params, Node $current, array &$dirStack, int $idx): void
    {
        $isClosing = in_array($directive, ['endif', 'endforeach', 'endwhile']) || $directive === 'else';

        $this->validateDirectiveNesting($dirStack, $directive, $idx);

        if ($directive === 'else') {
            $php = $this->handleDirectiveClose($directive, $idx);
        } elseif ($isClosing) {
            array_pop($dirStack);
            $php = $this->handleDirectiveClose($directive, $idx);
        } else {
            $dirStack[] = ['type' => $directive, 'idx' => $idx];
            $php = $this->handleDirectiveOpen($directive, $params, $idx);
        }

        $current->append(new Node('rawphp', null, $php));
    }

    private function handleTag(array $token, Node $current, array &$stack, int $idx): void
    {
        $info = $token['parsed'];
        
        if ($info['isClosing']) {
            if (count($stack) > 1 && end($stack)->tagName === $info['tagName']) {
                array_pop($stack);
            } else {
                if (!$this->isVoidElement($info['tagName'])) {
                    throw new ParseException("Mismatched closing tag </{$info['tagName']}>", 0, 0, "token #{$idx}");
                }
            }
        } else {
            $node = new Node('element', $info['tagName'], null, $info['attributes']);
            $current->append($node);
            
            if (!$info['isSelfClosing'] && !$this->isVoidElement($info['tagName'])) {
                $stack[] = $node;
            }
        }
    }

    private function validateFinalState(array $stack, array $dirStack): void
    {
        if (!empty($dirStack)) {
            $top = end($dirStack);
            throw new ParseException("Unclosed directive block: @{$top['type']}", 0, 0, "starting at token #{$top['idx']}");
        }

        if (count($stack) !== 1) {
            $openTags = array_slice($stack, 1);
            $tagNames = array_map(fn($n) => $n->tagName, $openTags);
            throw new ParseException("Unclosed HTML tags: " . implode(', ', $tagNames));
        }
    }

    private function isVoidElement(string $tagName): bool
    {
        static $voidElements = [
            'area' => true, 'base' => true, 'br' => true, 'col' => true,
            'embed' => true, 'hr' => true, 'img' => true, 'input' => true,
            'link' => true, 'meta' => true, 'param' => true, 'source' => true,
            'track' => true, 'wbr' => true
        ];
        return isset($voidElements[strtolower($tagName)]);
    }

    private function canHandleDirective(string $directive): bool
    {
        return in_array($directive, self::BLOCK_DIRECTIVES)
            || in_array($directive, self::END_DIRECTIVES)
            || $directive === 'else';
    }

    private function handleDirectiveOpen(string $directive, ?string $params, int $tokenIndex): string
    {
        if (!in_array($directive, self::BLOCK_DIRECTIVES)) {
            throw new ParseException("Invalid opening directive: @{$directive}");
        }
        return "\n<?php {$directive}" . ($params ?? '') . ": ?>";
    }

    private function handleDirectiveClose(string $directive, int $tokenIndex): string
    {
        if ($directive === 'else') {
            return "<?php else: ?>";
        }

        if (!in_array($directive, self::END_DIRECTIVES)) {
            throw new ParseException("Invalid closing directive: @{$directive}");
        }

        $phpDirective = str_replace('end', '', $directive);
        return "<?php end{$phpDirective}; ?>";
    }

    private function validateDirectiveNesting(array $dirStack, string $directive, int $tokenIndex): void
    {
        if ($directive === 'else') {
            $top = end($dirStack);
            if (!$top || $top['type'] !== 'if') {
                throw new ParseException("@else directive must be inside an @if block", 0, 0, "token #{$tokenIndex}");
            }
            return;
        }

        if (in_array($directive, self::END_DIRECTIVES)) {
            if (empty($dirStack)) {
                throw new ParseException("Unexpected @{$directive} - no open block to end", 0, 0, "token #{$tokenIndex}");
            }

            $expectedType = self::DIRECTIVE_MAP[$directive];
            $top = end($dirStack);
            if ($top['type'] !== $expectedType) {
                throw new ParseException("Mismatched @{$directive} - expected end of {$top['type']} block", 0, 0, "token #{$tokenIndex}");
            }
        }
    }
}