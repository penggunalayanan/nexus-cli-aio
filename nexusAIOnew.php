#!/usr/bin/env php
<?php
// nexus_tool.php - Skrip All-in-One untuk Instalasi dan Manajemen Nexus Node
// Dijalankan dengan: sudo php nexus_tool.php

declare(strict_types=1);

// Variabel global
$nodeId = null;
$walletAddress = null;
$configFile = '';
$userHome = '';
$screenPath = ''; // Path absolut ke executable 'screen'
$nexusCliPath = ''; // Path absolut ke nexus-network CLI

// --- Helper Functions ---
function colorLog(string $text, string $color): string {
    $colors = [
        'green' => "\033[0;32m",
        'red'   => "\033[0;31m",
        'yellow'=> "\033[1;33m",
        'blue'  => "\033[0;34m",
        'cyan'  => "\033[0;36m",
        'reset' => "\033[0m"
    ];
    return ($colors[$color] ?? '') . $text . $colors['reset'];
}

function runCommand(string $command): void {
    echo colorLog("\n▶️ Menjalankan: ", 'yellow') . $command . "\n";
    passthru($command, $result_code);
    if ($result_code !== 0) {
        echo colorLog("❌ Perintah gagal dengan kode error: $result_code\n", 'red');
    } else {
        echo colorLog("✅ Perintah selesai.\n", 'green');
    }
    echo "--------------------------------------------------------\n";
}

function getUserHome(): string {
    $user = getenv('SUDO_USER');
    if ($user && function_exists('posix_getpwnam')) {
        $userInfo = posix_getpwnam($user);
        if ($userInfo && isset($userInfo['dir'])) {
            return $userInfo['dir'];
        }
    }
    return getenv('HOME');
}

/**
 * Mencari path absolut dari sebuah executable command.
 */
function findExecutable(string $command, ?string $userHome = null): ?string {
    if (is_executable($command)) return $command;

    $paths = explode(':', getenv('PATH') ?: '');
    $paths = array_merge($paths, ['/usr/bin', '/bin', '/usr/local/bin', '/sbin', '/usr/sbin', '/snap/bin']);
    if ($userHome) {
        $paths[] = $userHome . '/.local/bin';
    }
    $paths = array_unique(array_filter($paths));

    foreach ($paths as $path) {
        $fullPath = rtrim($path, '/') . '/' . $command;
        if (is_executable($fullPath)) return $fullPath;
    }
    
    $whichOutput = shell_exec('which ' . escapeshellarg($command));
    if ($whichOutput && is_executable(trim($whichOutput))) return trim($whichOutput);
    
    return null;
}

// --- Fungsi untuk Manajemen Konfigurasi ---
function saveConfiguration(): void {
    global $configFile, $walletAddress, $nodeId;
    $config = ['walletAddress' => $walletAddress, 'nodeId' => $nodeId];
    file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
}

function loadConfiguration(): void {
    global $configFile, $walletAddress, $nodeId;
    if (file_exists($configFile)) {
        $config = json_decode(file_get_contents($configFile), true);
        $walletAddress = $config['walletAddress'] ?? null;
        $nodeId = $config['nodeId'] ?? null;
        echo colorLog("Konfigurasi sebelumnya berhasil dimuat.\n", 'green');
    }
}

// --- Fungsi untuk setiap bagian menu ---

function checkStatus(): void {
    global $userHome, $screenPath, $nexusCliPath;
    echo colorLog("🔍 Memeriksa Status Dependensi...\n", 'cyan');
    
    echo "- Perintah 'screen': ";
    if ($screenPath) {
        echo colorLog("DITEMUKAN", 'green') . " di " . colorLog($screenPath, 'yellow') . "\n";
    } else {
        echo colorLog("TIDAK DITEMUKAN", 'red') . ". Jalankan 'sudo apt install screen'.\n";
    }

    $rustc_path = $userHome . '/.cargo/bin/rustc';
    echo "- Perintah 'rustc':   ";
    if (is_executable($rustc_path)) {
        echo colorLog("DITEMUKAN", 'green') . " di " . colorLog($rustc_path, 'yellow') . "\n";
    } else {
        echo colorLog("TIDAK DITEMUKAN", 'red') . ". Jalankan instalasi awal (Menu Utama -> 1).\n";
    }

    echo "- Perintah 'nexus-network': ";
    if ($nexusCliPath && is_executable($nexusCliPath)) {
        echo colorLog("DITEMUKAN", 'green') . " di " . colorLog($nexusCliPath, 'yellow') . "\n";
    } else {
        echo colorLog("TIDAK DITEMUKAN", 'red') . ". Jalankan 'Update versi Nexus CLI' (Menu Utama -> 2).\n";
    }
}

