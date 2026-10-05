# TaxiScanner Backend API

API-first backend for [TaxiScanner](https://taxiscanner.vercel.app/) built with **Laravel 12**.

> **Important Notice:**
> **TaxiScanner currently provides calculated fare estimates, not live provider quotes.**
> Prices displayed by the system are estimated, indicative, and calculated by TaxiScanner's estimation engine based on configurable UK benchmark assumptions. TaxiScanner does NOT scrape provider websites, mobile apps, or use unauthorized private APIs.

---

## Architecture Overview

```
Frontend (Vercel)
       │
       │ POST /api/v1/compare
       ▼
CompareController (Thin Orchestrator)
       │
       ▼
TaxiComparisonService
  ├── GeocodingServiceInterface (CachedGeocodingService -> Driver)
  ├── RouteServiceInterface (CachedRouteService -> Driver)
  └── TaxiProviderRegistry
        ├── UberEstimateProvider
        ├── BoltEstimateProvider
        ├── StreetCarsEstimateProvider
        └── VeezuEstimateProvider
              │
              ▼
        EstimateEngineInterface (EstimateEngine)
              │
              ▼
        PricingStrategyResolver
              ├── UberPricingStrategy
              ├── BoltPricingStrategy
              ├── StreetCarsPricingStrategy
              └── VeezuPricingStrategy
                    │
                    ▼
              ProviderPricingConfig (Database-driven assumptions)
```

---

## Fare Calculation Model

The estimation engine calculates indicative fares using database-driven configuration (`ProviderPricingConfig`):

$$\text{distance\_charge} = \text{distance\_miles} \times \text{per\_mile\_rate}$$

$$\text{time\_charge} = \text{duration\_minutes} \times \text{per\_minute\_rate}$$

$$\text{subtotal} = \text{base\_fare} + \text{distance\_charge} + \text{time\_charge} + \text{booking\_fee} + \text{applicable\_airport\_fee}$$

$$\text{adjusted\_total} = \text{subtotal} \times \text{dynamic\_multiplier}$$

$$\text{final\_estimate} = \max(\text{adjusted\_total}, \text{minimum\_fare})$$

### Deterministic Price Range
To reflect real-world variability (traffic, route divergence), the engine produces an indicative range:
- $\text{min\_price} = \text{round}(\text{final\_estimate} \times \text{estimate\_low\_multiplier}, 2)$ (default: `0.95`)
- $\text{max\_price} = \text{round}(\text{final\_estimate} \times \text{estimate\_high\_multiplier}, 2)$ (default: `1.05`)

---

## Provider Pricing Configuration

Pricing parameters are stored in the `provider_pricing_configs` table and can be modified without altering application code:

| Provider | Base Fare (£) | Per Mile (£) | Per Minute (£) | Min Fare (£) | Booking Fee (£) | Airport Fee (£) | Multiplier |
|:---|:---|:---|:---|:---|:---|:---|:---|
| **Uber** | £2.50 | £1.40 | £0.15 | £4.50 | £0.50 | £4.00 | 1.00 |
| **Bolt** | £2.20 | £1.35 | £0.14 | £4.20 | £0.40 | £4.00 | 1.00 |
| **StreetCars** | £3.00 | £1.60 | £0.10 | £5.00 | £0.00 | £3.50 | 1.00 |
| **Veezu** | £2.80 | £1.50 | £0.12 | £4.80 | £0.00 | £3.50 | 1.00 |

*Note: The values above represent TaxiScanner benchmark estimation assumptions and are not live provider tariffs.*

### How to Change Pricing Assumptions
You can adjust pricing assumptions anytime via database updates or by editing `database/seeders/ProviderSeeder.php` and running:
```bash
php artisan db:seed --class=ProviderSeeder
```

---

## Key Behaviors

1. **Airport Fee Detection**:
   - An extensible keyword and IATA pattern recognizer detects if origin or destination touches an airport (e.g. Manchester Airport, Heathrow, Gatwick, MAN, LHR, LGW).
   - If detected and configured, the provider's `airport_fee` is included in the subtotal.
   - Disabled or set to £0.00 for standard city journeys.

2. **Dynamic Multiplier**:
   - Defaults to `1.0`. Can be scaled to simulate peak demand/traffic adjustment factors without claiming live surge prediction.

3. **Minimum Fare Enforcement**:
   - Ensures short journeys do not drop below the provider's configured minimum threshold.

4. **Provider Failure Isolation**:
   - If an individual provider encounters an error or has missing configuration, it fails gracefully (`is_available: false`). The comparison endpoint continues returning estimates for the remaining available providers with HTTP 200.

5. **Future Official API Integration Path**:
   - Providers implement `TaxiProviderInterface`. When authorized provider APIs become available, each provider class can delegate directly to the authorized API client returning `TaxiQuote` with `quote_type = "live"`. No controller, resource, or routing changes will be required.

---

## Real Geocoding & Road Routing (Phase 4)

TaxiScanner integrates with **Mapbox** for real server-side geocoding and driving road routing:
- **Mapbox Geocoding API (v5)**: Resolves UK addresses, landmarks, and postcodes into precise coordinates.
- **Mapbox Directions API (v5)**: Computes driving distances along actual road networks and estimated travel duration.

### Setup Instructions
1. Create a free account at [mapbox.com](https://www.mapbox.com).
2. Retrieve your public/secret access token from your Mapbox dashboard.
3. Add the token to your `.env` file:
   ```env
   TAXISCANNER_GEOCODING_PROVIDER=mapbox
   TAXISCANNER_ROUTING_PROVIDER=mapbox
   TAXISCANNER_MAP_API_KEY=pk.eyJ1Ijoi...
   TAXISCANNER_MAP_TIMEOUT=5
   TAXISCANNER_ALLOW_SIMULATED_FALLBACK=false
   ```
4. For local offline development or automated CI/CD where no external API token is present, set:
   ```env
   TAXISCANNER_ALLOW_SIMULATED_FALLBACK=true
   ```

---

## Running Locally

```bash
# Run migrations and seed benchmark pricing
php artisan migrate --seed

# Run automated test suite
php artisan test

# Verify code formatting
./vendor/bin/pint --test
```

