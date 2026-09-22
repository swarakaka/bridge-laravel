<?php

declare(strict_types=1);

namespace Bridge\Testing;

use Bridge\Negotiation\Mode;
use Bridge\Support\Headers;
use Closure;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * TestResponse macros for asserting Bridge responses in any mode.
 */
final class BridgeTestingMacros
{
    public static function register(): void
    {
        if (TestResponse::hasMacro('assertBridgePage')) {
            return;
        }

        TestResponse::macro('assertBridgePage', function (?string $component = null, ?Closure $assert = null): TestResponse {
            /** @var TestResponse<Response> $this */
            $contentType = (string) $this->headers->get('Content-Type');
            Assert::assertTrue(
                str_starts_with($contentType, Mode::PAGE_MEDIA_TYPE) || str_starts_with($contentType, 'text/html'),
                "Expected a Bridge page response or an HTML shell, got [{$contentType}].",
            );

            $page = AssertablePage::fromResponse($this);

            if ($component !== null) {
                $page->component($component);
            }

            if ($assert !== null) {
                $assert($page);
            }

            return $this;
        });

        TestResponse::macro('assertBridgeProp', function (string $key, mixed $expected = null): TestResponse {
            /** @var TestResponse<Response> $this */
            $page = AssertablePage::fromResponse($this);

            if (func_num_args() === 1) {
                $page->has($key);
            } else {
                $page->where($key, $expected);
            }

            return $this;
        });

        TestResponse::macro('assertBridgeError', function (int $status, ?string $kind = null): TestResponse {
            /** @var TestResponse<Response> $this */
            $this->assertStatus($status);
            $contentType = (string) $this->headers->get('Content-Type');
            Assert::assertStringStartsWith(Mode::PAGE_MEDIA_TYPE, $contentType, "Expected a Bridge error response, got [{$contentType}].");

            $this->assertJson(fn (AssertableJson $json) => $json
                ->where('type', 'error')
                ->where('error.status', $status)
                ->when($kind !== null, fn (AssertableJson $j) => $j->where('error.kind', $kind))
                ->etc());

            return $this;
        });

        TestResponse::macro('assertJsonMode', function (?Closure $assert = null): TestResponse {
            /** @var TestResponse<Response> $this */
            $contentType = (string) $this->headers->get('Content-Type');
            Assert::assertStringStartsWith('application/json', $contentType, "Expected a JSON mode response, got [{$contentType}].");
            Assert::assertArrayHasKey('data', $this->json(), 'JSON mode responses carry a `data` member.');

            if ($assert !== null) {
                $this->assertJson(fn (AssertableJson $json) => $json->has('data', function (AssertableJson $data) use ($assert): void {
                    $assert($data);
                    $data->etc();
                })->etc());
            }

            return $this;
        });

        TestResponse::macro('assertHtmlShell', function (bool $embedded = true): TestResponse {
            /** @var TestResponse<Response> $this */
            $this->assertHeader('Content-Type', 'text/html; charset=utf-8');

            if ($embedded) {
                $this->assertSee('id="'.Headers::EMBEDDED_PAGE_ID.'"', false);
            } else {
                $this->assertDontSee('id="'.Headers::EMBEDDED_PAGE_ID.'"', false);
            }

            return $this;
        });
    }
}
