<?php

declare(strict_types=1);

namespace MacroLLM\Message;

/**
 * A single content part within a multimodal message.
 * Used in InternalMessage::$content when passing images alongside text.
 */
final class ContentPart
{
    public function __construct(
        public readonly ContentPartType $type,
        public readonly string $value,           // text string | image URL | base64 data
        public readonly ?string $mimeType = null, // required for image_base64
        public readonly string $detail = 'auto', // 'auto'|'low'|'high' for image_url
    ) {}

    public static function text(string $text): self
    {
        return new self(ContentPartType::Text, $text);
    }

    public static function imageUrl(string $url, string $detail = 'auto'): self
    {
        return new self(ContentPartType::ImageUrl, $url, detail: $detail);
    }

    /**
     * @param string $base64 Raw base64-encoded image data (no data URI prefix)
     * @param string $mimeType e.g. 'image/jpeg', 'image/png'
     */
    public static function imageBase64(string $base64, string $mimeType): self
    {
        return new self(ContentPartType::ImageBase64, $base64, mimeType: $mimeType);
    }
}
