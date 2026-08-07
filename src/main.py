"""
neuralpaws-directory-sync — main entrypoint.

Pulls new/trending AI-relevant launches from Product Hunt, dedupes
against the existing WordPress directory, and creates ai_tool entries:
  - status=publish  if the tool clears AUTO_PUBLISH_VOTE_THRESHOLD
  - status=draft    otherwise (queued in wp-admin for editorial review)

Run manually:
    python src/main.py --topics artificial-intelligence,developer-tools --days 2

Run on a schedule: see .github/workflows/sync-directory.yml
"""

from __future__ import annotations

import argparse
import logging
import os
import sys
from datetime import datetime, timedelta, timezone

from dotenv import load_dotenv

from content import build_content_html, build_excerpt
from filters import WATCHED_TOPICS, score_tool, should_ingest
from ph_client import ProductHuntClient
from wp_client import WordPressClient

logging.basicConfig(
    level=os.environ.get("LOG_LEVEL", "INFO"),
    format="%(asctime)s %(levelname)-8s %(name)s: %(message)s",
)
log = logging.getLogger("sync")


def parse_args():
    p = argparse.ArgumentParser(description="Sync new AI tool launches from Product Hunt into WordPress.")
    p.add_argument(
        "--topics",
        default=",".join(WATCHED_TOPICS),
        help="Comma-separated Product Hunt topic slugs to scan.",
    )
    p.add_argument(
        "--days",
        type=int,
        default=int(os.environ.get("LOOKBACK_DAYS", 2)),
        help="Only consider posts launched in the last N days.",
    )
    p.add_argument(
        "--auto-publish-threshold",
        type=int,
        default=int(os.environ.get("AUTO_PUBLISH_VOTE_THRESHOLD", 50)),
        help="Minimum PH votes for a tool to be auto-published instead of drafted.",
    )
    p.add_argument(
        "--min-votes",
        type=int,
        default=int(os.environ.get("MIN_VOTES_TO_LIST", 5)),
        help="Minimum PH votes for a tool to be listed at all (even as draft).",
    )
    p.add_argument(
        "--max-pages-per-topic",
        type=int,
        default=int(os.environ.get("MAX_PAGES_PER_TOPIC", 10)),
        help="Safety cap on pagination per topic per run.",
    )
    p.add_argument(
        "--dry-run",
        action="store_true",
        help="Fetch and score, but don't write anything to WordPress.",
    )
    return p.parse_args()


def main():
    load_dotenv()
    args = parse_args()

    ph_token = os.environ.get("PH_API_TOKEN")
    wp_url = os.environ.get("WP_BASE_URL")
    wp_user = os.environ.get("WP_USERNAME")
    wp_pass = os.environ.get("WP_APP_PASSWORD")

    missing = [
        name
        for name, val in [
            ("PH_API_TOKEN", ph_token),
            ("WP_BASE_URL", wp_url),
            ("WP_USERNAME", wp_user),
            ("WP_APP_PASSWORD", wp_pass),
        ]
        if not val
    ]
    if missing:
        log.error("Missing required environment variables: %s", ", ".join(missing))
        log.error("Copy .env.example to .env and fill it in (see README.md).")
        sys.exit(1)

    ph = ProductHuntClient(token=ph_token)
    wp = None if args.dry_run else WordPressClient(base_url=wp_url, username=wp_user, app_password=wp_pass)

    posted_after = datetime.now(timezone.utc) - timedelta(days=args.days)
    topics = [t.strip() for t in args.topics.split(",") if t.strip()]

    stats = {"scanned": 0, "ai_relevant": 0, "duplicates": 0, "published": 0, "drafted": 0, "skipped": 0, "errors": 0}
    seen_ph_ids: set[str] = set()

    for topic in topics:
        log.info("Scanning topic '%s' (last %s days)...", topic, args.days)
        try:
            for post in ph.iter_posts_by_topic(
                topic, posted_after=posted_after, max_pages=args.max_pages_per_topic
            ):
                stats["scanned"] += 1

                if post.ph_id in seen_ph_ids:
                    continue  # same post surfaced under a second watched topic
                seen_ph_ids.add(post.ph_id)

                scored = score_tool(
                    post,
                    auto_publish_vote_threshold=args.auto_publish_threshold,
                    min_votes_to_list_at_all=args.min_votes,
                )

                if not should_ingest(scored, args.min_votes):
                    stats["skipped"] += 1
                    continue

                stats["ai_relevant"] += 1
                log.info(
                    "%-40s votes=%-4d score=%-8s -> %s (%s)",
                    post.name,
                    post.votes,
                    scored.score,
                    "PUBLISH" if scored.auto_publish else "DRAFT",
                    ", ".join(scored.reasons) or "clean",
                )

                if args.dry_run:
                    continue

                try:
                    existing = wp.find_existing(post.ph_id, post.website)
                    if existing:
                        stats["duplicates"] += 1
                        # Refresh live signal fields on the existing entry
                        # (votes/last_verified) without touching editorial
                        # content or status — cheap freshness signal that
                        # helps with "content updated recently" ranking
                        # factors without risking overwriting a human edit.
                        wp.update_tool_meta(
                            existing["id"],
                            {
                                "_tool_ph_votes": post.votes,
                                "_tool_last_verified": datetime.now(timezone.utc).strftime("%Y-%m-%d"),
                            },
                        )
                        continue

                    category_ids = [
                        wp.get_or_create_term("tool_category", cat.replace("-ai", " AI").replace("-", " ").title())
                        for cat in scored.categories
                    ]
                    pricing_id = None
                    if scored.pricing_slug != "unknown":
                        pricing_id = wp.get_or_create_term(
                            "tool_pricing", scored.pricing_slug.replace("-", " ").title()
                        )

                    media_id = wp.sideload_image(post.thumbnail_url, post.slug) if post.thumbnail_url else None

                    status = "publish" if scored.auto_publish else "draft"
                    review_status = "auto_published" if scored.auto_publish else "needs_review"

                    result = wp.create_tool(
                        title=post.name,
                        excerpt=build_excerpt(scored),
                        content_html=build_content_html(scored),
                        status=status,
                        meta={
                            "_tool_tagline": post.tagline,
                            "_tool_website": post.website,
                            "_tool_ph_url": post.ph_url,
                            "_tool_ph_id": post.ph_id,
                            "_tool_logo_url": post.thumbnail_url or "",
                            "_tool_ph_votes": post.votes,
                            "_tool_ph_launch_date": (post.featured_at or post.created_at)[:10],
                            "_tool_pricing_start": "",
                            "_tool_source": "producthunt",
                            "_tool_review_status": review_status,
                            "_tool_last_verified": datetime.now(timezone.utc).strftime("%Y-%m-%d"),
                            "_tool_score": scored.score,
                        },
                        category_ids=category_ids,
                        pricing_id=pricing_id,
                        featured_media_id=media_id,
                    )
                    log.info("  -> created ai_tool #%s (%s)", result["id"], status)
                    if status == "publish":
                        stats["published"] += 1
                    else:
                        stats["drafted"] += 1

                except Exception as e:  # noqa: BLE001 - log and keep going
                    stats["errors"] += 1
                    log.error("Failed to sync '%s': %s", post.name, e)

        except Exception as e:  # noqa: BLE001
            log.error("Failed scanning topic '%s': %s", topic, e)

    log.info("=" * 60)
    log.info("Sync complete: %s", stats)
    log.info("=" * 60)

    if stats["drafted"] > 0:
        log.info(
            "%s tools queued for review: wp-admin -> AI Tools -> filter 'Needs review'",
            stats["drafted"],
        )


if __name__ == "__main__":
    main()
