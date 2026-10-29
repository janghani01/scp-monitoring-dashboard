#!/bin/bash

OUTPUT_FILE="scp_all_services_ip_list.csv"
echo "SERVICE_CATEGORY,RESOURCE_NAME,IP_ADDRESS,IP_TYPE,BOUND_PRIVATE_IP,VPC_ID,STATE" > $OUTPUT_FILE

echo "=================================================================="
echo "Security Group(SG) 수립용 SCP 전체 리소스 IP 전수 추출 시작"
echo "=================================================================="

# 1. Virtual Server (Compute)
echo "[1/6] Compute (Virtual Server) 사설/공인 IP 추출 중..."
VS_LIST=$(timeout 10 scp-cli virtualserver server list -f json < /dev/null 2>/dev/null)

if [ -n "$VS_LIST" ] && [ "$VS_LIST" != "[]" ]; then
    TMP_VS=$(mktemp)
    echo "$VS_LIST" | jq -c '.[]' 2>/dev/null > "$TMP_VS"

    while read -r r; do
        VS_ID=$(echo "$r" | jq -r '.id // .virtual_server_id // empty')
        NAME=$(echo "$r" | jq -r '.name // .server_name // "-"')
        VPC=$(echo "$r" | jq -r '.vpc_id // "-"')
        STATE=$(echo "$r" | jq -r '.state // .status // "-"')

        if [ -n "$VS_ID" ]; then
            DETAIL=$(timeout 5 scp-cli virtualserver server show --virtual_server_id "$VS_ID" -f json < /dev/null 2>/dev/null)
            [ -z "$DETAIL" ] && DETAIL=$(timeout 5 scp-cli virtualserver server show --id "$VS_ID" -f json < /dev/null 2>/dev/null)

            if [ -n "$DETAIL" ]; then
                PRIV_IPS=$(echo "$DETAIL" | jq -r '.. | .private_ip? // .ip_address? // .ip? // empty' | grep -v '^null$' | grep -v '^$' | sort -u)
                PUB_IPS=$(echo "$DETAIL" | jq -r '.. | .public_ip? // .public_ip_address? // empty' | grep -v '^null$' | grep -v '^$' | sort -u)

                for ip in $PRIV_IPS; do
                    echo "\"VirtualServer\",\"$NAME\",\"$ip\",\"Private_IP\",\"-\",\"$VPC\",\"$STATE\"" >> $OUTPUT_FILE
                done
                for ip in $PUB_IPS; do
                    echo "\"VirtualServer\",\"$NAME\",\"$ip\",\"Public_IP\",\"-\",\"$VPC\",\"$STATE\"" >> $OUTPUT_FILE
                done
            fi
        fi
    done < "$TMP_VS"
    rm -f "$TMP_VS"
fi

# 2. Kubernetes Engine (SKE: Cluster & Worker Nodes)
echo "[2/6] Kubernetes Engine (SKE) 클러스터 및 워커노드 IP 추출 중..."
SKE_LIST=$(timeout 10 scp-cli ske cluster list -f json < /dev/null 2>/dev/null)

