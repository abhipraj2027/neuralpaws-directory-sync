"""
Product Hunt API v2 (GraphQL) client.

Docs: https://api.producthunt.com/v2/docs
Endpoint: https://api.producthunt.com/v2/api/graphql
Auth: Authorization: Bearer <developer token>
      Get one free at https://www.producthunt.com/v2/oauth/applications
      (use the "Developer Token" at the bottom of your app's page — no
      OAuth flow needed for read-only public data).

Rate limiting: PH meters requests against a rolling complexity budget
(roughly 6250 units / 15 min window). A single page of ~20 posts with
topics costs ~100 units, so we can safely pull dozens of pages per run.
We read the x-rate-limit-* response headers and back off automatically
if we get close to the limit, and retry with exponential backoff on
429s or transient network errors.
"""

from __future__ import annotations

import logging
import time
from dataclasses import dataclass, field
from datetime import datetime, timezone
from typing import Iterator, Optional

import requests

log = logging.getLogger("ph_client")

API_URL = "https://api.producthunt.com/v2/api/graphql"

POSTS_QUERY = """
query PostsByTopic($topic: String, $postedAfter: DateTime, $after: String) {
  posts(topic: $topic, postedAfter: $postedAfter, order: VOTES, first: 20, after: $after) {
    pageInfo {
      hasNextPage
      endCursor
    }
    edges {
      node {
        id
        name
        tagline
        description
        slug
        url
        website
        votesCount
        commentsCount
        createdAt
        featuredAt
        thumbnail {
          url
        }
        topics(first: 10) {
          edges {
            node {
              name
              slug
            }
          }
        }
      }
    }
  }
}
"""


@dataclass
class PHPost:
    ph_id: str
    name: str
    tagline: str
    description: str
    slug: str
    ph_url: str
    website: str
    votes: int
    comments: int
    created_at: str
    featured_at: Optional[str]
    thumbnail_url: Optional[str]
    topics: list[str] = field(default_factory=list)


class ProductHuntClient:
    def __init__(self, token: str, timeout: int = 20):
        if not token:
            raise ValueError(
                "Product Hunt token is required. Set PH_API_TOKEN in your .env "
                "(get one at https://www.producthunt.com/v2/oauth/applications)."
            )
        self.token = token
        self.timeout = timeout
        self.session = requests.Session()
        self.session.headers.update(
            {
                "Authorization": f"Bearer {token}",
                "Content-Type": "application/json",
                "Accept": "application/json",
            }
        )

    def _post(self, query: str, variables: dict, max_retries: int = 4) -> dict:
        backoff = 2.0
        for attempt in range(1, max_retries + 1):
            resp = self.session.post(
                API_URL,
                json={"query": query, "variables": variables},
                timeout=self.timeout,
            )

            remaining = resp.headers.get("x-rate-limit-remaining")
            reset_in = resp.headers.get("x-rate-limit-reset")
            if remaining is not None:
                log.debug("PH rate limit remaining=%s reset_in=%s", remaining, reset_in)
                if int(remaining) < 50 and reset_in:
                    wait = min(int(reset_in), 900)
                    log.warning("Close to PH rate limit, sleeping %ss", wait)
                    time.sleep(wait)

            if resp.status_code == 429:
                wait = int(resp.headers.get("x-rate-limit-reset", backoff))
                log.warning("PH 429 rate-limited, sleeping %ss (attempt %s)", wait, attempt)
                time.sleep(wait)
                backoff *= 2
                continue

            if resp.status_code >= 500:
                log.warning("PH %s server error, retrying in %ss", resp.status_code, backoff)
                time.sleep(backoff)
                backoff *= 2
                continue

            resp.raise_for_status()
            data = resp.json()
            if "errors" in data:
                raise RuntimeError(f"Product Hunt API error: {data['errors']}")
            return data["data"]

        raise RuntimeError(f"Product Hunt API failed after {max_retries} retries")

    def iter_posts_by_topic(
        self, topic_slug: str, posted_after: Optional[datetime] = None, max_pages: int = 25
    ) -> Iterator[PHPost]:
        """Yield posts for a topic, newest-votes-first, paginating until
        exhausted or max_pages is hit (safety cap so a misconfigured run
        can't burn the whole rate-limit window)."""

        after_cursor = None
        posted_after_str = None
        if posted_after:
            posted_after_str = posted_after.astimezone(timezone.utc).strftime(
                "%Y-%m-%dT%H:%M:%SZ"
            )

        for page_num in range(max_pages):
            data = self._post(
                POSTS_QUERY,
                {
                    "topic": topic_slug,
                    "postedAfter": posted_after_str,
                    "after": after_cursor,
                },
            )
            posts = data["posts"]
            for edge in posts["edges"]:
                node = edge["node"]
                yield PHPost(
                    ph_id=node["id"],
                    name=node["name"],
                    tagline=node.get("tagline") or "",
                    description=node.get("description") or "",
                    slug=node["slug"],
                    ph_url=node.get("url") or f"https://www.producthunt.com/posts/{node['slug']}",
                    website=node.get("website") or "",
                    votes=node.get("votesCount") or 0,
                    comments=node.get("commentsCount") or 0,
                    created_at=node["createdAt"],
                    featured_at=node.get("featuredAt"),
                    thumbnail_url=(node.get("thumbnail") or {}).get("url"),
                    topics=[t["node"]["slug"] for t in node["topics"]["edges"]],
                )

            page_info = posts["pageInfo"]
            if not page_info["hasNextPage"]:
                break
            after_cursor = page_info["endCursor"]
