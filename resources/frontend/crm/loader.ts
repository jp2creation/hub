import { readCrmShellConfig } from './config';

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
      <video
        class="brand-morph-loader__video"
        src="${escapeHtml(readCrmShellConfig().assets.brandMorphLoaderVideo)}"
        autoplay
        muted
        loop
        playsinline
        preload="auto"
        data-brand-loader-video
      ></video>
      <p class="brand-morph-loader__message" data-brand-loader-message>Connexion...</p>
      <p class="brand-morph-loader__error" data-brand-loader-error></p>
    </div>
  `;

  document.body.prepend(loader);

  return loader;
}

function escapeHtml(value: unknown): string {
  return String(value ?? '').replace(/[&<>"']/g, (char) => {
    const entities: Record<string, string> = {
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;',
    };

    return entities[char] || char;
  });
}

export function revealBrandLoaderElement(): void {
  const loader = mountBrandLoaderElement();

  loader.classList.add('is-visible');
  loader.setAttribute('aria-hidden', 'false');
}
