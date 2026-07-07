const fs = require('fs')
const path = require('path')

const targetFile = path.resolve(__dirname, '../node_modules/vuex/dist/vuex.esm-bundler.js')
const marker = '/* codex-vuex-effectscope-compat */'

function main() {
  if (!fs.existsSync(targetFile)) {
    console.warn(`[patch-vuex-effectscope-compat] target not found: ${targetFile}`)
    return
  }

  const source = fs.readFileSync(targetFile, 'utf8')
  if (source.includes(marker)) {
    console.log('[patch-vuex-effectscope-compat] already patched')
    return
  }

  const importNeedle = "import { inject, effectScope, reactive, watch, computed } from 'vue';"
  const scopeNeedle = "  var scope = effectScope(true);\n\n  scope.run(function () {\n"
  const oldScopeStopNeedle = "  if (oldScope) {\n    oldScope.stop();\n  }\n"

  if (!source.includes(importNeedle) || !source.includes(scopeNeedle) || !source.includes(oldScopeStopNeedle)) {
    throw new Error('[patch-vuex-effectscope-compat] unexpected vuex bundle shape')
  }

  let patched = source.replace(
    importNeedle,
    `${marker}\nimport * as Vue from 'vue';\nconst { inject, reactive, watch, computed } = Vue;\nconst effectScope = typeof Vue.effectScope === 'function' ? Vue.effectScope : null;`
  )

  patched = patched.replace(
    scopeNeedle,
    "  var scope = effectScope ? effectScope(true) : null;\n\n  var runWrappedGetters = function () {\n"
  )

  const getterBodyNeedle = "  });\n\n  store._state = reactive({\n"
  if (!patched.includes(getterBodyNeedle)) {
    throw new Error('[patch-vuex-effectscope-compat] failed to locate wrapped getter block')
  }
  patched = patched.replace(
    getterBodyNeedle,
    "  };\n\n  if (scope && typeof scope.run === 'function') {\n    scope.run(runWrappedGetters);\n  } else {\n    runWrappedGetters();\n  }\n\n  store._state = reactive({\n"
  )

  patched = patched.replace(
    oldScopeStopNeedle,
    "  if (oldScope && typeof oldScope.stop === 'function') {\n    oldScope.stop();\n  }\n"
  )

  fs.writeFileSync(targetFile, patched, 'utf8')
  console.log('[patch-vuex-effectscope-compat] patched vuex effectScope compatibility')
}

main()
