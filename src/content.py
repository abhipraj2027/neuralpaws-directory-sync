"""
Builds the initial post content for a newly-synced tool.

Important: this is a SEED, not a finished editorial page. It's built
entirely from factual, structured data Product Hunt already gives us
(no fabricated claims, no invented pricing) precisely so it's safe to
auto-publish tools that clear the vote threshold. Every block below
maps 1:1 to a real field — nothing here is guessed except the pricing
label, which is explicitly caveated as best-effort.

For SEO health at scale, thin near-duplicate pages are the main risk
of an auto-populated directory. This template mitigates that by:
  - always including the tool's own tagline/description verbatim
    (PH descriptions are usually maker-written and reasonably unique)
  - varying structure based on available data (not every tool gets the
    exact same six sections)
  - flagging low-content tools as needs_review instead of auto-publish
    even if they clear the vote bar (see filters.py + main.py)
"""

from __future__ import annotations

import html

from filters import ScoredTool


def build_excerpt(scored: ScoredTool) -> str:
    tagline = scored.post.tagline.strip()
    if tagline:
        return tagline[:155]
    return (scored.post.description or scored.post.name)[:155]


def build_content_html(scored: ScoredTool) -> str:
    post = scored.post
    parts = []

    tagline = html.escape(post.tagline.strip()) if post.tagline else ""
    description = html.escape(post.description.strip()) if post.description else ""

    if tagline:
        parts.append(f"<p><strong>{tagline}</strong></p>")

    if description and description != tagline:
        parts.append(f"<p>{description}</p>")

    if post.votes:
        parts.append(
            f"<p>{post.name} launched on Product Hunt"
            + (f" on {post.featured_at[:10]}" if post.featured_at else "")
            + f" and has {post.votes} upvotes"
            + (f" and {post.comments} comments" if post.comments else "")
            + " so far.</p>"
        )

    if scored.pricing_slug == "unknown":
        parts.append(
            "<p><em>Pricing details for this tool haven't been verified yet — "
            "check the tool's website for current plans.</em></p>"
        )
    elif scored.pricing_slug == "free":
        parts.append("<p>This tool is described as free to use.</p>")
    elif scored.pricing_slug == "open-source":
        parts.append("<p>This tool is open source.</p>")

    if not parts:
        parts.append(f"<p>{html.escape(post.name)} is an AI tool listed via Product Hunt.</p>")

    return "\n".join(parts)
