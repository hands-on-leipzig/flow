export function imageUrl(path: string) {
  return `${import.meta.env.VITE_FILES_BASE_URL}${path}`;
}