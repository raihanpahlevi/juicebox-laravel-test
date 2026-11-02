# Juicebox Laravel Code Test: Posts & Weather API

A REST API built with Laravel 13, MySQL and Laravel Sanctum. It covers token authentication, a posts CRUD with ownership rules, paginated user listing, a queued welcome email, and the current weather for Perth pulled from Open-Meteo with caching and an hourly refresh job.

- [Requirements](#requirements)
- [Setup](#setup)
- [Running the queue worker and scheduler](#running-the-queue-worker-and-scheduler)
- [Sending the welcome email manually](#sending-the-welcome-email-manually)
- [Weather API setup](#weather-api-setup)
- [Running the tests](#running-the-tests)
- [API documentation](#api-documentation)
- [Design notes](#design-notes)

## Requirements

- PHP 8.3+
- Composer 2
- MySQL 8+

## Setup

```bash
git clone <this-repo-url> juicebox-test
cd juicebox-test

composer install
cp .env.example .env
php artisan key:generate
```

Create a database and set the connection in `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=juicebox
DB_USERNAME=root
DB_PASSWORD=
```

```bash
mysql -u root -e "CREATE DATABASE juicebox"
php artisan migrate --seed
php artisan serve
```

The API is now at `http://127.0.0.1:8000/api`. The seeder creates a demo account (`demo@example.com` / `password`) plus a few users and posts.

Emails are written to `storage/logs/laravel.log` by default (`MAIL_MAILER=log`). Point the `MAIL_*` variables at Mailtrap, Mailpit or a real SMTP server to receive them.

## Running the queue worker and scheduler

Queued jobs (the welcome email and the weather refresh) use the `database` queue driver, so no Redis is needed.

```bash
# Process queued jobs (keep this running in its own terminal)
php artisan queue:work

# Run scheduled tasks locally (keep this running too)
php artisan schedule:work
```

In production, run `queue:work` under Supervisor and add a single cron entry for the scheduler:

```cron
* * * * * cd /path/to/juicebox-test && php artisan schedule:run >> /dev/null 2>&1
```

`php artisan schedule:list` shows the hourly `RefreshWeatherData` job.

## Sending the welcome email manually

A welcome email is queued automatically when a user registers. To dispatch it by hand, pass a user ID or email:

```bash
php artisan users:send-welcome-email 1
php artisan users:send-welcome-email demo@example.com

# Skip the queue and send immediately
php artisan users:send-welcome-email 1 --sync
```

Without `--sync` the job goes to the queue, so `php artisan queue:work` must be running for the email to be sent.

## Weather API setup

The app uses [Open-Meteo](https://open-meteo.com/). It is free and needs **no API key or sign up**, so the endpoint works right after cloning.

These are the optional `.env` settings. The defaults already point at Perth:

| Variable | Default | Purpose |
|---|---|---|
| `WEATHER_BASE_URL` | `https://api.open-meteo.com` | API host |
| `WEATHER_LATITUDE` | `-31.9523` | Perth latitude |
| `WEATHER_LONGITUDE` | `115.8613` | Perth longitude |
| `WEATHER_TIMEZONE` | `Australia/Perth` | Times in the response are local Perth time |
| `WEATHER_CACHE_MINUTES` | `15` | How long a result is reused before calling the API again |

How it works (`app/Services/WeatherService.php`):

1. `GET /api/weather` returns the cached result if it is younger than 15 minutes.
2. Otherwise it calls Open-Meteo (5 second timeout, 2 retries) and caches the new result.
3. The `RefreshWeatherData` job runs every hour and refreshes the cache in the background, so most requests never wait on the external API.
4. Every successful result is also kept as "last known good". If Open-Meteo is down and the 15 minute cache has expired, the endpoint returns that copy with `"meta": {"stale": true}` instead of failing.
5. If the API fails and nothing has ever been cached, the endpoint returns `503` with a readable message. Failures are logged.

## Running the tests

```bash
php artisan test
```

Tests run on an in-memory SQLite database, so they don't touch your MySQL data. The external weather API is always mocked with `Http::fake()`, and `Http::preventStrayRequests()` makes a test fail if it ever tries a real call.

| File | Covers |
|---|---|
| `tests/Feature/AuthTest.php` | Register (and that the welcome job is queued), validation, login, wrong password, logout revoking the token, 401 JSON for guests |
| `tests/Feature/PostTest.php` | Auth required, pagination, `per_page` limit, create, validation, show, 404, partial update, delete, 403 when touching someone else's post |
| `tests/Feature/UserTest.php` | Show user with post count, email hidden from other users, pagination, 404 |
| `tests/Feature/WeatherTest.php` | Response mapping, caching (one API call for two requests), cache expiry, 503 on API error and bad payload, stale fallback, hourly refresh job |
| `tests/Feature/WelcomeEmailTest.php` | Job sends the mail, mail content, artisan command by ID and email, unknown user |

## API documentation

All endpoints live under `/api` and return JSON. Send `Accept: application/json` on every request.

Every endpoint except `register` and `login` needs a Sanctum token:

```
Authorization: Bearer <token>
```

A Postman collection with every request is included at [`docs/juicebox-api.postman_collection.json`](docs/juicebox-api.postman_collection.json). It saves the token automatically after register or login.

### Status codes

| Code | Meaning |
|---|---|
| 200 | OK |
| 201 | Created |
| 204 | Deleted, no body |
| 401 | Missing, invalid or revoked token, or wrong login credentials |
| 403 | Logged in, but not allowed (e.g. editing another user's post) |
| 404 | `{"message": "Resource not found."}` |
| 422 | Validation failed. The `errors` object lists the messages per field |
| 429 | Too many requests (10/min for register and login, 60/min for everything else) |
| 503 | Weather provider unavailable and no cached data |

### Auth

#### `POST /api/register`

| Field | Rules |
|---|---|
| `name` | required, max 255 |
| `email` | required, valid email, unique |
| `password` | required, min 8, must contain letters and numbers |
| `password_confirmation` | must match `password` |

```json
// 201 Created
{
  "user": { "id": 6, "name": "Jane Doe", "email": "jane@example.com", "created_at": "2026-11-02T11:49:57+00:00" },
  "token": "1|p7nXPBmKFiZY1i3C...",
  "token_type": "Bearer"
}
```

A `SendWelcomeEmail` job is queued for the new user.

#### `POST /api/login`

| Field | Rules |
|---|---|
| `email` | required |
| `password` | required |
| `device_name` | optional, used as the token name |

Returns `200` with the same shape as register. Wrong credentials return `401` with `{"message": "The provided credentials are incorrect."}`, and the message is the same whether the email exists or not.

#### `POST /api/logout`

Revokes the token used for the request. Tokens on other devices keep working.

```json
{ "message": "Logged out." }
```

### Posts

#### `GET /api/posts`

Newest first, paginated.

| Query | Default | Notes |
|---|---|---|
| `page` | 1 | |
| `per_page` | 15 | 1 to 100 |
| `user_id` | | Only posts by this user |

```json
{
  "data": [
    {
      "id": 18,
      "title": "Hello Perth",
      "body": "First post.",
      "author": { "id": 6, "name": "Jane Doe", "created_at": "..." },
      "created_at": "2026-11-02T11:49:57+00:00",
      "updated_at": "2026-11-02T11:49:57+00:00"
    }
  ],
  "links": { "first": "...?page=1", "last": "...?page=2", "prev": null, "next": "...?page=2" },
  "meta": { "current_page": 1, "last_page": 2, "per_page": 15, "total": 18 }
}
```

#### `GET /api/posts/{id}`

Returns `{ "data": { ...post } }`, or `404`.

#### `POST /api/posts`

| Field | Rules |
|---|---|
| `title` | required, max 255 |
| `body` | required, max 20,000 characters |

Returns `201` with the created post. The author is always the logged-in user. A `user_id` in the body is ignored.

#### `PATCH /api/posts/{id}`

Partial update: send only the fields you want to change (`title`, `body`). Only the author can update. Anyone else gets `403`.

#### `DELETE /api/posts/{id}`

Only the author can delete. Returns `204 No Content`.

### Users

#### `GET /api/users`

Paginated (`page`, `per_page` 1 to 100), ordered by ID, with each user's `posts_count`.

#### `GET /api/users/{id}`

```json
{ "data": { "id": 1, "name": "Demo User", "posts_count": 5, "created_at": "..." } }
```

`email` is included only when you request your own profile.

### Weather

#### `GET /api/weather`

Current weather and today's forecast for Perth, Australia.

```json
{
  "data": {
    "location": "Perth, Australia",
    "timezone": "Australia/Perth",
    "observed_at": "2026-11-02T19:45",
    "condition": "Overcast",
    "temperature_c": 17.9,
    "feels_like_c": 17.1,
    "humidity_percent": 68,
    "precipitation_mm": 0,
    "wind_speed_kmh": 10,
    "today": { "date": "2026-11-02", "min_c": 15.4, "max_c": 19.8, "precipitation_chance_percent": 49 },
    "source": "open-meteo.com",
    "fetched_at": "2026-11-02T11:49:59+00:00"
  },
  "meta": { "stale": false }
}
```

`meta.stale` is `true` when the provider is down and the last known result is being served.

## Design notes

- **Structure.** Validation lives in Form Requests, response shapes in API Resources, ownership rules in `PostPolicy`, and the weather logic in `WeatherService`, so controllers stay short.
- **Database.** `posts.user_id` is a foreign key with `ON DELETE CASCADE`, and `posts.created_at` is indexed for the default sort. `User hasMany Post`, `Post belongsTo User`.
- **Performance.** Post lists eager load the author (no N+1 queries), user lists use `withCount`, `per_page` is capped at 100, and weather calls are cached.
- **Security.**
  - Passwords are hashed.
  - Login doesn't reveal whether an email exists.
  - Register and login are rate limited.
  - Only the author can change or delete a post.
  - Mass assignment is limited to `title` and `body`.
  - Other users' emails are hidden.
  - 404 responses don't leak model class names.
  - API routes always answer with JSON, never with a redirect.
- **Queues.** `SendWelcomeEmail` retries 3 times with backoff and dispatches after the database commit. `RefreshWeatherData` is unique, so overlapping refreshes don't pile up.
