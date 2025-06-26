#!/usr/bin/php
<?php

// Nonaktifkan Xdebug untuk sesi ini untuk menghindari pesan koneksi.
if (function_exists('xdebug_disable')) {
    xdebug_disable();
}
// Alternatif untuk versi Xdebug yang lebih baru
if (ini_get('xdebug.mode') !== 'off') {
    ini_set('xdebug.mode', 'off');
}


// Mengatur batas waktu eksekusi tanpa batas, penting untuk proses yang lama.
set_time_limit(0);

// Path untuk file konfigurasi
$configFile = __DIR__ . '/nexus_config.json';

// Fungsi untuk membaca konfigurasi dari file JSON
function readConfig($filePath) {
    if (!file_exists($filePath)) {
        return [];
    }
    $json = file_get_contents($filePath);
    return json_decode($json, true) ?: [];
}

// Fungsi untuk menulis konfigurasi ke file JSON
function writeConfig($filePath, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT);
    file_put_contents($filePath, $json);
}


// Fungsi untuk menjalankan perintah shell dan menampilkan outputnya secara real-time.
function executeCommand($command, &$output_lines = null, $isPassthru = true) {
    if ($isPassthru) {
        echo "======================================================================\n";
        echo "Executing: " . $command . "\n";
        echo "======================================================================\n";
    }
    
    // Gunakan exec untuk menangkap output jika diperlukan
    if (!$isPassthru) {
        exec($command . ' 2>&1', $output_lines, $return_code);
    } else {
        // Gunakan passthru untuk output real-time jika tidak perlu menangkap output
        passthru($command, $return_code);
    }

    if ($isPassthru) {
        echo "======================================================================\n";
        echo "Command finished with exit code: " . $return_code . "\n";
        echo "======================================================================\n\n";
    }
    return $return_code;
}

// Fungsi untuk instalasi awal dan dependensi.
function initialSetup() {
    echo "Memulai proses instalasi awal...\n";
    executeCommand('sudo apt update && sudo apt upgrade -y');
    executeCommand('sudo apt install screen curl build-essential pkg-config libssl-dev git-all -y');
    executeCommand('sudo apt install protobuf-compiler -y');
    executeCommand('sudo apt update');
    echo "Mengunduh dan menginstal Rust...\n";
    executeCommand('curl --proto \'=https\' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y');
    echo "Memuat environment Cargo...\n";
    $cargo_env_path = getenv("HOME") . '/.cargo/env';
    putenv("PATH=" . getenv("HOME") . "/.cargo/bin:" . getenv("PATH"));
    executeCommand("bash -c 'source " . escapeshellarg($cargo_env_path) . " && rustup target add riscv32i-unknown-none-elf'");
    echo "Proses instalasi awal selesai!\n";
}

// Fungsi baru untuk HANYA mengecek versi GLIBC
function checkGlibcVersion() {
    echo "Mengecek versi GLIBC...\n";
    $output = [];
    executeCommand('ldd --version', $output, false);
    
    if (isset($output[0]) && preg_match('/ldd\s+\(.*\)\s+([\d\.]+)/', $output[0], $matches)) {
        echo "Versi GLIBC terdeteksi: " . $matches[1] . "\n";
    } else {
        echo "Tidak dapat mendeteksi versi GLIBC secara otomatis.\n";
    }
    readline("Tekan Enter untuk melanjutkan...");
}

