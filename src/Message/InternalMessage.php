<?php

declare(strict_types=1);

namespace MacroLLM\Message;

use MacroLLM\Tool\ToolCall;
use MacroLLM\Tool\ToolResult;

final class InternalMessage
{
    /**
     * @param string|ContentPart[]|null $content  String for text-only; ContentPart[] for multimodal
     * @param ToolCall[] $toolCalls
     */
    public function __construct(
        public readonly Role $role,
        public readonly string|array|null $content,
        public readonly array $toolCalls = [],
        public readonly ?string $toolCallId = null,
        public readonly ?string $name = null,
    ) {}

    public static function system(string $content): self
    {
        return new self(role: Role::System, content: $content);
    }

    public static function user(string $content): self
    {
        return new self(role: Role::User, content: $content);
    }

    /**
     * Create a multimodal user message with mixed text and image parts.
     *
     * @param ContentPart ...$parts
     */
    public static function userWithParts(ContentPart ...$parts): self
    {
        return new self(role: Role::User, content: $parts);
    }

    /**
     * Convenience factory: text + one image, base64 by default.
     *
     * - Local file path  → reads file, encodes to base64 (default)
     * - URL              → encodes to base64 by fetching (default), or sends as URL if $asUrl=true
     * - Raw base64 string → used directly when $mimeType is provided
     *
     * @param string      $text     The text prompt
     * @param string      $image    File path, URL, or raw base64 string
     * @param string|null $mimeType MIME type (e.g. 'image/jpeg'). Auto-detected from file extension if null.
     * @param bool        $asUrl    Send as image URL instead of base64 (only works with providers that accept URLs)
     */
    public static function userWithImage(
        string $text,
        string $image,
        ?string $mimeType = null,
        bool $asUrl = false,
    ): self {
        if ($asUrl) {
            return new self(role: Role::User, content: [
                ContentPart::text($text),
                ContentPart::imageUrl($image),
            ]);
        }

        // Resolve to base64
        if (file_exists($image)) {
            // Local file
            $data     = base64_encode(file_get_contents($image));
            $mimeType = $mimeType ?? self::mimeFromPath($image);
        } elseif (filter_var($image, FILTER_VALIDATE_URL)) {
            // Remote URL — fetch and encode
            $raw      = file_get_contents($image);
            $data     = base64_encode($raw);
            $mimeType = $mimeType ?? 'image/jpeg';
        } else {
            // Already base64
            $data     = $image;
            $mimeType = $mimeType ?? 'image/jpeg';
        }

        return new self(role: Role::User, content: [
            ContentPart::text($text),
            ContentPart::imageBase64($data, $mimeType),
        ]);
    }

    private static function mimeFromPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            default       => 'image/jpeg',
        };
    }

    /** @param ToolCall[] $toolCalls */
    public static function assistant(?string $content, array $toolCalls = []): self
    {
        return new self(role: Role::Assistant, content: $content, toolCalls: $toolCalls);
    }

    public static function tool(ToolResult $result): self
    {
        return new self(
            role: Role::Tool,
            content: is_string($result->content) ? $result->content : json_encode($result->content),
            toolCallId: $result->toolCallId,
            name: $result->name,
        );
    }

    /** Returns true if this message has multimodal content (ContentPart[]). */
    public function isMultimodal(): bool
    {
        return is_array($this->content);
    }
}
