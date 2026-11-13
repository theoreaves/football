<?php

namespace App\Support;

use App\Models\Exhibition;
use Generator;
use Illuminate\Database\Eloquent\Casts\Json;
use JsonException;
use OutOfBoundsException;

/** Reads existing history JSON one play at a time; no recording format changes. */
final class ExhibitionHistory
{
    public static function json(Exhibition $game): string
    {
        return $game->getAttributes()['history'] ?? '[]';
    }

    public static function isList(Exhibition $game): bool
    {
        return str_starts_with(ltrim(self::json($game)), '[');
    }

    /** @return Generator<int|string, array> */
    public static function entries(Exhibition $game): Generator
    {
        $json = self::json($game);
        if (! self::isList($game)) {
            foreach (self::decode($json) as $key => $play) {
                yield $key => $play;
            }

            return;
        }
        foreach (self::segments($json) as $index => [$start, $length]) {
            yield $index => self::decode(substr($json, $start, $length), 511);
        }
    }

    public static function count(Exhibition $game): int
    {
        $count = 0;
        foreach (self::entries($game) as $play) {
            $count++;
        }

        return $count;
    }

    public static function last(Exhibition $game): ?array
    {
        $last = null;
        foreach (self::entries($game) as $play) {
            $last = $play;
        }

        return $last;
    }

    public static function at(Exhibition $game, int $index): ?array
    {
        $found = null;
        foreach (self::entries($game) as $key => $play) {
            if ($key === $index) {
                $found = $play;
            }
        }

        return $found;
    }

    public static function find(Exhibition $game, callable $predicate): ?array
    {
        $found = null;
        foreach (self::entries($game) as $key => $play) {
            if ($found === null && $predicate($play, $key)) {
                $found = $play;
            }
        }

        return $found;
    }

    /** Returns selected entries keyed and ordered exactly as in saved history. */
    public static function select(Exhibition $game, array $indices): array
    {
        $wanted = array_fill_keys($indices, true);
        $selected = [];
        foreach (self::entries($game) as $index => $play) {
            if (isset($wanted[$index])) {
                $selected[$index] = $play;
            }
        }

        return $selected;
    }

    public static function append(Exhibition $game, array $play, array $attributes = []): bool
    {
        $json = self::json($game);
        if (! self::isList($game)) {
            $history = self::decode($json);
            $history[] = $play;

            return $game->updateHistoryJson(self::encode($history), $attributes);
        }
        $count = self::count($game);
        $close = strlen(rtrim($json, " \t\r\n")) - 1;
        $next = substr($json, 0, $close).($count ? ',' : '').self::encodePlay($play).substr($json, $close);

        return $game->updateHistoryJson($next, $attributes);
    }

    public static function replace(Exhibition $game, int $index, array $play, array $attributes = []): bool
    {
        $json = self::json($game);
        if (! self::isList($game)) {
            $history = self::decode($json);
            if (! array_key_exists($index, $history)) {
                throw new OutOfBoundsException('Saved play does not exist.');
            }
            $history[$index] = $play;

            return $game->updateHistoryJson(self::encode($history), $attributes);
        }
        $selected = null;
        foreach (self::segments($json) as $key => [$start, $length]) {
            self::decode(substr($json, $start, $length), 511);
            if ($key === $index) {
                $selected = [$start, $length];
            }
        }
        if ($selected === null) {
            throw new OutOfBoundsException('Saved play does not exist.');
        }
        [$start, $length] = $selected;
        $next = substr($json, 0, $start).self::encodePlay($play).substr($json, $start + $length);

        return $game->updateHistoryJson($next, $attributes);
    }

    private static function encodePlay(array $play): string
    {
        // Keep the same encoding depth budget as encoding the complete history.
        return substr(self::encode([$play]), 1, -1);
    }

    private static function encode(array $value): string
    {
        $json = Json::encode($value);
        if ($json === false) {
            throw new JsonException(json_last_error_msg(), json_last_error());
        }

        return $json;
    }

    private static function decode(string $json, int $depth = 512): array
    {
        $value = json_decode($json, true, $depth, JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new JsonException('Saved history must contain play objects or arrays.', JSON_ERROR_SYNTAX);
        }

        return $value;
    }

    /** @return Generator<int, array{int,int}> */
    private static function segments(string $json): Generator
    {
        $length = strlen($json);
        $offset = strspn($json, " \t\r\n");
        if (($json[$offset] ?? null) !== '[') {
            throw new JsonException('Saved history must be a JSON array.', JSON_ERROR_SYNTAX);
        }
        $offset++;
        $offset += strspn($json, " \t\r\n", $offset);
        if (($json[$offset] ?? null) === ']') {
            $offset++;
        } else {
            while ($offset < $length) {
                $start = $offset;
                $depth = 0;
                $quoted = false;
                for (; $offset < $length; $offset++) {
                    $char = $json[$offset];
                    if ($quoted) {
                        if ($char === '\\') {
                            $offset++;
                        } elseif ($char === '"') {
                            $quoted = false;
                        }
                    } elseif ($char === '"') {
                        $quoted = true;
                    } elseif ($char === '[' || $char === '{') {
                        $depth++;
                    } elseif ($char === '}' || ($char === ']' && $depth > 0)) {
                        $depth--;
                        if ($depth < 0) {
                            throw new JsonException('Invalid saved history JSON.', JSON_ERROR_SYNTAX);
                        }
                    } elseif ($depth === 0 && ($char === ',' || $char === ']')) {
                        break;
                    }
                }
                if ($quoted || $depth !== 0 || $offset >= $length || $offset === $start) {
                    throw new JsonException('Incomplete saved history JSON.', JSON_ERROR_SYNTAX);
                }
                yield [$start, $offset - $start];
                $end = $json[$offset++] === ']';
                if ($end) {
                    break;
                }
                $offset += strspn($json, " \t\r\n", $offset);
                if (($json[$offset] ?? null) === ']') {
                    throw new JsonException('Trailing history comma.', JSON_ERROR_SYNTAX);
                }
            }
        }
        $offset += strspn($json, " \t\r\n", $offset);
        if ($offset !== $length || ! str_ends_with(rtrim($json, " \t\r\n"), ']')) {
            throw new JsonException('Invalid saved history suffix.', JSON_ERROR_SYNTAX);
        }
    }
}
