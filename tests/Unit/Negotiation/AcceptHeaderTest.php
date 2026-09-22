<?php

declare(strict_types=1);

use Bridge\Negotiation\AcceptHeader;

it('treats a missing or empty header as */*', function (?string $header) {
    $accept = AcceptHeader::parse($header);

    expect($accept->ranges)->toHaveCount(1)
        ->and($accept->ranges[0]->type)->toBe('*')
        ->and($accept->ranges[0]->subtype)->toBe('*');
})->with([null, '', '   ']);

it('parses q values and parameters', function () {
    $accept = AcceptHeader::parse('application/vnd.bridge+json; v=1, application/json;q=0.9, */*;Q=0.1');

    expect($accept->ranges)->toHaveCount(3)
        ->and($accept->ranges[0]->parameter('v'))->toBe('1')
        ->and($accept->ranges[0]->quality)->toBe(1.0)
        ->and($accept->ranges[1]->quality)->toBe(0.9)
        ->and($accept->ranges[2]->quality)->toBe(0.1);
});

it('drops malformed ranges', function () {
    $accept = AcceptHeader::parse('garbage, text/html;q=abc, */json, application/json');

    expect(array_map(fn ($r) => $r->mediaType(), $accept->ranges))->toBe(['application/json']);
});

it('prefers the most specific matching range', function () {
    $accept = AcceptHeader::parse('*/*;q=0.1, text/*;q=0.5, text/html');

    expect($accept->bestMatch('text/html')?->quality)->toBe(1.0)
        ->and($accept->bestMatch('text/plain')?->quality)->toBe(0.5)
        ->and($accept->bestMatch('application/json')?->quality)->toBe(0.1);
});

it('handles quoted parameter values containing commas', function () {
    $accept = AcceptHeader::parse('application/json; profile="a,b", text/html');

    expect($accept->ranges)->toHaveCount(2)
        ->and($accept->ranges[0]->parameter('profile'))->toBe('a,b');
});
