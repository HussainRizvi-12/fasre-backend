# FASRE — Faculty Audit & Student Review Ecosystem

**FASRE** is a unified quality assurance ecosystem featuring a Laravel 11 REST & Blade Backend and two dedicated Flutter mobile apps (Student Review App & Faculty Peer Audit App).

---

## 🏗️ Technology Architecture

- **Backend:** Laravel 11.x, PHP 8.2+, PostgreSQL 18
- **Authentication:** Laravel Sanctum (Token Auth for Mobile Apps + Session Auth for Web Admin Portal)
- **Web Admin Portal:** Custom Stitch Blade Portal (`#1E3A8A` Navy / Gold accents)
- **Mobile Apps:** Flutter / Dart (Data-layer wired to REST APIs with 100% visual preservation)

---

## 🚀 Backend Environment Setup

### 1. Requirements
- PHP 8.2 or higher (with `pdo_pgsql`, `pgsql`, `mbstring`, `openssl`, `curl` extensions enabled)
- Composer 2.x
- PostgreSQL 18 (Running on Port `5433` by default)

### 2. Configure Environment (`.env`)
Create or verify `.env` in `Backend admin panel/`:
```env
APP_NAME="FASRE Backend"
APP_ENV=local
APP_KEY=base64:7V61Ue7s0vM39T3aK2j1QzW4xY5e9r2p1l0m3n4o5p6=
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5433
DB_DATABASE=fasre
DB_USERNAME=postgres
DB_PASSWORD=

SESSION_DRIVER=database
SANCTUM_STATEFUL_DOMAINS=127.0.0.1,localhost
```

### 3. Install Dependencies & Setup Database
```bash
# Install PHP dependencies
composer install

# Run fresh migrations and load the realistic demo dataset
php artisan migrate:fresh --seed --seeder=DemoSeeder

# Start the local development server
php artisan serve --host=0.0.0.0 --port=8000
```

---

## 📱 Mobile App Setup & API Base URL Configuration

Both Flutter apps use `ApiClient` (`core/network/api_client.dart` or environment configs). Set the base URL depending on your target device:

| Environment | Base URL | Notes |
| :--- | :--- | :--- |
| **Android Emulator** | `http://10.0.2.2:8000/api` | Maps to host machine `127.0.0.1:8000` |
| **iOS Simulator / macOS** | `http://127.0.0.1:8000/api` | Direct local loopback |
| **Physical Phone (Wi-Fi)** | `http://<YOUR_LOCAL_IP>:8000/api` | Host PC and phone on same Wi-Fi |

---

## 🧪 Running Automated Tests

Run the full PHPUnit feature test suite (36 tests, 233 assertions, 100% pass rate):
```bash
php artisan test
```

---

## 🔑 Demo Credentials (Password: `Password@123`)

| Role | Email | Use Case |
| :--- | :--- | :--- |
| **Admin** | `admin@fasre.test` | Web Admin Portal (`/login`) |
| **Faculty (Auditor/Auditee)** | `ahmed.khan@fasre.test` | Faculty Audit App |
| **Faculty (Auditee)** | `sara.ali@fasre.test` | Faculty Audit App |
| **Faculty (Auditor)** | `usman.raza@fasre.test` | Faculty Audit App |
| **Student** | `ali.hassan@fasre.test` | Student Review App |

For a complete walkthrough script with step-by-step instructions, see [DEMO_SCRIPT.md](file:///c:/Users/Hussain/Desktop/New%20FYP/Backend%20admin%20panel/DEMO_SCRIPT.md).
