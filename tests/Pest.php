<?php

declare(strict_types=1);

use Bridge\Tests\Fixtures\AppMiddlewareTestCase;
use Bridge\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Conformance');
pest()->extend(AppMiddlewareTestCase::class)->in('AppMiddleware');