function initialSetup(): void {
    global $userHome;
    echo colorLog("🚀 Memulai Instalasi Awal & Setup Dependensi...\n", 'cyan');
    runCommand("sudo apt update && sudo apt upgrade -y");
    runCommand("sudo apt install screen curl build-essential pkg-config libssl-dev git-all -y");
    runCommand("sudo apt install protobuf-compiler -y");
    runCommand("sudo apt update");
    
    echo colorLog("\n Rust akan diinstal...\n", 'yellow');
    runCommand("curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y");

    $cargo_path = $userHome . '/.cargo/bin';
    runCommand("PATH={$cargo_path}:\$PATH rustup target add riscv32i-unknown-none-elf");

    echo colorLog("\n Menginstal Nexus CLI...\n", 'cyan');
    updateNexusCLI();

    echo colorLog("🎉 Instalasi Awal Selesai! Jalankan ulang skrip untuk masuk ke menu utama.\n", 'green');
    exit;
}

function updateNexusCLI(): void {
    echo colorLog("🔄 Memperbarui versi Nexus CLI...\n", 'cyan');
    runCommand("curl https://cli.nexus.xyz/ | sh");
    echo colorLog("Nexus CLI telah diperbarui. Mohon jalankan ulang skrip agar path terdeteksi.\n", 'yellow');
}

function registerWallet(): void {
    global $walletAddress, $nexusCliPath;
    if (!$nexusCliPath) {
        echo colorLog("Perintah 'nexus-network' tidak ditemukan. Jalankan 'Cek Status' (#1) dan 'Update CLI' (#2).\n", 'red');
        return;
    }
    echo colorLog("🔑 Pendaftaran Alamat Wallet\n", 'cyan');
    $addressInput = readline("Masukkan alamat wallet Anda: ");
    if (empty(trim($addressInput))) {
        echo colorLog("Alamat wallet tidak boleh kosong.\n", 'red');
        return;
    }
    
    $walletAddress = trim($addressInput);
    $command = $nexusCliPath . " register-user --wallet-address " . escapeshellarg($walletAddress);
    runCommand($command);
    saveConfiguration();
    echo colorLog("Alamat wallet '$walletAddress' telah disimpan.\n", 'yellow');
}

function createNodeId(): void {
    global $nodeId, $nexusCliPath;
    if (!$nexusCliPath) {
        echo colorLog("Perintah 'nexus-network' tidak ditemukan. Jalankan 'Cek Status' (#1) dan 'Update CLI' (#2).\n", 'red');
        return;
    }
    echo colorLog("🆔 Membuat Node ID Baru...\n", 'cyan');
    
    $command = $nexusCliPath . " register-node";
    echo colorLog("\n▶️ Menjalankan: ", 'yellow') . $command . "\n";

    // Menggunakan exec untuk menangkap output
    exec($command, $output, $return_var);
    $fullOutput = implode("\n", $output);
    echo $fullOutput . "\n"; // Tampilkan seluruh output untuk debugging

    if ($return_var === 0) {
        $foundId = false;
        // Regex yang lebih fleksibel untuk menangkap Node ID
        // Mencari "Node registered successfully with ID: <ID>" atau "Successfully registered node with ID: <ID>"
        if (preg_match('/(?:Node registered successfully with ID|Successfully registered node with ID):\s*([a-zA-Z0-9\-]+)/i', $fullOutput, $matches)) {
            $nodeId = trim($matches[1]);
            saveConfiguration();
            echo colorLog("\n✅ Node ID berhasil dibuat dan disimpan: " . $nodeId . "\n", 'green');
            $foundId = true;
        }
        
        if (!$foundId) {
            echo colorLog("Tidak dapat menemukan Node ID pada output, mohon periksa kembali. Output lengkap di atas.\n", 'red');
        }
    } else {
        echo colorLog("Gagal membuat Node ID. Pastikan wallet Anda sudah terdaftar. Kode error: $return_var\n", 'red');
    }
}

