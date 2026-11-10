/**
 * FESTIVAL IGUASSU INOVA 2026 - NAVEGAÇÃO GLOBAL UNIFICADA
 * Divide o ecossistema em 2 blocos lógicos:
 * 1. Executivo & Diretoria (Tomada de decisão, PDF e histórico de fases)
 * 2. Operação & Tempo Real (War Room Meta 30k com Yazo/GA4 + Meta Ads API)
 */
(function() {
  const NAV_GROUPS = [
    {
      id: 'executive',
      label: 'Executivo & Diretoria',
      items: [
        { href: 'index.html', label: 'Home Executiva', icon: 'fa-solid fa-briefcase' },
        { href: 'diretoria.html', label: 'Relatório PDF (Fase 2)', icon: 'fa-solid fa-file-lines' },
        { href: 'campanha_fase1.html', label: 'Fase 1 (14/09–07/10)', icon: 'fa-solid fa-play' },
        { href: 'respostas_cliente.html', label: 'Respostas Oficiais', icon: 'fa-solid fa-clipboard-check' }
      ]
    },
    {
      id: 'operations',
      label: 'Operação & Tempo Real',
      items: [
        { href: 'operacao.html', label: 'War Room • Meta 30k', icon: 'fa-solid fa-gauge-high', badge: 'Yazo + GA4', badgeTone: 'terra' },
        { href: 'meta_marketing.html', label: 'Meta Ads API', icon: 'fa-brands fa-meta', badge: 'v21.0', badgeTone: 'blue' }
      ]
    }
  ];

  function getCurrentPage() {
    const path = window.location.pathname || '';
    const file = path.split('/').pop();
    if (!file || file === '') return 'index.html';
    return file.toLowerCase();
  }

  function buildNavMarkup(currentPage) {
    const groupsHtml = NAV_GROUPS.map(group => {
      const linksHtml = group.items.map(item => {
        const isActive = (currentPage === item.href.toLowerCase());
        const badgeHtml = item.badge
          ? `<span class="gnav-badge ${item.badgeTone || 'neutral'}">${item.badge}</span>`
          : '';
        return `<a href="${item.href}" class="gnav-link${isActive ? ' active' : ''}" data-group="${group.id}">
          <i class="${item.icon}"></i>
          <span>${item.label}</span>
          ${badgeHtml}
        </a>`;
      }).join('');

      return `<div class="gnav-group" data-group="${group.id}">
        <span class="gnav-group-label">${group.label}</span>
        <div class="gnav-links">${linksHtml}</div>
      </div>`;
    }).join('<div class="gnav-divider" aria-hidden="true"></div>');

    return `<nav class="global-nav-shell" aria-label="Navegação Principal">${groupsHtml}</nav>`;
  }

  function mountGlobalNav() {
    const currentPage = getCurrentPage();
    const markup = buildNavMarkup(currentPage);

    const mountTarget = document.getElementById('globalNavMount');
    if (mountTarget) {
      mountTarget.outerHTML = markup;
      return;
    }

    // Auto-mount fallback caso uma página não tenha placeholder explícito
    const dashHeader = document.querySelector('header.dashboard-header');
    if (dashHeader) {
      dashHeader.insertAdjacentHTML('afterend', markup);
      return;
    }

    const topNav = document.querySelector('header.top-nav');
    const container = document.querySelector('.container');
    if (topNav && container && !topNav.contains(container)) {
      container.insertAdjacentHTML('afterbegin', markup);
    } else if (container) {
      container.insertAdjacentHTML('afterbegin', markup);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mountGlobalNav);
  } else {
    mountGlobalNav();
  }
})();
