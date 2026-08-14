/**
 * Caffeine's browser runtime.
 *
 * It does not render a search interface. The server already did that, as real links and real
 * forms that work with this file absent — this only intercepts them, so the page updates without
 * a reload. That ordering is the whole design: every behaviour here is an optimisation of
 * something that already works, which is why it fits in a few kilobytes and why switching
 * JavaScript off degrades to page loads rather than to nothing.
 *
 * Ships as ES modules with no build step. The engine, decoder and URL codec are imported
 * dynamically and only for the `client` transport, so a site using the default `htmx` transport
 * loads this file alone.
 */

const INSTANCES = new WeakMap()

/** How long to wait after the last keystroke before searching. */
const TYPING_DELAY = 250

class Caffeine {
  constructor(root) {
    this.root = root
    this.config = JSON.parse(root.getAttribute('data-caffeine') || '{}')
    this.basePath = new URL(this.config.path, location.href).pathname
    this.busy = false
    this.sequence = 0
    this.typingTimer = null

    // Populated only for the `client` transport.
    this.artifact = null
    this.engine = null
    this.urlstate = null
  }

  async init() {
    this.root.addEventListener('click', (event) => this.onClick(event))
    this.root.addEventListener('input', (event) => this.onInput(event))
    this.root.addEventListener('change', (event) => this.onChange(event))
    this.root.addEventListener('submit', (event) => this.onSubmit(event))

    window.addEventListener('popstate', () => this.render(location.href, { push: false }))

    // The submit buttons exist so the forms work without this file. They are redundant now.
    for (const button of this.root.querySelectorAll('[data-caffeine-submit]')) {
      button.hidden = true
    }

    if (this.config.transport === 'client') {
      await this.loadArtifact()
    }

    await this.hydrate()

    this.root.dispatchEvent(new CustomEvent('caffeine:ready', { bubbles: true, detail: { caffeine: this } }))
  }

  /**
   * Brings a cached page into line with the URL it was requested at.
   *
   * A full-page cache stores the canonical, unrefined render — that is the point of it — so a
   * visitor arriving at `?brand=Acme` from a bookmark or a shared link gets HTML that knows
   * nothing about Acme. Rather than accept a flash of the wrong results, the runtime notices
   * that the URL and the rendered state disagree and refines immediately.
   */
  async hydrate() {
    const rendered = this.stateParams(new URL(this.config.path + this.renderedQuery(), location.href))
    const actual = this.stateParams(new URL(location.href))

    if (rendered.toString() === actual.toString()) return

    this.root.setAttribute('data-caffeine-hydrating', '')
    await this.render(location.href, { push: false })
    this.root.removeAttribute('data-caffeine-hydrating')
  }

  /** The query string the server actually rendered, rebuilt from the config's state. */
  renderedQuery() {
    const params = new URLSearchParams()
    const state = this.config.state || {}
    const prefix = this.config.prefix || ''

    if (state.query) params.set(prefix + 'q', state.query)

    for (const [key, values] of Object.entries(state.refinements || {})) {
      if (values.length) params.set(prefix + key, values.map(String).join(','))
    }

    for (const [key, range] of Object.entries(state.ranges || {})) {
      params.set(prefix + key, `${range.min ?? ''}..${range.max ?? ''}`)
    }

    if (state.sortBy && state.sortBy !== this.config.defaultSort) params.set(prefix + 'sort', state.sortBy)
    if (state.page) params.set(prefix + 'page', String(state.page + 1))

    const query = params.toString()

    return query === '' ? '' : '?' + query
  }

  // ---------------------------------------------------------------------------------------------
  // Events
  // ---------------------------------------------------------------------------------------------

  onClick(event) {
    if (event.defaultPrevented || event.button !== 0) return
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return

    const link = event.target.closest('a[href]')

    if (!link || !this.root.contains(link)) return
    if (link.target && link.target !== '_self') return
    if (link.hasAttribute('download') || link.dataset.caffeineIgnore !== undefined) return

    const url = new URL(link.href, location.href)

    // Only links pointing back at this listing. A hit's link to the product itself has a
    // different path and must be left alone — which is why no `data-` attribute is needed on
    // facet links, and why the plain `<a href="{{ search.toggleUrl(…) }}">` in the docs works.
    if (url.origin !== location.origin || url.pathname !== this.basePath) return

    event.preventDefault()
    this.render(url.href)
  }

  onInput(event) {
    const field = event.target.closest('[data-caffeine-query]')

    if (!field || !this.root.contains(field)) return

    clearTimeout(this.typingTimer)

    this.typingTimer = setTimeout(() => {
      const url = this.withParams((params) => {
        const name = (this.config.prefix || '') + 'q'

        if (field.value === '') {
          params.delete(name)
        } else {
          params.set(name, field.value)
        }
      })

      // Replaced, not pushed: pushing every keystroke would make the back button walk letter by
      // letter out of a word the visitor typed in one go.
      this.render(url, { push: false, replace: true })
    }, TYPING_DELAY)
  }