if [ -n "$SKE_LIST" ] && [ "$SKE_LIST" != "[]" ]; then
    TMP_SKE=$(mktemp)
    echo "$SKE_LIST" | jq -c '.[]' 2>/dev/null > "$TMP_SKE"

    while read -r r; do
        CLUSTER_ID=$(echo "$r" | jq -r '.id // .cluster_id // empty')
        NAME=$(echo "$r" | jq -r '.name // .cluster_name // "-"')
        VPC=$(echo "$r" | jq -r '.vpc_id // "-"')
        STATE=$(echo "$r" | jq -r '.state // .status // "-"')

        ENDPOINTS=$(echo "$r" | jq -r '.. | .endpoint? // .vip? // .ip_address? // empty' | grep -v '^null$' | grep -v '^$' | sort -u)
        for ip in $ENDPOINTS; do
            echo "\"SKE (Kubernetes)\",\"$NAME (Cluster)\",\"$ip\",\"Cluster_Endpoint\",\"-\",\"$VPC\",\"$STATE\"" >> $OUTPUT_FILE
        done

        if [ -n "$CLUSTER_ID" ]; then
            NP_NODES=$(timeout 5 scp-cli ske nodepool node list --ske_cluster_id "$CLUSTER_ID" -f json < /dev/null 2>/dev/null)
            [ -z "$NP_NODES" ] && NP_NODES=$(timeout 5 scp-cli ske nodepool node list --cluster_id "$CLUSTER_ID" -f json < /dev/null 2>/dev/null)

            if [ -n "$NP_NODES" ] && [ "$NP_NODES" != "[]" ]; then
                NODE_IPS=$(echo "$NP_NODES" | jq -r '.. | .private_ip? // .ip_address? // .ip? // empty' | grep -v '^null$' | grep -v '^$' | sort -u)
                for ip in $NODE_IPS; do
                    echo "\"SKE (K8s Node)\",\"$NAME (Node)\",\"$ip\",\"Private_IP\",\"-\",\"$VPC\",\"ACTIVE\"" >> $OUTPUT_FILE
                done
            fi
        fi
    done < "$TMP_SKE"
    rm -f "$TMP_SKE"
fi

# 3. Public IP 전수 조사 (사설 IP 매핑)
echo "[3/6] Public IP 및 연결된 사설 IP(Private IP) 매핑 추출 중..."
PIP_LIST=$(timeout 10 scp-cli vpc public-ip list -f json < /dev/null 2>/dev/null)

if [ -n "$PIP_LIST" ] && [ "$PIP_LIST" != "[]" ]; then
    echo "$PIP_LIST" | jq -c '.[]' 2>/dev/null | while read -r r; do
        PUB_IP=$(echo "$r" | jq -r '.public_ip // .ip_address // empty')
        BOUND_PRIV_IP=$(echo "$r" | jq -r '.private_ip // .attached_ip // .instance_ip // .ip // "-"')
        BOUND_RES_NAME=$(echo "$r" | jq -r '.instance_name // .server_name // .attached_resource_name // .name // "-"')
        VPC=$(echo "$r" | jq -r '.vpc_id // "-"')
        STATE=$(echo "$r" | jq -r '.state // .status // "-"')

        [ -n "$PUB_IP" ] && [ "$PUB_IP" != "null" ] && echo "\"Public IP\",\"$BOUND_RES_NAME\",\"$PUB_IP\",\"Public_IP\",\"$BOUND_PRIV_IP\",\"$VPC\",\"$STATE\"" >> $OUTPUT_FILE
    done
fi

# 4. Database Services & CacheStore (DBaaS)
echo "[4/6] Database / Cache Services (MySQL, Postgres, MariaDB 등) 추출 중..."
DB_ENGINES=("mysql" "postgresql" "mariadb" "epas" "sqlserver" "cachestore")

for engine in "${DB_ENGINES[@]}"; do
    DB_LIST=$(timeout 10 scp-cli $engine cluster list -f json < /dev/null 2>/dev/null)

    if [ -n "$DB_LIST" ] && [ "$DB_LIST" != "[]" ]; then
        TMP_DB=$(mktemp)
        echo "$DB_LIST" | jq -c '.[]' 2>/dev/null > "$TMP_DB"

        while read -r r; do
            DB_ID=$(echo "$r" | jq -r '.id // .db_cluster_id // empty')
            NAME=$(echo "$r" | jq -r '.name // .db_cluster_name // "-"')
            VPC=$(echo "$r" | jq -r '.vpc_id // "-"')
            STATE=$(echo "$r" | jq -r '.service_state // .state // "-"')

            if [ -n "$DB_ID" ]; then
                DETAIL=$(timeout 5 scp-cli $engine cluster show --db_cluster_id "$DB_ID" -f json < /dev/null 2>/dev/null)
                [ -z "$DETAIL" ] && DETAIL=$(timeout 5 scp-cli $engine cluster show --id "$DB_ID" -f json < /dev/null 2>/dev/null)

                if [ -n "$DETAIL" ]; then
                    ALL_DB_IPS=$(echo "$DETAIL" | jq -r '.. | .ip_address? // .vip? // .private_ip? // .endpoint? // empty' | grep -v '^null$' | grep -v '^$' | sort -u)
                    for ip in $ALL_DB_IPS; do
                        echo "\"Database ($engine)\",\"$NAME\",\"$ip\",\"DB_VIP/Endpoint\",\"-\",\"$VPC\",\"$STATE\"" >> $OUTPUT_FILE
                    done
                fi
            fi
        done < "$TMP_DB"
        rm -f "$TMP_DB"
    fi
