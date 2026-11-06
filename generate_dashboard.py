"""
Script de processamento e consolidação de dados para o Dashboard Festival Iguassu Inova 2026.
Lê o arquivo 'inscritos festival.csv' (mesmo com encoding cp1252 / latin1 e cabeçalhos de metadados)
e gera o arquivo 'data.js' estruturado para consumo no painel.
"""

import io
import json
import os
import sys
import pandas as pd

def process_csv(csv_path, output_js_path):
    print(f"Lendo arquivo: {csv_path} ...")
    if not os.path.exists(csv_path):
        print(f"Erro: Arquivo não encontrado: {csv_path}")
        return False

    with open(csv_path, 'rb') as f:
        raw_bytes = f.read()

    # Tenta decodificar com cp1252, fallback latin1
    try:
        text = raw_bytes.decode('cp1252')
    except UnicodeDecodeError:
        text = raw_bytes.decode('latin1', errors='replace')

    lines = text.splitlines()
    header_idx = None
    for i, line in enumerate(lines):
        if 'createdAt' in line:
            header_idx = i
            break

    if header_idx is None:
        print("Erro: Cabeçalho 'createdAt' não encontrado no CSV.")
        return False

    df = pd.read_csv(io.StringIO('\n'.join(lines[header_idx:])), sep=';')
    df.columns = ['createdAt', 'email', 'name', 'tipo_inscricao', 'perfil']
    df['dt'] = pd.to_datetime(df['createdAt'], format='%d/%m/%Y %H:%M', errors='coerce')
    
    # Remove linhas inválidas de data se houver
    df = df.dropna(subset=['dt'])
    df = df.sort_values(by='dt')
    
    df['date'] = df['dt'].dt.strftime('%Y-%m-%d')
    df['hour'] = df['dt'].dt.hour
    df['date_br'] = df['dt'].dt.strftime('%d/%m/%Y')
    
    records = []
    for _, r in df.iterrows():
        records.append({
            'dt': r['createdAt'],
            'date': r['date'],
            'date_br': r['date_br'],
            'hour': int(r['hour']),
            'email': str(r['email']).strip() if pd.notna(r['email']) else '',
            'name': str(r['name']).strip() if pd.notna(r['name']) else '',
            'tipo': str(r['tipo_inscricao']).strip() if pd.notna(r['tipo_inscricao']) else '',
            'perfil': str(r['perfil']).strip() if pd.notna(r['perfil']) else ''
        })

    payload = {
        'total_records': len(records),
        'unique_emails': int(df['email'].nunique()),
        'min_date': df['date'].min(),
        'max_date': df['date'].max(),
        'records': records
    }

    with open(output_js_path, 'w', encoding='utf-8') as f:
        f.write('window.FESTIVAL_DATA = ' + json.dumps(payload, ensure_ascii=False) + ';')

    print(f"Sucesso! {len(records)} registros exportados para: {output_js_path}")
    return True

if __name__ == '__main__':
    base_dir = os.path.dirname(os.path.abspath(__file__))
    csv_file = os.path.join(base_dir, 'inscritos festival.csv')
    out_file = os.path.join(base_dir, 'data.js')
    process_csv(csv_file, out_file)
