<?php

declare(strict_types=1);

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

require_once __DIR__ . '/../vendor/autoload.php';

// Yii2 is not autoloadable on its own — Yii.php registers the class map, the DI container and
// the `Yii` alias. Craft normally does this during its bootstrap; the definition models extend
// craft\base\Model, so their validators need it.
require_once __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

ini_set('date.timezone', 'UTC');
date_default_timezone_set('UTC');
