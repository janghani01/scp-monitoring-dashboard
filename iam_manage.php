<?php
session_start();
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['error' => '세션이 만료되었습니다. 다시 로그인해 주세요.']);
        exit;
    }

    $db_file = '/opt/scp/dashboard_users.db';
    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // IAM 테이블 자동 생성
    $db->exec("CREATE TABLE IF NOT EXISTS iam_users_v2 (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE,
        group_name TEXT,
        role_type TEXT,
        has_access_key TEXT DEFAULT 'N',
        created_at TEXT
    )");

    $action = $_GET['action'] ?? $_POST['action'] ?? 'list';

    // 1. IAM 목록 조회
    if ($action === 'list') {
        $stmt = $db->query("SELECT * FROM iam_users_v2 ORDER BY id DESC");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $users]);
        exit;
    }

    // 2. CSV 파일 대량 업로드
    if ($action === 'upload_csv') {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['error' => '업로드된 CSV 파일을 찾을 수 없거나 파일 크기 초과입니다.']);
            exit;
        }

        $file = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($file, "r");
        
        if ($handle === false) {
            echo json_encode(['error' => '업로드된 파일을 읽을 수 없습니다.']);
            exit;
        }

        // UTF-8 BOM 제거
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $imported = 0;
        $row_idx = 0;

        $stmt = $db->prepare("INSERT OR REPLACE INTO iam_users_v2 (username, group_name, role_type, has_access_key, created_at) VALUES (:username, :group_name, :role_type, :has_access_key, :created_at)");

        while (($data = fgetcsv($handle, 1000, ",")) !== false) {
            $row_idx++;
            if ($row_idx === 1) continue; // 헤더행 스킵

            $username = trim($data[0] ?? '');
            $group_name = trim($data[1] ?? 'General');
            $role_type = trim($data[2] ?? 'ReadOnlyAccess');
            $has_access_key = strtoupper(trim($data[3] ?? 'N')) === 'Y' ? 'Y' : 'N';
            $created_at = trim($data[4] ?? date('Y-m-d'));

            if (empty($username)) continue;

            $stmt->bindValue(':username', $username, PDO::PARAM_STR);
            $stmt->bindValue(':group_name', $group_name, PDO::PARAM_STR);
            $stmt->bindValue(':role_type', $role_type, PDO::PARAM_STR);
            $stmt->bindValue(':has_access_key', $has_access_key, PDO::PARAM_STR);
            $stmt->bindValue(':created_at', $created_at, PDO::PARAM_STR);
            $stmt->execute();

            $imported++;
        }

        fclose($handle);
        echo json_encode(['success' => true, 'message' => "총 {$imported}건의 IAM 계정이 성공적으로 등록되었습니다!"]);
        exit;
    }

    // 3. 단건 등록
    if ($action === 'add') {
        $rawInput = file_get_contents('php://input');
        $req = json_decode($rawInput, true);

        $username = trim($req['username'] ?? '');
        $group_name = trim($req['group_name'] ?? 'General');
        $role_type = trim($req['role_type'] ?? 'ReadOnlyAccess');
        $has_access_key = strtoupper(trim($req['has_access_key'] ?? 'N')) === 'Y' ? 'Y' : 'N';
        $created_at = date('Y-m-d');

        if (empty($username)) {
            echo json_encode(['error' => '사용자 ID를 입력해 주세요.']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO iam_users_v2 (username, group_name, role_type, has_access_key, created_at) VALUES (:username, :group_name, :role_type, :has_access_key, :created_at)");
        $stmt->bindValue(':username', $username, PDO::PARAM_STR);
        $stmt->bindValue(':group_name', $group_name, PDO::PARAM_STR);
        $stmt->bindValue(':role_type', $role_type, PDO::PARAM_STR);
        $stmt->bindValue(':has_access_key', $has_access_key, PDO::PARAM_STR);
        $stmt->bindValue(':created_at', $created_at, PDO::PARAM_STR);
        $stmt->execute();

        echo json_encode(['success' => true, 'message' => "IAM 계정이 생성되었습니다."]);
        exit;
    }

    // 4. 단건 삭제
    if ($action === 'delete') {
        $rawInput = file_get_contents('php://input');
        $req = json_decode($rawInput, true);
        $id = $req['id'] ?? 0;

        $stmt = $db->prepare("DELETE FROM iam_users_v2 WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        echo json_encode(['success' => true, 'message' => "계정이 삭제되었습니다."]);
        exit;
    }

} catch (Exception $e) {
    echo json_encode(['error' => '백엔드 처리 오류: ' . $e->getMessage()]);
    exit;
}