  onChange(event) {
    const sort = event.target.closest('[data-caffeine-sort]')

    if (sort && this.root.contains(sort)) {
      const url = this.withParams((params) => {
        const name = (this.config.prefix || '') + 'sort'

        if (sort.value === this.config.defaultSort) {
          params.delete(name)
        } else {
          params.set(name, sort.value)
        }
      })

      this.render(url)
      return
    }

    const bound = event.target.closest('[data-caffeine-bound]')

    if (bound && this.root.contains(bound)) {
      this.submitForm(bound.closest('form'))
    }
  }

  onSubmit(event) {
    const form = event.target.closest('[data-caffeine-form]')

    if (!form || !this.root.contains(form)) return

    event.preventDefault()
    this.submitForm(form)
  }

  submitForm(form) {
    if (!form) return

    const params = new URLSearchParams()

    for (const [name, value] of new FormData(form).entries()) {
      if (value !== '') params.append(name, value)
    }

    // A control was touched, so the visitor is looking at a different result set. Staying on
    // page 7 of it would usually show them nothing.
    params.delete((this.config.prefix || '') + 'page')

    const query = params.toString()

    this.render(this.basePath + (query === '' ? '' : '?' + query))
  }

  /** Current URL with its parameters edited in place. */
  withParams(mutate) {
    const url = new URL(location.href)

    mutate(url.searchParams)
    url.searchParams.delete((this.config.prefix || '') + 'page')

    return url.pathname + (url.searchParams.toString() === '' ? '' : '?' + url.searchParams.toString())
  }

  /** The query parameters that belong to this instance's state, sorted so two can be compared. */
  stateParams(url) {
    const prefix = this.config.prefix || ''
    const params = new URLSearchParams()
    const names = [...url.searchParams.keys()].filter((name) => prefix === '' || name.startsWith(prefix)).sort()

    for (const name of names) {
      if (name === 'caffeineToken') continue
      params.append(name, url.searchParams.get(name))
    }

    return params
  }

  // ---------------------------------------------------------------------------------------------
  // Rendering
  // ---------------------------------------------------------------------------------------------

  async render(href, { push = true, replace = false } = {}) {
    const url = new URL(href, location.href)

    // Every request carries a sequence number and only the newest one is allowed to paint.
    // Without it, a slow request for "co" can land after a fast one for "coffee" and leave the
    // visitor looking at results for something they finished typing two seconds ago.
    const ticket = ++this.sequence

    this.root.setAttribute('data-caffeine-busy', '')
    this.busy = true

    try {
      const applied = this.artifact
        ? await this.renderLocally(url)
        : await this.renderFromServer(url)

      if (ticket !== this.sequence) return

      if (applied !== false) {
        if (replace) history.replaceState(null, '', url.href)
        else if (push) history.pushState(null, '', url.href)

        this.root.dispatchEvent(new CustomEvent('caffeine:render', { bubbles: true, detail: { url: url.href } }))
      }
    } catch (error) {
      // A failed refinement must not leave the page stuck. Fall back to what the link would have
      // done on its own.
      console.error('[caffeine]', error)
      location.href = url.href
    } finally {
      if (ticket === this.sequence) {
        this.busy = false
        this.root.removeAttribute('data-caffeine-busy')
      }
    }
  }

  /** The `htmx` transport: ask the server to re-render, and swap what it sends back. */
  async renderFromServer(url) {
    const endpoint = new URL(this.config.fragment, location.href)

    for (const [name, value] of this.stateParams(url).entries()) {
      endpoint.searchParams.append(name, value)
    }

    endpoint.searchParams.set(this.config.tokenParam || 'caffeineToken', this.config.token)

    const response = await fetch(endpoint.href, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })

    if (!response.ok) throw new Error(`Fragment request failed: ${response.status}`)

    const payload = await response.json()

    this.swapRegions(payload.regions || {})

