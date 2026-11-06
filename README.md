# 📊 Festival Iguassu Inova 2026 — Dashboard de Inteligência de Dados & Performance

Sistema de auditoria executiva, BI e conciliação de conversão do **Festival Iguassu Inova 2026**.

---

## 🚀 Telas e Relatórios do Projeto

1. **`index.html` — Painel Geral & Operacional:**
   - Curva de conversão horária (00h às 23h) com identificação de horários de pico.
   - Filtros dinâmicos por período, médias móveis (7 dias) e curva S acumulada.
   - Análise de canais de tráfego pago (Instagram Reels, Feed, Spotify Ads e Google CPC).
   - Funil Comparativo em 3 Colunas: **Geral (Global)** vs. **Meta Ads Pago** vs. **Orgânico & Direto**.
   - Eventos oficiais do GA4 e distribuição internacional (Brasil, Paraguai, Argentina).

2. **`diretoria.html` — Relatório Executivo C-Level (Fase 2: 08/10 a 06/11):**
   - Respostas executivas comprovadas com foco em **Usuários Únicos (Pessoas Reais)**.
   - Comportamento de retenção vs. desistência na Landing Page e no checkout.
   - Conciliação da reta final: 43.338 sessões GA4, 50k cliques Meta Ads, 4.000 inscrições concluídas.
   - Botão nativo para **Imprimir / Salvar PDF**.

3. **`campanha_fase1.html` — Fase 1: Alcance, Branding & Vídeo (14/09 a 07/10):**
   - Consolidação de **R$ 25.023,52** investidos em mídia paga.
   - **Google Ads YouTube:** 6,3 milhões de impressões, 228k TrueViews a R$ 0,06 por view nas praças de Paraná, Sul/Sudeste e Foz do Iguaçu.
   - **Meta Ads:** 71.023 cliques no link (CPC R$ 0,17).
   - Salto da base de inscritos de 5.147 para 6.904 participantes.

4. **`respostas_cliente.html` — Perguntas Oficiais do Cliente:**
   - Respostas diretas às perguntas estratégicas da campanha (Meta Geral, Meta Pago, YouTube).
   - Gráfico interativo com a **Curva S contínua de inscrições (0 a 11.000 inscritos)** no App Yazo demonstrando a tração de cada período.

---

## 🛠️ Tecnologias Utilizadas

- **Frontend:** HTML5, CSS3 moderno (Dark Executive Theme), Vanilla JavaScript.
- **Visualização de Dados:** Chart.js 4.4, FontAwesome 6.5, Google Fonts (Plus Jakarta Sans & Inter).
- **Backend / ETL:** Python 3 (pandas, openpyxl), PHP 8 (Apache proxy local para Yazo API).
- **Integração de APIs:** Conector REST para Yazo Tickets API v1 (`sync_yazo_api.py` / `sync_yazo.php`).

---

## 📦 Como Executar Localmente

1. Clone o repositório na pasta `htdocs` do seu XAMPP:
   ```bash
   cd c:\xampp\htdocs\festivaliguassuinova\dashboard 26
   ```
2. Inicie o Apache no painel do XAMPP.
3. Abra o navegador em:
   ```text
   http://localhost/festivaliguassuinova/dashboard%2026/
   ```
   Ou dê duplo clique no arquivo `abrir_dashboard.bat`.
