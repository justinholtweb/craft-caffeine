<?php

declare(strict_types=1);
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;

/**
 * Test bootstrap.
 *
 * The suite runs without booting Craft. Everything load-bearing in Caffeine — the tokeniser,
 * the value conversions, the definition models and, from Phase 2, the query engine — is plain
 * PHP over plain data, so it can be exercised in milliseconds with no database.
 *
 * The parts that genuinely need Craft (reading field values off real elements, project-config
 * storage, the element event wiring) are covered by integration runs against the
 * plugin-testing harness instead, because faking an element well enough to be meaningful costs
 * more than it proves.
 */

require_once __DIR__.'/../vendor/autoload.php';

ini_set('date.timezone', 'UTC');
date_default_timezone_set('UTC');

/*
 * The definition models validate through Laravel's validator (Craft 6 components do). The unit
 * suite doesn't boot Craft, so give the container just enough to build one.
 */
$container = new Container;
Container::setInstance($container);
$translator = new Translator(new ArrayLoader, 'en');
$container->instance('translator', $translator);
$container->instance(
    Illuminate\Contracts\Validation\Factory::class,
    new Factory($translator, $container),
);
$container->alias(Illuminate\Contracts\Validation\Factory::class, 'validator');
$container->instance('events', new Dispatcher($container));
