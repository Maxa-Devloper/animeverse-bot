<?php
// ============================================
// DEBUG
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Logging
$rawInput = file_get_contents('php://input');
file_put_contents(__DIR__ . '/bot_debug.log', date('Y-m-d H:i:s') . " | " . $rawInput . "\n\n", FILE_APPEND);

require_once 'config.php';

// ============================================
// TELEGRAM API
// ============================================
function tg($method, $data){
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $r = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    
    file_put_contents(__DIR__ . '/bot_debug.log', "cURL $method: " . ($r ?: "ERROR: $err") . "\n", FILE_APPEND);
    
    return $r ? json_decode($r, true) : null;
}

function sendMsg($chat_id, $text, $kb = null){
    $d = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if($kb) $d['reply_markup'] = $kb;
    return tg('sendMessage', $d);
}

function editMsg($chat_id, $msg_id, $text, $kb = null){
    $d = ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if($kb) $d['reply_markup'] = $kb;
    return tg('editMessageText', $d);
}

function cb($id, $text = ''){ 
    return tg('answerCallbackQuery', ['callback_query_id' => $id, 'text' => $text]); 
}

// ============================================
// UPDATE
// ============================================
$update = json_decode($rawInput, true);

if(!$update){ 
    http_response_code(200); 
    echo 'Bot ishlayapti (webhook uchun)';
    exit; 
}

$db = db();

/* ============================================
   MESSAGE HANDLER
   ============================================ */
if(isset($update['message'])){
    $msg = $update['message'];
    $chat_id = $msg['chat']['id'];
    $text = $msg['text'] ?? '';
    $photo = $msg['photo'] ?? null;
    $from = $msg['from'];
    $first_name = $from['first_name'] ?? 'Foydalanuvchi';

    // ---------- /START ----------
    if(strpos($text, '/start') === 0){
        $parts = explode(' ', $text, 2);
        $user_id = isset($parts[1]) ? trim($parts[1]) : '';

        if($user_id && strpos($user_id, 'u_') === 0){
            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $u = $stmt->fetch();

            if($u){
                $stmt = $db->prepare("UPDATE users SET telegram_chat_id = ? WHERE id = ?");
                $stmt->execute([$chat_id, $user_id]);

                sendMsg($chat_id,
                    "👋 <b>Salom, " . htmlspecialchars($first_name) . "!</b>\n\n" .
                    "🎬 Bu — <b>AnimeVerse</b> VIP to'lov boti.\n\n" .
                    "💎 <b>Sizning hisobingiz:</b>\n" .
                    "└ Login: <code>" . htmlspecialchars($u['username']) . "</code>\n" .
                    "└ Holat: " . ($u['is_vip'] ? "👑 VIP — " . htmlspecialchars($u['vip_plan']) : "🆓 Bepul") . "\n\n" .
                    "Quyidagi tugmalardan birini tanlang:",
                    ['inline_keyboard' => [
                        [['text' => '👑 VIP sotib olish', 'callback_data' => 'buy_' . $user_id]],
                        [['text' => '📊 Mening holatim', 'callback_data' => 'status_' . $user_id]],
                        [['text' => '❓ Yordam', 'callback_data' => 'help']]
                    ]]
                );
            } else {
                sendMsg($chat_id, "❌ Foydalanuvchi topilmadi. Saytdan qaytadan urinib ko'ring.");
            }
        } else {
            sendMsg($chat_id,
                "👋 <b>Salom!</b>\n\n" .
                "🎬 AnimeVerse VIP botiga xush kelibsiz!\n\n" .
                "Saytda <b>VIP sotib olish</b> tugmasini bosing."
            );
        }
    }
    // ---------- PHOTO (CHEK) ----------
    elseif($photo){
        $stmt = $db->prepare("SELECT * FROM payments WHERE chat_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$chat_id]);
        $p = $stmt->fetch();

        if($p){
            $file_id = end($photo)['file_id'];
            $stmt = $db->prepare("UPDATE payments SET screenshot_id = ? WHERE id = ?");
            $stmt->execute([$file_id, $p['id']]);

            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$p['user_id']]);
            $u = $stmt->fetch();

            $adminText = "🔔 <b>YANGI TO'LOV!</b>\n\n" .
                "👤 <b>Foydalanuvchi:</b> " . htmlspecialchars($u['name']) . "\n" .
                "🔗 <b>Username:</b> @" . htmlspecialchars($u['username']) . "\n" .
                "📧 <b>Email:</b> " . htmlspecialchars($u['email']) . "\n" .
                "🆔 <b>User ID:</b> <code>" . $u['id'] . "</code>\n\n" .
                "💎 <b>Reja:</b> " . htmlspecialchars($p['plan']) . "\n" .
                "💰 <b>Narx:</b> " . number_format($p['price']) . " so'm\n" .
                "⏰ <b>Vaqt:</b> " . date('d.m.Y H:i', $p['created_at']) . "\n\n" .
                "👇 <b>Tasdiqlash:</b>";

            tg('sendPhoto', [
                'chat_id' => BOT_ADMIN_ID,
                'photo' => $file_id,
                'caption' => $adminText,
                'parse_mode' => 'HTML',
                'reply_markup' => ['inline_keyboard' => [[
                    ['text' => '✅ Tasdiqlash', 'callback_data' => 'approve_' . $p['id']],
                    ['text' => '❌ Rad etish', 'callback_data' => 'reject_' . $p['id']]
                ]]]
            ]);

            sendMsg($chat_id,
                "✅ <b>Chekingiz qabul qilindi!</b>\n\n" .
                "⏳ Administrator tekshirib, <b>" . htmlspecialchars($p['plan']) . "</b> rejasini faollashtiradi.\n\n" .
                "🕐 Odatda 5-15 daqiqa ichida tasdiqlanadi."
            );
        } else {
            sendMsg($chat_id, "❓ Avval VIP sotib olishni boshlang. /start bosing.");
        }
    }
    // ---------- OTHER ----------
    else {
        sendMsg($chat_id, "🤖 Buyruqni tushunmadim. /start bosing.");
    }
}

