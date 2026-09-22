<?php

declare(strict_types=1);

namespace Bridge\Representation;

use Bridge\Negotiation\Mode;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Mode → Representer. Applications may swap an implementation with extend().
 */
final class RepresenterRegistry
{
    /** @var array<string, class-string<Representer>|Representer> */
    private array $bindings = [];

    public function __construct(private readonly Container $container)
    {
        $this->bindings = [
            Mode::Html->value => HtmlRepresenter::class,
            Mode::Page->value => PageRepresenter::class,
            Mode::Json->value => JsonRepresenter::class,
        ];
    }

    /**
     * @param  class-string<Representer>|Representer  $representer
     */
    public function extend(Mode $mode, string|Representer $representer): void
    {
        $this->bindings[$mode->value] = $representer;
    }

    public function for(Mode $mode): Representer
    {
        $binding = $this->bindings[$mode->value] ?? null;

        if ($binding === null) {
            throw new InvalidArgumentException("No representer registered for mode [{$mode->value}].");
        }

        if ($binding instanceof Representer) {
            return $binding;
        }

        $instance = $this->container->make($binding);

        if (! $instance instanceof Representer) {
            throw new InvalidArgumentException("[$binding] is not a ".Representer::class);
        }

        return $this->bindings[$mode->value] = $instance;
    }
}