done

# 5. Data Analytics
echo "[5/6] Data Analytics (SearchEngine, Vertica, EventStreams) 추출 중..."
ANALYTICS_ENGINES=("searchengine" "vertica" "eventstreams")

for ana in "${ANALYTICS_ENGINES[@]}"; do
    ANA_LIST=$(timeout 10 scp-cli $ana cluster list -f json < /dev/null 2>/dev/null)

    if [ -n "$ANA_LIST" ] && [ "$ANA_LIST" != "[]" ]; then
        TMP_ANA=$(mktemp)
        echo "$ANA_LIST" | jq -c '.[]' 2>/dev/null > "$TMP_ANA"

        while read -r r; do
            ANA_ID=$(echo "$r" | jq -r '.id // empty')
            NAME=$(echo "$r" | jq -r '.name // "-"')
            VPC=$(echo "$r" | jq -r '.vpc_id // "-"')
            STATE=$(echo "$r" | jq -r '.state // "-"')

            if [ -n "$ANA_ID" ]; then
                DETAIL=$(timeout 5 scp-cli $ana cluster show --id "$ANA_ID" -f json < /dev/null 2>/dev/null)
                if [ -n "$DETAIL" ]; then
                    ALL_ANA_IPS=$(echo "$DETAIL" | jq -r '.. | .ip_address? // .vip? // .private_ip? // .endpoint? // empty' | grep -v '^null$' | grep -v '^$' | sort -u)
                    for ip in $ALL_ANA_IPS; do
                        echo "\"Analytics ($ana)\",\"$NAME\",\"$ip\",\"Analytics_VIP/Endpoint\",\"-\",\"$VPC\",\"$STATE\"" >> $OUTPUT_FILE
                    done
                fi
            fi
        done < "$TMP_ANA"
        rm -f "$TMP_ANA"
    fi
done

# 6. Load Balancer
echo "[6/6] Load Balancer VIP / SNAT IP 추출 중..."
LB_LIST=$(timeout 10 scp-cli loadbalancer loadbalancer list -f json < /dev/null 2>/dev/null)

if [ -n "$LB_LIST" ] && [ "$LB_LIST" != "[]" ]; then
    echo "$LB_LIST" | jq -c '.[]' 2>/dev/null | while read -r r; do
        NAME=$(echo "$r" | jq -r '.name // "-"')
        VIP=$(echo "$r" | jq -r '.service_ip // .vip // empty')
        SNAT_IP=$(echo "$r" | jq -r '.source_nat_ip // empty')
        VPC=$(echo "$r" | jq -r '.vpc_id // "-"')
        STATE=$(echo "$r" | jq -r '.state // "-"')

        [ -n "$VIP" ] && [ "$VIP" != "null" ] && echo "\"LoadBalancer\",\"$NAME\",\"$VIP\",\"Service_VIP\",\"-\",\"$VPC\",\"$STATE\"" >> $OUTPUT_FILE
        [ -n "$SNAT_IP" ] && [ "$SNAT_IP" != "null" ] && echo "\"LoadBalancer\",\"$NAME\",\"$SNAT_IP\",\"Source_NAT_IP\",\"-\",\"$VPC\",\"$STATE\"" >> $OUTPUT_FILE
    done
fi

sort -u $OUTPUT_FILE -o $OUTPUT_FILE

echo "------------------------------------------------------------------"
echo "✓ 전체 자산 IP 전수 정밀 추출 완료! 파일명: $OUTPUT_FILE"
echo "------------------------------------------------------------------"
