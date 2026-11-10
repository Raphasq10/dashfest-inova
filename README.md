# 📊 Festival Iguassu Inova 2026 — Dashboard de Inteligência de Dados & Performance

Sistema de auditoria executiva, BI, acompanhamento operacional e conciliação de conversão do **Festival Iguassu Inova 2026**, integrando **Yazo Tickets API v1**, **Google Analytics 4 (GA4 Data API v1beta)** e **Meta Marketing API v21.0**.

---

## 🧭 Navegação Unificada (6 Telas)

Compartilhada em todo o ecossistema via [`nav.js`](nav.js) e dividida em dois blocos:

### 1. Executivo & Diretoria
- **[`index.html`](index.html) — Home Executiva:** Visão consolidada com **Visão Executiva**, **Performance Mídia Paga (GA4)** e **Funil Multi-Canal Comparativo (Site GA4 → Página Yazo → Yazo Validados)**, além de distribuição geográfica e canais de aquisição.
- **[`diretoria.html`](diretoria.html) — Relatório PDF (Fase 2: 08/10 a 06/11):** Análise C-Level com foco em usuários únicos, comportamento na Landing Page/checkout e exportação para **Imprimir / Salvar PDF**.
- **[`campanha_fase1.html`](campanha_fase1.html) — Fase 1 (14/09 a 07/10):** Consolidação de alcance, branding e vídeo (YouTube Ads & Meta Ads).
- **[`respostas_cliente.html`](respostas_cliente.html) — Respostas Oficiais:** Respostas objetivas às perguntas estratégicas da diretoria e Curva S de inscritos.

### 2. Operação & Tempo Real
- **[`operacao.html`](operacao.html) — War Room • Meta 30k (`Yazo + GA4`):** Monitoramento diário e horário rumo à meta de 30.000 inscritos, UTMs ao vivo, sincronização em tempo real via [`api_yazo.php`](api_yazo.php) e [`api_ga4.php`](api_ga4.php).
- **[`meta_marketing.html`](meta_marketing.html) — Meta Ads API (`v21.0`):** Investimento, impressões, cliques no link, CTR, CPC, conversões Pixel e CPA das campanhas oficiais 2026 via [`api_meta.php`](api_meta.php).

---

## 🛠️ Arquitetura, Tecnologias & Segurança (LGPD)

- **Frontend:** HTML5, Vanilla JavaScript e Sistema de Design **Urban Chic** ([`theme.css`](theme.css) / [`theme.js`](theme.js)) com tema claro padrão e modo escuro opcional.
- **Visualização de Dados:** Chart.js 4, FontAwesome 6, Google Fonts (*Plus Jakarta Sans* & *Inter*).
- **Backend Proxy (PHP 8):**
  - [`api_yazo.php`](api_yazo.php) — Sincronização Yazo Tickets API v1 com geração de snapshot anonimizado em [`yazo_operacao_data.js`](yazo_operacao_data.js) (identificador em hash MD5 truncado, sem nomes, e-mails, telefones ou CPFs).
  - [`api_ga4.php`](api_ga4.php) — Autenticação JWT / Service Account no GA4 Data API v1beta e atualização de [`ga4_operacao_data.js`](ga4_operacao_data.js).
  - [`api_meta.php`](api_meta.php) — Consulta à Meta Graph API v21.0 filtrando o ciclo atual (`2026-08-01` em diante) e atualizando [`meta_data.js`](meta_data.js).
- **Modo Estático / GitHub Pages:** Todas as telas funcionam com os snapshots sanitizados (`.js`) quando abertas sem backend PHP.
- **Proteção de Dados e Segredos:** Arquivos `.env*`, `ga4-credentials.json`, caches (`*_cache.json`) e eventuais planilhas (`.csv`, `.xlsx`, `.xls`) são bloqueados pelo [`.gitignore`](.gitignore), [`.htaccess`](.htaccess) e workflow do GitHub Pages.

---

## 📦 Como Executar Localmente

### 1. Clonar o repositório
```bash
git clone https://github.com/Raphasq10/dashfest-inova.git
cd dashfest-inova
```

### 2. Configurar credenciais locais (opcional — apenas para atualização ao vivo via APIs)
Crie um arquivo `.env.local` a partir de [`.env.example`](.env.example):
```env
META_ACCESS_TOKEN=seu_meta_system_user_token_aqui
META_AD_ACCOUNT_ID=act_140031283
META_API_VERSION=v21.0

YAZO_API_TOKEN=seu_token_yazo_ytk_aqui
YAZO_EVENT_ID=68868ba6-fe65-4c08-8d10-531a90361ebf

GA4_PROPERTY_ID=seu_property_id_numerico_ga4
GA4_CREDENTIALS_FILE=ga4-credentials.json
```
*(Para o GA4 ao vivo, salve também o JSON da Service Account como `ga4-credentials.json` na raiz da pasta — ambos permanecem ignorados pelo Git.)*

### 3. Iniciar o servidor local
- **Opção recomendada (Windows / XAMPP PHP):** dê duplo clique em [`servidor_local.bat`](servidor_local.bat) ou execute na pasta do projeto:
  ```powershell
  C:\xampp\php\php.exe -S localhost:8085
  ```
  Acesse no navegador: **`http://localhost:8085/`**
- **Opção via Apache (XAMPP):** coloque a pasta dentro de `C:\xampp\htdocs\`, inicie o módulo **Apache** no painel do XAMPP e acesse pela URL correspondente em `http://localhost/...`.
- **Modo offline/estático rápido:** abra [`index.html`](index.html) (ou dê duplo clique em [`abrir_dashboard.bat`](abrir_dashboard.bat)) para visualizar os snapshots já carregados sem depender de PHP.
