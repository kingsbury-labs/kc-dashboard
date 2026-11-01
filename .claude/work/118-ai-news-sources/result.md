Changed `config/feeds.php` only: 12 new `ai` feeds (limit 15). `--filter Feed`: 23 passed. No mutation check (Small card).

Kept (valid RSS/Atom, item within 30 days): The Decoder, IEEE Spectrum AI, Interconnects, One Useful Thing, AI Snake Oil, Ahead of AI, NVIDIA Technical Blog, The Register AI, MIT News ML, The New Stack, Nature Machine Learning, Last Week in AI (latest 9 Oct).

Dropped:
- MarkTechPost, Microsoft Research: 403 to curl.
- VentureBeat: 429.
- AI News (artificialintelligence-news.com), LangChain: returned HTML, not a feed.
- ML@CMU (10 Aug), BAIR (29 Jul), The Gradient (Feb 2026), SemiAnalysis (Sep 2025), Replicate (Apr 2026): stale.
- Podcasts and already-present feeds (Hugging Face, Latent Space, Simon Willison, DeepMind): skipped.
