#!/bin/bash

ACCESS_KEY="7a59f8d6f7e9448681c15273cb9bd989"
SECRET_KEY="02c3b9a9-8e56-449d-9ac7-60589ae30399"

METHOD="GET"
HOST="baremetal.kr-west1.e.samsungsdscloud.com"
URI="/v1/baremetals"
URL="https://${HOST}${URI}"

TIMESTAMP=$(date +%s%3N)

MESSAGE="${METHOD}${URL}${TIMESTAMP}${ACCESS_KEY}Openapi"

SIGNATURE=$(printf "%s" "$MESSAGE" \
 | openssl dgst -sha256 -hmac "$SECRET_KEY" -binary \
 | openssl base64)

curl -s "$URL" \
 -H "Scp-Accesskey: $ACCESS_KEY" \
 -H "Scp-Signature: $SIGNATURE" \
 -H "Scp-Timestamp: $TIMESTAMP" \
 -H "Scp-ClientType: Openapi" \
 -H "Scp-Api-Version: baremetal 1.1" \
 -H "Accept: application/json"
