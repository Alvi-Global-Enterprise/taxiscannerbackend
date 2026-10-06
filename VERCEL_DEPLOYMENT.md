# TaxiScanner Backend — Vercel Deployment Guide

This guide provides step-by-step instructions for deploying and running the **TaxiScanner** Laravel backend on Vercel as a production API using the bundled read-only SQLite database.

---

## 1. Architectural Overview

- **Framework:** Laravel 12.x
- **PHP Runtime:** PHP 8.3 (`vercel-php@0.7.4`)
- **Execution Model:** Serverless PHP Lambda functions triggered via `api/index.php`.
- **Database Engine:** Bundled SQLite (`database/database.sqlite`).
  > **Architecture Note:**
  > SQLite is intentionally used as a read-only static/reference database. The application does not rely on persistent runtime writes to SQLite. Vercel's filesystem is ephemeral, so SQLite must not be treated as a persistent writable production database.
- **Filesystem Model:** Ephemeral and read-only root filesystem at runtime. Temporary files, compiled Blade views, file-based rate limits, and logs are directed to `/tmp/storage`.
- **Dual Deployment Compatibility:** The Vercel configuration is strictly additive. Existing Docker/Sail, EC2, and local development configurations remain completely intact.

---

## 2. Prerequisites

1. A [Vercel account](https://vercel.com/) and the Vercel CLI installed (`npm install -g vercel`).
2. Mapbox API Token for geocoding and routing calculation (`TAXISCANNER_MAP_API_KEY` / `MAPBOX_ACCESS_TOKEN`).
3. Generated Laravel `APP_KEY` (`php artisan key:generate --show`).
4. Pre-seeded SQLite database file (`database/database.sqlite`) tracked and committed in your Git repository.

---

## 3. Vercel Project Settings

When creating or configuring the project in the [Vercel Dashboard](https://vercel.com/dashboard):

- **Framework Preset:** `Other` (or leave default; `framework: null` is explicitly configured in `vercel.json`).
- **Root Directory:** `./` (project root).
- **Build Command:** Leave empty / default (Vercel invokes Composer automatically).
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

### Database (Read-Only Static SQLite)
| Variable | Value / Description | Example |
| :--- | :--- | :--- |
| `DB_CONNECTION` | SQLite database driver | `sqlite` |
| `DB_DATABASE` | Path to bundled SQLite file (default resolved by Laravel) | `database/database.sqlite` |

### Logging (Serverless Runtime Stream)
| Variable | Value / Description | Example |
| :--- | :--- | :--- |
| `LOG_CHANNEL` | Streams logs directly to Vercel runtime log console | `stderr` |
| `LOG_LEVEL` | Minimum log level | `info` |

### Cache & Sessions (Zero-Database Writes)
| Variable | Value / Description | Example |
| :--- | :--- | :--- |
| `CACHE_STORE` | File-based cache in writable `/tmp` (or `array`) | `file` |
| `SESSION_DRIVER` | In-memory array session for stateless API | `array` |

### Queue
| Variable | Value / Description | Example |
| :--- | :--- | :--- |
| `QUEUE_CONNECTION` | Synchronous execution for serverless | `sync` |

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
| `TAXISCANNER_ANALYTICS_ENABLED` | Persistent analytics (keep false) | `false` |

---

## 5. SQLite Bundling & Read-Only Handling

1. **Packaging:** The SQLite database (`database/database.sqlite`) is tracked and deployed directly inside the project repository (whitelisted via `!database.sqlite` in `database/.gitignore`).
2. **Zero Deployment Migrations:** Migrations are **never** run during deployment. The database is pre-seeded with all reference data (`providers`, `provider_pricing_configs`, and calibration observations).
3. **Read-Only Operation:**
   - The comparison pipeline (`POST /api/v1/compare`) reads pricing matrices, multipliers, and providers from SQLite without making any database writes.
   - Cache and rate-limiting (`throttle:60,1`) are directed to `CACHE_STORE=file` (inside `/tmp/storage/framework/cache/data`) or `CACHE_STORE=array`.
   - Sessions are configured to `SESSION_DRIVER=array`, preventing session garbage collection queries against SQLite.
   - Analytics logging is disabled (`TAXISCANNER_ANALYTICS_ENABLED=false`).

---

## 6. Step-by-Step Vercel Deployment

### Method A: Deploy via Git Integration (Recommended)

1. Ensure the SQLite database and deployment configuration are staged and pushed:
   ```bash
   git add database/.gitignore database/database.sqlite api/index.php vercel.json .vercelignore .env.example VERCEL_DEPLOYMENT.md
   git commit -m "feat: deploy with bundled read-only SQLite database"
   git push origin main
   ```
2. Import the repository into the [Vercel Dashboard](https://vercel.com/new).
3. Add the required Environment Variables in the project configuration (Section 4).
4. Click **Deploy**.

### Method B: Deploy via Vercel CLI

1. Authenticate with Vercel:
   ```bash
   vercel login
   ```
2. Link your project:
   ```bash
   vercel link
   ```
3. Deploy to Production:
   ```bash
   vercel --prod
   ```

---

## 7. Verifying & Testing the Deployed API

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
**Expected Response:** HTTP `200 OK`

### 3. Taxi Fare Comparison (`POST /api/v1/compare`)
```bash
curl -i -X POST https://your-backend.vercel.app/api/v1/compare \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "pickup": "Manchester Airport",
    "dropoff": "Manchester Piccadilly Station"
  }'
```
**Expected Response:**
```json
{
  "success": true,
  "data": {
    "pickup": {
      "query": "Manchester Airport",
      "formatted_address": "Manchester Airport (MAN), Ringway, Manchester, M90 1QX, United Kingdom",
      "coordinates": { "latitude": 53.3588, "longitude": -2.2727 }
    },
    "dropoff": {
      "query": "Manchester Piccadilly Station",
      "formatted_address": "Manchester Piccadilly Station, Piccadilly Station Approach, Manchester, M60 7RA, United Kingdom",
      "coordinates": { "latitude": 53.4774, "longitude": -2.2312 }
    },
    "route": {
      "distance_miles": 9.84,
      "duration_minutes": 28
    },
    "quotes": [
      { "provider": "uber", "display_name": "Uber", "min_price": 23.73, "max_price": 26.23 },
      { "provider": "bolt", "display_name": "Bolt", "min_price": 22.61, "max_price": 24.99 },
      { "provider": "streetcars", "display_name": "StreetCars", "min_price": 24.83, "max_price": 27.45 },
      { "provider": "veezu", "display_name": "Veezu", "min_price": 23.20, "max_price": 25.64 }
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
**Expected Response:** HTTP `422 Unprocessable Content` with validation error JSON.

### 5. CORS Preflight Check (`OPTIONS /api/v1/compare`)
```bash
curl -i -X OPTIONS https://your-backend.vercel.app/api/v1/compare \
  -H "Origin: https://taxiscanner.vercel.app" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type, Authorization"
```
**Expected Response:** HTTP `204 No Content` or `200 OK` with CORS headers (`Access-Control-Allow-Origin: https://taxiscanner.vercel.app`).

---

## 8. Common Errors & Troubleshooting

| Error | Root Cause | Solution |
| :--- | :--- | :--- |
| `500 Server Error: Read-only file system` | Attempting to write views or logs to root disk | Handled automatically by `api/index.php` which redirects storage to `/tmp/storage`. |
| `attempt to write a readonly database` | Sessions or Cache configured to use `database` driver on read-only SQLite | Ensure `CACHE_STORE=file` (or `array`) and `SESSION_DRIVER=array` (or `cookie`). `api/index.php` sets these safe defaults automatically. |
| `Database [database/database.sqlite] does not exist` | SQLite database file was not committed/deployed | Ensure `!database.sqlite` is in `database/.gitignore` and `database/database.sqlite` is staged and committed to Git. |
| `500 Server Error: No application encryption key specified` | Missing `APP_KEY` in Vercel settings | Set `APP_KEY` in Vercel Environment Variables. Generate one with `php artisan key:generate --show`. |
| `CORS Error: No 'Access-Control-Allow-Origin' header` | Origin not matched in backend | Add your frontend domain to `CORS_ALLOWED_ORIGINS` in Vercel settings. |
| `429 Too Many Requests` | Rate limit exceeded (`throttle:60,1`) | Request frequency exceeded 60 requests/minute per client IP. Rate limits reset automatically after 60 seconds. |
| `Logs not showing in Vercel Dashboard` | `LOG_CHANNEL` set to `single` or `stack` | Ensure `LOG_CHANNEL=stderr` is configured in Vercel Environment Variables. |
