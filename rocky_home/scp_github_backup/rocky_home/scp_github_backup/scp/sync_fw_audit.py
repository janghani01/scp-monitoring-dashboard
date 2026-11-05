#!/usr/bin/env python3
import subprocess
import json
import csv
import os
import ast
from datetime import datetime

CLI_CMD = "/usr/local/bin/scp-cli"
DIR_PATH = "/opt/scp"
PREV_STATE_FILE = f"{DIR_PATH}/fw_prev_state.json"
CHANGE_LOG_CSV = f"{DIR_PATH}/fw_change_log.csv"
IAM_JSON_FILE = f"{DIR_PATH}/iam_current.json"

def run_cli(command):
    try:
        full_cmd = f"{CLI_CMD} {command} -f json"
        result = subprocess.run(full_cmd, shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True, check=True)
        return json.loads(result.stdout)
    except Exception as e:
        return None

def load_iam_mapping():
    mapping = {}
    if os.path.exists(IAM_JSON_FILE):
        try:
            with open(IAM_JSON_FILE, "r", encoding="utf-8") as f:
                iam_data = json.load(f)
            items = iam_data.get('contents', iam_data.get('users', iam_data.get('items', iam_data)))
            if isinstance(items, list):
                for item in items:
                    u_id = item.get('userId', item.get('user_id', item.get('id', '')))
                    u_name = item.get('userName', item.get('user_name', item.get('name', '')))
                    if u_id and u_name:
                        mapping[u_id] = u_name
        except Exception:
            pass
    return mapping

def parse_fw_field(field_data):
    if isinstance(field_data, str) and (field_data.startswith('[') or field_data.startswith('{')):
        try:
            return ast.literal_eval(field_data)
        except:
            pass
    return field_data

def get_ports_set(rule):
    svcs = parse_fw_field(rule.get('service', []))
    ports = set()
    if isinstance(svcs, list):
        for s in svcs:
            if isinstance(s, dict):
                v = s.get('service_value', s.get('serviceValue', ''))
                if v: ports.add(str(v))
    return ports

def get_ip_set(rule, key):
    ip_field = parse_fw_field(rule.get(key, []))
    if isinstance(ip_field, list):
        return set([str(i) for i in ip_field])
    elif ip_field:
        return set([str(ip_field)])
    return set()

def get_rule_diff(prev_rules, curr_rules):
    """문자열 1개가 아닌, 개별 컬럼(순서, ID, 내용)을 딕셔너리로 분리하여 리스트로 반환"""
    if not isinstance(prev_rules, list): prev_rules = []
    if not isinstance(curr_rules, list): curr_rules = []
    
    prev_map = {str(r.get('id', '')): r for r in prev_rules if r.get('id')}
    curr_map = {str(r.get('id', '')): r for r in curr_rules if r.get('id')}
    
    diff_list = []
    
    for r_id, curr_r in curr_map.items():
        seq = curr_r.get('sequence', '-')
        short_id = str(r_id)[:8] + "..." if len(str(r_id)) > 8 else str(r_id)
        
        if r_id not in prev_map:
            ports = ",".join(get_ports_set(curr_r)) or "ALL"
            direction = curr_r.get('direction', 'INBOUND')
            diff_list.append({'seq': seq, 'id': short_id, 'msg': f"[추가] {direction} 포트({ports})"})
        else:
            prev_r = prev_map[r_id]
            
            prev_ports = get_ports_set(prev_r)
            curr_ports = get_ports_set(curr_r)
            prev_src = get_ip_set(prev_r, 'source_address')
            curr_src = get_ip_set(curr_r, 'source_address')
            prev_dst = get_ip_set(prev_r, 'destination_address')
            curr_dst = get_ip_set(curr_r, 'destination_address')
            
            changes = []
            
            added_ports = curr_ports - prev_ports
            del_ports = prev_ports - curr_ports
            if added_ports: changes.append(f"+포트({','.join(added_ports)})")
            if del_ports: changes.append(f"-포트({','.join(del_ports)})")
            
            added_src = curr_src - prev_src
            del_src = prev_src - curr_src
            if added_src: changes.append(f"+출발지({','.join(added_src)})")
            if del_src: changes.append(f"-출발지({','.join(del_src)})")
            
            added_dst = curr_dst - prev_dst
            del_dst = prev_dst - curr_dst
            if added_dst: changes.append(f"+도착지({','.join(added_dst)})")
            if del_dst: changes.append(f"-도착지({','.join(del_dst)})")
            
            p_act = prev_r.get('action', '')
            c_act = curr_r.get('action', '')
            if p_act != c_act:
                changes.append(f"액션({p_act}->{c_act})")
                
            if changes:
                diff_list.append({'seq': seq, 'id': short_id, 'msg': f"[수정] " + " / ".join(changes)})
                
    for r_id, prev_r in prev_map.items():
        if r_id not in curr_map:
            seq = prev_r.get('sequence', '-')
            short_id = str(r_id)[:8] + "..." if len(str(r_id)) > 8 else str(r_id)
            ports = ",".join(get_ports_set(prev_r)) or "ALL"
            direction = prev_r.get('direction', 'INBOUND')
            diff_list.append({'seq': seq, 'id': short_id, 'msg': f"[삭제] {direction} 포트({ports})"})
            
    return diff_list

