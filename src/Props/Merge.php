<?php

declare(strict_types=1);

namespace Bridge\Props;

/**
 * Included normally, but listed under `meta.merge` so clients append arrays
 * (and paginator `data`) on partial reloads instead of replacing them.
 * Pass a Closure when the value is expensive.
 */
final class Merge extends PropHint {}
