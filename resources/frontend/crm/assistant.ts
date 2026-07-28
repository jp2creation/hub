import { crmApi } from './api/client';

type HubAssistantSuggestion = {
  external?: boolean;
  label: string;
  url: string;
};

type HubAssistantResponse = {
  label?: string | null;
  message?: string;
  ok?: boolean;
  suggestions?: HubAssistantSuggestion[];
  url?: string | null;
};

type AssistantMessage = {
  label?: string | null;
  role: 'assistant' | 'user';
  suggestions?: HubAssistantSuggestion[];
  text: string;
  url?: string | null;
};

const initialMessages: AssistantMessage[] = [
  {
    role: 'assistant',
    text: 'Bonjour. Je peux vous aider à trouver une page, répondre à une question courante ou vous guider dans le HUB.',
  },
];

let isOpen = false;
let isLoading = false;
let messages = [...initialMessages];
let hostElement: HTMLDivElement | null = null;

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

function icon(name: 'back' | 'bot' | 'close' | 'send' | 'spark' | 'user'): string {
  const paths: Record<typeof name, string> = {
    back: '<path d="m15 18-6-6 6-6"></path>',
    bot:
      '<path d="M12 8V4"></path><rect x="5" y="8" width="14" height="11" rx="3"></rect><path d="M8.5 12h.01M15.5 12h.01M9 16h6"></path>',
    close: '<path d="M18 6 6 18M6 6l12 12"></path>',
    send: '<path d="m22 2-7 20-4-9-9-4Z"></path><path d="M22 2 11 13"></path>',
    spark: '<path d="m12 3 1.9 5.2L19 10l-5.1 1.8L12 17l-1.9-5.2L5 10l5.1-1.8Z"></path>',
    user: '<path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"></path><path d="M4 21a8 8 0 0 1 16 0"></path>',
  };

  return `<svg class="hub-assistant-icon" viewBox="0 0 24 24" aria-hidden="true">${paths[name]}</svg>`;
}

function isExternalUrl(url: string): boolean {
  try {
    return new URL(url, window.location.href).origin !== window.location.origin;
  } catch {
    return false;
  }
}

function resultLink(url: string | null | undefined, label: string | null | undefined): string {
  if (!url || !label) {
    return '';
  }

  const external = isExternalUrl(url);

  return [
    `<a class="hub-assistant-result" href="${escapeHtml(url)}" data-hub-assistant-link${external ? ' target="_blank" rel="noopener noreferrer"' : ''}>`,
    `<span>${escapeHtml(label)}</span>`,
    '<span aria-hidden="true">→</span>',
    '</a>',
  ].join('');
}

function suggestionLinks(suggestions: HubAssistantSuggestion[] = []): string {
  if (suggestions.length === 0) {
    return '';
  }

  return [
    '<div class="hub-assistant-suggestions">',
    ...suggestions.map((suggestion) => {
      const external = suggestion.external || isExternalUrl(suggestion.url);

      return [
        `<a href="${escapeHtml(suggestion.url)}" data-hub-assistant-link${external ? ' target="_blank" rel="noopener noreferrer"' : ''}>`,
        escapeHtml(suggestion.label),
        '</a>',
      ].join('');
    }),
    '</div>',
  ].join('');
}

function memberProfile(): { name: string; photoUrl: string } {
  const photo = document.querySelector<HTMLImageElement>('[data-crm-native-profile-photo]');
  const name = document.querySelector<HTMLElement>('[data-crm-native-profile-name]')?.textContent?.trim() || photo?.alt?.trim() || 'Membre';

  return {
    name,
    photoUrl: photo?.dataset.crmImageSrc || photo?.getAttribute('src') || '',
  };
}

function messageAvatar(message: AssistantMessage): string {
  if (message.role === 'assistant') {
    return `<span class="hub-assistant-avatar is-assistant" aria-hidden="true">${icon('bot')}</span>`;
  }

  const profile = memberProfile();

  if (profile.photoUrl) {
    return [
      '<span class="hub-assistant-avatar is-user" aria-hidden="true">',
      `<img src="${escapeHtml(profile.photoUrl)}" alt="" loading="lazy" onerror="this.closest('.hub-assistant-avatar')?.classList.add('is-fallback');this.remove()">`,
      `<span class="hub-assistant-avatar-fallback">${icon('user')}</span>`,
      '</span>',
    ].join('');
  }

  return `<span class="hub-assistant-avatar is-user" aria-label="${escapeHtml(profile.name)}">${icon('user')}</span>`;
}

function renderMessage(message: AssistantMessage): string {
  return [
    `<article class="hub-assistant-message-row is-${message.role}">`,
    messageAvatar(message),
    `<div class="hub-assistant-message is-${message.role}">`,
    `<p>${escapeHtml(message.text)}</p>`,
    resultLink(message.url, message.label),
    suggestionLinks(message.suggestions),
    '</div>',
    '</article>',
  ].join('');
}

