// Caffeine's index tools screen: the record preview and the query playground.
//
// Loaded on every control-panel page (that's how Craft 6 ships plugin CP scripts), so it does
// nothing unless the tools screen is showing. CP pages change through Inertia without a reload,
// so it binds again after each visit.
;(() => {
  const bound = new WeakSet()

  function xsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
    return match ? decodeURIComponent(match[1]) : ''
  }

  async function post(url, data) {
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': xsrfToken(),
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(data),
    })

    const body = await response.json().catch(() => ({ error: `Request failed (${response.status}).` }))

    if (!response.ok && !body.error) body.error = body.message || `Request failed (${response.status}).`

    return body
  }

  function wire(button, output, url, data, onDone) {
    if (!button || !output) return

    button.addEventListener('click', async () => {
      button.disabled = true
      output.hidden = true

      try {
        const body = await post(url, data())
        output.textContent = body.error ? body.error : JSON.stringify(body, null, 2)
        if (onDone && !body.error) onDone(body)
      } catch (error) {
        output.textContent = error.message || 'Request failed.'
      } finally {
        output.hidden = false
        button.disabled = false
      }
    })
  }

  function bind() {
    const root = document.getElementById('caffeine-tools')
    if (!root || bound.has(root)) return
    bound.add(root)

    const byId = (id) => root.querySelector(`#${id}`)

    wire(byId('caffeine-preview-go'), byId('caffeine-preview-out'), root.dataset.previewUrl, () => ({
      elementId: byId('caffeine-preview-id').value,
    }))

    const timing = byId('caffeine-query-timing')

    wire(byId('caffeine-query-go'), byId('caffeine-query-out'), root.dataset.queryUrl, () => ({
      state: byId('caffeine-query-state').value,
    }), (body) => {
      if (timing) timing.textContent = `v${body.version} · ${body.ms} ms · ${body.result.nbHits} hits`
    })
  }

  // Scripts run in <head>, before the CP app renders the screen, and an Inertia visit swaps the
  // screen in a moment after its navigate event. Look for a few seconds each time.
  function watch() {
    let tries = 0
    const timer = setInterval(() => {
      bind()
      if (++tries >= 20) clearInterval(timer)
    }, 200)
  }

  watch()
  document.addEventListener('inertia:navigate', watch)
})()
