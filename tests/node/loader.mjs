export async function resolve(specifier, context, next) {
  if (specifier === 'server-only') {
    return { url: new URL('./server-only-stub.mjs', import.meta.url).href, shortCircuit: true };
  }
  if ((specifier.startsWith('./') || specifier.startsWith('../')) && !/\.[cm]?[jt]sx?$/.test(specifier)) {
    try {
      return await next(specifier + '.ts', context);
    } catch {
      // fall through
    }
  }
  return next(specifier, context);
}