function scrollMessagesToBottom(): void {
  window.requestAnimationFrame(() => {
    const messagesElement = hostElement?.querySelector<HTMLElement>('[data-hub-assistant-messages]');

    if (messagesElement) {
      messagesElement.scrollTop = messagesElement.scrollHeight;
    }
  });
}

function focusInput(): void {
  window.requestAnimationFrame(() => {
    hostElement?.querySelector<HTMLInputElement>('[data-hub-assistant-input]')?.focus({ preventScroll: true });
  });
}

function render(): void {
  if (!hostElement) {
    return;
  }

  hostElement.innerHTML = [
    `<section class="hub-assistant-panel${isOpen ? ' is-open' : ''}" role="dialog" aria-label="Assistant du HUB"${isOpen ? '' : ' hidden'}>`,
    '<header class="hub-assistant-header">',
    '<div>',
    '<span class="hub-assistant-kicker">Assistant HUB</span>',
    '<h2>Aide et navigation</h2>',
    '</div>',
    `<button class="hub-assistant-close" type="button" aria-label="Fermer" data-hub-assistant-close><span class="hub-assistant-close-desktop">${icon('close')}</span><span class="hub-assistant-close-mobile">${icon('back')}<span>Retour</span></span></button>`,
    '</header>',
    `<div class="hub-assistant-messages" data-hub-assistant-messages>${messages.map(renderMessage).join('')}</div>`,
    '<form class="hub-assistant-form" data-hub-assistant-form>',
    '<label class="hub-assistant-sr-only" for="hub-assistant-input">Message</label>',
    `<input id="hub-assistant-input" name="message" type="text" autocomplete="off" placeholder="Posez une question ou recherchez une page" maxlength="500" data-hub-assistant-input${isLoading ? ' disabled' : ''}>`,
    `<button type="submit" aria-label="Envoyer"${isLoading ? ' disabled' : ''}>${isLoading ? icon('spark') : icon('send')}</button>`,
    '</form>',
    '</section>',
  ].join('');

  bindElements();
  scrollMessagesToBottom();

  if (isOpen && !isLoading) {
    focusInput();
  }
}

function setOpen(nextOpen: boolean): void {
  isOpen = nextOpen;
  document.body.classList.toggle('hub-assistant-open', isOpen);
  render();
}

async function submitMessage(form: HTMLFormElement): Promise<void> {
  const input = form.querySelector<HTMLInputElement>('[data-hub-assistant-input]');
  const text = input?.value.trim() || '';

  if (!text || isLoading) {
    return;
  }

  messages.push({ role: 'user', text });
  isLoading = true;
  render();

  try {
    const response = await crmApi.post<HubAssistantResponse>('/api/hub-assistant/message', { message: text });

    messages.push({
      label: response.label ?? null,
      role: 'assistant',
      suggestions: response.suggestions ?? [],
      text: response.message || 'Je n’ai pas trouve de page correspondante.',
      url: response.url ?? null,
    });
  } catch (error) {
    messages.push({
      role: 'assistant',
      text: error instanceof Error ? error.message : 'Assistant HUB indisponible pour le moment.',
    });
  } finally {
    isLoading = false;
    render();
  }
}

function bindElements(): void {
  hostElement?.querySelector('[data-hub-assistant-close]')?.addEventListener('click', () => setOpen(false));
  hostElement?.querySelector('[data-hub-assistant-form]')?.addEventListener('submit', (event) => {
    event.preventDefault();

    if (event.currentTarget instanceof HTMLFormElement) {
      void submitMessage(event.currentTarget);
    }
  });
  hostElement?.querySelectorAll('[data-hub-assistant-link]').forEach((link) => {
    link.addEventListener('click', () => setOpen(false));
  });
}

export function installHubAssistant(): void {
  if (window.__martinSolsHubAssistantInstalled) {
    return;
  }

  window.__martinSolsHubAssistantInstalled = true;
  window.MartinSolsHubAssistant = {
    close: () => setOpen(false),
    open: () => setOpen(true),
    toggle: () => setOpen(!isOpen),
  };

  hostElement = document.createElement('div');
  hostElement.className = 'hub-assistant';
  hostElement.dataset.hubAssistant = 'true';
  document.body.appendChild(hostElement);

  document.addEventListener(
    'keydown',
    (event) => {
      if (event.key === 'Escape' && isOpen) {
        setOpen(false);
      }
    },
    true,
  );

  window.addEventListener('crm:profile-updated', () => {
    if (isOpen) {
      render();
    }
  });

  render();
}