// Fungsi baru untuk HANYA mengupdate GLIBC
function updateGlibc() {
    echo "Memeriksa prasyarat untuk update GLIBC...\n";
    $output = [];
    executeCommand('ldd --version', $output, false);
    
    $glibc_version = '';
    if (isset($output[0]) && preg_match('/ldd\s+\(.*\)\s+([\d\.]+)/', $output[0], $matches)) {
        $glibc_version = $matches[1];
    } else {
        echo "Tidak dapat mendeteksi versi GLIBC. Update tidak bisa dilanjutkan.\n";
        readline("Tekan Enter untuk melanjutkan...");
        return;
    }

    if ($glibc_version !== '2.35') {
        echo "Versi GLIBC Anda adalah " . $glibc_version . ", bukan 2.35. Update tidak diperlukan atau tidak didukung oleh skrip ini.\n";
        readline("Tekan Enter untuk melanjutkan...");
        return;
    }

    $confirm = strtolower(readline("Versi GLIBC Anda adalah 2.35. Apakah Anda SETUJU untuk mengupdate ke versi 2.39? (Ini akan memakan waktu lama) [setuju/tidak]: "));
    if ($confirm === 'setuju') {
        echo "Memulai proses update GLIBC ke 2.39...\n";
        $update_command = "bash -c '" .
            "sudo apt update && " .
            "sudo apt install -y gawk bison gcc make wget tar && " .
            "wget -c https://ftp.gnu.org/gnu/glibc/glibc-2.39.tar.gz && " .
            "tar -zxvf glibc-2.39.tar.gz && " .
            "cd glibc-2.39 && " .
            "mkdir -p glibc-build && " .
            "cd glibc-build && " .
            "../configure --prefix=/opt/glibc-2.39 && " .
            "make -j\$(nproc) && " .
            "sudo make install && " .
            "echo \"Update GLIBC selesai. Membersihkan file instalasi...\" && " .
            "cd ~ && rm -rf glibc-2.39 glibc-2.39.tar.gz" .
            "'";
        executeCommand($update_command);
        echo "Proses update GLIBC telah selesai.\n";
    } else {
        echo "Update GLIBC dibatalkan.\n";
    }
    readline("Tekan Enter untuk melanjutkan...");
}

// Fungsi baru untuk membuat Swap File
function handleSwapFile() {
    echo "Fungsi ini akan membuat swap file untuk membantu mencegah VPS kehabisan memori.\n";
    echo "Pilih ukuran swap file yang diinginkan:\n";
    echo "1. 4G\n";
    echo "2. 8G\n";
    echo "3. 16G\n";
    $choice = readline("Pilihan Anda (1-3): ");

    $size = '';
    switch ($choice) {
        case '1':
            $size = '4G';
            break;
        case '2':
            $size = '8G';
            break;
        case '3':
            $size = '16G';
            break;
        default:
            echo "Pilihan tidak valid. Proses dibatalkan.\n";
            readline("Tekan Enter untuk melanjutkan...");
            return;
    }

    $confirm = strtolower(readline("Anda akan membuat swap file sebesar $size. Apakah Anda yakin? [y/n]: "));
    if ($confirm !== 'y') {
        echo "Proses dibatalkan.\n";
        readline("Tekan Enter untuk melanjutkan...");
        return;
    }

    echo "Membuat swap file sebesar $size...\n";
    executeCommand("sudo fallocate -l " . escapeshellarg($size) . " /swapfile");
    executeCommand("sudo chmod 600 /swapfile");
    executeCommand("sudo mkswap /swapfile");
    executeCommand("sudo swapon /swapfile");
    executeCommand("echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab");
    echo "Swap file berhasil dibuat dan diaktifkan.\n";
    readline("Tekan Enter untuk melanjutkan...");
}

// Fungsi baru untuk reboot VPS
function handleReboot() {
    $confirm = strtolower(readline("PERINGATAN: Anda akan me-reboot VPS. Semua sesi yang tidak disimpan akan hilang. Lanjutkan? [y/n]: "));
    if ($confirm === 'y') {
        echo "Rebooting VPS sekarang...\n";
        executeCommand("sudo reboot");
    } else {
        echo "Reboot dibatalkan.\n";
        readline("Tekan Enter untuk melanjutkan...");
    }
}


function getRunningScreens() {
    $output = [];
    executeCommand('screen -ls', $output, false);
    $running_screens = [];
    foreach ($output as $line) {
        if (preg_match('/^\s+(\d+\.[^\s\(]+)/', $line, $matches)) {
            $running_screens[] = $matches[1];
        }
    }
    return $running_screens;
}