function startNode(): void {
    global $nodeId, $nexusCliPath;
    if (!$nexusCliPath) {
        echo colorLog("Perintah 'nexus-network' tidak ditemukan. Jalankan 'Cek Status' (#1) dan 'Update CLI' (#2).\n", 'red');
        return;
    }
    echo colorLog("⚡ Menjalankan Node...\n", 'cyan');
    
    $idToUse = $nodeId;
    if (empty($idToUse)) {
        echo colorLog("Node ID belum disimpan. Masukkan secara manual.\n", 'yellow');
        $idToUse = readline("Masukkan Node ID: ");
    } else {
        echo "Gunakan Node ID yang tersimpan: " . colorLog($idToUse, 'green') . "? (Y/n): ";
        if (strtolower(trim(readline())) === 'n') {
            $idToUse = readline("Masukkan Node ID baru: ");
        }
    }

    if (empty(trim($idToUse))) {
        echo colorLog("Node ID tidak boleh kosong.\n", 'red');
        return;
    }
    
    echo colorLog("Untuk menghentikan node, tekan CTRL+C.\n", 'yellow');
    $command = $nexusCliPath . " start --node-id " . escapeshellarg(trim($idToUse));
    runCommand($command);
}

function startNodeInBackground(): void {
    global $nodeId, $screenPath, $nexusCliPath;
    if (!$screenPath) {
        echo colorLog("Perintah 'screen' tidak ditemukan. Jalankan 'Cek Status' (#1) untuk info.\n", 'red');
        return;
    }
    if (!$nexusCliPath) {
        echo colorLog("Perintah 'nexus-network' tidak ditemukan. Jalankan 'Cek Status' (#1) dan 'Update CLI' (#2).\n", 'red');
        return;
    }
    if (empty($nodeId)) {
        echo colorLog("Node ID belum disimpan. Silakan buat Node ID terlebih dahulu (Pilihan #4).\n", 'red');
        return;
    }
    
    echo colorLog("🖥️ Menjalankan Node di mode screen (background)...\n", 'cyan');
    
    $baseNames = ['nexus', 'nexusnode'];
    $screenName = $baseNames[array_rand($baseNames)] . '-' . rand(100, 999);
    
    echo colorLog("Node ID yang akan digunakan: ", 'cyan') . colorLog($nodeId, 'yellow') . "\n";
    echo colorLog("Nama screen yang dibuat secara acak: ", 'cyan') . colorLog($screenName, 'yellow') . "\n";

    $screen_command = $nexusCliPath . " start --node-id " . escapeshellarg(trim($nodeId));
    $command = $screenPath . " -S " . escapeshellarg(trim($screenName)) . " -dm bash -c " . escapeshellarg($screen_command);
    runCommand($command);

    echo colorLog("✅ Node telah dimulai di dalam screen bernama '{$screenName}'.\n", 'green');
}

/**
 * Fungsi baru untuk menjalankan node dengan jumlah thread maksimal yang ditentukan.
 */
function startNodeWithMaxThreads(): void {
    global $nodeId, $screenPath, $nexusCliPath;
    if (!$screenPath) {
        echo colorLog("Perintah 'screen' tidak ditemukan. Jalankan 'Cek Status' (#1) untuk info.\n", 'red');
        return;
    }
    if (!$nexusCliPath) {
        echo colorLog("Perintah 'nexus-network' tidak ditemukan. Jalankan 'Cek Status' (#1) dan 'Update CLI' (#2).\n", 'red');
        return;
    }
    if (empty($nodeId)) {
        echo colorLog("Node ID belum disimpan. Silakan buat Node ID terlebih dahulu (Pilihan #4).\n", 'red');
        return;
    }

    echo colorLog("🖥️ Menjalankan Node dengan 4 threads di mode screen...\n", 'cyan');

    $baseNames = ['nexus', 'nexusnode', 'nexus-prover'];
    $screenName = $baseNames[array_rand($baseNames)] . '-' . rand(100, 999);
    
    echo colorLog("Node ID yang akan digunakan: ", 'cyan') . colorLog($nodeId, 'yellow') . "\n";
    echo colorLog("Nama screen yang dibuat secara acak: ", 'cyan') . colorLog($screenName, 'yellow') . "\n";

    // Perintah untuk menjalankan node dengan 4 thread
    $screen_command = $nexusCliPath . " start --node-id " . escapeshellarg(trim($nodeId)) . " --max-threads 4";
    $command = $screenPath . " -S " . escapeshellarg(trim($screenName)) . " -dm bash -c " . escapeshellarg($screen_command);
    runCommand($command);

    echo colorLog("✅ Node telah dimulai di dalam screen bernama '{$screenName}'.\n", 'green');
}

