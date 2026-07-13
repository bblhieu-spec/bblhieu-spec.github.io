<?php
// =========================================================================
// PHẦN 1: BỘ NÃO XỬ LÝ BACKEND (Tự động ngắt trang khi có yêu cầu API)
// =========================================================================
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    date_default_timezone_set('Asia/Ho_Chi_Minh');

    // CONFIG ADMIN: Hãy đổi mật khẩu này để bảo mật đường dẫn tạo key
    $admin_secret = "MẬT_KHẨU_ADMIN"; 
    $db_file = 'keys_data.json';

    if (!file_exists($db_file)) {
        file_put_contents($db_file, json_encode([], JSON_PRETTY_PRINT));
    }
    $db = json_decode(file_get_contents($db_file), true) ?: [];

    function save_db($data, $file) {
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    $api = $_GET['api'];

    // API 1: ADMIN TẠO KEY KHÓA
    if ($api == 'generate_key') {
        $secret = isset($_GET['secret']) ? $_GET['secret'] : '';
        if ($secret !== $admin_secret) {
            echo json_encode(['success' => false, 'error' => 'Sai mật khẩu quyền Admin!']);
            exit;
        }
        $days = isset($_GET['days']) ? intval($_GET['days']) : 30;
        $max_uids = isset($_GET['max_uids']) ? intval($_GET['max_uids']) : 3;
        $key_code = 'VITA-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));

        $db[$key_code] = [
            'valid' => true,
            'key_code' => $key_code,
            'duration_days' => $days,
            'max_uids' => $max_uids,
            'remaining' => $max_uids,
            'started_at' => null,
            'instances' => [
                ['label' => 'VPN Server VIP 1', 'ip_port' => '103.82.23.4:8080'],
                ['label' => 'VPN Server VIP 2', 'ip_port' => '103.82.23.5:9000']
            ],
            'connected_uids' => []
        ];
        save_db($db, $db_file);
        echo json_encode(['success' => true, 'key' => $key_code, 'days' => $days, 'slots' => $max_uids]);
        exit;
    }

    // API 2: KIỂM TRA KEY
    if ($api == 'check_key') {
        $key_code = isset($_POST['key_code']) ? trim($_POST['key_code']) : '';
        if (!isset($db[$key_code])) {
            echo json_encode(['valid' => false, 'error' => 'Mã Key này không tồn tại trên hệ thống!']);
            exit;
        }
        $key_data = $db[$key_code];
        if ($key_data['started_at'] !== null) {
            $expiration = $key_data['started_at'] + ($key_data['duration_days'] * 86400);
            if (time() > $expiration) {
                echo json_encode(['valid' => false, 'error' => 'Key bản quyền này đã hết hạn sử dụng!']);
                exit;
            }
        }
        echo json_encode($key_data);
        exit;
    }

    // API 3: KÍCH HOẠT THIẾT BỊ (UID)
    if ($api == 'activate') {
        $key_code = isset($_POST['key_code']) ? trim($_POST['key_code']) : '';
        $uid = isset($_POST['uid']) ? trim($_POST['uid']) : '';
        if (!isset($db[$key_code])) {
            echo json_encode(['success' => false, 'error' => 'Key không hợp lệ.']);
            exit;
        }
        $key_data = &$db[$key_code];
        if ($key_data['started_at'] === null) { $key_data['started_at'] = time(); }

        $is_existing = false;
        foreach ($key_data['connected_uids'] as $u) {
            if ($u['uid'] == $uid) { $is_existing = true; break; }
        }
        if ($is_existing) {
            echo json_encode(['success' => true, 'is_existing' => true, 'message' => 'Thiết bị này đã được kích hoạt trước đó!']);
            exit;
        }
        if ($key_data['remaining'] <= 0) {
            echo json_encode(['success' => false, 'error' => 'Key đã hết lượt kích hoạt (Tối đa ' . $key_data['max_uids'] . ' máy)']);
            exit;
        }

        $expires_at = $key_data['started_at'] + ($key_data['duration_days'] * 86400);
        $key_data['connected_uids'][] = [
            'uid' => $uid,
            'ip' => '113.161.' . rand(1, 254) . '.' . rand(1, 254),
            'expires_at' => $expires_at,
            'activated_at' => date('d/m/Y H:i:s')
        ];
        $key_data['remaining'] = max(0, $key_data['remaining'] - 1);
        save_db($db, $db_file);

        echo json_encode([
            'success' => true,
            'is_existing' => false,
            'message' => 'Kích hoạt thiết bị thành công!',
            'uid' => $uid,
            'expires_human' => date('d/m/Y H:i:s', $expires_at),
            'instances' => $key_data['instances']
        ]);
        exit;
    }

    // API 4: TẢI FILE CẤU HÌNH CÀI ĐẶT
    if ($api == 'download_profile') {
        $key_code = isset($_POST['key_code']) ? trim($_POST['key_code']) : 'UNKNOWN';
        header('Content-Type: application/x-apple-aspen-config');
        header('Content-Disposition: attachment; filename="vitamod_vip_' . $key_code . '.mobileconfig"');
        echo '<?xml version="1.0" encoding="UTF-8"?><plist version="1.0"><dict><key>PayloadDisplayName</key><string>VITA MOD VIP</string><key>PayloadIdentifier</key><string>com.vitamod.vip</string><key>PayloadUUID</key><string>'.md5($key_code).'</string><key>PayloadType</key><string>Configuration</string><key>PayloadVersion</key><integer>1</integer></dict></plist>';
        exit;
    }

    // API 5: XÓA UID KHỎI KEY
    if ($api == 'reset_uid') {
        $key_code = isset($_POST['key_code']) ? trim($_POST['key_code']) : '';
        $uid = isset($_POST['uid']) ? trim($_POST['uid']) : '';
        if (isset($db[$key_code])) {
            foreach ($db[$key_code]['connected_uids'] as $idx => $u) {
                if ($u['uid'] == $uid) {
                    array_splice($db[$key_code]['connected_uids'], $idx, 1);
                    $db[$key_code]['remaining'] = min($db[$key_code]['max_uids'], $db[$key_code]['remaining'] + 1);
                    save_db($db, $db_file);
                    echo json_encode(['success' => true, 'message' => 'Gỡ thiết bị thành công!']);
                    exit;
                }
            }
        }
        echo json_encode(['success' => false, 'error' => 'Không tìm thấy UID']); exit;
    }

    // API 6: RESET IP BIND
    if ($api == 'reset_ip') {
        $key_code = isset($_POST['key_code']) ? trim($_POST['key_code']) : '';
        $uid = isset($_POST['uid']) ? trim($_POST['uid']) : '';
        if (isset($db[$key_code])) {
            foreach ($db[$key_code]['connected_uids'] as &$u) {
                if ($u['uid'] == $uid) { $u['ip'] = 'Chưa liên kết'; save_db($db, $db_file); echo json_encode(['success' => true]); exit; }
            }
        }
        echo json_encode(['success' => false]); exit;
    }

    // API 7: TRA CỨU UID TỪ TAB CHỨC NĂNG
    if ($api == 'lookup_uid') {
        $uid = isset($_POST['uid']) ? trim($_POST['uid']) : '';
        foreach ($db as $k => $key_data) {
            foreach ($key_data['connected_uids'] as $u) {
                if ($u['uid'] == $uid) {
                    echo json_encode(['found' => true, 'uid' => $uid, 'key_masked' => substr($k,0,5).'***', 'status' => 'Đang hoạt động', 'ip' => $u['ip'], 'activated_at' => $u['activated_at'], 'expires_at' => date('d/m/Y H:i:s', $u['expires_at'])]);
                    exit;
                }
            }
        }
        echo json_encode(['found' => false, 'error' => 'Thiết bị này chưa kích hoạt trên hệ thống']); exit;
    }

    // API 8: XÓA UID TRONG TAB TRA CỨU
    if ($api == 'delete_uid') {
        $uid = isset($_POST['uid']) ? trim($_POST['uid']) : '';
        foreach ($db as $k => &$key_data) {
            foreach ($key_data['connected_uids'] as $idx => $u) {
                if ($u['uid'] == $uid) {
                    array_splice($key_data['connected_uids'], $idx, 1);
                    $key_data['remaining'] = min($key_data['max_uids'], $key_data['remaining'] + 1);
                    save_db($db, $db_file);
                    echo json_encode(['success' => true]); exit;
                }
            }
        }
        echo json_encode(['success' => false]); exit;
    }
}
?>