// Mode fallback jika ncurses tidak tersedia
function handleScreenLogsSimple() {
    $running_screens = getRunningScreens();
    if (empty($running_screens)) {
        echo "Tidak ada sesi screen yang aktif ditemukan.\n";
        readline("Tekan Enter untuk kembali ke menu utama...");
        return;
    }

    echo "Pilih screen untuk disambungkan (attach):\n";
    foreach ($running_screens as $index => $screen) {
        echo ($index + 1) . ". " . $screen . "\n";
    }
    $return_option = count($running_screens) + 1;
    echo $return_option . ". Kembali ke Menu Utama\n";

    $choice = readline("Masukkan pilihan Anda (1-" . $return_option . "): ");
    $choice_index = intval($choice) - 1;

    if (isset($running_screens[$choice_index])) {
        $selected_screen = escapeshellarg($running_screens[$choice_index]);
        echo "Menyambungkan ke screen: " . $selected_screen . "...\n";
        echo "Untuk melepaskan diri dari screen (detach), tekan CTRL+A lalu D.\n";
        passthru("screen -r " . $selected_screen);
        echo "\nSesi screen telah ditutup. Kembali ke menu utama.\n";
    }
}

// Fungsi baru untuk menutup (quit) screen
function handleKillScreen() {
    $running_screens = getRunningScreens();
    if (empty($running_screens)) {
        echo "Tidak ada sesi screen yang aktif ditemukan.\n";
        readline("Tekan Enter untuk kembali ke menu utama...");
        return;
    }

    echo "Pilih screen yang akan ditutup (quit):\n";
    foreach ($running_screens as $index => $screen) {
        echo ($index + 1) . ". " . $screen . "\n";
    }
    $return_option = count($running_screens) + 1;
    echo $return_option . ". Kembali ke Menu Utama\n";

    $choice = readline("Masukkan pilihan Anda (1-" . $return_option . "): ");
    $choice_index = intval($choice) - 1;

    if (isset($running_screens[$choice_index])) {
        $selected_screen = $running_screens[$choice_index];
        $safe_selected_screen = escapeshellarg($selected_screen);
        echo "Mengirim perintah 'quit' ke screen: " . $selected_screen . "...\n";
        executeCommand("screen -XS " . $safe_selected_screen . " quit");
        echo "\nPerintah untuk menutup screen telah dikirim.\n";
    }
    readline("Tekan Enter untuk melanjutkan...");
}

// Fungsi untuk menginstal ncurses
function installNcurses() {
    echo "Menginstal ekstensi php-ncurses...\n";
    executeCommand("sudo apt-get update");
    executeCommand("sudo apt-get install -y php-ncurses");
    echo "Instalasi selesai. Harap jalankan ulang skrip ini agar perubahan diterapkan.\n";
    readline("Tekan Enter untuk keluar agar Anda dapat menjalankan ulang skrip.");
    exit(0);
}


