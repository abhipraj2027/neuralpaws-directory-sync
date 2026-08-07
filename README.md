# NeuralPaws AI Tools Directory — Product Hunt Sync

Production-ready pipeline that pulls new AI tool launches from Product Hunt
and turns them into a structured, filterable directory on neuralpaws.com —
running unattended on a daily GitHub Actions schedule.

**What you get:**
- A WordPress plugin: `ai_tool` custom post type + `tool_category` /
  `tool_pricing` taxonomies, REST-exposed meta fields, SEO schema
  (SoftwareApplication, ItemList, BreadcrumbList), and directory/single
  page templates.
- A Python sync engine: pulls from Product Hunt's GraphQL API, filters
  for AI relevance, dedupes against your existing directory, and creates
  entries — auto-published above a vote threshold, drafted for review
  below it.
- A GitHub Actions workflow that runs it daily for free, no server needed.

---

## 1. Install the WordPress plugin

1. Zip the `wordpress-plugin/` folder's contents (or upload the folder
   directly via SFTP to `wp-content/plugins/neuralpaws-directory/`).
2. WP Admin → Plugins → Activate **NeuralPaws AI Tools Directory**.
3. Go to Settings → Permalinks and click **Save** once (flushes rewrite
   rules so `/ai-tools/` resolves).
4. Visit `https://neuralpaws.com/ai-tools/` — you should see an empty
   directory grid. That's expected; the sync script fills it.

**If you run Rank Math or Yoast:** open Rank Math → Titles & Meta →
Post Types → AI Tools, and turn OFF its automatic Schema generation for
this post type. The plugin ships its own `SoftwareApplication` schema
tailored to directory listings — running both creates duplicate/
conflicting JSON-LD, which hurts rather than helps.

## 2. Create a WordPress Application Password

The Python script authenticates as a real WP user via an **Application
Password** (built into WordPress core since 5.6) — never your login
password.

1. WP Admin → Users → your profile (or a dedicated `directory-bot` user
   with the Author or Editor role — recommended, so a leaked credential
   can't touch anything outside the directory).
2. Scroll to **Application Passwords**, name it `directory-sync`, click
   **Add New**.
3. Copy the generated password immediately (spaces included) — WordPress
   won't show it again.

## 3. Get a Product Hunt Developer Token

1. Go to <https://www.producthunt.com/v2/oauth/applications>.
2. Create an application (name/redirect URL don't matter for this use
   case — you're not doing user OAuth).
3. On the application's page, scroll to the bottom → **Developer Token**
   → copy it. This is a non-expiring, read-only token — no OAuth flow
   needed.

## 4. Configure

```bash
cp .env.example .env
```

Fill in `PH_API_TOKEN`, `WP_BASE_URL`, `WP_USERNAME`, `WP_APP_PASSWORD`.
Defaults for the rest are sensible — see comments in `.env.example`.

## 5. Run it locally first

```bash
python -m venv venv && source venv/bin/activate
pip install -r requirements.txt

# Dry run — see exactly what would happen, writes nothing:
cd src && python main.py --dry-run --days 3

# Real run:
python main.py --days 3
```

You'll see one line per AI-relevant tool found, e.g.:

```
2026-08-07 ... INFO sync: Charlie Labs Daemons             votes=277  score=318.55 -> PUBLISH (clean)
2026-08-07 ... INFO sync: SomeNewAIWriter                  votes=12   score=13.8   -> DRAFT (below auto-publish threshold (12 < 50 votes))
```

## 6. Automate it with GitHub Actions (recommended, free)

1. Push this repo to GitHub (private repo is fine).
2. Repo → Settings → Secrets and variables → Actions → **New repository
   secret**, add:
   - `PH_API_TOKEN`
   - `WP_BASE_URL`
   - `WP_USERNAME`
   - `WP_APP_PASSWORD`
3. (Optional) Under the **Variables** tab, add `AUTO_PUBLISH_VOTE_THRESHOLD`
   and `MIN_VOTES_TO_LIST` to override the defaults without touching code.
4. The workflow at `.github/workflows/sync-directory.yml` runs daily at
   06:00 UTC automatically. To test it immediately: Actions tab →
   "Sync AI Tools Directory from Product Hunt" → **Run workflow** (tick
   "Dry run" for the first test).

That's it — no server, no cron box to maintain, free on GitHub's public
runner minutes for a job this size.

### Alternative: cron on your own server

```cron
# /etc/cron.d/neuralpaws-directory-sync
0 6 * * * deploy cd /opt/neuralpaws-directory-sync/src && /opt/neuralpaws-directory-sync/venv/bin/python main.py >> /var/log/directory-sync.log 2>&1
```

---

## How the auto-publish decision works

Every tool found gets scored (`src/filters.py`):

| Check | Effect |
|---|---|
| Not AI-relevant (no umbrella topic tag, no AI keyword in name/tagline/description) | Skipped entirely — never created |
| No website URL | Skipped entirely |
| Fewer than `MIN_VOTES_TO_LIST` (default 5) | Skipped entirely — too little signal to be worth a page |
| ≥ `MIN_VOTES_TO_LIST` but < `AUTO_PUBLISH_VOTE_THRESHOLD` (default 50) | Created as **draft**, tagged `needs_review` |
| ≥ `AUTO_PUBLISH_VOTE_THRESHOLD` | Created as **published**, tagged `auto_published` |

Review the queue any time: **wp-admin → AI Tools → filter "Needs review"**.

Already-listed tools (matched by Product Hunt ID or by website domain)
are never re-created or overwritten editorially — the sync only refreshes
their vote count and a `last_verified` timestamp, so re-running the
script daily is always safe.

**Tuning the threshold:** 50 votes is a reasonable starting bar (roughly
top-15–20 of a typical PH day). Lower it if the directory feels too
sparse; raise it if `needs_review` is filling up faster than you can
clear it. Either way, nothing goes live without at least clearing
`MIN_VOTES_TO_LIST` and having a working website — see
`docs/seo-strategy.md` for why that quality floor matters for rankings,
not just editorial sanity.

---

## Project structure

```
wordpress-plugin/
  neuralpaws-directory.php     CPT, taxonomies, REST meta, schema, admin UI
  templates/
    archive-ai_tool.php        Directory grid + filters (category, pricing, sort)
    single-ai_tool.php         Individual tool page + related tools

src/
  ph_client.py                 Product Hunt GraphQL client (paginated, rate-limit aware)
  filters.py                   AI-relevance scoring, category mapping, auto-publish gate
  content.py                   Builds the initial seed content block per tool
  wp_client.py                 WP REST client — dedupe, taxonomy, media, create/update
  main.py                      Orchestrator / CLI entrypoint

.github/workflows/
  sync-directory.yml           Daily scheduled run

docs/
  seo-strategy.md               Full SEO strategy for ranking the directory
```

## Extending it

- **More topics:** edit `WATCHED_TOPICS` in `src/filters.py`.
- **More categories:** extend `TOPIC_CATEGORY_MAP` in the same file.
- **Real pricing data instead of best-effort guesses:** add a step in
  `main.py` that fetches `{website}/pricing` and extracts a price with
  a regex or a small LLM call before `create_tool()` — the `_tool_pricing_start`
  meta field is already wired up for it, just currently left blank.
- **Slack/email digest of what got published each run:** add a webhook
  POST at the end of `main()` in `main.py` using the `stats` dict.
