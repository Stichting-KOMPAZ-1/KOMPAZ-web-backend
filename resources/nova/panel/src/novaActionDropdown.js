/*
 * Nova's own ActionDropdown, kept so the replacement can hand back to it.
 *
 * Index.vue resolves `ActionDropdown` by name, and Nova registers it globally, so replacing it is
 * how the one case below gets a different control. Every other case — a row's menu, a detail page,
 * a listing with more than one standalone action — has to go on behaving exactly as before, which
 * means rendering the component that was there first rather than reimplementing it.
 */
let original = null

export function rememberActionDropdown(component) {
  original = component
}

export function originalActionDropdown() {
  return original
}