// ==================================================================
// =================== FUNGSI UTAMA TUI NCURSES =====================
// ==================================================================
function runTui($configFile) {
    ncurses_init();
    ncurses_curs_set(0);
    ncurses_noecho();
    ncurses_keypad(STDSCR, true);
    ncurses_timeout(500); // Set timeout untuk loop non-blocking (500ms)

    $main_menu_items = [
        ['title' => '--- Pendaftaran & Update ---'],
        ['title' => '1. Update versi Nexus CLI', 'action' => 'update_cli'],
        ['title' => '2. Register/Update alamat wallet', 'action' => 'register_wallet'],
        ['title' => '3. Create/Register Node ID', 'action' => 'register_node'],
        ['title' => '4. Lihat Log Screen', 'action' => 'view_logs'],
        ['title' => '5. Keluar dari Screen (Quit)', 'action' => 'kill_screen'],
        ['title' => ''],
        ['title' => '--- Operasi Node ---'],
        ['title' => '6. Jalankan node di dalam \'screen\'', 'action' => 'run_screen'],
        ['title' => '7. Jalankan node (foreground)', 'action' => 'run_foreground'],
        ['title' => '8. Jalankan Lewat LocalBin', 'action' => 'run_localbin'],
        ['title' => ''],
        ['title' => '--- Utilitas Sistem ---'],
        ['title' => '9. Cek versi GLIBC', 'action' => 'check_glibc'],
        ['title' => '10. Update Versi GLIBC', 'action' => 'update_glibc'],
        ['title' => '11. Mengatasi VPS Killed (Swap)', 'action' => 'create_swap'],
        ['title' => '12. Reboot VPS', 'action' => 'reboot_vps'],
        ['title' => '13. Keluar', 'action' => 'exit'],
    ];

    $current_selection = 1; // Mulai dari item pertama yang bisa dipilih
    $active_log_session = null; // Menyimpan nama screen yang sedang dilihat
    $log_content = ['Pilih "Lihat Log Screen" dari menu untuk memulai.'];
    $last_refresh = 0;

    while (true) {
        $config = readConfig($configFile);
        $height = 0; $width = 0;
        ncurses_getmaxyx(STDSCR, $height, $width);
        $left_width = (int)($width / 2.5); // Panel kiri lebih kecil
        $right_width = $width - $left_width;

        // --- Panel Kiri (Menu Utama) ---
        $left_panel = ncurses_newwin($height, $left_width, 0, 0);
        ncurses_wborder($left_panel, 0,0,0,0,0,0,0,0);
        ncurses_mvwaddstr($left_panel, 1, 2, "MENU UTAMA");
        foreach ($main_menu_items as $index => $item) {
             $is_selectable = isset($item['action']);
            if ($is_selectable && $index === $current_selection) {
                ncurses_wattron($left_panel, NCURSES_A_REVERSE);
                ncurses_mvwaddstr($left_panel, $index + 3, 2, $item['title']);
                ncurses_wattroff($left_panel, NCURSES_A_REVERSE);
            } else {
                ncurses_mvwaddstr($left_panel, $index + 3, 2, $item['title']);
            }
        }
        ncurses_wrefresh($left_panel);

        // --- Panel Kanan (Info atau Log Viewer) ---
        $right_panel = ncurses_newwin($height, $right_width, 0, $left_width);
        ncurses_wborder($right_panel, 0,0,0,0,0,0,0,0);

        if ($active_log_session !== null) {
            // Mode Tampilan Log
            ncurses_mvwaddstr($right_panel, 1, 2, "LOG: " . $active_log_session . " (Tekan 'Q' untuk berhenti)");
            $time = time();
            if ($time - $last_refresh > 2) { // Refresh log setiap 2 detik
                $tmp_file = "/tmp/screen_log_" . preg_replace('/[^a-zA-Z0-9]/', '_', $active_log_session) . ".txt";
                executeCommand("screen -S " . escapeshellarg($active_log_session) . " -X hardcopy -h " . escapeshellarg($tmp_file), $out, false);
                if (file_exists($tmp_file)) {
                    $log_content = file($tmp_file, FILE_IGNORE_NEW_LINES) ?: ["File log kosong."];
                    unlink($tmp_file);
                } else {
                    $log_content = ["Gagal mengambil log untuk screen: " . $active_log_session];
                }
                $last_refresh = $time;
            }
        } else {
            // Mode Tampilan Default
            $log_content = [
                "Selamat datang di Manajer Node Nexus.",
                "",
                "Gunakan tombol panah ATAS dan BAWAH untuk navigasi.",
                "Tekan ENTER untuk memilih opsi.",
                "",
                "--- Konfigurasi Saat Ini ---",
                "Wallet: " . ($config['wallet_address'] ?? 'N/A'),
                "Node ID: " . ($config['node_id'] ?? 'N/A')
            ];
             ncurses_mvwaddstr($right_panel, 1, 2, "Panel Informasi");
        }
        
        ncurses_mvwaddstr($right_panel, 2, 1, str_repeat('-', $right_width - 2));
        $log_height = $height - 4;
        $start_line = max(0, count($log_content) - $log_height);
        for ($i = 0; $i < $log_height; $i++) {
            if (isset($log_content[$start_line + $i])) {
                ncurses_mvwaddstr($right_panel, $i + 3, 2, substr($log_content[$start_line + $i], 0, $right_width - 4));
            }
        }
        ncurses_wrefresh($right_panel);

        // --- Handle Input ---
        $input = ncurses_getch();

        if ($active_log_session !== null && (ord('q') === $input || ord('Q') === $input)) {
            $active_log_session = null; // Hentikan melihat log
            continue;
        }
        
        switch ($input) {
            case NCURSES_KEY_UP:
                do { $current_selection = max(0, $current_selection - 1); } while (empty($main_menu_items[$current_selection]['action']));
                break;
            case NCURSES_KEY_DOWN:
                 do { $current_selection = min(count($main_menu_items) - 1, $current_selection + 1); } while (empty($main_menu_items[$current_selection]['action']));
                break;
            case 10: // Enter
                $action = $main_menu_items[$current_selection]['action'];
                if ($action === 'exit') {
                    ncurses_end();
                    return;
                }
                
                // Keluar dari ncurses sementara untuk menjalankan aksi
                ncurses_end();
                echo "\033[2J\033[H"; // Clear screen
                
                if ($action === 'view_logs') {
                    $running_screens = getRunningScreens();
                    if (empty($running_screens)) {
                        echo "Tidak ada sesi screen aktif untuk dilihat.\n";
                        readline("Tekan Enter untuk kembali...");
                    } else {
                        echo "Pilih screen yang ingin dipantau:\n";
                        foreach($running_screens as $idx => $screen) {
                            echo ($idx+1) . ". " . $screen . "\n";
                        }
                        $choice = readline("Pilihan Anda: ");
                        if (isset($running_screens[$choice-1])) {
                             $active_log_session = $running_screens[$choice-1];
                             $last_refresh = 0; // Force refresh on first view
                        } else {
                            echo "Pilihan tidak valid.\n";
                            readline("Tekan Enter untuk kembali...");
                        }
                    }
                } else {
                    // --- Blok Eksekusi Aksi Lainnya ---
                    switch ($action) {
                        case 'update_cli':
                            executeCommand("bash -c 'curl https://cli.nexus.xyz/ | sh && source ~/.bashrc'");
                            readline("Tekan Enter untuk kembali...");
                            break;
                        case 'register_wallet':
                            $wallet_address = readline("Masukkan alamat wallet Anda: ");
                            if (!empty($wallet_address)) {
                                $command = "bash -c 'source ~/.cargo/env && nexus-network register-user --wallet-address " . escapeshellarg($wallet_address) . "'";
                                if (executeCommand($command) === 0) {
                                    $config['wallet_address'] = $wallet_address; writeConfig($configFile, $config); echo "Alamat wallet berhasil disimpan.\n";
                                } else { echo "Pendaftaran alamat wallet gagal.\n"; }
                            }
                            readline("Tekan Enter untuk kembali...");
                            break;
                        case 'register_node':
                            $output_lines = [];
                            $command = "bash -c 'source ~/.cargo/env && nexus-network register-node'";
                            if (executeCommand($command, $output_lines, false) === 0) {
                                 foreach ($output_lines as $line) {
                                    if (preg_match('/(?:Node ID|ID):\s*(\w+)/i', $line, $matches)) {
                                        $config['node_id'] = $matches[1]; writeConfig($configFile, $config);
                                        echo "Node ID berhasil dideteksi dan disimpan: " . $matches[1] . "\n"; break;
                                    }
                                }
                            }
                            readline("Tekan Enter untuk kembali...");
                            break;
                        case 'kill_screen': handleKillScreen(); break;
                        case 'run_screen': case 'run_foreground': case 'run_localbin':
                             $saved_node_id = $config['node_id'] ?? '';
                            $prompt = "Masukkan Node ID" . (!empty($saved_node_id) ? " (tersimpan: $saved_node_id, tekan Enter): " : ": ");
                            $node_id = readline($prompt) ?: $saved_node_id;
                            if(empty($node_id)) { echo "Node ID kosong.\n"; readline("Tekan Enter..."); break; }
                            $safe_node_id = escapeshellarg($node_id);
                            if ($action === 'run_localbin') {
                                $cmd = "/opt/glibc-2.39/lib/ld-linux-x86-64.so.2 --library-path /opt/glibc-2.39/lib:/lib/x86_64-linux-gnu:/usr/lib/x86_64-linux-gnu /usr/local/bin/nexus-network start --node-id " . $safe_node_id;
                            } else {
                                 $cmd = "bash -c 'source ~/.cargo/env && nexus-network start --node-id " . $safe_node_id . "'";
                            }
                            if ($action === 'run_screen') {
                                $screen_name = readline("Nama screen (default: nexusnode): ") ?: 'nexusnode';
                                executeCommand("screen -dmS ".escapeshellarg($screen_name)." ".$cmd);
                                echo "Node telah dimulai di background dalam screen bernama '$screen_name'.\n";
                            } else { executeCommand($cmd); }
                            readline("Tekan Enter untuk kembali...");
                            break;
                        case 'check_glibc': checkGlibcVersion(); break;
                        case 'update_glibc': updateGlibc(); break;
                        case 'create_swap': handleSwapFile(); break;
                        case 'reboot_vps': handleReboot(); break;
                    }
                }
                
                // Kembali ke mode ncurses
                ncurses_init();
                ncurses_curs_set(0);
                ncurses_noecho();
                ncurses_keypad(STDSCR, true);
                ncurses_timeout(500);
                break;
        }
    }
}


