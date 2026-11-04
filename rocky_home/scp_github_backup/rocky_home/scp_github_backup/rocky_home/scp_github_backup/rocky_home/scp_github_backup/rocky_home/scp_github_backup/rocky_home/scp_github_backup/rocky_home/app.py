from flask import Flask
import requests
import time
import uuid
import hmac
import hashlib
import json
import ssl
from requests.adapters import HTTPAdapter
from urllib3.poolmanager import PoolManager

app = Flask(__name__)

# =========================================================
# SCP Enterprise 정보
# =========================================================
ACCESS_KEY = "7a59f8d6f7e9448681c15273cb9bd989"
SECRET_KEY = "02c3b9a9-8e56-449d-9ac7-60589ae30399"

HOST = "kr-west1.api-enterprise.samsungcloudplatform.com"

BASE_URL = f"https://{HOST}"
URI = "/v1/servers"
QUERY = "limit=5"

# =========================================================
# TLS 강제 SNI Adapter
# =========================================================
class TLSAdapter(HTTPAdapter):

    def init_poolmanager(self, *args, **kwargs):

        ctx = ssl.create_default_context()

        ctx.set_ciphers("DEFAULT")

        ctx.check_hostname = True

        ctx.verify_mode = ssl.CERT_REQUIRED

        self.poolmanager = PoolManager(
            *args,
            ssl_context=ctx,
            server_hostname=HOST,
            **kwargs
        )

# =========================================================
# Signature 생성
# =========================================================
def make_signature():

    nonce = str(uuid.uuid4())

    timestamp = str(int(time.time() * 1000))

    canonical_request = (
        f"GET\n"
        f"{URI}\n"
        f"{QUERY}\n"
        f"content-type:application/json\n"
        f"host:{HOST}\n"
        f"x-auth-accesskey:{ACCESS_KEY}\n"
        f"x-auth-nonce:{nonce}\n"
        f"x-auth-timestamp:{timestamp}\n"
        f"\n"
        f"content-type;host;x-auth-accesskey;x-auth-nonce;x-auth-timestamp\n"
        f"e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
    )

    hashed_request = hashlib.sha256(
        canonical_request.encode()
    ).hexdigest()

    string_to_sign = (
        f"SCP-HMAC-SHA256\n"
        f"{timestamp}\n"
        f"{hashed_request}"
    )

    signature = hmac.new(
        SECRET_KEY.encode(),
        string_to_sign.encode(),
        hashlib.sha256
    ).digest()

    import base64

    signature = base64.b64encode(signature).decode()

    return signature, nonce, timestamp, canonical_request, string_to_sign

# =========================================================
# API 호출
# =========================================================
def get_servers():

    signature, nonce, timestamp, canonical, sts = make_signature()

    headers = {
        "Host": HOST,
        "Content-Type": "application/json",
        "Accept": "application/json",
        "X-Auth-AccessKey": ACCESS_KEY,
        "X-Auth-Nonce": nonce,
        "X-Auth-Timestamp": timestamp,
        "X-Auth-Signature": signature
    }

    url = f"{BASE_URL}{URI}?{QUERY}"

    print("=" * 80)
    print("REQUEST URL")
    print(url)

    print("\nCANONICAL REQUEST")
    print(canonical)

    print("\nSTRING TO SIGN")
    print(sts)

    print("\nHEADERS")
    print(json.dumps(headers, indent=4))
    print("=" * 80)

    session = requests.Session()

    session.mount("https://", TLSAdapter())

    try:

        response = session.get(
            url,
            headers=headers,
            timeout=30
        )

        print("\nHTTP STATUS :", response.status_code)

        print("\nRAW RESPONSE")
        print(response.text)

        return response.text

    except Exception as e:

        print("\nEXCEPTION :", str(e))

        return str(e)

# =========================================================
# Flask
# =========================================================
@app.route("/")
def home():

    result = get_servers()

    return f"<pre>{result}</pre>"

# =========================================================
# 실행
# =========================================================
if __name__ == "__main__":

    print("=" * 80)
    print("PYTHON SSL VERSION")
    print(ssl.OPENSSL_VERSION)
    print("=" * 80)

    app.run(
        host="0.0.0.0",
        port=5000,
        debug=True
    )
