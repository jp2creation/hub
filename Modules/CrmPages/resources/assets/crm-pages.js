(function () {
  var API = '/api/pages';
  var root = null;
  var shell = null;
  var bootedRoot = null;
  var shellEventsBound = false;

  var state = {
    loading: true,
    saving: false,
    error: '',
    notice: null,
    user: null,
    pages: [],
    selectedPage: null,
    query: '',
    modal: null,
  };

  function html(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function slugFromLocation() {
    var match = window.location.pathname.match(/^\/pages-crm\/([^/]+)$/);
    return match ? decodeURIComponent(match[1]) : '';
  }

  function canManage() {
    var permissions = Array.isArray(state.user && state.user.permissions) ? state.user.permissions : [];
    return permissions.indexOf('pages.manage') !== -1 || permissions.indexOf('platform.manage_modules') !== -1;
  }

  async function api(action, body, extraQuery) {
    var query = new URLSearchParams(Object.assign({ action: action }, extraQuery || {}));
    var response = await fetch(API + '?' + query.toString(), {
      method: body ? 'POST' : 'GET',
      credentials: 'same-origin',
      headers: body
        ? { 'Content-Type': 'application/json', Accept: 'application/json' }
        : { Accept: 'application/json' },
      body: body ? JSON.stringify(body) : undefined,
    });
    var payload = await response.json();

    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || 'Action impossible');
    }

    return payload;
  }

  async function loadData(preferredSlug) {
    state.loading = true;
    state.error = '';
    render();

    try {
      var payload = await api('bootstrap');
      state.user = payload.user || null;
      state.pages = Array.isArray(payload.pages) ? payload.pages : [];

      var slug = preferredSlug || slugFromLocation() || (state.pages[0] && state.pages[0].slug) || '';
      if (slug) {
        await loadPage(slug, false, true);
      } else {
        state.selectedPage = null;
      }
    } catch (error) {
      state.error = error instanceof Error ? error.message : 'Impossible de charger les pages HUB';
    } finally {
      state.loading = false;
      render();
    }
  }

  async function loadPage(slug, push, silent) {
    if (!slug) {
      state.selectedPage = null;
      return;
    }

    if (!silent) {
      state.loading = true;
      render();
    }

    try {
      var payload = await api('page', null, { slug: slug });
      state.selectedPage = payload.page || null;

      if (push !== false && state.selectedPage) {
        window.history.pushState({}, '', state.selectedPage.routePath || '/pages-crm/' + state.selectedPage.slug);
      }
    } catch (error) {
      state.selectedPage = null;
      state.error = error instanceof Error ? error.message : 'Page introuvable';
    } finally {
      if (!silent) {
        state.loading = false;
        render();
      }
    }
  }

  function filteredPages() {
    var query = state.query.trim().toLowerCase();
    if (!query) {
      return state.pages.slice();
    }

    return state.pages.filter(function (page) {
      return [page.title, page.excerpt, page.slug].some(function (value) {
        return (
          String(value || '')
            .toLowerCase()
            .indexOf(query) !== -1
        );
      });
    });
  }

  function pageIcon(page) {
    var key = String((page && page.iconKey) || 'PC')
      .slice(0, 2)
      .toUpperCase();
    return key || 'PC';
  }

  function renderMarkdown(value) {
    var lines = String(value || '')
      .replace(/\r\n/g, '\n')
      .split('\n');
    var output = [];
    var list = [];

    function flushList() {
      if (!list.length) {
        return;
      }

      output.push(
        '<ul class="crm-page-content-list">' +
          list
            .map(function (item) {
              return '<li>' + inlineMarkdown(item) + '</li>';
            })
            .join('') +
          '</ul>',
      );
      list = [];
    }

    lines.forEach(function (line) {
      var trimmed = line.trim();

      if (!trimmed) {
        flushList();
        return;
      }

      if (trimmed.indexOf('### ') === 0) {
        flushList();
        output.push('<h4>' + inlineMarkdown(trimmed.slice(4)) + '</h4>');
        return;
      }

      if (trimmed.indexOf('## ') === 0) {
        flushList();
        output.push('<h3>' + inlineMarkdown(trimmed.slice(3)) + '</h3>');
        return;
      }

      if (trimmed.indexOf('# ') === 0) {
        flushList();
        output.push('<h2>' + inlineMarkdown(trimmed.slice(2)) + '</h2>');
        return;
      }

      if (/^[-*]\s+/.test(trimmed)) {
        list.push(trimmed.replace(/^[-*]\s+/, ''));
        return;
      }

      flushList();
      output.push('<p>' + inlineMarkdown(trimmed) + '</p>');
    });

    flushList();

    return output.join('') || '<p class="crm-muted">Cette page est vide.</p>';
  }

  function inlineMarkdown(value) {
    var escaped = html(value);
    escaped = escaped.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    escaped = escaped.replace(/(^|[\s(])(https?:\/\/[^\s<]+)/g, function (_, prefix, url) {
      return prefix + '<a href="' + html(url) + '" target="_blank" rel="noopener noreferrer">' + html(url) + '</a>';
    });
    return escaped;
  }

  function renderStyles() {
    return (
      '<style id="crm-pages-module-style">' +
      [
        '.crm-pages-module{display:grid;gap:1.25rem}',
        '.crm-pages-hero{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem}',
        '.crm-pages-title{margin:0;color:#0f172a;font-size:clamp(2rem,4vw,3.25rem);font-weight:600;letter-spacing:0;line-height:1.05}',
        '.crm-pages-subtitle{margin:.65rem 0 0;color:#64748b;font-size:1rem;font-weight:600;line-height:1.55}',
        '.crm-pages-actions{display:flex;flex-wrap:wrap;align-items:center;justify-content:flex-end;gap:.65rem}',
        '.crm-button{display:inline-flex;min-height:2.65rem;align-items:center;justify-content:center;gap:.45rem;border:1px solid transparent;border-radius:.65rem;padding:.65rem 1rem;font-size:.92rem;font-weight:600;line-height:1;text-decoration:none;cursor:pointer;transition:background .15s,border-color .15s,color .15s,box-shadow .15s}',
        '.crm-button-primary{background:rgb(var(--theme-primary));color:#fff;box-shadow:0 12px 22px rgb(var(--theme-primary) / .18)}',
        '.crm-button-primary:hover{background:rgb(var(--theme-primary) / .92)}',
        '.crm-button-secondary{border-color:#e2e8f0;background:#fff;color:#334155}',
        '.crm-button-secondary:hover{border-color:rgb(var(--theme-primary) / .35);color:rgb(var(--theme-primary))}',
        '.crm-button-danger{background:#fee2e2;color:#991b1b;border-color:#fecaca}',
        '.crm-card{border:1px solid #e2e8f0;border-radius:.85rem;background:#fff;box-shadow:0 12px 28px rgb(15 23 42 / .05)}',
        '.crm-page-layout{display:grid;grid-template-columns:minmax(16rem,22rem) minmax(0,1fr);gap:1rem;align-items:start}',
        '.crm-page-list{overflow:hidden}',
        '.crm-page-list-head{display:grid;gap:.75rem;border-bottom:1px solid #e2e8f0;padding:1rem}',
        '.crm-page-search{width:100%;height:2.65rem;border:1px solid #e2e8f0;border-radius:.65rem;background:#f8fafc;padding:0 .85rem;color:#0f172a;font:inherit;font-size:.9rem;font-weight:700;outline:none}',
        '.crm-page-search:focus{border-color:rgb(var(--theme-primary) / .45);box-shadow:0 0 0 3px rgb(var(--theme-primary) / .12)}',
        '.crm-page-list-items{display:grid;gap:.45rem;max-height:calc(100vh - 17rem);overflow:auto;padding:.7rem}',
        '.crm-page-list-button{display:grid;grid-template-columns:2.35rem minmax(0,1fr);gap:.75rem;width:100%;border:1px solid transparent;border-radius:.7rem;background:transparent;padding:.75rem;text-align:left;cursor:pointer}',
        '.crm-page-list-button:hover,.crm-page-list-button.is-active{border-color:rgb(var(--theme-primary) / .25);background:rgb(var(--theme-primary) / .06)}',
        '.crm-page-icon{display:inline-flex;width:2.35rem;height:2.35rem;align-items:center;justify-content:center;border-radius:.65rem;background:#f1f5f9;color:#475569;font-size:.68rem;font-weight:600}',
        '.crm-page-list-button.is-active .crm-page-icon{background:rgb(var(--theme-primary));color:#fff}',
        '.crm-page-list-title{display:block;overflow:hidden;color:#0f172a;font-size:.95rem;font-weight:600;text-overflow:ellipsis;white-space:nowrap}',
        '.crm-page-list-excerpt{display:-webkit-box;margin-top:.22rem;overflow:hidden;color:#64748b;font-size:.82rem;font-weight:600;line-height:1.35;-webkit-line-clamp:2;-webkit-box-orient:vertical}',
        '.crm-page-reader{min-height:32rem;overflow:hidden}',
        '.crm-page-reader-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;border-bottom:1px solid #e2e8f0;padding:1.1rem}',
        '.crm-page-reader-title{margin:0;color:#0f172a;font-size:clamp(1.55rem,3vw,2.35rem);font-weight:600;letter-spacing:0;line-height:1.08}',
        '.crm-page-reader-meta{margin:.45rem 0 0;color:#64748b;font-size:.92rem;font-weight:700;line-height:1.5}',
        '.crm-page-content{padding:1.2rem;color:#334155;font-size:1rem;font-weight:600;line-height:1.72}',
        '.crm-page-content h2,.crm-page-content h3,.crm-page-content h4{margin:1rem 0 .5rem;color:#0f172a;font-weight:600;letter-spacing:0;line-height:1.2}',
        '.crm-page-content h2{font-size:1.45rem}.crm-page-content h3{font-size:1.22rem}.crm-page-content h4{font-size:1.05rem}',
        '.crm-page-content p{margin:.75rem 0}.crm-page-content a{color:rgb(var(--theme-primary));font-weight:600}.crm-page-content-list{margin:.75rem 0;padding-left:1.25rem}.crm-page-content-list li{margin:.35rem 0}',
        '.crm-muted{color:#64748b}.crm-empty{padding:2.5rem 1.25rem;text-align:center;color:#64748b;font-weight:700}',
        '.crm-notice{border-radius:.75rem;padding:.85rem 1rem;font-size:.92rem;font-weight:800}.crm-notice-success{border:1px solid #bbf7d0;background:#f0fdf4;color:#166534}.crm-notice-error{border:1px solid #fecaca;background:#fef2f2;color:#991b1b}',
        '.crm-modal-backdrop{position:fixed;inset:0;z-index:80;display:flex;align-items:center;justify-content:center;background:rgb(15 23 42 / .48);padding:1rem}',
        '.crm-modal{width:min(48rem,100%);max-height:92vh;overflow:auto;border-radius:.85rem;background:#fff;box-shadow:0 24px 70px rgb(15 23 42 / .2)}',
        '.crm-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;border-bottom:1px solid #e2e8f0;padding:1rem 1.1rem}',
        '.crm-modal-title{margin:0;color:#0f172a;font-size:1.3rem;font-weight:600}',
        '.crm-form{display:grid;gap:1rem;padding:1.1rem}.crm-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem}',
        '.crm-field{display:grid;gap:.4rem}.crm-label{color:#334155;font-size:.82rem;font-weight:600}.crm-input,.crm-textarea{width:100%;border:1px solid #e2e8f0;border-radius:.65rem;background:#f8fafc;padding:.75rem .85rem;color:#0f172a;font:inherit;font-size:.92rem;font-weight:700;outline:none}.crm-textarea{min-height:12rem;resize:vertical;line-height:1.5}.crm-input:focus,.crm-textarea:focus{border-color:rgb(var(--theme-primary) / .45);box-shadow:0 0 0 3px rgb(var(--theme-primary) / .12)}',
        '.crm-checks{display:flex;flex-wrap:wrap;gap:.8rem}.crm-check{display:flex;align-items:center;gap:.45rem;color:#334155;font-size:.88rem;font-weight:800}',
        '@media (max-width:980px){.crm-page-layout{grid-template-columns:1fr}.crm-page-list-items{max-height:none}.crm-pages-hero{flex-direction:column}.crm-pages-actions{justify-content:flex-start}}',
        '@media (max-width:640px){.crm-pages-title{font-size:2.35rem}.crm-page-reader-head{flex-direction:column}.crm-form-grid{grid-template-columns:1fr}.crm-button{width:100%}.crm-pages-actions{width:100%}.crm-page-content{font-size:.95rem}.crm-modal-backdrop{align-items:flex-end;padding:0}.crm-modal{max-height:94vh;border-radius:.85rem .85rem 0 0}}',
      ].join('') +
      '</style>'
    );
  }

  function renderList() {
    var pages = filteredPages();
    var selectedSlug = state.selectedPage && state.selectedPage.slug;

    return (
      '<aside class="crm-card crm-page-list">' +
      '<div class="crm-page-list-head">' +
      '<input class="crm-page-search" data-search value="' +
      html(state.query) +
      '" placeholder="Rechercher une page">' +
      '<span class="crm-muted">' +
      pages.length +
      ' page(s)</span>' +
      '</div>' +
      '<div class="crm-page-list-items">' +
      (pages
        .map(function (page) {
          var active = page.slug === selectedSlug ? ' is-active' : '';
          return (
            '<button type="button" class="crm-page-list-button' +
            active +
            '" data-open-page="' +
            html(page.slug) +
            '">' +
            '<span class="crm-page-icon">' +
            html(pageIcon(page)) +
            '</span>' +
            '<span><span class="crm-page-list-title">' +
            html(page.title) +
            '</span>' +
            '<span class="crm-page-list-excerpt">' +
            html(page.excerpt || page.routePath || '') +
            '</span></span>' +
            '</button>'
          );
        })
        .join('') || '<div class="crm-empty">Aucune page disponible.</div>') +
      '</div>' +
      '</aside>'
    );
  }

  function renderReader() {
    var page = state.selectedPage;

    if (!page) {
      return '<section class="crm-card crm-page-reader"><div class="crm-empty">Sélectionnez une page pour consulter son contenu.</div></section>';
    }

    return (
      '<section class="crm-card crm-page-reader">' +
      '<div class="crm-page-reader-head">' +
      '<div>' +
      '<h2 class="crm-page-reader-title">' +
      html(page.title) +
      '</h2>' +
      '<p class="crm-page-reader-meta">' +
      html(page.excerpt || 'Page interne HUB') +
      '</p>' +
      '</div>' +
      (canManage()
        ? '<div class="crm-pages-actions">' +
          '<button type="button" class="crm-button crm-button-secondary" data-edit-page>Modifier</button>' +
          '<button type="button" class="crm-button crm-button-danger" data-delete-page>Supprimer</button>' +
          '</div>'
        : '') +
      '</div>' +
      '<article class="crm-page-content">' +
      renderMarkdown(page.content || '') +
      '</article>' +
      '</section>'
    );
  }

  function modalHtml() {
    if (!state.modal) {
      return '';
    }

    var form = state.modal;
    var editing = Boolean(form.id);

    return (
      '<div class="crm-modal-backdrop" data-close-modal>' +
      '<section class="crm-modal" data-modal-panel>' +
      '<header class="crm-modal-head">' +
      '<div><h2 class="crm-modal-title">' +
      (editing ? 'Modifier la page' : 'Nouvelle page HUB') +
      '</h2>' +
      '<p class="crm-muted">Le contenu accepte les titres Markdown simples et les listes.</p></div>' +
      '<button type="button" class="crm-button crm-button-secondary" data-close>Fermer</button>' +
      '</header>' +
      '<form class="crm-form" data-page-form>' +
      '<div class="crm-form-grid">' +
      '<label class="crm-field"><span class="crm-label">Titre</span><input class="crm-input" name="title" required value="' +
      html(form.title) +
      '"></label>' +
      '<label class="crm-field"><span class="crm-label">Slug</span><input class="crm-input" name="slug" value="' +
      html(form.slug) +
      '" placeholder="généré automatiquement"></label>' +
      '</div>' +
      '<div class="crm-form-grid">' +
      '<label class="crm-field"><span class="crm-label">Icône</span><input class="crm-input" name="iconKey" value="' +
      html(form.iconKey || 'article') +
      '"></label>' +
      '<label class="crm-field"><span class="crm-label">Ordre</span><input class="crm-input" type="number" min="0" max="999" name="sortOrder" value="' +
      html(form.sortOrder || 100) +
      '"></label>' +
      '</div>' +
      '<label class="crm-field"><span class="crm-label">Résumé</span><input class="crm-input" name="excerpt" value="' +
      html(form.excerpt || '') +
      '"></label>' +
      '<label class="crm-field"><span class="crm-label">Contenu</span><textarea class="crm-textarea" name="content" required>' +
      html(form.content || '') +
      '</textarea></label>' +
      '<div class="crm-checks">' +
      '<label class="crm-check"><input type="checkbox" name="active" ' +
      (form.active !== false ? 'checked' : '') +
      '> Active</label>' +
      '<label class="crm-check"><input type="checkbox" name="showInMenu" ' +
      (form.showInMenu !== false ? 'checked' : '') +
      '> Visible dans le menu</label>' +
      '</div>' +
      '<button class="crm-button crm-button-primary" type="submit" ' +
      (state.saving ? 'disabled' : '') +
      '>' +
      (state.saving ? 'Enregistrement...' : 'Enregistrer') +
      '</button>' +
      '</form>' +
      '</section>' +
      '</div>'
    );
  }

  function render() {
    if (state.loading) {
      root.innerHTML = renderStyles() + '<div class="crm-card crm-empty">Chargement des pages HUB...</div>';
      return;
    }

    if (state.error) {
      root.innerHTML = renderStyles() + '<div class="crm-notice crm-notice-error">' + html(state.error) + '</div>';
      bindEvents();
      return;
    }

    root.innerHTML =
      renderStyles() +
      '<section class="crm-pages-module">' +
      '<header class="crm-pages-hero">' +
      '<div><h1 class="crm-pages-title">Pages HUB</h1>' +
      '<p class="crm-pages-subtitle">Consultez et organisez les pages internes du HUB Martin Sols.</p></div>' +
      '<div class="crm-pages-actions">' +
      (canManage()
        ? '<button type="button" class="crm-button crm-button-primary" data-new-page>+ Nouvelle page</button>'
        : '') +
      '<a class="crm-button crm-button-secondary" href="/">Tableau de bord</a>' +
      '</div>' +
      '</header>' +
      (state.notice
        ? '<div class="crm-notice crm-notice-' + html(state.notice.type) + '">' + html(state.notice.message) + '</div>'
        : '') +
      '<div class="crm-page-layout">' +
      renderList() +
      renderReader() +
      '</div>' +
      '</section>' +
      modalHtml();

    bindEvents();
  }

  function openModal(page) {
    state.notice = null;
    state.modal = page
      ? Object.assign({}, page)
      : {
          title: '',
          slug: '',
          excerpt: '',
          content: '',
          iconKey: 'article',
          active: true,
          showInMenu: true,
          sortOrder: 100,
        };
    render();
  }

  async function savePage(event) {
    event.preventDefault();
    if (!state.modal) {
      return;
    }

    var data = Object.fromEntries(new FormData(event.target).entries());
    data.id = state.modal.id || undefined;
    data.active = event.target.elements.active.checked;
    data.showInMenu = event.target.elements.showInMenu.checked;

    state.saving = true;
    render();

    try {
      var payload = await api('save_page', data);
      state.notice = { type: 'success', message: 'Page enregistrée.' };
      state.modal = null;
      state.saving = false;
      await loadData(payload.page && payload.page.slug);
      if (payload.page && payload.page.routePath) {
        window.history.pushState({}, '', payload.page.routePath);
      }
    } catch (error) {
      state.saving = false;
      state.notice = { type: 'error', message: error instanceof Error ? error.message : 'Enregistrement impossible' };
      render();
    }
  }

  async function deleteSelectedPage() {
    var page = state.selectedPage;
    if (!page || !window.confirm('Supprimer cette page HUB ?')) {
      return;
    }

    try {
      await api('delete_page', { id: page.id });
      state.notice = { type: 'success', message: 'Page supprimée.' };
      window.history.pushState({}, '', '/pages-crm');
      await loadData('');
    } catch (error) {
      state.notice = { type: 'error', message: error instanceof Error ? error.message : 'Suppression impossible' };
      render();
    }
  }

  function bindEvents() {
    root.querySelector('[data-search]')?.addEventListener('input', function (event) {
      state.query = event.target.value;
      render();
      var input = root.querySelector('[data-search]');
      if (input) {
        input.focus();
        input.setSelectionRange(input.value.length, input.value.length);
      }
    });

    root.querySelectorAll('[data-open-page]').forEach(function (button) {
      button.addEventListener('click', function () {
        state.error = '';
        loadPage(button.dataset.openPage, true, false);
      });
    });

    root.querySelector('[data-new-page]')?.addEventListener('click', function () {
      openModal(null);
    });

    root.querySelector('[data-edit-page]')?.addEventListener('click', function () {
      openModal(state.selectedPage);
    });

    root.querySelector('[data-delete-page]')?.addEventListener('click', deleteSelectedPage);
    root.querySelector('[data-page-form]')?.addEventListener('submit', savePage);

    root.querySelector('[data-close]')?.addEventListener('click', function () {
      state.modal = null;
      state.notice = null;
      render();
    });

    root.querySelector('[data-close-modal]')?.addEventListener('click', function (event) {
      if (event.target.dataset.closeModal !== undefined) {
        state.modal = null;
        state.notice = null;
        render();
      }
    });

    root.querySelector('[data-modal-panel]')?.addEventListener('click', function (event) {
      event.stopPropagation();
    });
  }

  function bindShellEvents() {
    if (shellEventsBound) {
      return;
    }

    document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', function () {
      shell && shell.classList.toggle('is-sidebar-open');
    });

    document.querySelector('[data-sidebar-close]')?.addEventListener('click', function () {
      shell && shell.classList.remove('is-sidebar-open');
    });

    shellEventsBound = true;
  }

  function boot() {
    var nextRoot = document.getElementById('crm-pages-root');

    if (!nextRoot) {
      return false;
    }

    if (nextRoot === bootedRoot) {
      return true;
    }

    root = nextRoot;
    shell = document.querySelector('[data-pages-shell]');
    bootedRoot = nextRoot;
    state.loading = true;
    state.error = '';
    state.notice = null;
    bindShellEvents();
    loadData(slugFromLocation());

    return true;
  }

  document.addEventListener(
    'click',
    function (event) {
      var link = event.target && event.target.closest ? event.target.closest('a[href^="/pages-crm"]') : null;

      if (link) {
        window.setTimeout(boot, 80);
      }
    },
    true,
  );

  function startBootObserver() {
    boot();

    if (document.body) {
      new MutationObserver(boot).observe(document.body, { childList: true, subtree: true });
    }
  }

  window.addEventListener('popstate', function () {
    if (boot()) {
      loadData(slugFromLocation());
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startBootObserver, { once: true });
  } else {
    startBootObserver();
  }
})();