<!-- =========================================================================
     PHẦN 2: GIAO DIỆN ĐỒNG BỘ HTML / CSS / JAVASCRIPT
     ========================================================================= -->
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VITA MOD - Hệ Thống Quản Lý Bản Quyền</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <style>
        body { background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%); min-height: 100vh; color: #f8fafc; font-family: system-ui, sans-serif; }
    </style>
</head>
<body class="p-4 md:p-8">
    <div class="max-w-4xl mx-auto">
        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-blue-500 tracking-wide uppercase">VITA MOD SYSTEM</h1>
            <p class="text-slate-400 mt-2 text-sm">Hệ thống kích hoạt License Key và cấu hình thiết bị an toàn</p>
        </div>

        <!-- Điều hướng Tab -->
        <div class="flex space-x-2 bg-slate-900/60 p-1.5 rounded-xl border border-slate-800 mb-6">
            <button onclick="switchTab('tab-activate')" id="btn-tab-activate" class="flex-1 py-2.5 text-sm font-medium rounded-lg bg-blue-600 text-white transition-all cursor-pointer">🔑 Kích Hoạt Cấu Hình</button>
            <button onclick="switchTab('tab-lookup')" id="btn-tab-lookup" class="flex-1 py-2.5 text-sm font-medium rounded-lg text-slate-400 hover:text-slate-200 transition-all cursor-pointer">🔍 Tra Cứu Thiết Bị</button>
        </div>

        <!-- TAB 1: KÍCH HOẠT KEY -->
        <div id="tab-activate" class="space-y-6">
            <!-- Form tra cứu / Nhập key bước 1 -->
            <div class="bg-slate-900/80 border border-slate-800 rounded-2xl p-6 shadow-xl">
                <h2 class="text-lg font-semibold mb-4 flex items-center gap-2 text-cyan-400">Bước 1: Xác thực mã Key của bạn</h2>
                <div class="flex flex-col md:flex-row gap-3">
                    <input type="text" id="input-key" placeholder="Nhập mã Key bản quyền của bạn (Ví dụ: VITA-XXXX-XXXX)" class="flex-1 bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-cyan-500 transition-all font-mono uppercase tracking-wider">
                    <button onclick="checkKey()" class="bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-600 hover:to-blue-700 px-6 py-3 rounded-xl font-medium text-white transition-all shadow-lg shadow-cyan-500/20 active:scale-98 cursor-pointer">Kiểm tra Key</button>
                </div>
                <p id="key-error" class="text-red-400 text-sm mt-3 hidden font-medium"></p>
            </div>

            <!-- Khu vực hiện thông tin chi tiết sau khi check key đúng -->
            <div id="key-details-box" class="bg-slate-900/80 border border-slate-800 rounded-2xl p-6 shadow-xl hidden space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="bg-slate-950 p-4 rounded-xl border border-slate-800">
                        <span class="text-xs text-slate-400 block mb-1">Mã Bản Quyền</span>
                        <strong id="txt-key" class="text-emerald-400 font-mono tracking-wide">---</strong>
                    </div>
                    <div class="bg-slate-950 p-4 rounded-xl border border-slate-800">
                        <span class="text-xs text-slate-400 block mb-1">Thời Hạn Gói</span>
                        <strong id="txt-days" class="text-white">---</strong>
                    </div>
                    <div class="bg-slate-950 p-4 rounded-xl border border-slate-800">
                        <span class="text-xs text-slate-400 block mb-1">Slot Thiết Bị Trống</span>
                        <strong id="txt-slots" class="text-amber-400">---</strong>
                    </div>
                </div>

                <!-- Form add UID -->
                <div class="border-t border-slate-800/60 pt-6">
                    <h3 class="text-sm font-semibold text-slate-300 mb-3">Đăng ký thêm thiết bị mới (K kích hoạt UID)</h3>
                    <div class="flex flex-col md:flex-row gap-3">
                        <input type="text" id="input-uid" placeholder="Nhập mã định danh UID thiết bị của bạn" class="flex-1 bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-cyan-500 transition-all font-mono">
                        <button onclick="activateUid()" class="bg-emerald-600 hover:bg-emerald-700 px-6 py-3 rounded-xl font-medium text-white transition-all active:scale-98 cursor-pointer">Kích hoạt Máy</button>
                    </div>
                </div>

                <!-- Tải file Profile Apple cấu hình -->
                <div class="bg-slate-950 border border-slate-800 p-4 rounded-xl flex flex-col md:flex-row justify-between items-center gap-4">
                    <div>
                        <h4 class="font-medium text-white text-sm">Tải File Cấu Hình Kèm Theo Key</h4>
                        <p class="text-xs text-slate-400 mt-1">Dành cho các dòng máy yêu cầu cài đặt Profile (.mobileconfig)</p>
                    </div>
                    <form method="POST" action="?api=download_profile" target="_blank">
                        <input type="hidden" name="key_code" id="hidden-key-download">
                        <button type="submit" class="bg-slate-800 hover:bg-slate-700 text-white border border-slate-700 px-5 py-2 rounded-lg text-sm transition-all cursor-pointer">📥 Tải cấu hình</button>
                    </form>
                </div>

                <!-- Danh sách máy đang chạy thuộc Key này -->
                <div class="border-t border-slate-800/60 pt-4">
                    <h3 class="text-sm font-semibold text-slate-300 mb-3">Danh sách thiết bị đã liên kết</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm text-slate-300">
                            <thead>
                                <tr class="text-slate-400 border-b border-slate-800 text-xs uppercase">
                                    <th class="py-2">Mã Máy (UID)</th>
                                    <th class="py-2">Địa Chỉ IP</th>
                                    <th class="py-2">Ngày Đăng Ký</th>
                                    <th class="py-2 text-right">Hành Động</th>
                                </tr>
                            </thead>
                            <tbody id="uid-list-table"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 2: TRA CỨU RỜI CHO ADMIN / KHÁCH CHỦ ĐỘNG -->
        <div id="tab-lookup" class="space-y-6 hidden">
            <div class="bg-slate-900/80 border border-slate-800 rounded-2xl p-6 shadow-xl">
                <h2 class="text-lg font-semibold mb-4 flex items-center gap-2 text-blue-400">Kiểm tra thông tin của một máy cụ thể</h2>
                <div class="flex flex-col md:flex-row gap-3">
                    <input type="text" id="lookup-uid-input" placeholder="Nhập mã UID máy cần kiểm tra dữ liệu" class="flex-1 bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-blue-500 transition-all font-mono">
                    <button onclick="lookupUid()" class="bg-blue-600 hover:bg-blue-700 px-6 py-3 rounded-xl font-medium text-white transition-all cursor-pointer">Tìm kiếm dữ liệu</button>
                </div>
            </div>

            <!-- Kết quả tìm UID -->
            <div id="lookup-result-box" class="bg-slate-900/80 border border-slate-800 rounded-2xl p-6 shadow-xl hidden space-y-4">
                <h3 class="font-bold text-base border-b border-slate-800 pb-2 text-emerald-400">Dữ liệu tìm thấy trên máy chủ</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <p><span class="text-slate-400">Mã thiết bị (UID):</span> <strong id="l-uid" class="font-mono text-white"></strong></p>
                    <p><span class="text-slate-400">Thuộc Key bản quyền:</span> <strong id="l-key" class="font-mono text-white"></strong></p>
                    <p><span class="text-slate-400">IP Trạng thái:</span> <strong id="l-ip" class="text-white"></strong></p>
                    <p><span class="text-slate-400">Ngày Đăng Ký:</span> <strong id="l-date" class="text-white"></strong></p>
                    <p class="md:col-span-2"><span class="text-slate-400">Ngày hết hạn dùng gói:</span> <strong id="l-expiry" class="text-amber-400"></strong></p>
                </div>
                <div class="pt-4 border-t border-slate-800 flex justify-end">
                    <button id="btn-delete-uid-lookup" class="bg-red-950 text-red-400 border border-red-900 hover:bg-red-900 hover:text-white px-4 py-2 rounded-lg text-xs transition-all font-medium cursor-pointer">⚠️ Gỡ vĩnh viễn thiết bị khỏi hệ thống</button>
                </div>
            </div>
        </div>
    </div>

    <!-- SCRIPT ĐIỀU HÈN HỆ THỐNG -->
    <script>
        let currentKey = "";

        // Hàm chuyển đổi qua lại giữa các Tab chức năng
        function switchTab(tabId) {
            document.getElementById('tab-activate').classList.add('hidden');
            document.getElementById('tab-lookup').classList.add('hidden');
            document.getElementById('btn-tab-activate').className = "flex-1 py-2.5 text-sm font-medium rounded-lg text-slate-400 hover:text-slate-200 transition-all cursor-pointer";
            document.getElementById('btn-tab-lookup').className = "flex-1 py-2.5 text-sm font-medium rounded-lg text-slate-400 hover:text-slate-200 transition-all cursor-pointer";

            document.getElementById(tabId).classList.remove('hidden');
            document.getElementById('btn-' + tabId).className = "flex-1 py-2.5 text-sm font-medium rounded-lg bg-blue-600 text-white transition-all cursor-pointer";
        }

        // Kiểm tra cứu Key
        async function checkKey() {
            const keyInput = document.getElementById('input-key').value.trim();
            const errorText = document.getElementById('key-error');
            const detailsBox = document.getElementById('key-details-box');

            if (!keyInput) { alert("Vui lòng điền Key vào ô trống"); return; }

            errorText.classList.add('hidden');
            
            let formData = new FormData();
            formData.append('key_code', keyInput);

            try {
                let res = await fetch('?api=check_key', { method: 'POST', body: formData });
                let data = await res.json();

                if (data.valid === false) {
                    errorText.innerText = data.error;
                    errorText.classList.remove('hidden');
                    detailsBox.classList.add('hidden');
                } else {
                    currentKey = data.key_code;
                    document.getElementById('txt-key').innerText = data.key_code;
                    document.getElementById('txt-days').innerText = data.duration_days + " ngày";
                    document.getElementById('txt-slots').innerText = data.remaining + " / " + data.max_uids + " Thiết bị";
                    document.getElementById('hidden-key-download').value = data.key_code;
                    
                    // Vẽ danh sách máy
                    renderUidTable(data.connected_uids);
                    detailsBox.classList.remove('hidden');
                }
            } catch (e) {
                alert("Đã xảy ra lỗi kết nối hệ thống server PHP!");
            }
        }

        // Vẽ danh sách máy đang kết nối trong bảng
        function renderUidTable(uids) {
            const tbody = document.getElementById('uid-list-table');
            tbody.innerHTML = "";
            if (!uids || uids.length === 0) {
                tbody.innerHTML = `<tr><td colspan="4" class="py-4 text-center text-slate-500 italic">Chưa có thiết bị nào kích hoạt bằng key này.</td></tr>`;
                return;
            }
            uids.forEach(u => {
                tbody.innerHTML += `
                    <tr class="border-b border-slate-800/50 hover:bg-slate-900/40 text-xs">
                        <td class="py-3 font-mono text-white">${u.uid}</td>
                        <td class="py-3 text-slate-400">${u.ip}</td>
                        <td class="py-3 text-slate-400">${u.activated_at || 'Vừa xong'}</td>
                        <td class="py-3 text-right space-x-2">
                            <button onclick="resetIp('${u.uid}')" class="text-cyan-400 hover:underline cursor-pointer">Xóa IP</button>
                            <button onclick="resetUid('${u.uid}')" class="text-red-400 hover:underline ml-2 cursor-pointer">Xóa Máy</button>
                        </td>
                    </tr>
                `;
            });
        }

        // Kích hoạt thêm UID máy mới
        async function activateUid() {
            const uidInput = document.getElementById('input-uid').value.trim();
            if(!uidInput) { alert("Hãy nhập mã UID của máy cần kích hoạt"); return; }

            let formData = new FormData();
            formData.append('key_code', currentKey);
            formData.append('uid', uidInput);

            let res = await fetch('?api=activate', { method: 'POST', body: formData });
            let data = await res.json();

            if (data.success) {
                alert(data.message);
                document.getElementById('input-uid').value = "";
                checkKey(); // Cập nhật lại giao diện
            } else {
                alert("Lỗi: " + data.error);
            }
        }

        // Reset IP cho máy
        async function resetIp(uid) {
            if(!confirm("Bạn có chắc chắn muốn reset IP liên kết của thiết bị này?")) return;
            let formData = new FormData();
            formData.append('key_code', currentKey);
            formData.append('uid', uid);
            await fetch('?api=reset_ip', { method: 'POST', body: formData });
            checkKey();
        }

        // Reset UID (Xóa máy giải phóng slot)
        async function resetUid(uid) {
            if(!confirm("Xóa máy này sẽ trống 1 slot bản quyền. Đồng ý gỡ?")) return;
            let formData = new FormData();
            formData.append('key_code', currentKey);
            formData.append('uid', uid);
            await fetch('?api=reset_uid', { method: 'POST', body: formData });
            checkKey();
        }

        // Tra cứu nhanh 1 UID rời ở Tab 2
        async function lookupUid() {
            const uid = document.getElementById('lookup-uid-input').value.trim();
            if(!uid) return alert("Vui lòng nhập UID thiết bị");

            let formData = new FormData();
            formData.append('uid', uid);

            let res = await fetch('?api=lookup_uid', { method: 'POST', body: formData });
            let data = await res.json();

            const resultBox = document.getElementById('lookup-result-box');
            if(data.found) {
                document.getElementById('l-uid').innerText = data.uid;
                document.getElementById('l-key').innerText = data.key_masked + " (Bảo mật)";
                document.getElementById('l-ip').innerText = data.ip;
                document.getElementById('l-date').innerText = data.activated_at;
                document.getElementById('l-expiry').innerText = data.expires_at;
                
                document.getElementById('btn-delete-uid-lookup').onclick = function() { deleteUidFromLookup(data.uid); };
                resultBox.classList.remove('hidden');
            } else {
                alert(data.error);
                resultBox.classList.add('hidden');
            }
        }

        // Xóa UID trực tiếp từ tab tra cứu rời
        async function deleteUidFromLookup(uid) {
            if(!confirm("Hành động này sẽ xóa hoàn toàn thiết bị ra khỏi danh sách hệ thống. Tiếp tục?")) return;
            let formData = new FormData();
            formData.append('uid', uid);
            let res = await fetch('?api=delete_uid', { method: 'POST', body: formData });
            let data = await res.json();
            if(data.success) {
                alert("Đã gỡ bỏ thiết bị thành công!");
                document.getElementById('lookup-result-box').classList.add('hidden');
                document.getElementById('lookup-uid-input').value = "";
            }
        }
    </script>
</body>
</html>
