#!/usr/bin/env python3
import subprocess
import json
import csv
import os
from datetime import datetime

CLI_CMD = "/usr/local/bin/scp-cli"
DIR_PATH = "/opt/scp"
PREV_STATE_FILE = f"{DIR_PATH}/fw_prev_state.json"
CHANGE_LOG_CSV = f"{DIR_PATH}/fw_change_log.csv"

def run_cli(command):
    try:
        full_cmd = f"{CLI_CMD} {command} -f json"
        result = subprocess.run(full_cmd, shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True, check=True)
        return json.loads(result.stdout)
    except Exception as e:
        return None

def main():
    print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] 🚀 방화벽(IGW/LB) 변경점 추적 시작...")
    
    fw_list_response = run_cli("firewall firewall list")
    if not fw_list_response:
        print("방화벽 리스트를 가져오지 못했습니다.")
        return

    fw_list = fw_list_response.get("contents", fw_list_response) if isinstance(fw_list_response, dict) else fw_list_response
    
    current_state = {}
    
    print(f"총 {len(fw_list)}개의 방화벽을 찾았습니다. 상세 룰 정보를 수집합니다. (조금만 기다려주세요!)")
    
    for idx, fw in enumerate(fw_list, 1):
        fw_id = fw.get("firewallId", fw.get("id", ""))
        fw_name = fw.get("firewallName", fw.get("name", ""))
        fw_state = fw.get("firewallState", fw.get("state", "UNKNOWN"))
        modified_at = fw.get("modifiedDt", fw.get("modified_at", fw.get("createdDt", "")))
        
        if not fw_id:
            continue
            
        # 💡 답답함 해소용: 실시간 진행 상황 화면 출력
        print(f" ⏳ [{idx}/{len(fw_list)}] {fw_name} 규칙 조회 중...")
        
        rule_response = run_cli(f"firewall firewall-rule list --firewall_id {fw_id}")
        rules = rule_response.get("contents", rule_response) if isinstance(rule_response, dict) and rule_response else []
        
        current_state[fw_id] = {
            "name": fw_name,
            "type": "LB Firewall" if "LB" in fw_name.upper() else "IGW Firewall",
            "state": fw_state,
            "modified_at": modified_at,
            "rules": rules
        }

    prev_state = {}
    if os.path.exists(PREV_STATE_FILE):
        with open(PREV_STATE_FILE, "r", encoding="utf-8") as f:
            prev_state = json.load(f)

    changes_detected = []
    write_header = not os.path.exists(CHANGE_LOG_CSV)
    
    with open(CHANGE_LOG_CSV, "a", encoding="utf-8-sig", newline="") as f:
        writer = csv.writer(f)
        if write_header:
            writer.writerow(["감지 일시", "방화벽명", "유형", "상태", "마지막 수정일", "변경 내용", "Jira 티켓 번호(수기/연동용)"])
            
        for fw_id, curr_data in current_state.items():
            if fw_id not in prev_state:
                change_msg = "신규 방화벽 추가"
                writer.writerow([datetime.now().strftime('%Y-%m-%d %H:%M:%S'), curr_data['name'], curr_data['type'], curr_data['state'], curr_data['modified_at'], change_msg, ""])
                changes_detected.append(f"[{curr_data['name']}] {change_msg}")
            else:
                prev_data = prev_state[fw_id]
                if curr_data['rules'] != prev_data['rules'] or curr_data['modified_at'] != prev_data['modified_at']:
                    change_msg = "방화벽 Rule 수정/삭제 감지"
                    writer.writerow([datetime.now().strftime('%Y-%m-%d %H:%M:%S'), curr_data['name'], curr_data['type'], curr_data['state'], curr_data['modified_at'], change_msg, ""])
                    changes_detected.append(f"[{curr_data['name']}] {change_msg}")
                    
        for fw_id, prev_data in prev_state.items():
            if fw_id not in current_state:
                change_msg = "방화벽 삭제 감지"
                writer.writerow([datetime.now().strftime('%Y-%m-%d %H:%M:%S'), prev_data['name'], prev_data['type'], "DELETED", datetime.now().strftime('%Y-%m-%d %H:%M:%S'), change_msg, ""])
                changes_detected.append(f"[{prev_data['name']}] {change_msg}")

    with open(PREV_STATE_FILE, "w", encoding="utf-8") as f:
        json.dump(current_state, f, ensure_ascii=False, indent=4)
        
    if changes_detected:
        print(f"\n⚠️ 변경점 {len(changes_detected)}건 감지되어 {CHANGE_LOG_CSV}에 기록되었습니다!")
        for msg in changes_detected:
            print(f" - {msg}")
    else:
        print("\n✅ 이전과 비교하여 변경된 방화벽 내역이 없습니다. (종료)")

if __name__ == "__main__":
    main()
