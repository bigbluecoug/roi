# Event Lead Capture

Mobile-first Laravel app for conference lead capture. Reps log in, choose a state, pick or create an event, upload a badge or business-card photo, review AI-extracted visible text, confirm a district, and manually add the reviewed record to HubSpot.

## Local Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve
```

The seeder imports district data from `colorado-event-roi.html`, creates one default event for each supported state, and creates the default internal users:

- Email: `eric.price@derivita.com`
- Email: `duane@derivita.com`
- Password: `capture`

Override the shared seeded password with `INTERNAL_USER_PASSWORD` before seeding.

## Required Env Vars

```dotenv
OPENAI_API_KEY=
OPENAI_MODEL=gpt-5.4-mini
HUBSPOT_ACCESS_TOKEN=
CAPTURE_RETENTION_DAYS=30
```

Without `OPENAI_API_KEY`, captures still save and can be reviewed manually. Without `HUBSPOT_ACCESS_TOKEN`, the manual HubSpot sync button reports a configuration error instead of writing records.

## iPhone Use

The app is installable from Safari as `Lead Capture` when served from a hosted HTTPS URL. The capture form uses:

```html
accept="image/*" capture="environment"
```

That hints iPhone Safari to open the rear camera for badge and business-card photos. V1 is online-required; the service worker only caches install shell assets and icons, not lead drafts or API requests.

## Maintenance

```bash
php artisan captures:purge-images
php artisan test
./vendor/bin/pint --test
```

`captures:purge-images` deletes stored badge/card images after the configured retention window while keeping extracted fields and sync history.
