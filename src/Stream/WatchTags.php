<?php

declare(strict_types=1);

namespace Bridge\Stream;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Turns watch sources into watch tags (PLAN §20.6, spec/page.md §13): a model
 * class becomes `<tag>`, a model instance `<tag>.<key>`, a string is used as
 * given. `<tag>` is the table name (`table` style, the default) or the morph
 * class (`class` style); a model's `bridgeTag()` overrides both.
 */
final class WatchTags
{
    public const STYLE_TABLE = 'table';

    public const STYLE_CLASS = 'class';

    public function __construct(private readonly string $style = self::STYLE_TABLE)
    {
        if (! in_array($style, [self::STYLE_TABLE, self::STYLE_CLASS], true)) {
            throw new InvalidArgumentException("bridge.watch.tags must be 'table' or 'class', got [{$style}].");
        }
    }

    /**
     * Tags a prop watches: a record watches `<tag>.<key>` only, so it does not
     * reload when another record of the same model changes.
     *
     * @return list<string>
     */
    public function forWatch(Model|string $source): array
    {
        if ($source instanceof Model) {
            return [$this->record($source)];
        }

        if (self::isModelClass($source)) {
            return [$this->base($source)];
        }

        return [self::assertValid($source)];
    }

    /**
     * Tags published for a change: a record publishes `<tag>` and
     * `<tag>.<key>`, so props watching either match.
     *
     * @return list<string>
     */
    public function forChange(Model|string $source): array
    {
        if ($source instanceof Model) {
            return [$this->base($source), $this->record($source)];
        }

        if (self::isModelClass($source)) {
            return [$this->base($source)];
        }

        return [self::assertValid($source, wildcard: true)];
    }

    /**
     * `<tag>` of a model class or instance.
     *
     * @param  Model|class-string<Model>  $model
     */
    public function base(Model|string $model): string
    {
        $instance = $model instanceof Model ? $model : new $model;

        if (method_exists($instance, 'bridgeTag')) {
            $tag = $instance->bridgeTag();

            if (! is_string($tag)) {
                throw new InvalidArgumentException($instance::class.'::bridgeTag() must return a string.');
            }
        } else {
            $tag = $this->style === self::STYLE_CLASS ? $instance->getMorphClass() : $instance->getTable();
        }

        return self::assertValid($tag);
    }

    /** `<tag>.<key>` of a saved record. */
    public function record(Model $model): string
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidArgumentException($model::class.': watch tags need a model with an integer or string key.');
        }

        return self::assertValid($this->base($model).'.'.$key);
    }

    /**
     * A tag contains no `*`, `,` or whitespace; a published tag may end in `.*`.
     */
    public static function assertValid(string $tag, bool $wildcard = false): string
    {
        $pattern = $wildcard ? '/^[^*,\s]+(\.\*)?$/' : '/^[^*,\s]+$/';

        if (preg_match($pattern, $tag) !== 1) {
            throw new InvalidArgumentException("Invalid watch tag [{$tag}]: tags contain no '*', ',' or whitespace".($wildcard ? " (except a trailing '.*')." : '.'));
        }

        return $tag;
    }

    /** @phpstan-assert-if-true class-string<Model> $value */
    private static function isModelClass(string $value): bool
    {
        return class_exists($value) && is_subclass_of($value, Model::class);
    }
}