function listScreenSessions(): array {
    global $screenPath;
    if (!$screenPath) return [];

    exec($screenPath . ' -ls', $output);
    $sessions = [];
    foreach ($output as $line) {
        if (preg_match('/^\s+([0-9]+\..*?)\s+\(/', $line, $matches)) {
            $sessions[] = trim($matches[1]);
        }
    }
    return $sessions;
}

function viewScreenLogs(): void {
    global $screenPath;
    if (!$screenPath) {
        echo colorLog("Perintah 'screen' tidak ditemukan. Jalankan 'Cek Status' (#1) untuk info.\n", 'red');
        return;
    }
    echo colorLog("📊 Mengecek sesi screen yang berjalan...\n", 'cyan');
    $sessions = listScreenSessions();

    if (empty($sessions)) {
        echo colorLog("Tidak ada sesi screen yang sedang berjalan.\n", 'yellow');
        return;
    }

    echo colorLog("Sesi screen yang ditemukan:\n", 'green');
    foreach ($sessions as $index => $session) echo ($index + 1) . ". " . colorLog($session, 'yellow') . "\n";

    echo "\n";
    echo colorLog("Pilih sesi untuk disambungkan (ketik nomornya), atau 'x' untuk kembali: ", 'cyan');
    $choice = trim(readline());
    
    if (strtolower($choice) === 'x' || $choice === '') return;
    
    $choiceIndex = intval($choice) - 1;
    if (isset($sessions[$choiceIndex])) {
        $selectedSession = $sessions[$choiceIndex];
        echo colorLog("Menyambungkan ke sesi '{$selectedSession}'...\n", 'green');
        passthru('clear');
        passthru($screenPath . ' -r ' . escapeshellarg($selectedSession));
        echo colorLog("\nKembali dari sesi screen. Tekan [Enter] untuk melanjutkan.", 'cyan');
        readline();
    } else {
        echo colorLog("Pilihan tidak valid.\n", 'red');
    }
}

function stopScreen(): void {
    global $screenPath;
     if (!$screenPath) {
        echo colorLog("Perintah 'screen' tidak ditemukan. Jalankan 'Cek Status' (#1) untuk info.\n", 'red');
        return;
    }
    echo colorLog("🛑 Menghentikan sesi screen...\n", 'cyan');
    $sessions = listScreenSessions();

    if (empty($sessions)) {
        echo colorLog("Tidak ada sesi screen yang sedang berjalan.\n", 'yellow');
        return;
    }

    echo colorLog("Sesi screen yang ditemukan:\n", 'green');
    foreach ($sessions as $index => $session) echo ($index + 1) . ". " . colorLog($session, 'yellow') . "\n";

    echo "\n";
    echo colorLog("Pilih sesi untuk dihentikan (ketik nomornya), atau 'x' untuk kembali: ", 'cyan');
    $choice = trim(readline());

    if (strtolower($choice) === 'x' || $choice === '') return;

    $choiceIndex = intval($choice) - 1;
    if (isset($sessions[$choiceIndex])) {
        $selectedSession = $sessions[$choiceIndex];
        $command = $screenPath . ' -X -S ' . escapeshellarg($selectedSession) . ' quit';
        runCommand($command);
        echo colorLog("Sesi '{$selectedSession}' telah dihentikan.\n", "green");
    } else {
        echo colorLog("Pilihan tidak valid.\n", 'red');
    }
}

