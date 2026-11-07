/**
 * FESTIVAL IGUASSU INOVA 2026 - CONTROLE DE TEMA EXECUTIVO
 * Paleta Oficial: Urban Chic (~Ivory, ~Gunmetal, ~Steel Blue, ~Ocean Mist, ~Fiery Terracotta)
 */
(function() {
  const THEME_STORAGE_KEY = 'festival_inova_theme';

  // 1. Obter tema atual (PADRÃO: 'light' se não definido)
  function getPreferredTheme() {
    const saved = localStorage.getItem(THEME_STORAGE_KEY);
    if (saved === 'dark' || saved === 'light') {
      return saved;
    }
    return 'light'; // Padrão: Urban Chic Claro
  }

  // 2. Aplicar tema no elemento raiz <html>
  function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem(THEME_STORAGE_KEY, theme);
    updateThemeToggleButton(theme);
    updateChartColors(theme);
  }

  // 3. Atualizar botão de tema
  function updateThemeToggleButton(theme) {
    const btns = document.querySelectorAll('.btn-theme-toggle');
    btns.forEach(btn => {
      if (theme === 'dark') {
        btn.innerHTML = '<i class="fa-solid fa-moon theme-icon" style="color: #5B9DD4;"></i> <span>Modo Escuro</span>';
        btn.title = 'Clique para voltar ao Modo Urban Chic Executivo';
      } else {
        btn.innerHTML = '<i class="fa-solid fa-sun theme-icon" style="color: #D97706;"></i> <span>Modo Executivo</span>';
        btn.title = 'Clique para alternar para Modo Noturno';
      }
    });
  }

  // 4. Calibrar cores do Chart.js com a paleta Urban Chic
  function updateChartColors(theme) {
    if (typeof Chart === 'undefined') return;

    const isLight = (theme !== 'dark');
    const textColor = isLight ? '#393E41' : '#B2BCC2';
    const gridColor = isLight ? '#E2E5D5' : '#3E464C';
    const titleColor = isLight ? '#393E41' : '#F6F7EB';
    const doughnutBorder = isLight ? '#FFFFFF' : '#282E32';

    if (Chart.instances) {
      Object.values(Chart.instances).forEach(chart => {
        try {
          if (chart.options) {
            if (chart.options.plugins && chart.options.plugins.legend && chart.options.plugins.legend.labels) {
              chart.options.plugins.legend.labels.color = textColor;
            }
            if (chart.options.plugins && chart.options.plugins.title) {
              chart.options.plugins.title.color = titleColor;
            }
            if (chart.options.scales) {
              Object.values(chart.options.scales).forEach(scale => {
                if (scale.ticks) scale.ticks.color = textColor;
                if (scale.grid) scale.grid.color = gridColor;
              });
            }
            if (chart.data && chart.data.datasets) {
              chart.data.datasets.forEach(ds => {
                if (chart.config.type === 'doughnut' || chart.config.type === 'pie') {
                  ds.borderColor = doughnutBorder;
                }
              });
            }
            chart.update();
          }
        } catch (e) {}
      });
    }
  }

  window.toggleDashboardTheme = function() {
    const current = document.documentElement.getAttribute('data-theme') || 'light';
    const nextTheme = current === 'dark' ? 'light' : 'dark';
    applyTheme(nextTheme);
  };

  const initialTheme = getPreferredTheme();
  document.documentElement.setAttribute('data-theme', initialTheme);

  function initThemeUI() {
    const activeTheme = getPreferredTheme();
    applyTheme(activeTheme);

    if (document.querySelector('.btn-theme-toggle')) {
      updateThemeToggleButton(activeTheme);
      return;
    }

    const navActions = document.querySelector('.nav-actions') || document.querySelector('.header-actions');
    if (navActions) {
      const toggleBtn = document.createElement('button');
      toggleBtn.type = 'button';
      toggleBtn.className = 'btn-theme-toggle';
      toggleBtn.onclick = window.toggleDashboardTheme;
      
      if (navActions.firstChild) {
        navActions.insertBefore(toggleBtn, navActions.firstChild);
      } else {
        navActions.appendChild(toggleBtn);
      }
      updateThemeToggleButton(activeTheme);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initThemeUI);
  } else {
    initThemeUI();
  }

  window.addEventListener('load', function() {
    setTimeout(function() { updateChartColors(getPreferredTheme()); }, 200);
    setTimeout(function() { updateChartColors(getPreferredTheme()); }, 800);
  });
})();
