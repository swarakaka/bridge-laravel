<?php

declare(strict_types=1);

use Bridge\Negotiation\Mode;
use Bridge\Negotiation\Negotiation;
use Bridge\Representation\JsonRepresenter;
use Bridge\Representation\Representer;
use Bridge\Representation\RepresenterRegistry;
use Illuminate\Http\Request;

it('resolves, caches and swaps representers per mode', function () {
    $registry = new RepresenterRegistry($this->app);

    $json = $registry->for(Mode::Json);
    expect($json)->toBeInstanceOf(JsonRepresenter::class)
        ->and($registry->for(Mode::Json))->toBe($json);

    $custom = Mockery::mock(Representer::class);
    $registry->extend(Mode::Json, $custom);
    expect($registry->for(Mode::Json))->toBe($custom);

    expect(fn () => $registry->for(Mode::Stream))->toThrow(InvalidArgumentException::class, 'No representer registered for mode [stream].');

    $registry->extend(Mode::Html, stdClass::class);
    expect(fn () => $registry->for(Mode::Html))->toThrow(InvalidArgumentException::class, 'is not a');
});

it('describes the negotiated mode and its content type', function () {
    $json = Negotiation::for(Request::create('/', 'GET', server: ['HTTP_ACCEPT' => 'application/json']));
    $page = Negotiation::for(Request::create('/', 'GET', server: ['HTTP_ACCEPT' => $this::PAGE_ACCEPT]));
    $html = Negotiation::for(Request::create('/', 'GET', server: ['HTTP_ACCEPT' => 'text/html']));
    $stream = new Negotiation(Mode::Stream, 1, 'text/event-stream', null);

    expect($json->is(Mode::Json))->toBeTrue()
        ->and($json->is(Mode::Page))->toBeFalse()
        ->and($json->contentType())->toBe('application/json')
        ->and($page->contentType())->toBe('application/vnd.bridge+json; v=1')
        ->and($html->contentType())->toBe('text/html; charset=utf-8')
        ->and($stream->contentType())->toBe('text/event-stream; charset=utf-8')
        ->and($stream->viaWildcard())->toBeFalse();
});
