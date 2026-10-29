import boto3
import json

ACCESS_KEY = "7a59f8d6f7e9448681c15273cb9bd989"
SECRET_KEY = "02c3b9a9-8e56-449d-9ac7-60589ae30399"
ENDPOINT = "https://object-store.kr-west1.e.samsungsdscloud.com"

s3 = boto3.client(
    's3',
    endpoint_url=ENDPOINT,
    aws_access_key_id=ACCESS_KEY,
    aws_secret_access_key=SECRET_KEY
)

try:
    print("⏳ Object Storage 버킷 목록 및 용량 계산 중...")
    print("⚠️ (버킷 내 파일이 많을 경우 시간이 다소 소요될 수 있습니다.)")
    
    response = s3.list_buckets()
    buckets_data = []
    total_size_bytes_all = 0
    
    for bucket in response.get('Buckets', []):
        bucket_name = bucket['Name']
        creation_date = bucket['CreationDate'].strftime("%Y-%m-%d %H:%M:%S")
        
        bucket_size_bytes = 0
        file_count = 0
        
        try:
            # 버킷 안으로 들어가서 모든 파일(Object) 사이즈 합산
            paginator = s3.get_paginator('list_objects_v2')
            for page in paginator.paginate(Bucket=bucket_name):
                if 'Contents' in page:
                    for obj in page['Contents']:
                        file_count += 1
                        bucket_size_bytes += obj.get('Size', 0)
                        
            # 바이트(Byte)를 기가바이트(GB)로 변환 (소수점 2자리까지)
            size_gb = round(bucket_size_bytes / (1024 ** 3), 2)
            total_size_bytes_all += bucket_size_bytes
            
        except Exception as e:
            # 접근 권한이 없는 특정 버킷은 에러 처리
            print(f"⚠️ '{bucket_name}' 버킷 조회 실패: 권한 부족 등")
            size_gb = "-"
            file_count = "-"
            
        buckets_data.append({
            "bucketName": bucket_name,
            "creationDate": creation_date,
            "sizeGB": size_gb,
            "fileCount": file_count,
            "accessType": "확인 필요", 
            "status": "ACTIVE"
        })
        
    total_size_gb_all = round(total_size_bytes_all / (1024 ** 3), 2)
        
    result_json = {
        "totalBuckets": len(buckets_data),
        "totalSizeGB": total_size_gb_all,
        "buckets": buckets_data
    }
    
    with open('/opt/scp/storage.json', 'w', encoding='utf-8') as f:
        json.dump(result_json, f, ensure_ascii=False, indent=2)
        
    print(f"✅ 성공! 총 {len(buckets_data)}개 버킷, 전체 {total_size_gb_all}GB 계산 완료.")
    
except Exception as e:
    print("❌ API 연동 실패:")
    print(e)
