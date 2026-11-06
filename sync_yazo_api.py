"""
=============================================================================
INTEGRAÇÃO API YAZO TICKETS -> DASHBOARD FESTIVAL IGUASSU INOVA 2026
Documentação oficial: https://yazo-tickets.readme.io/reference/list-events
=============================================================================

Este script:
1. Conecta na API da Yazo Tickets (https://api.yazotickets.com/v1)
2. Busca o Evento do Festival Iguassu Inova 2026
3. Itera com paginação automática por todas as inscrições (registrations)
4. Mapeia os dados (createdAt, user.name, user.email, ticket.name, dynamicFormAnswers)
5. Gera automaticamente o arquivo 'data.js' atualizando o Dashboard em tempo real!

Como usar:
1. Obtenha seu Token de API no painel da Yazo (Configurações / Integrações / API)
2. Defina a variável YAZO_API_TOKEN abaixo (ou defina no ambiente: set YAZO_API_TOKEN=seu_token)
3. Execute: python sync_yazo_api.py
"""

import os
import sys
import json
import time
import urllib.request
import urllib.error
from datetime import datetime

# ================= CONFIGURAÇÃO =================
# Insira seu token de API do Yazo Tickets aqui ou defina na variável de ambiente
YAZO_API_TOKEN = os.getenv("YAZO_API_TOKEN", "COLOQUE_SEU_TOKEN_AQUI")

# Se você já souber o eventId, pode preencher aqui. Se deixar None, o script lista e busca pelo nome.
YAZO_EVENT_ID = os.getenv("YAZO_EVENT_ID", None)

BASE_URL = "https://api.yazotickets.com/v1"
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
OUTPUT_JS_PATH = os.path.join(BASE_DIR, "data.js")
# ================================================

def make_request(endpoint, params=None):
    """Executa chamadas HTTP autenticadas para a API da Yazo."""
    url = f"{BASE_URL}{endpoint}"
    if params:
        query_string = "&".join(f"{k}={v}" for k, v in params.items() if v is not None)
        url = f"{url}?{query_string}"

    headers = {
        "Authorization": f"Bearer {YAZO_API_TOKEN.strip()}",
        "Content-Type": "application/json",
        "Accept": "application/json",
        "User-Agent": "FestivalIguassuInova-Dashboard/1.0"
    }

    req = urllib.request.Request(url, headers=headers, method="GET")
    try:
        with urllib.request.urlopen(req, timeout=30) as response:
            return json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        error_body = e.read().decode("utf-8", errors="replace")
        print(f"❌ Erro HTTP {e.code} ao acessar {url}: {error_body}")
        if e.code == 401:
            print("👉 Verifique se o seu Token de API da Yazo está correto e ativo.")
        return None
    except Exception as e:
        print(f"❌ Falha de conexão: {e}")
        return None

def get_or_find_event_id():
    """Retorna o ID do festival listando os eventos caso não esteja pré-configurado."""
    global YAZO_EVENT_ID
    if YAZO_EVENT_ID:
        return YAZO_EVENT_ID

    print("🔍 Consultando lista de eventos na API Yazo (/v1/events)...")
    res = make_request("/events", {"size": 50})
    if not res:
        return None

    # Pode vir como list direto ou paginado { items: [...] }
    items = res.get("items", res) if isinstance(res, dict) else res
    if not items:
        print("⚠️ Nenhum evento retornado para este token.")
        return None

    print(f"📋 Encontrados {len(items)} eventos na conta:")
    for ev in items:
        ev_id = ev.get("id")
        ev_name = ev.get("name", "Sem nome")
        print(f"   • [{ev_id}] {ev_name}")
        if "IGUASSU" in ev_name.upper() or "INOVA" in ev_name.upper():
            YAZO_EVENT_ID = ev_id

    if YAZO_EVENT_ID:
        print(f"✅ Evento do Festival selecionado automaticamente: ID {YAZO_EVENT_ID}")
        return YAZO_EVENT_ID

    # Padrão: primeiro evento se nenhum bater por nome
    YAZO_EVENT_ID = items[0].get("id")
    print(f"ℹ️ Usando primeiro evento disponível: ID {YAZO_EVENT_ID}")
    return YAZO_EVENT_ID

