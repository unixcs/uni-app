import { inArray, isEmpty } from '@/utils/util'

export function checkModuleKey (moduleKey) {
    // return inArray(moduleKey, store.getters.modules)
    return true
}

export const checkModules = moduleKeys => {
    if (isEmpty(moduleKeys)) {
        return true
    }
    return moduleKeys.filter(val => checkModuleKey(val)).length > 0
}

export const filterModules = array => {
    return array.filter(item => !item.moduleKey || checkModuleKey(item.moduleKey))
}
