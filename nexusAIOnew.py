#!/usr/bin/env python3
# nexus_tool.py - Skrip All-in-One untuk Instalasi dan Manajemen Nexus Node
# Dijalankan dengan: sudo python3 nexus_tool.py

import os
import sys
import json
import subprocess
import getpass
import re
from typing import List, Optional

# Variabel global
node_id = None
wallet_address = None
config_file = ''
user_home = ''
screen_path = None  # Path absolut ke executable 'screen'
nexus_cli_path = None  # Path absolut ke nexus-network CLI

# --- Helper Functions ---
def color_log(text: str, color: str) -> str:
    """Mengembalikan teks dengan kode warna ANSI."""
    colors = {
        'green': "\033[0;32m",
        'red': "\033[0;31m",
        'yellow': "\033[1;33m",
        'blue': "\033[0;34m",
        'cyan': "\033[0;36m",
        'reset': "\033[0m"
    }
    return f"{colors.get(color, '')}{text}{colors['reset']}"

def run_command(command: str) -> bool:
    """Menjalankan perintah dan mencetak output secara real-time."""
    print(color_log(f"\n▶️ Menjalankan: ", 'yellow') + command)
    try:
        process = subprocess.Popen(command, shell=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
        for line in process.stdout:
            sys.stdout.write(line)
            sys.stdout.flush()
        process.wait()
        result_code = process.returncode
    except FileNotFoundError:
        print(color_log("❌ Perintah tidak ditemukan.", 'red'))
        result_code = 1
    
    if result_code != 0:
        print(color_log(f"❌ Perintah gagal dengan kode error: {result_code}\n", 'red'))
    else:
        print(color_log("✅ Perintah selesai.\n", 'green'))
    print("--------------------------------------------------------")
    return result_code == 0

def get_user_home() -> str:
    """Mendapatkan direktori home pengguna yang menjalankan sudo."""
    sudo_user = os.getenv('SUDO_USER')
    if sudo_user:
        try:
            import pwd
            return pwd.getpwnam(sudo_user).pw_dir
        except (ImportError, KeyError):
            pass
    return os.path.expanduser('~')

def find_executable(command: str, user_home: Optional[str] = None) -> Optional[str]:
    """Mencari path absolut dari sebuah executable command."""
    if os.path.isabs(command) and os.access(command, os.X_OK):
        return command

    paths = os.environ.get('PATH', '').split(os.pathsep)
    paths.extend(['/usr/bin', '/bin', '/usr/local/bin', '/sbin', '/usr/sbin', '/snap/bin'])
    if user_home:
        paths.append(os.path.join(user_home, '.local', 'bin'))
        
    for path in sorted(list(set(paths))):
        full_path = os.path.join(path, command)
        if os.path.isfile(full_path) and os.access(full_path, os.X_OK):
            return full_path
    
    try:
        which_output = subprocess.check_output(['which', command], text=True).strip()
        if which_output and os.access(which_output, os.X_OK):
            return which_output
    except (subprocess.CalledProcessError, FileNotFoundError):
        pass
    
    return None

def get_input(prompt: str) -> str:
    """Menangani input pengguna dengan pesan yang dikodekan."""
    return input(color_log(prompt, 'cyan'))

# --- Fungsi untuk Manajemen Konfigurasi ---
def save_configuration():
    """Menyimpan konfigurasi ke file JSON."""
    global config_file, wallet_address, node_id
    config = {'walletAddress': wallet_address, 'nodeId': node_id}
    try:
        with open(config_file, 'w') as f:
            json.dump(config, f, indent=4)
    except IOError as e:
        print(color_log(f"❌ Gagal menyimpan konfigurasi: {e}", 'red'))

def load_configuration():
    """Memuat konfigurasi dari file JSON."""
    global config_file, wallet_address, node_id
    if os.path.exists(config_file):
        try:
            with open(config_file, 'r') as f:
                config = json.load(f)
                wallet_address = config.get('walletAddress')
                node_id = config.get('nodeId')
                print(color_log("Konfigurasi sebelumnya berhasil dimuat.\n", 'green'))
        except (IOError, json.JSONDecodeError) as e:
            print(color_log(f"❌ Gagal memuat konfigurasi: {e}", 'red'))
            
# --- Fungsi untuk setiap bagian menu ---

def check_status():
    """Memeriksa status dependensi yang diperlukan."""
    global user_home, screen_path, nexus_cli_path
    print(color_log("🔍 Memeriksa Status Dependensi...\n", 'cyan'))
    
    print("- Perintah 'screen': ", end='')
    if screen_path:
        print(color_log("DITEMUKAN", 'green') + f" di {color_log(screen_path, 'yellow')}")
    else:
        print(color_log("TIDAK DITEMUKAN", 'red') + ". Jalankan 'sudo apt install screen'.")

    rustc_path = os.path.join(user_home, '.cargo', 'bin', 'rustc')
    print("- Perintah 'rustc':   ", end='')
    if os.path.isfile(rustc_path) and os.access(rustc_path, os.X_OK):
        print(color_log("DITEMUKAN", 'green') + f" di {color_log(rustc_path, 'yellow')}")
    else:
        print(color_log("TIDAK DITEMUKAN", 'red') + ". Jalankan instalasi awal (Menu Utama -> 1).")

    print("- Perintah 'nexus-network': ", end='')
    if nexus_cli_path and os.access(nexus_cli_path, os.X_OK):
        print(color_log("DITEMUKAN", 'green') + f" di {color_log(nexus_cli_path, 'yellow')}")
    else:
        print(color_log("TIDAK DITEMUKAN", 'red') + ". Jalankan 'Update versi Nexus CLI' (Menu Utama -> 2).")

def initial_setup():
    """Menjalankan instalasi awal dan setup dependensi."""
    global user_home
    print(color_log("🚀 Memulai Instalasi Awal & Setup Dependensi...\n", 'cyan'))
    run_command("sudo apt update && sudo apt upgrade -y")
    run_command("sudo apt install screen curl build-essential pkg-config libssl-dev git-all -y")
    run_command("sudo apt install protobuf-compiler -y")
    run_command("sudo apt update")
    
    print(color_log("\n Rust akan diinstal...\n", 'yellow'))
    if not run_command("curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y"):
        sys.exit(1)

    cargo_path = os.path.join(user_home, '.cargo', 'bin')
    os.environ['PATH'] = f"{cargo_path}:{os.environ['PATH']}"
    run_command("rustup target add riscv32i-unknown-none-elf")

    print(color_log("\n Menginstal Nexus CLI...\n", 'cyan'))
    update_nexus_cli()

    print(color_log("🎉 Instalasi Awal Selesai! Jalankan ulang skrip untuk masuk ke menu utama.\n", 'green'))
    sys.exit(0)

def update_nexus_cli():
    """Memperbarui versi Nexus CLI."""
    print(color_log("🔄 Memperbarui versi Nexus CLI...\n", 'cyan'))
    run_command("curl https://cli.nexus.xyz/ | sh")
    print(color_log("Nexus CLI telah diperbarui. Mohon jalankan ulang skrip agar path terdeteksi.\n", 'yellow'))

def register_wallet():
    """Mendaftarkan alamat dompet."""
    global wallet_address, nexus_cli_path
    if not nexus_cli_path:
        print(color_log("Perintah 'nexus-network' tidak ditemukan. Jalankan 'Cek Status' (#1) dan 'Update CLI' (#2).\n", 'red'))
        return
    print(color_log("🔑 Pendaftaran Alamat Wallet\n", 'cyan'))
    address_input = get_input("Masukkan alamat wallet Anda: ")
    if not address_input.strip():
        print(color_log("Alamat wallet tidak boleh kosong.\n", 'red'))
        return
    
    wallet_address = address_input.strip()
    command = f"{nexus_cli_path} register-user --wallet-address {subprocess.list2cmdline([wallet_address])}"
    if run_command(command):
        save_configuration()
        print(color_log(f"Alamat wallet '{wallet_address}' telah disimpan.\n", 'yellow'))

def create_node_id():
    """Membuat dan menyimpan ID node baru."""
    global node_id, nexus_cli_path
    if not nexus_cli_path:
        print(color_log("Perintah 'nexus-network' tidak ditemukan. Jalankan 'Cek Status' (#1) dan 'Update CLI' (#2).\n", 'red'))
        return
    print(color_log("🆔 Membuat Node ID Baru...\n", 'cyan'))
    
    command = f"{nexus_cli_path} register-node"
    
    try:
        print(color_log(f"\n▶️ Menjalankan: ", 'yellow') + command)
        result = subprocess.run(command, shell=True, check=True, text=True, capture_output=True)
        full_output = result.stdout
        print(full_output) # Tampilkan seluruh output untuk debugging

        found_id = False
        # Regex yang lebih fleksibel untuk menangkap Node ID
        match = re.search(r'(?:Node registered successfully with ID|Successfully registered node with ID):\s*([a-zA-Z0-9\-]+)', full_output)
        if match:
            node_id = match.group(1).strip()
            save_configuration()
            print(color_log(f"\n✅ Node ID berhasil dibuat dan disimpan: {node_id}\n", 'green'))
            found_id = True
        
        if not found_id:
            print(color_log("Tidak dapat menemukan Node ID pada output, mohon periksa kembali. Output lengkap di atas.\n", 'red'))

    except subprocess.CalledProcessError as e:
        print(color_log(f"Gagal membuat Node ID. Pastikan wallet Anda sudah terdaftar. Kode error: {e.returncode}\n", 'red'))

def start_node(background: bool = False, max_threads: Optional[int] = None):
    """Menjalankan node."""
    global node_id, screen_path, nexus_cli_path
    if background and not screen_path:
        print(color_log("Perintah 'screen' tidak ditemukan. Jalankan 'Cek Status' (#1) untuk info.\n", 'red'))
        return
    if not nexus_cli_path:
        print(color_log("Perintah 'nexus-network' tidak ditemukan. Jalankan 'Cek Status' (#1) dan 'Update CLI' (#2).\n", 'red'))
        return
    
    print(color_log(f"⚡ Menjalankan Node{' di background' if background else ''}...\n", 'cyan'))
    
    id_to_use = node_id
    if not id_to_use:
        print(color_log("Node ID belum disimpan. Masukkan secara manual.", 'yellow'))
        id_to_use = get_input("Masukkan Node ID: ")
    else:
        confirm = get_input(f"Gunakan Node ID yang tersimpan: {color_log(id_to_use, 'green')}? (Y/n): ").lower()
        if confirm == 'n':
            id_to_use = get_input("Masukkan Node ID baru: ")

    if not id_to_use.strip():
        print(color_log("Node ID tidak boleh kosong.\n", 'red'))
        return

    command = [f"{nexus_cli_path}", "start", "--node-id", id_to_use.strip()]
    if max_threads:
        command.extend(["--max-threads", str(max_threads)])

    if background:
        print(color_log("Node ID yang akan digunakan: ", 'cyan') + color_log(id_to_use, 'yellow'))
        import random
        screen_name = f"nexus-{random.randint(100, 999)}"
        if max_threads:
            screen_name = f"nexus-prover-{random.randint(100, 999)}"
        
        print(color_log(f"Nama screen yang dibuat secara acak: ", 'cyan') + color_log(screen_name, 'yellow'))
        
        screen_command = " ".join([subprocess.list2cmdline(command)])
        full_command = f"{screen_path} -S {screen_name} -dm bash -c {subprocess.list2cmdline([screen_command])}"
        run_command(full_command)
        print(color_log(f"✅ Node telah dimulai di dalam screen bernama '{screen_name}'.\n", 'green'))
    else:
        print(color_log("Untuk menghentikan node, tekan CTRL+C.\n", 'yellow'))
        run_command(subprocess.list2cmdline(command))

def list_screen_sessions() -> List[str]:
    """Mendapatkan daftar sesi screen yang berjalan."""
    global screen_path
    if not screen_path:
        return []

    try:
        output = subprocess.check_output([screen_path, '-ls'], text=True).splitlines()
        sessions = []
        for line in output:
            match = re.search(r'^\s+([0-9]+\..*?)\s+\(', line)
            if match:
                sessions.append(match.group(1).strip())
        return sessions
    except (subprocess.CalledProcessError, FileNotFoundError):
        return []

def view_screen_logs():
    """Menyambungkan ke sesi screen yang dipilih."""
    global screen_path
    if not screen_path:
        print(color_log("Perintah 'screen' tidak ditemukan. Jalankan 'Cek Status' (#1) untuk info.\n", 'red'))
        return
    print(color_log("📊 Mengecek sesi screen yang berjalan...\n", 'cyan'))
    sessions = list_screen_sessions()

    if not sessions:
        print(color_log("Tidak ada sesi screen yang sedang berjalan.\n", 'yellow'))
        return

    print(color_log("Sesi screen yang ditemukan:\n", 'green'))
    for i, session in enumerate(sessions, 1):
        print(f"{i}. {color_log(session, 'yellow')}")

    choice = get_input("\nPilih sesi untuk disambungkan (ketik nomornya), atau 'x' untuk kembali: ")
    
    if choice.lower() == 'x' or not choice.strip():
        return
    
    try:
        choice_index = int(choice) - 1
        if 0 <= choice_index < len(sessions):
            selected_session = sessions[choice_index]
            print(color_log(f"Menyambungkan ke sesi '{selected_session}'...\n", 'green'))
            os.system('clear')
            subprocess.run([screen_path, '-r', selected_session])
            get_input(color_log("\nKembali dari sesi screen. Tekan [Enter] untuk melanjutkan.", 'cyan'))
        else:
            print(color_log("Pilihan tidak valid.\n", 'red'))
    except ValueError:
        print(color_log("Input tidak valid.\n", 'red'))

def stop_screen():
    """Menghentikan sesi screen yang dipilih."""
    global screen_path
    if not screen_path:
        print(color_log("Perintah 'screen' tidak ditemukan. Jalankan 'Cek Status' (#1) untuk info.\n", 'red'))
        return
    print(color_log("🛑 Menghentikan sesi screen...\n", 'cyan'))
    sessions = list_screen_sessions()

    if not sessions:
        print(color_log("Tidak ada sesi screen yang sedang berjalan.\n", 'yellow'))
        return

    print(color_log("Sesi screen yang ditemukan:\n", 'green'))
    for i, session in enumerate(sessions, 1):
        print(f"{i}. {color_log(session, 'yellow')}")

    choice = get_input("\nPilih sesi untuk dihentikan (ketik nomornya), atau 'x' untuk kembali: ")

    if choice.lower() == 'x' or not choice.strip():
        return

    try:
        choice_index = int(choice) - 1
        if 0 <= choice_index < len(sessions):
            selected_session = sessions[choice_index]
            command = f"{screen_path} -X -S {subprocess.list2cmdline([selected_session])} quit"
            run_command(command)
            print(color_log(f"Sesi '{selected_session}' telah dihentikan.\n", "green"))
        else:
            print(color_log("Pilihan tidak valid.\n", 'red'))
    except ValueError:
        print(color_log("Input tidak valid.\n", 'red'))

def manage_glibc():
    """Mengecek dan memperbarui versi GLIBC."""
    global user_home
    print(color_log("🔎 Mengecek versi GLIBC...\n", 'cyan'))
    try:
        output = subprocess.check_output(['ldd', '--version'], text=True).splitlines()
        version_line = output[0]
        match = re.search(r'([0-9]+\.[0-9]+)$', version_line)
        if not match:
            print(color_log("Tidak dapat mendeteksi versi GLIBC dari output.\n", 'red'))
            return
        glibc_version = match.group(1)
        print(color_log("Versi GLIBC terdeteksi: ", 'green') + color_log(glibc_version, 'yellow'))

        if glibc_version == '2.35':
            print("\n" + color_log("=" * 56 + "\n", 'red'))
            print(color_log("                       PERINGATAN KERAS\n", 'yellow'))
            print(color_log("=" * 56 + "\n", 'red'))
            print(color_log("Mengubah GLIBC adalah operasi SANGAT BERISIKO dan dapat merusak\n", 'yellow'))
            print(color_log("sistem Anda. Lanjutkan hanya jika Anda tahu apa yang Anda lakukan.\n", 'yellow'))

            confirm = get_input("\nSetuju untuk update ke GLIBC 2.39? (Ketik 'SETUJU'): ")
            if confirm == 'SETUJU':
                print(color_log("Konfirmasi diterima. Memulai proses update GLIBC...", 'green'))
                
                original_dir = os.getcwd()
                os.chdir(user_home) # Pindah ke home directory untuk proses
                
                run_command("sudo apt update")
                run_command("sudo apt install -y gawk bison gcc make wget tar")
                run_command("wget -c https://ftp.gnu.org/gnu/glibc/glibc-2.39.tar.gz")
                
                update_commands = [
                    'tar -zxvf glibc-2.39.tar.gz',
                    'cd glibc-2.39',
                    'mkdir -p glibc-build',
                    'cd glibc-build',
                    f'../configure --prefix=/opt/glibc-2.39',
                    'make -j$(nproc)',
                    'sudo make install'
                ]
                run_command(" && ".join(update_commands))
                
                os.chdir(original_dir) # Kembali ke direktori awal
                print(color_log("Proses update GLIBC selesai. Disarankan untuk me-reboot sistem Anda.", "green"))
            else:
                print(color_log("Update dibatalkan.\n", 'red'))
        else:
            print(color_log("Versi GLIBC Anda bukan 2.35. Tidak ada tindakan yang diperlukan.\n", 'green'))
            
    except (subprocess.CalledProcessError, FileNotFoundError):
        print(color_log("Tidak dapat mengecek versi GLIBC.\n", 'red'))

def manage_swap():
    """Membuat dan mengaktifkan swap file."""
    print(color_log("💾 Mengelola Swap File untuk Mengatasi VPS 'Killed'...\n", 'cyan'))
    print("Fungsi ini akan membuat swap file untuk membantu VPS dengan RAM terbatas.")
    print("Pilih ukuran swap yang diinginkan:")
    print("1. 4G (Untuk VPS dengan RAM 4GB atau kurang)")
    print("2. 8G (Untuk VPS dengan RAM 8GB)")
    print("3. 16G (Untuk VPS dengan RAM 16GB atau lebih)")
    
    choice = get_input("Masukkan pilihan Anda [1-3]: ")
    size = ''

    if choice == '1': size = '4G'
    elif choice == '2': size = '8G'
    elif choice == '3': size = '16G'
    else:
        print(color_log("Pilihan tidak valid.\n", 'red'))
        return

    confirm = get_input(f"Anda memilih untuk membuat swap file sebesar {size}. Lanjutkan? (y/n): ")
    if confirm.lower() != 'y':
        print(color_log("Pembuatan swap dibatalkan.\n", 'red'))
        return

    if not run_command(f"sudo fallocate -l {size} /swapfile"): return
    if not run_command("sudo chmod 600 /swapfile"): return
    if not run_command("sudo mkswap /swapfile"): return
    if not run_command("sudo swapon /swapfile"): return
    run_command("echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab")
    
    print(color_log(f"Swap file sebesar {size} berhasil dibuat dan diaktifkan.\n", 'green'))

def reboot_vps():
    """Mereboot VPS."""
    print(color_log("🔄 Reboot VPS\n", 'red'))
    print(color_log("PERINGATAN: Ini akan me-reboot VPS Anda. Semua sesi yang tidak disimpan akan hilang.\n", 'yellow'))
    confirm = get_input("Apakah Anda yakin ingin melanjutkan? (y/n): ")
    if confirm.lower() == 'y':
        print(color_log("Perintah reboot dikirim...", 'red'))
        run_command("sudo reboot")
    else:
        print(color_log("Reboot dibatalkan.\n", 'green'))

def show_system_utilities_menu():
    """Menampilkan submenu utilitas sistem."""
    while True:
        os.system('clear')
        print(color_log("========== MENU UTILITAS SISTEM ==========\n", 'yellow'))
        print("1. Cek & Update Versi GLIBC")
        print("2. Mengatasi VPS Killed (Buat Swap)")
        print("3. Reboot VPS")
        print("4. Kembali ke Menu Utama")
        
        choice = get_input("Masukkan pilihan Anda [1-4]: ")
        
        if choice == '1': manage_glibc()
        elif choice == '2': manage_swap()
        elif choice == '3': reboot_vps()
        elif choice == '4': return
        else: print(color_log("Pilihan tidak valid. Silakan coba lagi.\n", 'red'))
        
        get_input(color_log("\nTekan [Enter] untuk kembali ke menu utilitas...", 'cyan'))

# --- Main Program Logic ---
def main():
    """Logika utama program."""
    global user_home, config_file, screen_path, nexus_cli_path
    
    if os.geteuid() != 0:
        print(color_log("Error: Skrip ini harus dijalankan dengan sudo.", 'red'))
        print(f"Silakan coba lagi dengan: {color_log('sudo python3 ' + os.path.basename(__file__), 'yellow')}")
        sys.exit(1)
    
    user_home = get_user_home()
    config_file = os.path.join(user_home, '.nexus_tool_config.json')
    screen_path = find_executable('screen')
    nexus_cli_path = find_executable('nexus-network', user_home)

    print(color_log("============================================\n", 'blue'))
    print(color_log("   Selamat Datang di Nexus Node Tool v2.4   \n", 'blue'))
    print(color_log("============================================\n", 'blue'))
    print("Pilih Opsi:")
    print("1. " + color_log("[INSTALASI AWAL]", 'cyan') + " (Jalankan jika ini pertama kali)")
    print("2. " + color_log("[MENU UTAMA]", 'green') + " (Langsung ke manajemen node)")
    choice = get_input("Masukkan pilihan Anda (1/2): ")
    if choice.strip() == '1':
        initial_setup()

    load_configuration()
    
    while True:
        # Perbarui path nexus_cli_path setiap kali masuk loop utama
        # Ini penting jika CLI baru diinstal atau diperbarui
        nexus_cli_path = find_executable('nexus-network', user_home)
        
        get_input(color_log("\nTekan [Enter] untuk menampilkan menu...", 'cyan'))
        os.system('clear')
        
        print(color_log("================= MENU MANAJEMEN NEXUS =================\n", 'blue'))
        print(color_log("Konfigurasi Tersimpan:\n", 'cyan'))
        display_wallet = wallet_address or color_log('[Belum diatur]', 'red')
        print(f"- Wallet Address : {color_log(str(display_wallet), 'yellow')}")
        display_node_id = node_id or color_log('[Belum diatur]', 'red')
        print(f"- Node ID        : {color_log(str(display_node_id), 'yellow')}")
        print(color_log("========================================================\n", 'blue'))
        
        print("\nPilih tindakan:")
        print("1. " + color_log("Cek Status Dependensi", 'green'))
        print("2. Update versi Nexus CLI")
        print("3. Register/Update alamat wallet")
        print("4. Create/Update Node ID")
        print("5. Run node (foreground)")
        print("6. " + color_log("Run Node (background)", 'cyan'))
        print("6b. " + color_log("Run Node with MaxThread (On Screen)", 'yellow'))
        print("7. Lihat Sesi Screen (Logs)")
        print("8. " + color_log("Hentikan Sesi Screen", 'red'))
        print("9. " + color_log("Utilitas Sistem", 'yellow'))
        print("10. Keluar dari skrip")
        
        choice = get_input("Masukkan pilihan Anda [1-10]: ")
        
        if choice == '1': check_status()
        elif choice == '2': update_nexus_cli()
        elif choice == '3': register_wallet()
        elif choice == '4': create_node_id()
        elif choice == '5': start_node()
        elif choice == '6': start_node(background=True)
        elif choice == '6b': start_node(background=True, max_threads=4)
        elif choice == '7': view_screen_logs()
        elif choice == '8': stop_screen()
        elif choice == '9': show_system_utilities_menu()
        elif choice == '10': 
            print(color_log("👋 Sampai jumpa!\n", 'green'))
            sys.exit(0)
        else: print(color_log("Pilihan tidak valid. Silakan coba lagi.\n", 'red'))

if __name__ == "__main__":
    main()
