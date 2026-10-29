#!/usr/bin/env python3
import json
import os
from datetime import datetime

SERVERS_FILE = '/opt/scp/servers.json'           # API로 계속 갱신되는 원본 파일
KNOWN_SERVERS_FILE = '/opt/scp/known_servers.json' # 봇이 기억하는 과거 목록
DELETED_HISTORY_FILE = '/opt/scp/deleted_history.json' # 지워진 이력 무덤 파일
CREATED_HISTORY_FILE = '/opt/scp/created_history.json' # 💡 새로 추가: 생성된 이력 보관 파일

def main():
    # 1. 현재 살아있는 서버 목록 읽기
    try:
        with open(SERVERS_FILE, 'r', encoding='utf-8') as f:
            current_data = json.load(f)
            current_servers = {s['id']: s for s in current_data.get('servers', [])}
    except Exception:
        return

    # 2. 봇이 예전에 기억해둔 과거 서버 목록 읽기
    known_servers = {}
    if os.path.exists(KNOWN_SERVERS_FILE):
        with open(KNOWN_SERVERS_FILE, 'r', encoding='utf-8') as f:
            known_servers = json.load(f)

    # 3. 삭제 내역 / 생성 내역 파일 읽기
    deleted_history = []
    if os.path.exists(DELETED_HISTORY_FILE):
        with open(DELETED_HISTORY_FILE, 'r', encoding='utf-8') as f:
            deleted_history = json.load(f)

    created_history = []
    if os.path.exists(CREATED_HISTORY_FILE):
        with open(CREATED_HISTORY_FILE, 'r', encoding='utf-8') as f:
            created_history = json.load(f)

    # 4. 비교 시작! 
    newly_deleted = False
    newly_created = False
    now_str = datetime.now().strftime("%Y-%m-%dT%H:%M:%SZ")

    # [삭제 감지] 과거엔 있었는데 지금은 없으면 지워진 것
    for s_id, s_info in known_servers.items():
        if s_id not in current_servers:
            s_info['event_time'] = now_str
            s_info['status'] = '삭제'
            deleted_history.append(s_info)
            newly_deleted = True
            print(f"[삭제 감지] 서버명: {s_info.get('name')} (ID: {s_id}) - {now_str}")

    # 💡 [생성 감지] 지금은 있는데 과거엔 없었으면 새로 생성된 것
    for s_id, s_info in current_servers.items():
        if s_id not in known_servers:
            s_info['event_time'] = now_str
            s_info['status'] = '생성'
            created_history.append(s_info)
            newly_created = True
            print(f"[생성 감지] 서버명: {s_info.get('name')} (ID: {s_id}) - {now_str}")

    # 5. 변경된 이력이 있다면 각각의 파일에 누적 저장
    if newly_deleted:
        with open(DELETED_HISTORY_FILE, 'w', encoding='utf-8') as f:
            json.dump(deleted_history, f, ensure_ascii=False, indent=2)
            
    if newly_created:
        with open(CREATED_HISTORY_FILE, 'w', encoding='utf-8') as f:
            json.dump(created_history, f, ensure_ascii=False, indent=2)

    # 6. 다음 비교를 위해 현재 상태를 '과거 목록'으로 저장
    with open(KNOWN_SERVERS_FILE, 'w', encoding='utf-8') as f:
        json.dump(current_servers, f, ensure_ascii=False, indent=2)

if __name__ == '__main__':
    main()
