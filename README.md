<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>SHOP ACC FF</title>
    <style>
        body { background: #000; color: #fff; margin: 0; padding: 10px; font-family: sans-serif; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .box { background: #1a1a1a; padding: 10px; border-radius: 8px; border: 1px solid #444; text-align: center; }
        .box img { width: 100%; height: 100px; object-fit: cover; border-radius: 5px; background: #333; }
        .price { color: #ff0; font-weight: bold; margin: 5px 0; }
        .btn { background: #d00; color: #fff; padding: 8px; border-radius: 5px; text-decoration: none; display: block; font-size: 14px; }
    </style>
</head>
<body>
    <h1 style="text-align: center;">SHOP CỦA HÙNG</h1>
    <div class="grid" id="acc-list"></div>

    <script>
        const list = document.getElementById('acc-list');
        for (let i = 1; i <= 20; i++) {
            let price = (400 + (i-1)*600) + "k";
            list.innerHTML += `
                <div class="box">
                    <img src="https://via.placeholder.com/150?text=ACC+${i}" alt="Acc ${i}">
                    <p style="margin:5px 0;">ID ${i < 10 ? '0'+i : i}</p>
                    <div class="price">${price}</div>
                    <a href="https://zalo.me/0866084535" class="btn">MUA NGAY</a>
                </div>
            `;
        }
    </script>
</body>
</html>