    return true
  }

  /**
   * Replaces every state-dependent region the server sent.
   *
   * Regions rather than just the results, because refining changes the facet counts beside them
   * too — leaving "Acme (12)" next to three results is the exact thing that makes hand-rolled
   * filtering feel broken.
   */
  swapRegions(regions) {
    const focused = document.activeElement
    const focusedRegion = focused && focused.closest ? focused.closest('[data-caffeine-region]') : null
    const focusedSelection = focused && focused.selectionStart !== undefined
      ? [focused.selectionStart, focused.selectionEnd]
      : null

    for (const [id, html] of Object.entries(regions)) {
      const target = this.root.querySelector(`[data-caffeine-region="${cssEscape(id)}"]`)

      if (!target) continue

      // The region holding the cursor is skipped. Replacing it would drop focus mid-word and
      // reset the caret, which is unusable when the field is a search box being typed into.
      if (focusedRegion && target === focusedRegion) continue

      target.innerHTML = html
    }

    for (const button of this.root.querySelectorAll('[data-caffeine-submit]')) {
      button.hidden = true
    }

    if (focused && focusedSelection && document.contains(focused)) {
      try {
        focused.setSelectionRange(focusedSelection[0], focusedSelection[1])
      } catch {
        // Not every input type supports a selection range. Nothing here depends on it.
      }
    }
  }

  // ---------------------------------------------------------------------------------------------
  // The `client` transport
  // ---------------------------------------------------------------------------------------------

  /**
   * Fetches the artifact through the stable pointer and hands it to the local engine.
   *
   * Two requests, both cacheable: the pointer is small and revalidated, the payload it names is
   * immutable and cached forever.
   */
  async loadArtifact() {
    const [{ decodeArtifact }, { Engine }, urlstate] = await Promise.all([
      import('./decode.js'),
      import('./engine.js'),
      import('./url.js'),
    ])

    this.urlstate = urlstate

    const manifest = await (await fetch(this.config.pointer, { credentials: 'omit' })).json()
    const base = new URL(this.config.pointer, location.href)

    const documents = await Promise.all(
      ['index', 'payload']
        .filter((kind) => manifest.shards[kind])
        .map((kind) => fetch(new URL(manifest.shards[kind].file, base).href).then((r) => r.json())),
    )

    this.artifact = decodeArtifact(documents[0], documents[1] || null, manifest.version)
    this.engine = new Engine(this.artifact)
  }

  /** Answers from the artifact already in memory. No request at all. */
  async renderLocally(url) {
    const params = {}

    for (const [name, value] of url.searchParams.entries()) params[name] = value

    const state = this.urlstate.parse(params, this.artifact.facets, {
      prefix: this.config.prefix || '',
      defaultSort: this.config.defaultSort,
    })

    const result = this.engine.search(state, this.config.hitsPerPage)

    this.renderHits(result)
    this.patchFacets(result, state)

    return true
  }

  /**
   * Renders hits from a `<template data-caffeine-hit>` on the page.
   *
   * The client transport is the one place hit markup cannot come from Twig, because no request
   * is made. A template element keeps it in the page rather than in a JavaScript string, and
   * `{{ field }}` is interpolated with escaping.
   */
  renderHits(result) {
    const target = this.root.querySelector('[data-caffeine-results]')
    const template = this.root.querySelector('template[data-caffeine-hit]')

    if (!target || !template) return

    const source = template.innerHTML

    target.innerHTML = result.hits
      .map((hit) => source.replace(/\{\{\s*([\w.]+)\s*\}\}/g, (_, path) => escapeHtml(dig(hit, path))))
      .join('')

    for (const node of this.root.querySelectorAll('[data-caffeine-stats]')) {
      node.textContent = result.nbHits === 0 ? 'No results' : `${result.nbHits} results`
    }
  }

  /**
   * Updates counts and refinement state on the server-rendered facet controls.
   *
   * Sound rather than merely convenient: a cached page is rendered unrefined, and an unrefined
   * result contains every value the facet has. Refining can only ever reduce a count, never
   * introduce a value that was not already on the page — so patching in place is complete.
   * The exception is a facet truncated by `maxValuesPerFacet`, which is documented.
   */
  patchFacets(result, state) {
    for (const [key, facet] of Object.entries(result.caffeineFacets || {})) {
      const counts = new Map(facet.buckets.map((bucket) => [String(bucket.value), bucket]))
      const container = this.root.querySelector(`[data-caffeine-facet="${cssEscape(key)}"]`)

      if (!container) continue

      for (const link of container.querySelectorAll('[data-caffeine-value]')) {
        const bucket = counts.get(link.dataset.caffeineValue)
        const item = link.closest('li') || link
        const count = link.querySelector('[class$="__count"]')

        if (!bucket) {
          item.hidden = true
          continue
        }

        item.hidden = false
        if (count) count.textContent = String(bucket.count)

        item.classList.toggle('is-refined', bucket.isRefined)
        link.setAttribute('aria-pressed', bucket.isRefined ? 'true' : 'false')
        link.href = this.toggleHref(state, key, bucket.value)
      }
    }
  }

  toggleHref(state, key, value) {
    const next = JSON.parse(JSON.stringify(state))
    const values = next.refinements[key] || []
    const at = values.findIndex((existing) => existing === value)

    if (at === -1) values.push(value)
    else values.splice(at, 1)

    next.refinements[key] = values
    next.page = 0

    return this.urlstate.url(this.basePath, next, {
      prefix: this.config.prefix || '',
      defaultSort: this.config.defaultSort,
    })
  }
}

// -------------------------------------------------------------------------------------------------

function dig(object, path) {
  return path.split('.').reduce((value, key) => (value == null ? undefined : value[key]), object)
}

function escapeHtml(value) {
  if (value == null) return ''

  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
}

/** `CSS.escape` where it exists; a conservative fallback where it does not. */
function cssEscape(value) {
  return typeof CSS !== 'undefined' && CSS.escape ? CSS.escape(value) : String(value).replace(/["\\]/g, '\\$&')
}

export function start(scope = document) {
  const started = []

  for (const root of scope.querySelectorAll('[data-caffeine]')) {
    if (INSTANCES.has(root)) continue

    const instance = new Caffeine(root)

    INSTANCES.set(root, instance)
    started.push(instance.init())
  }

  return Promise.all(started)
}

export function instance(root) {
  return INSTANCES.get(root) || null
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => start())
} else {
  start()
}

export { Caffeine }
