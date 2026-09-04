# FASRE — Faculty Audit & Student Review Ecosystem

**FASRE** is a unified quality assurance ecosystem featuring a Laravel 11 REST & Blade Backend and two dedicated Flutter mobile apps (Student Review App & Faculty Peer Audit App).

---

## 🏗️ Technology Architecture

- **Backend:** Laravel (PHP 8.3+), PostgreSQL (production on Azure) / SQLite (local development)
- **Authentication:** Laravel Sanctum (Token Auth for Mobile Apps + Token Auth for Web Admin Portal)
- **Web Admin Portal:** React SPA served by the backend at `/admin`
- **Mobile Apps:** Flutter / Dart (Data-layer wired to REST APIs)

---

## 🚀 Backend Environment Setup

### 1. Requirements
- PHP 8.3 or higher (with `pdo_sqlite`, `mbstring`, `openssl`, `curl` extensions enabled)
- Composer 2.x
- No database server needed for local development (SQLite is used out of the box)

### 2. Configure Environment (`.env`)
The repository's `.env` points at the local SQLite database (`database/database.sqlite`). Production Azure credentials are kept separately in `.env.production` (gitignored) — never put them in `.env` locally:
```env
APP_NAME="FASRE"
APP_ENV=local
APP_KEY=<php artisan key:generate>
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite

SESSION_DRIVER=file
SANCTUM_TOKEN_EXPIRATION=120
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

> ⚠️ **Demo use only.** These accounts exist only in the demo-seeded dataset. Never seed them into a production database, and never publish the live deployment URL together with this README.

| Role | Email | Use Case |
| :--- | :--- | :--- |
| **Admin** | `admin@fasre.test` | Web Admin Portal (`/login`) |
| **Faculty (Auditor/Auditee)** | `ahmed.khan@fasre.test` | Faculty Audit App |
| **Faculty (Auditee)** | `sara.ali@fasre.test` | Faculty Audit App |
| **Faculty (Auditor)** | `usman.raza@fasre.test` | Faculty Audit App |
| **Student** | `ali.hassan@fasre.test` | Student Review App |

For a complete walkthrough script with step-by-step instructions, see **Section 10** of [FASRE_MASTER_DOCUMENTATION.md](FASRE_MASTER_DOCUMENTATION.md).
