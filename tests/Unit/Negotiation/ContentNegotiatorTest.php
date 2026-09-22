<?php

declare(strict_types=1);

use Bridge\Negotiation\ContentNegotiator;
use Bridge\Negotiation\Mode;
use Bridge\Negotiation\NotAcceptableException;
use Bridge\Negotiation\UnsupportedProtocolVersionException;
use Illuminate\Http\Request;

function negotiate(?string $accept, ?ContentNegotiator $negotiator = null)
{
    $request = Request::create('/customers', 'GET');

    // Symfony's Request::create() sets a browser-like default Accept header.
    $request->headers->remove('Accept');

    if ($accept !== null) {
        $request->headers->set('Accept', $accept);
    }

    return ($negotiator ?? new ContentNegotiator)->negotiate($request);
}

it('selects modes per the precedence matrix', function (?string $accept, Mode $expected) {
    expect(negotiate($accept)->mode)->toBe($expected);
})->with([
    'missing' => [null, Mode::Html],
    'wildcard' => ['*/*', Mode::Html],
    'browser' => ['text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', Mode::Html],
    'json' => ['application/json', Mode::Json],
    'page' => ['application/vnd.bridge+json; v=1', Mode::Page],
    'page without v' => ['application/vnd.bridge+json', Mode::Page],
    'page beats json on q' => ['application/vnd.bridge+json, application/json;q=0.9', Mode::Page],
    'page beats json on tie' => ['application/json, application/vnd.bridge+json', Mode::Page],
    'json beats html on tie' => ['text/html, application/json', Mode::Json],
    'stream' => ['text/event-stream', Mode::Stream],
    'stream beats everything on tie' => ['text/html, application/json, text/event-stream', Mode::Stream],
    'q ordering wins over rank' => ['text/event-stream;q=0.5, text/html', Mode::Html],
    'application/* prefers json over page' => ['application/*', Mode::Page],
]);

it('reports the protocol version and content type', function () {
    $n = negotiate('application/vnd.bridge+json; v=1');

    expect($n->protocolVersion)->toBe(1)
        ->and($n->contentType())->toBe('application/vnd.bridge+json; v=1')
        ->and($n->acceptedType)->toBe('application/vnd.bridge+json');
});

it('rejects unsupported protocol versions', function (string $v) {
    negotiate("application/vnd.bridge+json; v={$v}");
})->with(['2', '0', 'abc', '1.5'])->throws(UnsupportedProtocolVersionException::class);

it('rejects requests that accept nothing bridge can produce', function () {
    negotiate('image/png');
})->throws(NotAcceptableException::class);

it('excludes modes with q=0 and falls back to json for generic clients', function () {
    expect(negotiate('text/html;q=0, */*')->mode)->toBe(Mode::Json)
        ->and(negotiate('text/html;q=0, application/json;q=0, */*')->mode)->toBe(Mode::Page);
});

it('honours the configured default for wildcard-only requests', function () {
    $negotiator = new ContentNegotiator(1, Mode::Json);

    expect(negotiate('*/*', $negotiator)->mode)->toBe(Mode::Json)
        ->and(negotiate(null, $negotiator)->mode)->toBe(Mode::Json)
        ->and(negotiate('text/html', $negotiator)->mode)->toBe(Mode::Html);
});

it('flags wildcard matches', function () {
    expect(negotiate('*/*')->viaWildcard())->toBeTrue()
        ->and(negotiate('text/html')->viaWildcard())->toBeFalse();
});
