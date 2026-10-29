import subprocess
import json

def run_cmd(cmd):
    result = subprocess.run(cmd, shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True)
    if result.returncode != 0:
        return None
    return json.loads(result.stdout)

print("MySQL 클러스터 리스트 조회 중...")
clusters = run_cmd("/usr/local/bin/scp-cli mysql cluster list -f json")
dashboard_data = []

if clusters:
    for c in clusters:
        cluster_id = c.get("id")
        if not cluster_id: continue
        
        # 💡 에러가 났던 부분을 별도 변수로 안전하게 빼서 처리했습니다.
        c_name = c.get("name", "Unknown")
        print(f"상세 정보 추출 중: {c_name} ({cluster_id})")
        
        detail = run_cmd(f"/usr/local/bin/scp-cli mysql cluster show --cluster_id {cluster_id} -f json")
        
        if detail:
            engine = detail.get("dbaas_engine", "MySQL")
            cluster_name = detail.get("name", "")
            vip = detail.get("virtual_ip_address", "")
            port = detail.get("init_config_option", {}).get("database_port", "")
            cluster_state = detail.get("service_state", "")
            
            instance_groups = detail.get("instance_groups", [])
            spec = ""
            server_nodes = []
            
            if instance_groups:
                spec = instance_groups[0].get("server_type_name", "")
                for inst in instance_groups[0].get("instances", []):
                    server_nodes.append({
                        "server_name": inst.get("name", ""),
                        "role": inst.get("role_type", ""),
                        "ip": inst.get("service_ip_address", ""),
                        "status": inst.get("service_state", "")
                    })
            
            dashboard_data.append({
                "engine": engine,
                "cluster_name": cluster_name,
                "vip": vip,
                "port": port,
                "spec": spec,
                "cluster_state": cluster_state,
                "nodes": server_nodes
            })

with open("/var/www/html/mysql_dashboard_data.json", "w", encoding="utf-8") as f:
    json.dump(dashboard_data, f, indent=4, ensure_ascii=False)

print("✅ 데이터 추출 완료! 결과가 /var/www/html/mysql_dashboard_data.json 에 저장되었습니다.")
