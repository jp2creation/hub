function mountBrandLoaderElement(): HTMLElement {
  const existing = document.getElementById('brand-morph-loader');

  if (existing) {
    return existing;
  }

  const loader = document.createElement('div');

  loader.id = 'brand-morph-loader';
  loader.className = 'brand-morph-loader';
  loader.setAttribute('aria-hidden', 'true');
  loader.innerHTML = `
    <div class="brand-morph-loader__backdrop"></div>
    <div class="brand-morph-loader__stage" role="status" aria-live="polite" aria-label="Chargement">
      <div class="brand-morph-loader__symbol">
        <span class="segment segment--top"></span>
        <span class="segment segment--right"></span>
        <span class="segment segment--bottom"></span>
        <span class="segment segment--left"></span>
      </div>
      <p class="brand-morph-loader__message" data-brand-loader-message>Connexion...</p>
      <p class="brand-morph-loader__error" data-brand-loader-error></p>
    </div>
  `;

  document.body.prepend(loader);

  return loader;
}

export function revealBrandLoaderElement(): void {
  const loader = mountBrandLoaderElement();

  loader.classList.add('is-visible');
  loader.setAttribute('aria-hidden', 'false');
}
