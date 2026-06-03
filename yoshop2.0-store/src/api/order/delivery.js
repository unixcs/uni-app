// Legacy delivery API removed.
// Keep this module as a safe stub so stale imports fail closed instead of
// reviving the old physical-commerce delivery workflow.
export function list () {
  return Promise.reject(new Error('Legacy delivery workflow is disabled'))
}

export function detail () {
  return Promise.reject(new Error('Legacy delivery workflow is disabled'))
}

export function delivery () {
  return Promise.reject(new Error('Legacy delivery workflow is disabled'))
}

export function traces () {
  return Promise.reject(new Error('Legacy delivery workflow is disabled'))
}
