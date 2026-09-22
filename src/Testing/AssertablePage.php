<?php

declare(strict_types=1);

namespace Bridge\Testing;

use Bridge\Support\Headers;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fluent assertions over a page object (from a page response or an HTML shell).
 */
final class AssertablePage
{
    /**
     * @param  array<string, mixed>  $page
     */
    private function __construct(private readonly array $page) {}

    /**
     * @param  TestResponse<Response>  $response
     */
    public static function fromResponse(TestResponse $response): self
    {
        $contentType = (string) $response->headers->get('Content-Type');

        if (str_starts_with($contentType, 'text/html')) {
            $content = (string) $response->getContent();
            $pattern = '#<script type="application/json" id="'.preg_quote(Headers::EMBEDDED_PAGE_ID, '#').'">(.*?)</script>#s';

            Assert::assertMatchesRegularExpression($pattern, $content, 'No embedded page object found in the HTML shell.');
            preg_match($pattern, $content, $m);

            return new self((array) json_decode($m[1], true, 512, JSON_THROW_ON_ERROR));
        }

        return new self((array) $response->json());
    }

    /**
     * @param  array<string, mixed>  $page
     */
    public static function fromArray(array $page): self
    {
        return new self($page);
    }

    public function component(string $component): self
    {
        Assert::assertSame($component, $this->page['component'] ?? null, 'Unexpected page component. Document: '.json_encode($this->page));

        return $this;
    }

    public function url(string $url): self
    {
        Assert::assertSame($url, $this->page['url'] ?? null, 'Unexpected page url.');

        return $this;
    }

    public function has(string $key, ?int $count = null): self
    {
        Assert::assertTrue(Arr::has($this->props(), $key), "Prop [{$key}] is missing.");

        if ($count !== null) {
            Assert::assertCount($count, (array) Arr::get($this->props(), $key), "Prop [{$key}] does not have {$count} items.");
        }

        return $this;
    }

    public function missing(string $key): self
    {
        Assert::assertFalse(Arr::has($this->props(), $key), "Prop [{$key}] should be missing.");

        return $this;
    }

    public function where(string $key, mixed $expected): self
    {
        $this->has($key);
        $actual = Arr::get($this->props(), $key);

        if ($expected instanceof Closure) {
            Assert::assertTrue((bool) $expected($actual), "Prop [{$key}] failed the closure assertion.");
        } else {
            Assert::assertEquals($expected, $actual, "Prop [{$key}] has an unexpected value.");
        }

        return $this;
    }

    /**
     * @param  list<string>  $keys
     */
    public function deferred(string $group, array $keys): self
    {
        Assert::assertSame($keys, Arr::get($this->page, "deferred.{$group}"), "Deferred group [{$group}] mismatch.");

        return $this;
    }

    public function build(?string $build): self
    {
        Assert::assertSame($build, $this->page['build'] ?? null, 'Unexpected build.');

        return $this;
    }

    /**
     * @param  Closure(AssertableJson): mixed  $assert
     */
    public function props(?Closure $assert = null): mixed
    {
        $props = (array) ($this->page['props'] ?? []);

        if ($assert === null) {
            return $props;
        }

        $assert(AssertableJson::fromArray($props));

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->page;
    }
}
