<?php

declare(strict_types=1);

namespace MacroLLM\Config;

/**
 * Expands ${VAR} placeholders from the process environment.
 *
 * Lookup order is $_ENV first, then getenv(). When a variable is not defined
 * the placeholder is left untouched, so a misconfigured value stays visible
 * instead of silently collapsing to an empty string.
 */
final class EnvResolver
{
    /** Expands every ${VAR} occurrence in the given string. */
    public static function resolve(string $value): string
    {
        if (!str_contains($value, '${')) {
            return $value;
        }

        return (string) preg_replace_callback(
            '/\$\{([A-Z0-9_]+)\}/',
            static function (array $matches): string {
                $name = $matches[1];

                if (isset($_ENV[$name])) {
                    return (string) $_ENV[$name];
                }

                $fromGetenv = getenv($name);

                return $fromGetenv !== false ? $fromGetenv : $matches[0];
            },
            $value,
        );
    }

    /** Expands ${VAR} in a nullable string, preserving null. */
    public static function resolveNullable(?string $value): ?string
    {
        return $value === null ? null : self::resolve($value);
    }

    /**
     * Expands ${VAR} in every string value of a flat map.
     *
     * @param  array<string, string> $values
     * @return array<string, string>
     */
    public static function resolveMap(array $values): array
    {
        return array_map(
            static fn(string $value): string => self::resolve($value),
            $values,
        );
    }
}