def extract_rules(response):
    if not response: return []
    if isinstance(response, list): return response
    if isinstance(response, dict):
        for k in ['contents', 'rules', 'firewallRules', 'items']:
            if k in response:
                return response[k]
    return []

def main():
    print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] 🚀 방화벽 상세 변경점(Rule Diff) 추적 시작...")
    
    iam_map = load_iam_mapping()
    fw_list_response = run_cli("firewall firewall list")
    if not fw_list_response: return

    fw_list = extract_rules(fw_list_response)
    current_state = {}
    
    for idx, fw in enumerate(fw_list, 1):
        fw_id = fw.get("firewallId", fw.get("id", ""))
        fw_name = fw.get("firewallName", fw.get("name", ""))
        fw_state = fw.get("firewallState", fw.get("state", "UNKNOWN"))
        modified_at = fw.get("modifiedDt", fw.get("modified_at", fw.get("createdDt", "")))
        
        raw_modified_by = fw.get("modifiedBy", fw.get("modified_by", fw.get("createdBy", "System"))) 
        modified_by = iam_map.get(raw_modified_by, raw_modified_by)
        
        if not fw_id: continue
        rule_response = run_cli(f"firewall firewall-rule list --firewall_id {fw_id}")
        rules = extract_rules(rule_response)
        
        current_state[fw_id] = {
            "name": fw_name,
            "type": "LB Firewall" if "LB" in fw_name.upper() else "IGW Firewall",
            "state": fw_state,
            "modified_at": modified_at,
            "modified_by": modified_by,
            "rules": rules
        }

    prev_state = {}
    if os.path.exists(PREV_STATE_FILE):
        with open(PREV_STATE_FILE, "r", encoding="utf-8") as f:
            prev_state = json.load(f)

    changes_detected = []
    
    if not prev_state:
        print("초기 상태를 기준점으로 저장합니다. 다음 실행부터 변경점이 감지됩니다.")
    else:
        write_header = not os.path.exists(CHANGE_LOG_CSV)
        with open(CHANGE_LOG_CSV, "a", encoding="utf-8-sig", newline="") as f:
            writer = csv.writer(f)
            if write_header:
                # 💡 엑셀 컬럼 2개(규칙 순서, 규칙 ID) 분리 및 추가
                writer.writerow(["감지 일시", "방화벽명", "유형", "상태", "수정자", "마지막 수정일", "규칙 순서", "규칙 ID", "변경 내용", "Jira 티켓 번호"])
                
            for fw_id, curr_data in current_state.items():
                if fw_id not in prev_state:
                    change_msg = "신규 방화벽 생성됨"
                    writer.writerow([datetime.now().strftime('%Y-%m-%d %H:%M:%S'), curr_data['name'], curr_data['type'], curr_data['state'], curr_data['modified_by'], curr_data['modified_at'], "-", "-", change_msg, ""])
                    changes_detected.append(f"[{curr_data['name']}] {change_msg}")
                else:
                    prev_data = prev_state[fw_id]
                    if curr_data['rules'] != prev_data['rules'] or curr_data['modified_at'] != prev_data['modified_at']:
                        diff_list = get_rule_diff(prev_data['rules'], curr_data['rules'])
                        if not diff_list:
                            diff_list = [{'seq': '-', 'id': '-', 'msg': '방화벽 속성 및 메타데이터 변경'}]
                            
                        for d in diff_list:
                            # 💡 분리된 컬럼 데이터를 각각의 열에 맞게 기록
                            writer.writerow([datetime.now().strftime('%Y-%m-%d %H:%M:%S'), curr_data['name'], curr_data['type'], curr_data['state'], curr_data['modified_by'], curr_data['modified_at'], d['seq'], d['id'], d['msg'], ""])
                            changes_detected.append(f"[{curr_data['name']}] {d['msg']}")
                        
            for fw_id, prev_data in prev_state.items():
                if fw_id not in current_state:
                    change_msg = "방화벽 전체 삭제 감지"
                    writer.writerow([datetime.now().strftime('%Y-%m-%d %H:%M:%S'), prev_data['name'], prev_data['type'], "DELETED", prev_data.get('modified_by', 'System'), datetime.now().strftime('%Y-%m-%d %H:%M:%S'), "-", "-", change_msg, ""])
                    changes_detected.append(f"[{prev_data['name']}] {change_msg}")

    with open(PREV_STATE_FILE, "w", encoding="utf-8") as f:
        json.dump(current_state, f, ensure_ascii=False, indent=4)
        
    if changes_detected:
        print(f"\n⚠️ 변경점 {len(changes_detected)}건 감지되어 {CHANGE_LOG_CSV}에 기록되었습니다!")
    else:
        print("\n✅ 이전과 비교하여 변경된 방화벽 룰 내역이 없습니다.")

if __name__ == "__main__":
    main()