def fetch_all_registrations(event_id):
    """Coleta todas as inscrições com paginação completa."""
    print(f"\n📥 Baixando inscrições do evento {event_id}...")
    page = 1
    size = 100
    all_registrations = []

    while True:
        print(f"   Carregando página {page}...", end="\r")
        data = make_request(f"/events/{event_id}/registrations", {"page": page, "size": size})
        if not data:
            break

        items = data.get("items", [])
        if not items:
            break

        all_registrations.extend(items)

        total_pages = data.get("totalPages", 1)
        if page >= total_pages or len(items) < size:
            break

        page += 1
        time.sleep(0.1) # Evita sobrecarga de requisições

    print(f"\n✅ Total de {len(all_registrations)} inscrições recebidas da API Yazo.")
    return all_registrations

def format_registration_records(raw_items):
    """Mapeia o retorno da API da Yazo para a estrutura utilizada pelo Dashboard."""
    records = []

    for item in raw_items:
        # Extrai usuário
        user = item.get("user") or {}
        name = user.get("name", "")
        email = user.get("email", "")

        # Extrai tipo de ingresso/inscrição
        ticket = item.get("ticket") or {}
        tipo = ticket.get("name") or item.get("ticketName") or "Geral"

        # Extrai perfil das respostas do formulário dinâmico
        perfil = "Não informado"
        form_answers = item.get("dynamicFormAnswers") or []
        for ans in form_answers:
            label = str(ans.get("label", "") or ans.get("question", "")).lower()
            if "perfil" in label or "representa" in label:
                perfil = ans.get("value") or ans.get("answer") or perfil
                break

        # Extrai e formata data/hora
        created_at_raw = item.get("createdAt") # Ex: 2026-08-25T19:16:00.000Z ou formato ISO
        if not created_at_raw:
            continue

        try:
            # Tenta parse ISO
            clean_ts = created_at_raw.replace("Z", "+00:00")
            dt = datetime.fromisoformat(clean_ts)
            # Converte para horário de Brasília / local caso venha UTC
            date_str = dt.strftime("%Y-%m-%d")
            date_br = dt.strftime("%d/%m/%Y")
            dt_display = dt.strftime("%d/%m/%Y %H:%M")
            hour = dt.hour
        except Exception:
            # Fallback se já vier como DD/MM/YYYY
            dt_display = str(created_at_raw)
            date_str = "2026-01-01"
            date_br = "01/01/2026"
            hour = 0

        records.append({
            "dt": dt_display,
            "date": date_str,
            "date_br": date_br,
            "hour": hour,
            "email": str(email).strip(),
            "name": str(name).strip(),
            "tipo": str(tipo).strip(),
            "perfil": str(perfil).strip()
        })

    # Ordena por data
    records.sort(key=lambda x: x["date"] + f" {x['hour']:02d}:00")
    return records

def sync():
    if YAZO_API_TOKEN == "COLOQUE_SEU_TOKEN_AQUI":
        print("="*70)
        print("⚠️ AVISO: É necessário informar o Token de API do Yazo Tickets.")
        print("👉 Edite este arquivo e coloque seu token na variável YAZO_API_TOKEN")
        print("   ou execute no terminal:")
        print("   set YAZO_API_TOKEN=seu_token_aqui && python sync_yazo_api.py")
        print("="*70)
        return False

    event_id = get_or_find_event_id()
    if not event_id:
        return False

    raw_items = fetch_all_registrations(event_id)
    if not raw_items:
        print("⚠️ Nenhuma inscrição para processar.")
        return False

    records = format_registration_records(raw_items)
    unique_emails = len(set(r["email"].lower() for r in records if r["email"]))

    payload = {
        "sync_source": "yazo_api",
        "sync_time": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "total_records": len(records),
        "unique_emails": unique_emails,
        "records": records
    }

    with open(OUTPUT_JS_PATH, "w", encoding="utf-8") as f:
        f.write("window.FESTIVAL_DATA = " + json.dumps(payload, ensure_ascii=False) + ";")

    print(f"\n🎉 Sucesso! Dashboard atualizado diretamente via API Yazo.")
    print(f"📊 {len(records)} inscrições ({unique_emails} e-mails únicos) salvas em {OUTPUT_JS_PATH}")
    return True

if __name__ == "__main__":
    sync()