function manageGlibc(): void {
    global $userHome;
    echo colorLog("🔎 Mengecek versi GLIBC...\n", 'cyan');
    exec('ldd --version', $output, $return_var);
    if ($return_var !== 0) {
        echo colorLog("Tidak dapat mengecek versi GLIBC.\n", 'red');
        return;
    }

    $versionLine = $output[0] ?? '';
    if (!preg_match('/([0-9]+\.[0-9]+)$/', $versionLine, $matches)) {
        echo colorLog("Tidak dapat mendeteksi versi GLIBC dari output.\n", 'red');
        return;
    }
    $glibcVersion = $matches[1];
    echo colorLog("Versi GLIBC terdeteksi: ", 'green') . colorLog($glibcVersion, 'yellow') . "\n";

    if ($glibcVersion === '2.35') {
        echo "\n" . colorLog(str_repeat("=", 56)."\n", 'red');
        echo colorLog("                       PERINGATAN KERAS\n", 'yellow');
        echo colorLog(str_repeat("=", 56)."\n", 'red');
        echo colorLog("Mengubah GLIBC adalah operasi SANGAT BERISIKO dan dapat merusak\n", 'yellow');
        echo colorLog("sistem Anda. Lanjutkan hanya jika Anda tahu apa yang Anda lakukan.\n", 'yellow');

        echo colorLog("\nSetuju untuk update ke GLIBC 2.39? (Ketik 'SETUJU'): ", 'cyan');
        if (trim(readline()) === 'SETUJU') {
            echo colorLog("Konfirmasi diterima. Memulai proses update GLIBC...\n", 'green');
            
            $originalDir = getcwd();
            chdir($userHome); // Pindah ke home directory untuk proses
            
            runCommand("sudo apt update");
            runCommand("sudo apt install -y gawk bison gcc make wget tar");
            runCommand("wget -c https://ftp.gnu.org/gnu/glibc/glibc-2.39.tar.gz");
            
            $updateCommands = [
                'tar -zxvf glibc-2.39.tar.gz',
                'cd glibc-2.39',
                'mkdir -p glibc-build',
                'cd glibc-build',
                '../configure --prefix=/opt/glibc-2.39',
                'make -j$(nproc)',
                'sudo make install'
            ];
            runCommand(implode(' && ', $updateCommands));
            
            chdir($originalDir); // Kembali ke direktori awal
            echo colorLog("Proses update GLIBC selesai. Disarankan untuk me-reboot sistem Anda.", "green");
        } else {
            echo colorLog("Update dibatalkan.\n", 'red');
        }
    } else {
        echo colorLog("Versi GLIBC Anda bukan 2.35. Tidak ada tindakan yang diperlukan.\n", 'green');
    }
}

function manageSwap(): void {
    echo colorLog("💾 Mengelola Swap File untuk Mengatasi VPS 'Killed'...\n", 'cyan');
    echo "Fungsi ini akan membuat swap file untuk membantu VPS dengan RAM terbatas.\n";
    echo "Pilih ukuran swap yang diinginkan:\n";
    echo "1. 4G (Untuk VPS dengan RAM 4GB atau kurang)\n";
    echo "2. 8G (Untuk VPS dengan RAM 8GB)\n";
    echo "3. 16G (Untuk VPS dengan RAM 16GB atau lebih)\n";
    
    echo colorLog("Masukkan pilihan Anda [1-3]: ", "yellow");
    $choice = trim(readline());
    $size = '';

    switch ($choice) {
        case '1': $size = '4G'; break;
        case '2': $size = '8G'; break;
        case '3': $size = '16G'; break;
        default:
            echo colorLog("Pilihan tidak valid.\n", 'red');
            return;
    }

    echo colorLog("Anda memilih untuk membuat swap file sebesar $size. Lanjutkan? (y/n): ", 'cyan');
    if (strtolower(trim(readline())) !== 'y') {
        echo colorLog("Pembuatan swap dibatalkan.\n", 'red');
        return;
    }

    runCommand("sudo fallocate -l $size /swapfile");
    runCommand("sudo chmod 600 /swapfile");
    runCommand("sudo mkswap /swapfile");
    runCommand("sudo swapon /swapfile");
    runCommand("echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab");
    
    echo colorLog("Swap file sebesar $size berhasil dibuat dan diaktifkan.\n", 'green');
}

function rebootVps(): void {
    echo colorLog("🔄 Reboot VPS\n", 'red');
    echo colorLog("PERINGATAN: Ini akan me-reboot VPS Anda. Semua sesi yang tidak disimpan akan hilang.\n", 'yellow');
    echo colorLog("Apakah Anda yakin ingin melanjutkan? (y/n): ", 'cyan');
    if (strtolower(trim(readline())) === 'y') {
        echo colorLog("Perintah reboot dikirim...\n", 'red');
        runCommand("sudo reboot");
    } else {
        echo colorLog("Reboot dibatalkan.\n", 'green');
    }
}


// --- Main Program Logic ---

if (posix_getuid() !== 0) {
    echo colorLog("Error: Skrip ini harus dijalankan dengan sudo.", 'red') . "\n";
    echo "Silakan coba lagi dengan: " . colorLog("sudo php " . basename(__FILE__), 'yellow') . "\n";
    exit(1);
}

