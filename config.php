<?php
// ============================================
// TELEGRAM BOT SOZLAMALARI
// ============================================
define('BOT_TOKEN', '8931711855:AAFTgHxPJ93IoCCzQNSw5vsNA19OQu16yMo');
define('BOT_USERNAME', 'AnimeVerseVip_Bot');
define('BOT_ADMIN_ID', '6101268117');  // ← O'zingizning Telegram ID

// ============================================
// NARXLAR
// ============================================
define('PRICE_PREMIUM', 15000);   // 1 oy
define('PRICE_ULTRA', 45000);     // 3 oy

// ============================================
// SQLITE DATABASE (Render uchun)
// ============================================
function db(){
    static $pdo = null;
    
    if($pdo === null){
        $dbFile = __DIR__ . '/animeverse.db';
        $isNew = !file_exists($dbFile);
        
        try {
            $pdo = new PDO('sqlite:' . $dbFile);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA foreign_keys = ON');
            
            // ============ JADVALLARNI YARATISH ============
            $pdo->exec("CREATE TABLE IF NOT EXISTS users (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                email TEXT UNIQUE NOT NULL,
                username TEXT UNIQUE NOT NULL,
                password TEXT NOT NULL,
                role INTEGER DEFAULT 3,
                is_vip INTEGER DEFAULT 0,
                vip_plan TEXT DEFAULT '',
                vip_expires INTEGER DEFAULT 0,
                banned INTEGER DEFAULT 0,
                avatar TEXT,
                bio TEXT,
                telegram_chat_id TEXT,
                coins INTEGER DEFAULT 0,
                created_at INTEGER
            )");
            
            $pdo->exec("CREATE TABLE IF NOT EXISTS anime (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                genres TEXT,
                rating REAL DEFAULT 0,
                status TEXT DEFAULT 'ongoing',
                year INTEGER DEFAULT 2024,
                eps INTEGER DEFAULT 12,
                dur INTEGER DEFAULT 24,
                vip INTEGER DEFAULT 0,
                vip_from_episode INTEGER DEFAULT 0,
                icon TEXT DEFAULT 'fa-film',
                bg TEXT DEFAULT 'g1',
                image TEXT,
                video TEXT,
                description TEXT,
                parent_id INTEGER,
                season_name TEXT,
                lang TEXT DEFAULT 'Sub',
                quality TEXT DEFAULT '720p',
                created_at INTEGER
            )");
            
            $pdo->exec("CREATE TABLE IF NOT EXISTS favorites (
                user_id TEXT,
                anime_id INTEGER,
                created_at INTEGER,
                PRIMARY KEY(user_id, anime_id)
            )");
            
            $pdo->exec("CREATE TABLE IF NOT EXISTS history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT,
                anime_id INTEGER,
                episode INTEGER,
                watched_at INTEGER
            )");
            
            $pdo->exec("CREATE TABLE IF NOT EXISTS watchlist (
                user_id TEXT,
                anime_id INTEGER,
                created_at INTEGER,
                PRIMARY KEY(user_id, anime_id)
            )");
            
            $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT,
                chat_id TEXT,
                plan TEXT,
                price INTEGER,
                status TEXT DEFAULT 'pending',
                screenshot_id TEXT,
                created_at INTEGER,
                approved_at INTEGER
            )");
            
            $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT
            )");
            
            // ============ SOZLAMALAR ============
            $pdo->exec("INSERT OR IGNORE INTO settings (key, value) VALUES 
                ('card_number', '8600 0000 0000 0000'),
                ('card_owner', 'Admin'),
                ('site_name', 'AnimeVerse')");
            
            // ============ OWNER AKKAUNT ============
            $check = $pdo->query("SELECT COUNT(*) as c FROM users WHERE id='u_owner'")->fetch();
            if($check['c'] == 0){
                $hash = password_hash('admin123', PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (id, name, email, username, password, role, is_vip, vip_plan, created_at) VALUES (?, ?, ?, ?, ?, 1, 1, ?, ?)");
                $stmt->execute(['u_owner', 'Owner', 'owner@animeverse.uz', 'owner', $hash, 'Ultra VIP', time()]);
            }
            
            // ============ DEFAULT ANIMELAR ============
            $check = $pdo->query("SELECT COUNT(*) as c FROM anime")->fetch();
            if($check['c'] == 0){
                $defaults = [
                    ['Attack on Titan', 'Action,Drama', 4.9, 'completed', 2013, 87, 24, 0, 0, 'fa-shield-halved', 'g1', 'https://mover.uz/video/embed/uxu60aYY', 'Insoniyat titanlardan himoyalanadi.'],
                    ['Demon Slayer', 'Action,Supernatural', 4.8, 'ongoing', 2019, 55, 23, 0, 0, 'fa-dragon', 'g4', '', 'Tanjiro oilasi qirg\'in qilinadi.'],
                    ['Jujutsu Kaisen', 'Action,Horror', 4.8, 'ongoing', 2020, 47, 24, 1, 1, 'fa-ghost', 'g3', '', 'Yuji la\'natlangan barmoqni yutadi.'],
                    ['One Piece', 'Adventure,Fantasy', 4.9, 'ongoing', 1999, 1100, 24, 0, 0, 'fa-skull', 'g5', '', 'Luffy xazina izlaydi.'],
                    ['Death Note', 'Thriller,Mystery', 4.9, 'completed', 2006, 37, 23, 1, 4, 'fa-book-dead', 'g6', '', 'Light o\'lim daftarini topadi.'],
                    ['Chainsaw Man', 'Action,Horror', 4.7, 'ongoing', 2022, 12, 24, 1, 1, 'fa-screwdriver-wrench', 'g6', '', 'Denji Pochita bilan qo\'shiladi.'],
                    ['Spy x Family', 'Comedy,Action', 4.8, 'ongoing', 2022, 37, 24, 0, 0, 'fa-user-secret', 'g3', '', 'Soxta oila missiyalarni bajaradi.'],
                    ['Hunter x Hunter', 'Adventure,Fantasy', 4.9, 'completed', 2011, 148, 23, 1, 5, 'fa-crosshairs', 'g5', '', 'Gon otasini topish uchun imtihondan o\'tadi.']
                ];
                
                $stmt = $pdo->prepare("INSERT INTO anime (title, genres, rating, status, year, eps, dur, vip, vip_from_episode, icon, bg, video, description, lang, quality, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Sub', '720p', ?)");
                
                foreach($defaults as $d){
                    $stmt->execute([$d[0], $d[1], $d[2], $d[3], $d[4], $d[5], $d[6], $d[7], $d[8], $d[9], $d[10], $d[11], $d[12], time()]);
                }
            }
            
        } catch(PDOException $e){
            error_log('DB xato: ' . $e->getMessage());
            die('DB xatolik: ' . $e->getMessage());
        }
    }
    
    return $pdo;
}
?>