/**
 * Resolve an enum display name without allowing an unexpected backend value
 * to abort a whole Vue render.
 */
export function getEnumName (enumObject, value, fallback = '--') {
  if (!enumObject || !Object.prototype.hasOwnProperty.call(enumObject, value)) {
    return fallback
  }
  const item = enumObject[value]
  return item && typeof item.name === 'string' && item.name ? item.name : fallback
}
