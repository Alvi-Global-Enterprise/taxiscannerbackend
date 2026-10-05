# TaxiScanner Backend — Vercel Deployment Guide

This guide provides step-by-step instructions for deploying and running the **TaxiScanner** Laravel backend on Vercel as a production API.

---

## 1. Architectural Overview

- **Framework:** Laravel 12.x
- **PHP Runtime:** PHP 8.3 (`vercel-php@0.7.4`)
- **Execution Model:** Serverless PHP Lambda functions triggered via `api/index.php`.
- **Filesystem Model:** Ephemeral and read-only root filesystem at runtime. Temporary files, compiled Blade views, sessions, and logs are directed to `/tmp/storage`.
- **Database Engine:** Managed remote database (PostgreSQL recommended; e.g. Neon, Supabase, AWS RDS). Local SQLite must **not** be used in serverless production.
- **Dual Deployment Compatibility:** The Vercel configuration is strictly additive. Existing Docker/Sail, EC2, and local development configurations remain completely intact.

---

## 2. Prerequisites

1. A [Vercel account](https://vercel.com/) and the Vercel CLI installed (`npm install -g vercel`).
2. A managed remote PostgreSQL (e.g., [Neon](https://neon.tech/), [Supabase](https://supabase.com/)) or MySQL database accessible from the internet.
3. Mapbox API Token for geocoding and routing calculation.
4. Generated Laravel `APP_KEY` (`php artisan key:generate --show`).

---

## 3. Vercel Project Settings

When creating or configuring the project in the [Vercel Dashboard](https://vercel.com/dashboard):

- **Framework Preset:** `Other` (or leave default; `framework: null` is explicitly configured in `vercel.json`).
- **Root Directory:** `./` (project root).
- **Build Command:** Leave empty / default (Vercel invokes `composer install` automatically).
- **Output Directory:** Leave empty / default.
- **Install Command:** Leave empty / default.

---

## 4. Required Environment Variables

Configure the following environment variables under **Settings > Environment Variables** in your Vercel project:

### Application
| Variable | Value / Description | Example |
| :--- | :--- | :--- |
| `APP_NAME` | Application Name | `TaxiScanner` |
| `APP_ENV` | Application Environment | `production` |
| `APP_KEY` | 32-character AES encryption key | `base64:...` (from `php artisan key:generate --show`) |
| `APP_DEBUG` | Debug mode | `false` |
| `APP_URL` | Backend URL | `https://taxiscanner-backend.vercel.app` |
| `APP_TIMEZONE` | Timezone | `UTC` |

### Logging (Serverless Optimized)
| Variable | Value / Description | Example |
| :--- | :--- | :--- |
| `LOG_CHANNEL` | Streams logs directly to Vercel runtime log console | `stderr` |
| `LOG_LEVEL` | Minimum log level | `info` |

### Database (Managed Remote PostgreSQL or MySQL)
| Variable | Value / Description | Example |
| :--- | :--- | :--- |
| `DB_CONNECTION` | Database driver | `pgsql` (or `mysql`) |
| `DB_HOST` | Database host | `ep-xyz.us-east-1.aws.neon.tech` |
| `DB_PORT` | Port | `5432` |
| `DB_DATABASE` | Database name | `taxiscanner` |
| `DB_USERNAME` | Database username | `taxiscanner_admin` |
| `DB_PASSWORD` | Database password | `••••••••••••` |
| `DB_SSLMODE` | SSL requirement | `require` |
| *Alternative* `DB_URL` | Full connection URI | `postgresql://user:pass@ep-xyz.neon.tech/taxiscanner?sslmode=require` |

### Cache & Rate Limiting
| Variable | Value / Description | Example |
| :--- | :--- | :--- |
| `CACHE_STORE` | Persistent cache backend | `database` (or `redis`) |
| `CACHE_PREFIX` | Cache key prefix | `taxiscanner_cache` |

### Queue
| Variable | Value / Description | Example |
| :--- | :--- | :--- |
| `QUEUE_CONNECTION` | Queue driver | `sync` |

### CORS Configuration
| Variable | Value / Description | Example |
| :--- | :--- | :--- |
| `CORS_ALLOWED_ORIGINS` | Comma-separated frontend domains | `https://taxiscanner.vercel.app,http://localhost:3000` |

### Location & Routing (Mapbox)
| Variable | Value / Description | Example |
| :--- | :--- | :--- |
| `TAXISCANNER_GEOCODING_PROVIDER` | Geocoding engine | `mapbox` |
| `TAXISCANNER_ROUTING_PROVIDER` | Routing engine | `mapbox` |
| `TAXISCANNER_MAP_API_KEY` | Mapbox public/secret token | `pk.eyJ...` |
| `MAPBOX_ACCESS_TOKEN` | Mapbox token fallback | `pk.eyJ...` |
| `TAXISCANNER_MAP_TIMEOUT` | Timeout in seconds | `5` |
| `TAXISCANNER_ALLOW_SIMULATED_FALLBACK` | Allow fallback to simulated coordinates | `false` |

### Taxi Providers
| Variable | Value / Description | Example |
| :--- | :--- | :--- |
| `TAXISCANNER_CURRENCY` | Default currency | `GBP` |
| `PROVIDER_UBER_ENABLED` | Uber active toggle | `true` |
| `PROVIDER_UBER_MODE` | Uber mode | `estimate` |
| `PROVIDER_BOLT_ENABLED` | Bolt active toggle | `true` |
| `PROVIDER_BOLT_MODE` | Bolt mode | `estimate` |
| `PROVIDER_STREETCARS_ENABLED` | StreetCars active toggle | `true` |
| `PROVIDER_STREETCARS_MODE` | StreetCars mode | `estimate` |
| `PROVIDER_VEEZU_ENABLED` | Veezu active toggle | `true` |
| `PROVIDER_VEEZU_MODE` | Veezu mode | `estimate` |
| `TAXISCANNER_STREETCARS_GEO_CALIBRATION_ENABLED` | Suburb calibration | `false` |

---

## 5. Database Setup & Remote Migration

Because Vercel serverless functions are ephemeral, database migrations and initial seeding should **never** be run dynamically inside a serverless HTTP request. Run them once from your deployment pipeline or local machine targeting the remote production database:

```bash
# 1. Point to your remote production database temporarily
export DB_CONNECTION=pgsql
export DB_HOST=ep-xyz.neon.tech
export DB_PORT=5432
export DB_DATABASE=taxiscanner
export DB_USERNAME=taxiscanner_user
export DB_PASSWORD=your_password
export DB_SSLMODE=require

# 2. Run migrations safely (NON-DESTRUCTIVE)
php artisan migrate --force

# 3. Seed providers and pricing configurations
php artisan db:seed --class=ProviderSeeder --force
```

> **IMPORTANT:** Never run destructive commands (`migrate:fresh`, `db:wipe`) against production databases.

---

## 6. Step-by-Step Vercel Deployment

### Method A: Deploy via Git Integration (Recommended)

1. Push your repository to GitHub / GitLab / Bitbucket:
   ```bash
   git add .
   git commit -m "Configure Laravel 12 for Vercel serverless deployment"
   git push origin main
   ```
2. Import the repository in the [Vercel Dashboard](https://vercel.com/new).
3. Add the required Environment Variables in the project configuration (Section 4).
4. Click **Deploy**. Vercel will install dependencies via Composer and deploy the serverless functions.

### Method B: Deploy via Vercel CLI

1. Authenticate with Vercel:
   ```bash
   vercel login
   ```
2. Link your project:
   ```bash
   vercel link
   ```
3. Deploy to Preview:
   ```bash
   vercel
   ```
4. Deploy to Production:
   ```bash
   vercel --prod
   ```

---

## 7. Operational Limitations & Best Practices

### A. Queues & Background Workers
- **Limitation:** Vercel serverless functions have hard execution limits (10-60s) and cannot run long-running worker processes (`php artisan queue:work` or `queue:listen`).
- **Production Solution:**
  - The fare comparison endpoint operates synchronously, so `QUEUE_CONNECTION=sync` works cleanly out of the box.
  - If heavy asynchronous jobs are added (e.g. batch geocoding, report generation), configure `QUEUE_CONNECTION=database` or `sqs` and run a persistent worker daemon on an EC2 instance, AWS ECS, or Docker container.

### B. Task Scheduler / Cron
- **Limitation:** Vercel cannot run the permanent daemon `php artisan schedule:work`.
- **Production Solution:**
  - If scheduled tasks are needed in the future, use **Vercel Cron Jobs** configured in `vercel.json` pointing to an authenticated internal webhook, or trigger Artisan tasks via GitHub Actions / AWS EventBridge.

### C. File Storage
- **Limitation:** The Vercel runtime filesystem is read-only except `/tmp`. Files written to `/tmp` are wiped when the lambda instance is recycled.
- **Production Solution:**
  - The API does not require persistent file uploads.
  - If persistent document, avatar, or report storage is required, use AWS S3, Cloudflare R2, or Supabase Storage by setting `FILESYSTEM_DISK=s3`.

### D. Session & Cache Persistence
- **Limitation:** File-based sessions and cache stored in `/tmp` are not shared between concurrent lambda functions.
- **Production Solution:**
  - Set `CACHE_STORE=database` (uses the migrated `cache` table) or `redis` (e.g., Upstash Redis).
  - API requests are stateless and do not require session storage.

---

## 8. Verifying & Testing the Deployed API

Replace `https://your-backend.vercel.app` with your actual Vercel deployment URL.

### 1. Healthcheck Route (`GET /up`)
```bash
curl -i -X GET https://your-backend.vercel.app/up
```
**Expected Response:** HTTP `200 OK`

### 2. Welcome Route (`GET /`)
```bash
curl -i -X GET https://your-backend.vercel.app/
```
**Expected Response:** HTTP `200 OK` (Welcome view)

### 3. Taxi Fare Comparison (`POST /api/v1/compare`)
```bash
curl -i -X POST https://your-backend.vercel.app/api/v1/compare \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "pickup": "Manchester Piccadilly",
    "dropoff": "Manchester Airport"
  }'
```
**Expected Response:**
```json
{
  "success": true,
  "data": {
    "pickup": {
      "query": "Manchester Piccadilly",
      "formatted_address": "Manchester Piccadilly Station, Piccadilly Station Approach, Manchester, M60 7RA, United Kingdom",
      "coordinates": {
        "latitude": 53.4774,
        "longitude": -2.2312
      }
    },
    "dropoff": {
      "query": "Manchester Airport",
      "formatted_address": "Manchester Airport (MAN), Ringway, Manchester, M90 1QX, United Kingdom",
      "coordinates": {
        "latitude": 53.3656,
        "longitude": -2.2728
      }
    },
    "route": {
      "distance_miles": 9.42,
      "duration_minutes": 22.5
    },
    "trip_category": "city_to_airport",
    "estimates": [
      {
        "provider": "StreetCars",
        "category": "city_to_airport",
        "estimated_fare": 27.50,
        "price_range": { "low": 24.75, "high": 30.25 },
        "currency": "GBP"
      },
      {
        "provider": "Uber",
        "category": "city_to_airport",
        "estimated_fare": 29.00,
        "price_range": { "low": 26.10, "high": 31.90 },
        "currency": "GBP"
      },
      {
        "provider": "Bolt",
        "category": "city_to_airport",
        "estimated_fare": 26.80,
        "price_range": { "low": 24.12, "high": 29.48 },
        "currency": "GBP"
      },
      {
        "provider": "Veezu",
        "category": "city_to_airport",
        "estimated_fare": 28.10,
        "price_range": { "low": 25.29, "high": 30.91 },
        "currency": "GBP"
      }
    ]
  }
}
```

### 4. Validation Error Handling (`POST /api/v1/compare`)
```bash
curl -i -X POST https://your-backend.vercel.app/api/v1/compare \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "pickup": "M"
  }'
```
**Expected Response:** HTTP `422 Unprocessable Content` with JSON error messages.

### 5. CORS Preflight Check (`OPTIONS /api/v1/compare`)
```bash
curl -i -X OPTIONS https://your-backend.vercel.app/api/v1/compare \
  -H "Origin: https://taxiscanner.vercel.app" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type, Authorization"
```
**Expected Response:** HTTP `204 No Content` or `200 OK` with CORS headers (`Access-Control-Allow-Origin: https://taxiscanner.vercel.app`).

---

## 9. Common Errors & Troubleshooting

| Error | Root Cause | Solution |
| :--- | :--- | :--- |
| `500 Server Error: Read-only file system` | Laravel attempting to write views/cache to read-only disk | Verify `api/index.php` is deployed. It directs storage paths to `/tmp/storage`. |
| `500 Server Error: No application encryption key specified` | Missing `APP_KEY` | Set `APP_KEY` in Vercel Environment Variables. Generate one locally with `php artisan key:generate --show`. |
| `500 Server Error: Database [sqlite] does not exist` | Default `DB_CONNECTION` still set to `sqlite` | Set `DB_CONNECTION=pgsql` (or `mysql`) and provide remote host credentials in Vercel Environment Variables. |
| `CORS Error: No 'Access-Control-Allow-Origin' header` | Origin not matched in backend | Add your frontend domain (e.g. `https://my-app.vercel.app`) to `CORS_ALLOWED_ORIGINS` in Vercel settings. |
| `429 Too Many Requests` | Rate limit triggered (`throttle:60,1`) | Request frequency exceeded 60 requests/minute per client IP. Rate limits reset automatically after 60 seconds. |
| `Logs not showing in Vercel Dashboard` | `LOG_CHANNEL` set to `single` or `stack` | Ensure `LOG_CHANNEL=stderr` is configured in Vercel Environment Variables. |