// Inisialisasi variabel global
$userHome = getUserHome();
$configFile = $userHome . '/.nexus_tool_config.json';
$screenPath = findExecutable('screen');
$nexusCliPath = findExecutable('nexus-network', $userHome);

echo colorLog("============================================\n", 'blue');
echo colorLog("   Selamat Datang di Nexus Node Tool v2.4   \n", 'blue');
echo colorLog("============================================\n", 'blue');
echo "Pilih Opsi:\n";
echo "1. " . colorLog("[INSTALASI AWAL]", 'cyan') . " (Jalankan jika ini pertama kali)\n";
echo "2. " . colorLog("[MENU UTAMA]", 'green') . " (Langsung ke manajemen node)\n";
echo colorLog("Masukkan pilihan Anda (1/2): ", "yellow");
if (trim(readline()) === '1') {
    initialSetup();
    exit;
}

loadConfiguration();

while (true) {
    // Perbarui path nexusCliPath setiap kali masuk loop utama
    // Ini penting jika CLI baru diinstal atau diperbarui
    $nexusCliPath = findExecutable('nexus-network', $userHome);

    echo colorLog("\nTekan [Enter] untuk menampilkan menu...", 'cyan');
    readline();
    passthru('clear');
    
    echo colorLog("================= MENU MANAJEMEN NEXUS =================\n", 'blue');
    echo colorLog("Konfigurasi Tersimpan:\n", 'cyan');
    $displayWallet = $walletAddress ?? colorLog('[Belum diatur]', 'red');
    echo "- Wallet Address : " . colorLog($displayWallet, 'yellow') . "\n";
    $displayNodeId = $nodeId ?? colorLog('[Belum diatur]', 'red');
    echo "- Node ID        : " . colorLog($displayNodeId, 'yellow') . "\n";
    echo colorLog("========================================================\n", 'blue');

    echo "\nPilih tindakan:\n";
    echo "1. " . colorLog("Cek Status Dependensi", 'green') . "\n";
    echo "2. Update versi Nexus CLI\n";
    echo "3. Register/Update alamat wallet\n";
    echo "4. Create/Update Node ID\n";
    echo "5. Run node (foreground)\n";
    echo "6. " . colorLog("Run Node (background)", 'cyan') . "\n";
    // Menambahkan opsi baru di sini
    echo "6b. " . colorLog("Run Node with MaxThread (On Screen)", 'yellow') . "\n";
    echo "7. Lihat Sesi Screen (Logs)\n";
    echo "8. " . colorLog("Hentikan Sesi Screen", 'red') . "\n";
    echo "9. " . colorLog("Utilitas Sistem", 'yellow') . "\n";
    echo "10. Keluar dari skrip\n";
    
    echo colorLog("Masukkan pilihan Anda [1-10]: ", "yellow");
    $choice = readline();
    
    switch (trim($choice)) {
        case '1': checkStatus(); break;
        case '2': updateNexusCLI(); break;
        case '3': registerWallet(); break;
        case '4': createNodeId(); break;
        case '5': startNode(); break;
        case '6': startNodeInBackground(); break;
        // Menambahkan case untuk opsi baru
        case '6b': startNodeWithMaxThreads(); break;
        case '7': viewScreenLogs(); break;
        case '8': stopScreen(); break;
        case '9': showSystemUtilitiesMenu(); break; // Panggil submenu baru
        case '10': echo colorLog("👋 Sampai jumpa!\n", 'green'); exit(0);
        default: echo colorLog("Pilihan tidak valid. Silakan coba lagi.\n", 'red'); break;
    }
}

function showSystemUtilitiesMenu(): void {
    while (true) {
        passthru('clear');
        echo colorLog("========== MENU UTILITAS SISTEM ==========\n", 'yellow');
        echo "1. Cek & Update Versi GLIBC\n";
        echo "2. Mengatasi VPS Killed (Buat Swap)\n";
        echo "3. Reboot VPS\n";
        echo "4. Kembali ke Menu Utama\n";
        
        echo colorLog("Masukkan pilihan Anda [1-4]: ", "yellow");
        $choice = readline();
        
        switch (trim($choice)) {
            case '1': manageGlibc(); break;
            case '2': manageSwap(); break;
            case '3': rebootVps(); break;
            case '4': return; // Kembali ke loop menu utama
            default: echo colorLog("Pilihan tidak valid.\n", 'red'); break;
        }
        echo colorLog("\nTekan [Enter] untuk kembali ke menu utilitas...", 'cyan');
        readline();
    }
}
