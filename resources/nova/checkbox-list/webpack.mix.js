let mix = require('laravel-mix')
let NovaExtension = require('laravel-nova-devtool')

mix.extend('nova', new NovaExtension())

// Built into dist/ and committed: the deploy runs Composer and nothing else, so what is not in the
// repository does not reach the panel. Rebuild with `npm ci && npm run production` in this folder.
mix
  .setPublicPath('dist')
  .js('src/field.js', 'js')
  .vue({ version: 3 })
  .nova('kompaz/checkbox-list')
