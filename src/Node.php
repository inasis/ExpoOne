<?php
declare(strict_types=1);

namespace ExpoOne;

/**
 * Node with better type safety and separation of concerns
 */
class Node
{
    public string $type;
    public ?string $tagName = null;
    /** @var array<string, string|bool> */
    public array $attributes = [];
    /** @var Node[] */
    public array $children = [];
    public ?string $content = null;

    private static Renderer $renderer;

    public function __construct(string $type, ?string $tagName = null, ?string $content = null, array $attributes = [])
    {
        $this->type = $type;
        $this->tagName = $tagName;
        $this->content = $content;
        $this->attributes = $attributes;

        if (!isset(self::$renderer)) {
            self::$renderer = new Renderer();
        }
    }

    public function append(Node $child): void
    {
        $this->children[] = $child;
    }

    public function toRender(): string
    {
        $output = self::$renderer->render($this);
        return self::$renderer->injectAssets($output);
    }
}