/* ============================================
   CALLBACK HANDLER
   ============================================ */
if(isset($update['callback_query'])){
    $cbq = $update['callback_query'];
    $chat_id = $cbq['message']['chat']['id'];
    $msg_id = $cbq['message']['message_id'];
    $data = $cbq['data'];

    // ---------- BUY ----------
    if(strpos($data, 'buy_') === 0){
        $user_id = substr($data, 4);
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $u = $stmt->fetch();
        if(!$u){ cb($cbq['id'], 'Xatolik'); exit; }

        editMsg($chat_id, $msg_id,
            "💎 <b>VIP REJALAR</b>\n\nTanlang:",
            ['inline_keyboard' => [
                [['text' => '👑 Premium — 15 000 so\'m / 1 oy', 'callback_data' => 'plan_Premium_' . $user_id]],
                [['text' => '💎 Ultra VIP — 45 000 so\'m / 3 oy', 'callback_data' => 'plan_Ultra_' . $user_id]],
                [['text' => '⬅️ Orqaga', 'callback_data' => 'back_' . $user_id]]
            ]]
        );
        cb($cbq['id']);
    }
    // ---------- PLAN ----------
    elseif(strpos($data, 'plan_') === 0){
        $parts = explode('_', $data);
        $plan = $parts[1];
        $user_id = $parts[2];
        $planName = $plan === 'Premium' ? 'Premium' : 'Ultra VIP';
        $price = $plan === 'Premium' ? PRICE_PREMIUM : PRICE_ULTRA;
        $period = $plan === 'Premium' ? '1 oy' : '3 oy';

        $stmt = $db->prepare("INSERT INTO payments (user_id, chat_id, plan, price, status, created_at) VALUES (?, ?, ?, ?, 'pending', ?)");
        $stmt->execute([$user_id, $chat_id, $planName, $price, time()]);

        $cardRes = $db->query("SELECT value FROM settings WHERE key = 'card_number'")->fetch();
        $card = $cardRes ? $cardRes['value'] : '8600 0000 0000 0000';
        $ownerRes = $db->query("SELECT value FROM settings WHERE key = 'card_owner'")->fetch();
        $owner = $ownerRes ? $ownerRes['value'] : 'Admin';

        editMsg($chat_id, $msg_id,
            "💳 <b>TO'LOV MA'LUMOTLARI</b>\n\n" .
            "💎 Reja: <b>" . $planName . "</b>\n" .
            "⏱ Muddat: <b>" . $period . "</b>\n" .
            "💰 Summa: <b>" . number_format($price) . " so'm</b>\n\n" .
            "💳 <b>Karta:</b>\n<code>" . $card . "</code>\n" .
            "👤 Egasi: <b>" . $owner . "</b>\n\n" .
            "📸 <b>To'lovni amalga oshirib, chekni shu chatga yuboring.</b>\n\n" .
            "⚠️ Aynan <b>" . number_format($price) . " so'm</b> yuboring.",
            ['inline_keyboard' => [[
                ['text' => '❌ Bekor qilish', 'callback_data' => 'cancel_' . $user_id]
            ]]]
        );
        cb($cbq['id']);
    }
    // ---------- STATUS ----------
    elseif(strpos($data, 'status_') === 0){
        $user_id = substr($data, 7);
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $u = $stmt->fetch();

        $status = $u['is_vip']
            ? "👑 <b>VIP — " . htmlspecialchars($u['vip_plan']) . "</b>"
            : "🆓 <b>Bepul foydalanuvchi</b>";

        $stmt = $db->prepare("SELECT * FROM payments WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$user_id]);
        $p = $stmt->fetch();

        $last = '';
        if($p){
            $st = ['pending'=>'⏳ Kutilmoqda','approved'=>'✅ Tasdiqlangan','rejected'=>'❌ Rad etilgan'];
            $last = "\n\n📋 <b>Oxirgi to'lov:</b>\n" .
                "└ " . $p['plan'] . " — " . number_format($p['price']) . " so'm\n" .
                "└ " . ($st[$p['status']] ?? $p['status']);
        }

        editMsg($chat_id, $msg_id,
            "📊 <b>HOLATINGIZ</b>\n\n" .
            "👤 " . htmlspecialchars($u['name']) . "\n" .
            "🎫 @" . htmlspecialchars($u['username']) . "\n" .
            "💎 " . $status . $last,
            ['inline_keyboard' => [[
                ['text' => '⬅️ Orqaga', 'callback_data' => 'back_' . $user_id]
            ]]]
        );
        cb($cbq['id']);
    }
    // ---------- BACK ----------
    elseif(strpos($data, 'back_') === 0){
        $user_id = substr($data, 5);
        if($user_id === 'home'){
            sendMsg($chat_id, "Asosiy menyu: /start");
            cb($cbq['id']);
            exit;
        }
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $u = $stmt->fetch();

        editMsg($chat_id, $msg_id,
            "🎬 <b>AnimeVerse VIP Bot</b>\n\n" .
            "💎 Hisobingiz:\n" .
            "└ Login: <code>" . htmlspecialchars($u['username']) . "</code>\n" .
            "└ Holat: " . ($u['is_vip'] ? "👑 " . htmlspecialchars($u['vip_plan']) : "🆓 Bepul"),
            ['inline_keyboard' => [
                [['text' => '👑 VIP sotib olish', 'callback_data' => 'buy_' . $user_id]],
                [['text' => '📊 Holatim', 'callback_data' => 'status_' . $user_id]],
                [['text' => '❓ Yordam', 'callback_data' => 'help']]
            ]]
        );
        cb($cbq['id']);
    }
    // ---------- CANCEL ----------
    elseif(strpos($data, 'cancel_') === 0){
        $user_id = substr($data, 7);
        $stmt = $db->prepare("UPDATE payments SET status = 'rejected' WHERE user_id = ? AND status = 'pending'");
        $stmt->execute([$user_id]);
        editMsg($chat_id, $msg_id, "❌ <b>Bekor qilindi</b>\n\n/start bosing.");
        cb($cbq['id']);
    }
    // ---------- HELP ----------
    elseif($data === 'help'){
        editMsg($chat_id, $msg_id,
            "❓ <b>YORDAM</b>\n\n" .
            "1️⃣ VIP sotib olish tugmasini bosing\n" .
            "2️⃣ Reja tanlang\n" .
            "3️⃣ Kartaga to'lov qiling\n" .
            "4️⃣ Chekni botga yuboring\n" .
            "5️⃣ Admin tasdiqlagach — VIP faollashadi",
            ['inline_keyboard' => [[
                ['text' => '⬅️ Orqaga', 'callback_data' => 'back_home']
            ]]]
        );
        cb($cbq['id']);
    }
    // ---------- APPROVE ----------
    elseif(strpos($data, 'approve_') === 0){
        if($chat_id != BOT_ADMIN_ID){ cb($cbq['id'], 'Siz admin emassiz!'); exit; }
        $pid = (int)substr($data, 8);
        $stmt = $db->prepare("SELECT * FROM payments WHERE id = ?");
        $stmt->execute([$pid]);
        $p = $stmt->fetch();
        
        if(!$p || $p['status'] != 'pending'){ cb($cbq['id'], 'Allaqachon ko\'rilgan'); exit; }

        $days = $p['plan'] === 'Premium' ? 30 : 90;
        $expires = time() + ($days * 24 * 60 * 60);

        $stmt = $db->prepare("UPDATE users SET is_vip = 1, vip_plan = ?, vip_expires = ? WHERE id = ?");
        $stmt->execute([$p['plan'], $expires, $p['user_id']]);

        $stmt = $db->prepare("UPDATE payments SET status = 'approved', approved_at = ? WHERE id = ?");
        $stmt->execute([time(), $pid]);

        $expireStr = date('d.m.Y', $expires);
        editMsg($chat_id, $msg_id,
            "✅ <b>TASDIQLANDI</b>\n\n" .
            "👤 <code>" . $p['user_id'] . "</code>\n" .
            "💎 " . $p['plan'] . "\n" .
            "💰 " . number_format($p['price']) . " so'm\n" .
            "📅 Tugaydi: <b>" . $expireStr . "</b>"
        );

        sendMsg($p['chat_id'],
            "🎉 <b>TABRIKLAYMIZ!</b>\n\n" .
            "✅ <b>" . $p['plan'] . "</b> faollashtirildi!\n\n" .
            "📅 Amal qiladi: <b>" . $expireStr . "</b>\n\n" .
            "💎 Endi barcha animelar ochiq!"
        );
        cb($cbq['id'], '✅ Tasdiqlandi');
    }
    // ---------- REJECT ----------
    elseif(strpos($data, 'reject_') === 0){
        if($chat_id != BOT_ADMIN_ID){ cb($cbq['id'], 'Siz admin emassiz!'); exit; }
        $pid = (int)substr($data, 7);
        $stmt = $db->prepare("SELECT * FROM payments WHERE id = ?");
        $stmt->execute([$pid]);
        $p = $stmt->fetch();
        if(!$p){ cb($cbq['id'], 'Topilmadi'); exit; }

        $stmt = $db->prepare("UPDATE payments SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$pid]);

        editMsg($chat_id, $msg_id,
            "❌ <b>RAD ETILDI</b>\n\n" .
            "👤 <code>" . $p['user_id'] . "</code>\n" .
            "💎 " . $p['plan']
        );

        sendMsg($p['chat_id'],
            "❌ <b>To'lovingiz rad etildi.</b>\n\nAdmin bilan bog'laning."
        );
        cb($cbq['id'], '❌ Rad etildi');
    }
    else { cb($cbq['id'], 'Noma\'lum'); }
}

http_response_code(200);
echo 'OK';
?>