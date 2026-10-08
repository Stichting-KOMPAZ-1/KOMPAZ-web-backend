/**
 * Keeps an action that only opens a page from also announcing that it "was executed successfully".
 *
 * "Bewerken" and "Informatie aanvullen" are actions that answer with a `visit` and nothing else:
 * nothing has happened yet, the form they open is where something will. Nova shows its success
 * toast before following any `visit` — `showActionResponseMessage` runs first, and falls back to
 * "The action was executed successfully." when the response carries no message of its own — so the
 * operator was congratulated for clicking a menu item (KOM-53). The server cannot prevent it: the
 * response's shape is Nova's, and it has no key for "say nothing".
 *
 * So the one toast that follows such a response is not shown. Narrowly: only after a request that
 * runs an action, only when its answer is a `visit` with no message, and only the very next success
 * message — which Nova shows synchronously, in the same tick it receives the response. An action
 * that does say something, or that does anything but navigate, is untouched.
 *
 * `Nova.request` is wrapped rather than given an interceptor, because it builds a fresh axios
 * instance on every call and an interceptor would not outlive the request it was added to.
 */
export default function quietWhenAnActionOnlyNavigates() {
  const request = Nova.request.bind(Nova)
  const success = Nova.success.bind(Nova)

  let swallowNextSuccess = false

  Nova.request = (options = null) => {
    const pending = request(options)

    if (!runsAnAction(options)) {
      return pending
    }

    return pending.then(response => {
      swallowNextSuccess = onlyNavigates(response?.data)

      return response
    })
  }

  Nova.success = message => {
    if (swallowNextSuccess) {
      swallowNextSuccess = false

      return
    }

    return success(message)
  }
}

function runsAnAction(options) {
  return (
    options != null &&
    String(options.method).toLowerCase() === 'post' &&
    /\/action$/.test(String(options.url))
  )
}

function onlyNavigates(data) {
  return data != null && typeof data === 'object' && data.visit != null && !data.message && !data.danger
}
