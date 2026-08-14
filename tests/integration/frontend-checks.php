<?php
// Exercises the Phase 4 front end against the plugin-testing harness.
//
// The conformance suite pins the URL codec across both languages with fixtures; this pins the
// parts fixtures cannot reach — the Twig tags, the widget templates, region capture, and the
// signed token the fragment endpoint trusts.
//
//   ddev exec bash -c "cd /var/www/html && php /var/www/craft-caffeine/tests/integration/frontend-checks.php"

require '/var/www/html/vendor/autoload.php';
define('CRAFT_BASE_PATH', '/var/www/html');
$app = require '/var/www/html/vendor/craftcms/cms/bootstrap/console.php';

use craft\web\View;
use justinholtweb\caffeine\Plugin;
use justinholtweb\caffeine\search\QueryState;
use justinholtweb\caffeine\search\UrlState;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;

    echo $ok ? '  ok   ' : '  FAIL ', $label, $detail !== '' ? "  ({$detail})" : '', PHP_EOL;

    if (!$ok) {
        $failures++;
    }
}

$plugin = Plugin::getInstance();
$view = Craft::$app->getView();

$templates = '/var/www/html/templates';
$name = '_caffeine_check';
$path = "{$templates}/{$name}.twig";

file_put_contents($path, <<<'TWIG'
{% caffeine 'products' with { path: '/listing' } as search %}
  {{ search.searchBox() }}
  {{ search.refinementList('choice') }}
  {{ search.currentRefinements() }}
  {% caffeineresults %}
    {{ search.stats() }}
    {% for hit in search.hits %}<li>{{ hit.objectID }}</li>{% endfor %}
  {% endcaffeineresults %}
{% endcaffeine %}
TWIG);

try {
    echo "Rendering\n";

    $html = $view->renderTemplate($name, [], View::TEMPLATE_MODE_SITE);

    check('emits a wrapper carrying the runtime config', str_contains($html, 'data-caffeine="{'));
    check('emits the results region', str_contains($html, 'data-caffeine-results'));
    check('emits a region per widget', substr_count($html, 'data-caffeine-region=') >= 4,
        substr_count($html, 'data-caffeine-region=') . ' regions');
    check('renders hits', str_contains($html, '<li>26-1</li>'));
    check('facet links are real hrefs', (bool)preg_match('/<a href="\/listing\?choice=red"/', $html));
    check('facet links are nofollow', str_contains($html, 'rel="nofollow"'));

    $context = $plugin->search->registered('products');

    check('the context is registered for the fragment endpoint', $context !== null);
    check('the results block was captured', $context?->getRenderedResults() !== null);
    check('every state-dependent region was captured', count($context?->getRegions() ?? []) >= 4);

    echo "\nToken\n";

    $token = $context->token();
    $payload = json_decode((string)Craft::$app->getSecurity()->validateData($token), true);

    check('the token validates', is_array($payload));
    check('and names the template it came from', ($payload['template'] ?? null) === $name, $payload['template'] ?? 'null');
    // The tag was given an explicit path, which is what a fragment re-render has to build its
    // URLs from — a console render has no request path to fall back on.
    check('and the page path, not the endpoint', ($payload['path'] ?? null) === '/listing', $payload['path'] ?? 'null');
    check('a tampered token is rejected', Craft::$app->getSecurity()->validateData('0' . substr($token, 1)) === false);

    echo "\nURL round trip against the real artifact\n";

    $artifact = $plugin->search->artifact($plugin->indexes->getByHandle('products'));

    $state = new QueryState(
        query: 'test',
        refinements: ['choice' => ['red'], 'featured' => [false]],
        sortBy: 'title_asc',
        page: 2,
    );

    $url = UrlState::url('/listing', $state, ['defaultSort' => 'relevance']);
    parse_str((string)parse_url($url, PHP_URL_QUERY), $params);
    $parsed = UrlState::parse($params, $artifact->facets, ['defaultSort' => 'relevance']);

    check('the URL is readable', $url === '/listing?q=test&choice=red&featured=false&sort=title_asc&page=3', $url);
    check('the query survives', $parsed->query === 'test');
    check('a string refinement survives', ($parsed->refinements['choice'] ?? null) === ['red']);
    check('a boolean refinement keeps its type', ($parsed->refinements['featured'][0] ?? null) === false,
        var_export($parsed->refinements['featured'][0] ?? null, true));
    check('the sorting survives', $parsed->sortBy === 'title_asc');
    check('the page survives', $parsed->page === 2);

    echo "\nWidget overrides\n";

    $override = "{$templates}/_caffeine/stats.twig";
    @mkdir(dirname($override), 0777, true);
    file_put_contents($override, 'OVERRIDDEN');

    $plugin->search->overridePath(null);
    $overridden = $view->renderTemplate($name, [], View::TEMPLATE_MODE_SITE);

    check("a site's own _caffeine/stats.twig wins", str_contains($overridden, 'OVERRIDDEN'));

    @unlink($override);
    @rmdir(dirname($override));
} finally {
    @unlink($path);
}

echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) failed.\n";

exit($failures === 0 ? 0 : 1);
