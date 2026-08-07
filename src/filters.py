"""
Relevance scoring, category mapping, and quality gating.

This is the layer that keeps the directory from turning into a spam
dump. Two decisions happen here per tool:

1. Is it AI-relevant at all? (topic + keyword match)
2. Does it meet the bar for AUTO-PUBLISH, or should it land as a draft
   for a human to review? (vote threshold + has a real website + not
   an obvious duplicate/rebrand)
"""

from __future__ import annotations

import re
from dataclasses import dataclass

from ph_client import PHPost

# Product Hunt topic slugs we pull from. "artificial-intelligence" is the
# umbrella; the others catch AI-adjacent tools that don't always tag the
# umbrella topic (e.g. a coding agent might only be tagged "developer-tools").
WATCHED_TOPICS = [
    "artificial-intelligence",
    "developer-tools",
    "productivity",
    "marketing",
    "design-tools",
    "no-code",
    "writing",
]

# Keywords that indicate genuine AI relevance, checked against name +
# tagline + description. A tool from a non-AI topic (e.g. "productivity")
# still needs to hit one of these to be included.
AI_KEYWORDS = [
    "ai ", " ai", "ai-", "-ai", "artificial intelligence", "machine learning",
    "llm", "gpt", "chatbot", "generative", "neural", "agent", "copilot",
    "automation", "genai", "gen ai", "large language model", "ml model",
    "prompt", "claude", "gemini", "openai", "anthropic",
]

# Maps PH topic slugs -> NeuralPaws tool_category taxonomy slugs.
# Extend this as the directory grows into new niches.
TOPIC_CATEGORY_MAP = {
    "developer-tools": "coding-ai",
    "programming": "coding-ai",
    "writing": "writing-ai",
    "marketing": "marketing-ai",
    "design-tools": "design-ai",
    "productivity": "productivity-ai",
    "no-code": "no-code-ai",
    "video": "video-ai",
    "photo-editing": "image-ai",
    "voice-assistant": "audio-ai",
    "sales": "sales-ai",
    "customer-support": "support-ai",
    "artificial-intelligence": None,  # umbrella only, not a category by itself
}
DEFAULT_CATEGORY = "productivity-ai"

# Very rough pricing signal from tagline/description text — the sync
# pipeline flags this as best-effort; editors can correct it during
# review. True pricing is confirmed later by the (optional) enrichment
# step that visits the site's /pricing page.
FREE_PATTERN = re.compile(r"\bfree\b", re.I)
OPEN_SOURCE_PATTERN = re.compile(r"\bopen[- ]source\b", re.I)


@dataclass
class ScoredTool:
    post: PHPost
    is_ai_relevant: bool
    score: float
    categories: list[str]
    pricing_slug: str
    auto_publish: bool
    reasons: list[str]


def is_ai_relevant(post: PHPost) -> bool:
    if "artificial-intelligence" in post.topics:
        return True
    text = f"{post.name} {post.tagline} {post.description}".lower()
    return any(kw in text for kw in AI_KEYWORDS)


def map_categories(post: PHPost) -> list[str]:
    cats = []
    for topic in post.topics:
        mapped = TOPIC_CATEGORY_MAP.get(topic)
        if mapped and mapped not in cats:
            cats.append(mapped)
    return cats or [DEFAULT_CATEGORY]


def guess_pricing(post: PHPost) -> str:
    text = f"{post.tagline} {post.description}".lower()
    if OPEN_SOURCE_PATTERN.search(text):
        return "open-source"
    if FREE_PATTERN.search(text):
        return "free"
    return "unknown"  # resolved later by enrichment, or left for editor


def score_tool(
    post: PHPost,
    auto_publish_vote_threshold: int = 50,
    min_votes_to_list_at_all: int = 5,
) -> ScoredTool:
    reasons = []
    relevant = is_ai_relevant(post)
    if not relevant:
        reasons.append("not AI-relevant")

    has_website = bool(post.website)
    if not has_website:
        reasons.append("no website URL")

    # Simple composite score: votes weighted highest, comments as an
    # engagement signal, small bonus for AI-umbrella topic tag (higher
    # signal than keyword match alone).
    score = post.votes * 1.0 + post.comments * 0.5
    if "artificial-intelligence" in post.topics:
        score *= 1.15

    meets_quality_bar = has_website and post.votes >= min_votes_to_list_at_all
    auto_publish = (
        relevant
        and meets_quality_bar
        and post.votes >= auto_publish_vote_threshold
    )

    if relevant and meets_quality_bar and not auto_publish:
        reasons.append(f"below auto-publish threshold ({post.votes} < {auto_publish_vote_threshold} votes)")

    return ScoredTool(
        post=post,
        is_ai_relevant=relevant,
        score=round(score, 2),
        categories=map_categories(post),
        pricing_slug=guess_pricing(post),
        auto_publish=auto_publish,
        reasons=reasons,
    )


def should_ingest(scored: ScoredTool, min_votes_to_list_at_all: int = 5) -> bool:
    """Whether the tool is worth creating a WP entry for at all (as
    either published or draft). Filters out total noise."""
    return (
        scored.is_ai_relevant
        and bool(scored.post.website)
        and scored.post.votes >= min_votes_to_list_at_all
    )
