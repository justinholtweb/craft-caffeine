<?php

namespace justinholtweb\caffeine\web\assets\runtime;

use craft\web\AssetBundle;

/**
 * The browser runtime.
 *
 * Published as ES modules, exactly as they are written, with no build step and no bundler in the
 * plugin's dependencies. Every modern browser resolves the imports natively, and the pieces only
 * the `client` transport needs — the engine, the decoder, the URL codec — are imported
 * dynamically, so a site on the default transport downloads `caffeine.js` and nothing else.
 *
 * The absence of a build step is also what keeps the JavaScript half of the conformance suite
 * honest: the files the tests import are the files the browser gets.
 */
class CaffeineAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/src';

        $this->js = [
            ['caffeine.js', 'type' => 'module'],
        ];

        parent::init();
    }
}
