/**
 * The JavaScript half of the conformance suite.
 *
 * Runs the same fixtures as tests/Conformance/ConformanceTest.php against engine.js, using the
 * artifacts that the PHP run compiled into build/. That mirrors production exactly —
 * compilation only ever happens in PHP — so this tests the JS engine against a real artifact
 * rather than a hand-written approximation.
 *
 * Run the PHP suite first; this exits with a clear message if the artifacts are missing.
 *
 *   ./vendor/bin/pest --group=conformance && node tests/Conformance/run.mjs
 */

import { readFileSync, existsSync, readdirSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { Engine } from '../../src/web/assets/runtime/src/engine.js'
import { tokenize } from '../../src/web/assets/runtime/src/tokenizer.js'
import * as varint from '../../src/web/assets/runtime/src/varint.js'
import { decodeArtifact } from '../../src/web/assets/runtime/src/decode.js'
import * as urlstate from '../../src/web/assets/runtime/src/url.js'
import { toKey, fromKey } from '../../src/web/assets/runtime/src/facet-value.js'

const here = dirname(fileURLToPath(import.meta.url))
const buildDir = join(here, 'build')

let passed = 0
const failures = []

const fixtures = readdirSync(here).filter((f) => f.endsWith('.json'))

if (fixtures.length === 0) {
  console.error('No conformance fixtures found.')
  process.exit(1)
}

for (const file of fixtures) {
  const fixture = JSON.parse(readFileSync(join(here, file), 'utf8'))

  if (fixture.type === 'facetvalue') {
    for (const testCase of fixture.cases) {
      const label = `${fixture.name} → ${JSON.stringify(testCase.value)}`
      const problems = []

      if (toKey(testCase.value) !== testCase.expect) {
        problems.push(`toKey: expected ${JSON.stringify(testCase.expect)}, got ${JSON.stringify(toKey(testCase.value))}`)
      }

      if (!same(fromKey(testCase.expect, [testCase.value]), testCase.value)) {
        problems.push(`fromKey: did not round-trip ${JSON.stringify(testCase.value)}`)
      }

      report(label, problems)
    }

    continue
  }

  if (fixture.type === 'urlstate') {
    for (const testCase of fixture.cases) {
      const label = `${fixture.name} → ${testCase.name}`
      const options = testCase.options || {}
      const problems = []

      // Parse-only cases cover input shapes nothing ever encodes — the `_min`/`_max` fields a
      // plain form posts, and the junk a real query string arrives with.
      if (testCase.parseParams) {
        const only = urlstate.parse(testCase.parseParams, fixture.facets, options)

        if (!same(normalizeParsed(only), testCase.expectParsed)) {
          problems.push(
            `parsed: expected ${JSON.stringify(testCase.expectParsed)}, got ${JSON.stringify(normalizeParsed(only))}`,
          )
        }

        report(label, problems)
        continue
      }

      const params = urlstate.encode(testCase.state, options)

      if (!same(params, testCase.expectParams)) {
        problems.push(`params: expected ${JSON.stringify(testCase.expectParams)}, got ${JSON.stringify(params)}`)
      }

      const href = urlstate.url(fixture.path, testCase.state, options)

      if (href !== testCase.expectUrl) {
        problems.push(`url: expected ${JSON.stringify(testCase.expectUrl)}, got ${JSON.stringify(href)}`)
      }

      const normalized = normalizeParsed(urlstate.parse(testCase.expectParams, fixture.facets, options))

      if (!same(normalized, testCase.expectParsed)) {
        problems.push(`parsed: expected ${JSON.stringify(testCase.expectParsed)}, got ${JSON.stringify(normalized)}`)
      }

      report(label, problems)
    }

    continue
  }

  if (fixture.type === 'varint') {
    for (const testCase of fixture.cases) {
      const encoder = testCase.codec === 'delta' ? varint.encodeDelta : varint.encode
      const decoder = testCase.codec === 'delta' ? varint.decodeDelta : varint.decode
      const label = `${fixture.name} ${testCase.codec} → ${JSON.stringify(testCase.values)}`

      const actual = encoder(testCase.values)
      // Both halves are checked: the encoding pins this implementation against the PHP one,
      // and the round trip catches a decoder that is wrong in the same direction as its encoder.
      const problems = []

      if (actual !== testCase.expect) {
        problems.push(`encoded: expected ${JSON.stringify(testCase.expect)}, got ${JSON.stringify(actual)}`)
      }

      if (!same(decoder(testCase.expect), testCase.values)) {
        problems.push(`decoded: expected ${JSON.stringify(testCase.values)}, got ${JSON.stringify(decoder(testCase.expect))}`)
      }

      if (problems.length === 0) {
        passed++
        console.log(`  \x1b[32m✓\x1b[0m ${label}`)
      } else {
        failures.push(`${label}\n    ${problems.join('\n    ')}`)
        console.log(`  \x1b[31m✗\x1b[0m ${label}`)
      }
    }

    continue
  }

  if ((fixture.type || 'search') === 'tokens') {
    for (const testCase of fixture.cases) {
      const actual = tokenize(testCase.input)

      if (same(actual, testCase.expect)) {
        passed++
        console.log(`  [32m✓[0m ${fixture.name} → ${JSON.stringify(testCase.input)}`)
      } else {
        failures.push(
          `${fixture.name} → ${JSON.stringify(testCase.input)}\n` +
            `    expected ${JSON.stringify(testCase.expect)}, got ${JSON.stringify(actual)}`,
        )
        console.log(`  [31m✗[0m ${fixture.name} → ${JSON.stringify(testCase.input)}`)
      }
    }

    continue
  }

  const artifactPath = join(buildDir, `${fixture.name}.artifact.json`)
  const encodedPath = join(buildDir, `${fixture.name}.encoded.json`)

  for (const path of [artifactPath, encodedPath]) {
    if (existsSync(path)) continue

    console.error(
      `Missing ${path}.\n` +
        'Artifacts are compiled by the PHP suite. Run `./vendor/bin/pest --group=conformance` first.',
    )
    process.exit(1)
  }

  // Two engines over the same fixture: one on the compiled structure the PHP suite wrote out,
  // one on that structure decoded from the wire format the browser actually receives. The first
  // pins this engine against its PHP twin; the second pins decode.js against ArtifactDecoder.
  const sources = [
    ['compiled', JSON.parse(readFileSync(artifactPath, 'utf8'))],
    // Version 1 matches the compiler's default; the shard documents omit it deliberately and
    // production reads it from the manifest instead.
    ['decoded', decodeArtifact(JSON.parse(readFileSync(encodedPath, 'utf8')), null, 1)],
  ]

  for (const [form, artifact] of sources) {
    const engine = new Engine(artifact)
    const suffix = form === 'compiled' ? '' : ' — decoded'

    for (const testCase of fixture.cases) {
      const state = normalizeState(testCase.state)
      const label = `${fixture.name} → ${testCase.name}${suffix}`

      let result
      try {
        result = engine.search(state, fixture.index.hitsPerPage || 24)
      } catch (error) {
        failures.push(`${label}\n    threw: ${error.stack}`)
        console.log(`  [31m✗[0m ${label}`)
        continue
      }

      const problems = check(result, testCase.expect)

      if (problems.length === 0) {
        passed++
        console.log(`  [32m✓[0m ${label}`)
      } else {
        failures.push(`${label}\n    ${problems.join('\n    ')}`)
        console.log(`  [31m✗[0m ${label}`)
      }
    }
  }
}

console.log('')

if (failures.length > 0) {
  console.error(`[31m${failures.length} failed[0m, ${passed} passed\n`)
  for (const failure of failures) console.error(`  ${failure}\n`)
  process.exit(1)
}

console.log(`[32m${passed} passed[0m`)

/**
 * The PHP side parses state through QueryState::fromArray, which fills in the defaults. The JS
 * engine reads a plain object, so the fixture's terse state needs the same defaults applied or
 * the two runners would be testing different queries.
 */
function normalizeState(state) {
  return {
    query: state.query || '',
    refinements: state.refinements || {},
    ranges: state.ranges || {},
    around: state.around || {},
    sortBy: state.sortBy || 'relevance',
    page: state.page || 0,
    hitsPerPage: state.hitsPerPage || null,
    facets: state.facets || null,
  }
}

function check(result, expect) {
  const problems = []

  for (const key of ['nbHits', 'page', 'nbPages']) {
    if (key in expect && result[key] !== expect[key]) {
      problems.push(`${key}: expected ${expect[key]}, got ${result[key]}`)
    }
  }

  if ('hits' in expect) {
    const actual = result.hits.map((hit) => hit.objectID)
    if (!same(actual, expect.hits)) {
      problems.push(`hits: expected ${JSON.stringify(expect.hits)}, got ${JSON.stringify(actual)}`)
    }
  }

  for (const [key, pairs] of Object.entries(expect.facets || {})) {
    const buckets = (result.caffeineFacets[key] || {}).buckets || []
    const actual = buckets.map((b) => [b.value, b.count])

    if (!same(actual, pairs)) {
      problems.push(
        `facet ${key}: expected ${JSON.stringify(pairs)}, got ${JSON.stringify(actual)}`,
      )
    }
  }

  for (const [key, stats] of Object.entries(expect.facetsStats || {})) {
    for (const [stat, value] of Object.entries(stats)) {
      const actual = (result.facets_stats[key] || {})[stat]
      if (actual !== value) {
        problems.push(`facets_stats.${key}.${stat}: expected ${value}, got ${actual}`)
      }
    }
  }

  return problems
}

/** The comparable subset of a parsed state — `facets` is a query option, not URL state. */
function normalizeParsed(state) {
  return {
    query: state.query,
    refinements: state.refinements,
    ranges: state.ranges,
    sortBy: state.sortBy,
    page: state.page,
    hitsPerPage: state.hitsPerPage,
  }
}

/** Records one check's outcome, so every fixture type reports the same way. */
function report(label, problems) {
  if (problems.length === 0) {
    passed++
    console.log(`  [32m✓[0m ${label}`)
    return
  }

  failures.push(`${label}\n    ${problems.join('\n    ')}`)
  console.log(`  [31m✗[0m ${label}`)
}

/** Deep equality over the plain JSON these comparisons produce. */
function same(a, b) {
  return JSON.stringify(a) === JSON.stringify(b)
}
