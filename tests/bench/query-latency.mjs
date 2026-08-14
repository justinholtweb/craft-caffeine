/**
 * How long a query takes, in the JavaScript engine.
 *
 * Reads the artifact query-latency.php wrote, so both halves are timed against identical data —
 * and against a *decoded* artifact, which is what a browser actually holds.
 *
 *   node tests/bench/query-latency.mjs 10000
 */

import { readFileSync, existsSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { decodeArtifact } from '../../src/web/assets/runtime/src/decode.js'
import { Engine } from '../../src/web/assets/runtime/src/engine.js'

const here = dirname(fileURLToPath(import.meta.url))
const count = Number(process.argv[2] || 10000)
const iterations = Number(process.argv[3] || 20)
const path = join(here, 'build', `bench-${count}.encoded.json`)

if (!existsSync(path)) {
  console.error(`Missing ${path}. Run: php tests/bench/query-latency.php ${count}`)
  process.exit(1)
}

const decodeStart = performance.now()
const artifact = decodeArtifact(JSON.parse(readFileSync(path, 'utf8')), null, 1)
const decodeMs = performance.now() - decodeStart

const engine = new Engine(artifact)

const queries = {
  unrefined: {},
  'one refinement': { refinements: { colour: ['red'] } },
  'two facets and a range': {
    refinements: { colour: ['red'], tags: ['tag-7'] },
    ranges: { price: { min: 20, max: 200 } },
  },
  'text query': { query: 'cordless dri' },
  'text query and a facet': { query: 'steel', refinements: { colour: ['navy'] } },
  'sorted, deep page': { sortBy: 'price_asc', page: 20 },
}

const normalize = (state) => ({
  query: state.query || '',
  refinements: state.refinements || {},
  ranges: state.ranges || {},
  sortBy: state.sortBy || 'relevance',
  page: state.page || 0,
  hitsPerPage: null,
  facets: null,
})

console.log(`JS engine — ${count.toLocaleString()} records, ${iterations} iterations`)
console.log(`Decode: ${decodeMs.toFixed(0)} ms (once, on load)\n`)
console.log(''.padEnd(26) + 'median'.padStart(9) + 'mean'.padStart(9) + 'p95'.padStart(9))

for (const [label, raw] of Object.entries(queries)) {
  const state = normalize(raw)
  const timings = []

  // One untimed pass: the first call through a function is interpreted, not JIT-compiled, and
  // reporting that number would describe a request nobody makes.
  engine.search(state, 24)

  for (let i = 0; i < iterations; i++) {
    const startedAt = performance.now()
    engine.search(state, 24)
    timings.push(performance.now() - startedAt)
  }

  timings.sort((a, b) => a - b)

  const median = timings[Math.floor(timings.length / 2)]
  const mean = timings.reduce((a, b) => a + b, 0) / timings.length
  const p95 = timings[Math.min(timings.length - 1, Math.floor(timings.length * 0.95))]

  console.log(
    label.padEnd(26) +
      median.toFixed(2).padStart(9) +
      mean.toFixed(2).padStart(9) +
      p95.toFixed(2).padStart(9) +
      '  ms',
  )
}
