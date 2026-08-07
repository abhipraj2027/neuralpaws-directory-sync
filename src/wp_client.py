"""
WordPress REST API client for the ai_tool custom post type.

Auth: WordPress Application Passwords (built into core since WP 5.6).
Create one at: WP Admin -> Users -> Your Profile -> Application Passwords.
Set WP_USERNAME + WP_APP_PASSWORD in .env — never use your real login password.

This client is deliberately conservative:
  - dedupe by _tool_ph_id first (exact re-launch match), then by
    normalized website domain (catches rebrands / re-submissions)
  - never deletes or unpublishes anything
  - logo sideloading downloads bytes and POSTs them as WP media (the PH
    thumbnail URL itself is not hotlink-stable long-term)
"""

from __future__ import annotations

import logging
import mimetypes
import re
from typing import Optional
from urllib.parse import urlparse

import requests
from requests.auth import HTTPBasicAuth

log = logging.getLogger("wp_client")


def normalize_domain(url: str) -> str:
    if not url:
        return ""
    try:
        netloc = urlparse(url if "://" in url else f"https://{url}").netloc.lower()
        return re.sub(r"^www\.", "", netloc)
    except Exception:
        return url.lower()


class WordPressClient:
    def __init__(self, base_url: str, username: str, app_password: str, timeout: int = 30):
        self.base_url = base_url.rstrip("/")
        self.auth = HTTPBasicAuth(username, app_password)
        self.timeout = timeout
        self.session = requests.Session()
        self.session.auth = self.auth

    # -------------------------------------------------------------- dedupe

    def find_existing_by_ph_id(self, ph_id: str) -> Optional[dict]:
        resp = self.session.get(
            f"{self.base_url}/wp-json/wp/v2/ai_tool",
            params={"meta_key": "_tool_ph_id", "meta_value": ph_id, "status": "any"},
            timeout=self.timeout,
        )
        # Note: default WP REST doesn't filter by arbitrary meta_value out
        # of the box (needs a small `rest_ai_tool_query` filter in the
        # plugin, added below) — see wordpress-plugin/neuralpaws-directory.php
        # meta-query-rest-filter section.
        resp.raise_for_status()
        results = resp.json()
        return results[0] if results else None

    def find_existing_by_domain(self, website: str) -> Optional[dict]:
        domain = normalize_domain(website)
        if not domain:
            return None
        resp = self.session.get(
            f"{self.base_url}/wp-json/wp/v2/ai_tool",
            params={"search": domain, "status": "any", "per_page": 5},
            timeout=self.timeout,
        )
        resp.raise_for_status()
        for candidate in resp.json():
            existing_domain = normalize_domain(
                candidate.get("meta", {}).get("_tool_website", "")
            )
            if existing_domain and existing_domain == domain:
                return candidate
        return None

    def find_existing(self, ph_id: str, website: str) -> Optional[dict]:
        found = self.find_existing_by_ph_id(ph_id)
        if found:
            return found
        return self.find_existing_by_domain(website)

    # ------------------------------------------------------------ taxonomy

    def get_or_create_term(self, taxonomy: str, name: str) -> int:
        slug = re.sub(r"[^a-z0-9]+", "-", name.lower()).strip("-")
        resp = self.session.get(
            f"{self.base_url}/wp-json/wp/v2/{taxonomy}",
            params={"slug": slug},
            timeout=self.timeout,
        )
        resp.raise_for_status()
        existing = resp.json()
        if existing:
            return existing[0]["id"]

        create = self.session.post(
            f"{self.base_url}/wp-json/wp/v2/{taxonomy}",
            json={"name": name, "slug": slug},
            timeout=self.timeout,
        )
        create.raise_for_status()
        return create.json()["id"]

    # --------------------------------------------------------------- media

    def sideload_image(self, image_url: str, filename_hint: str) -> Optional[int]:
        if not image_url:
            return None
        try:
            img_resp = requests.get(image_url, timeout=self.timeout)
            img_resp.raise_for_status()
        except requests.RequestException as e:
            log.warning("Could not download logo %s: %s", image_url, e)
            return None

        content_type = img_resp.headers.get("Content-Type", "image/png")
        ext = mimetypes.guess_extension(content_type.split(";")[0]) or ".png"
        safe_name = re.sub(r"[^a-z0-9-]+", "-", filename_hint.lower()).strip("-")
        filename = f"{safe_name}-logo{ext}"

        upload = self.session.post(
            f"{self.base_url}/wp-json/wp/v2/media",
            headers={
                "Content-Disposition": f'attachment; filename="{filename}"',
                "Content-Type": content_type.split(";")[0],
            },
            data=img_resp.content,
            timeout=self.timeout,
        )
        if upload.status_code not in (200, 201):
            log.warning("Media upload failed for %s: %s %s", filename, upload.status_code, upload.text[:300])
            return None
        return upload.json()["id"]

    # --------------------------------------------------------------- posts

    def create_tool(
        self,
        title: str,
        excerpt: str,
        content_html: str,
        status: str,  # "publish" | "draft"
        meta: dict,
        category_ids: list[int],
        pricing_id: Optional[int],
        featured_media_id: Optional[int],
    ) -> dict:
        payload = {
            "title": title,
            "excerpt": excerpt,
            "content": content_html,
            "status": status,
            "meta": meta,
            "tool_category": category_ids,
        }
        if pricing_id:
            payload["tool_pricing"] = [pricing_id]
        if featured_media_id:
            payload["featured_media"] = featured_media_id

        resp = self.session.post(
            f"{self.base_url}/wp-json/wp/v2/ai_tool",
            json=payload,
            timeout=self.timeout,
        )
        if resp.status_code not in (200, 201):
            raise RuntimeError(f"Failed to create tool '{title}': {resp.status_code} {resp.text[:500]}")
        return resp.json()

    def update_tool_meta(self, post_id: int, meta: dict) -> dict:
        resp = self.session.post(
            f"{self.base_url}/wp-json/wp/v2/ai_tool/{post_id}",
            json={"meta": meta},
            timeout=self.timeout,
        )
        resp.raise_for_status()
        return resp.json()