// ==================================================================
// ============ MENU SEDERHANA (JIKA NCURSES TIDAK ADA) =============
// ==================================================================
function runSimpleMenu($configFile) {
    while (true) {
        $config = readConfig($configFile);
        $wallet_address_saved = $config['wallet_address'] ?? 'Belum terdaftar';
        $node_id_saved = $config['node_id'] ?? 'Belum terdaftar';

        echo "=================== MENU MANAJEMEN NEXUS ===================\n\n";
        echo "Konfigurasi Saat Ini:\n";
        echo " - Wallet Address : " . $wallet_address_saved . "\n";
        echo " - Node ID        : " . $node_id_saved . "\n\n";
        echo "============================================================\n";
        echo "--- Pendaftaran & Update ---\n";
        echo "1. Update versi Nexus CLI\n";
        echo "2. Register/Update alamat wallet\n";
        echo "3. Create/Register Node ID\n";
        echo "4. Lihat Log Screen\n";
        echo "5. Keluar dari Screen (Quit)\n";
        echo "\n--- Operasi Node ---\n";
        echo "6. Jalankan node di dalam 'screen'\n";
        echo "7. Jalankan node (foreground)\n";
        echo "8. Jalankan Lewat LocalBin (jika ada error GLIBC)\n";
        echo "\n--- Utilitas Sistem ---\n";
        echo "9. Cek versi GLIBC\n";
        echo "10. Update Versi GLIBC (dari 2.35 ke 2.39)\n";
        echo "11. Mengatasi VPS Killed (Swap)\n";
        echo "12. Reboot VPS\n";
        echo "13. Install Ncurses (untuk Log Viewer multi-panel)\n";
        echo "14. Keluar\n";
        echo "============================================================\n";

        $choice = readline("Masukkan pilihan Anda (1-14): ");

        switch ($choice) {
            case '1':
                executeCommand("bash -c 'curl https://cli.nexus.xyz/ | sh && source ~/.bashrc'");
                break;
            case '2':
                $wallet_address = readline("Masukkan alamat wallet Anda: ");
                if (!empty($wallet_address)) {
                    $command = "bash -c 'source ~/.cargo/env && nexus-network register-user --wallet-address " . escapeshellarg($wallet_address) . "'";
                    if (executeCommand($command) === 0) {
                        $config['wallet_address'] = $wallet_address; writeConfig($configFile, $config); echo "Alamat wallet berhasil disimpan.\n";
                    } else { echo "Pendaftaran alamat wallet gagal.\n"; }
                }
                break;
            case '3':
                $output_lines = [];
                $command = "bash -c 'source ~/.cargo/env && nexus-network register-node'";
                if (executeCommand($command, $output_lines, false) === 0) {
                     foreach ($output_lines as $line) {
                        if (preg_match('/(?:Node ID|ID):\s*(\w+)/i', $line, $matches)) {
                            $config['node_id'] = $matches[1]; writeConfig($configFile, $config); echo "Node ID berhasil dideteksi: " . $matches[1] . "\n"; break;
                        }
                    }
                }
                break;
            case '4':
                handleScreenLogsSimple();
                break;
            case '5':
                handleKillScreen();
                break;
            case '6': case '7': case '8':
                $saved_node_id = $config['node_id'] ?? '';
                $prompt = "Masukkan Node ID" . (!empty($saved_node_id) ? " (tersimpan: $saved_node_id, tekan Enter): " : ": ");
                $node_id = readline($prompt) ?: $saved_node_id;
                if(empty($node_id)) { echo "Node ID kosong.\n"; break; }
                $safe_node_id = escapeshellarg($node_id);
                if ($choice === '8') {
                    $cmd = "/opt/glibc-2.39/lib/ld-linux-x86-64.so.2 --library-path /opt/glibc-2.39/lib:/lib/x86_64-linux-gnu:/usr/lib/x86_64-linux-gnu /usr/local/bin/nexus-network start --node-id " . $safe_node_id;
                } else {
                     $cmd = "bash -c 'source ~/.cargo/env && nexus-network start --node-id " . $safe_node_id . "'";
                }
                if ($choice === '6') {
                    $screen_name = readline("Nama screen (default: nexusnode): ") ?: 'nexusnode';
                    executeCommand("screen -dmS ".escapeshellarg($screen_name)." ".$cmd);
                    echo "Node telah dimulai di background dalam screen bernama '$screen_name'.\n";
                } else { executeCommand($cmd); }
                break;
            case '9':
                checkGlibcVersion();
                break;
            case '10':
                updateGlibc();
                break;
            case '11':
                handleSwapFile();
                break;
            case '12':
                handleReboot();
                break;
            case '13':
                installNcurses();
                break;
            case '14':
                echo "Keluar dari skrip. Sampai jumpa!\n";
                exit(0);
            default:
                echo "Pilihan tidak valid. Silakan coba lagi.\n";
        }
    }
}


// ================== SCRIPT UTAMA ==================
echo "Selamat datang di Skrip Manajer Node Nexus!\n\n";
if (!file_exists($configFile)) {
    $setup_choice = strtolower(readline("Ini adalah pertama kali Anda menjalankan skrip. Apakah Anda ingin menjalankan instalasi awal? [y/n]: "));
    if ($setup_choice === 'y') {
        initialSetup();
    } else {
        echo "Melewatkan proses instalasi awal.\n";
    }
}

if (function_exists('ncurses_init')) {
    runTui($configFile);
} else {
    echo "Peringatan: Ekstensi 'ncurses' PHP tidak ditemukan.\n";
    echo "Tampilan multi-panel dinonaktifkan. Menjalankan dalam mode menu sederhana.\n";
    echo "Anda dapat menginstalnya dari menu 'Utilitas Sistem' untuk pengalaman terbaik.\n\n";
    runSimpleMenu($configFile);
}
?>
