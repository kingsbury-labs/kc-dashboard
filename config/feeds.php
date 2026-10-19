<?php
// What bin/fetch-news.php reads. Each feed: name (shown as the source), url (https:// only), topic (the filter),
// and 'format' => 'algolia' for the Hacker News search, the only feed whose points are read.
// From the feeds checked in .claude/work/jarvis-research.md section 3.4 on 2026-10-19. Anthropic has no feed of its
// own, so it comes through the Olshansk/rss-feeds community scrape, which can stop without notice.
return [
    'feeds' => [
        ['name' => 'Simon Willison', 'url' => 'https://simonwillison.net/atom/everything/', 'topic' => 'ai'],
        ['name' => 'OpenAI', 'url' => 'https://openai.com/news/rss.xml', 'topic' => 'ai'],
        ['name' => 'Anthropic', 'url' => 'https://raw.githubusercontent.com/Olshansk/rss-feeds/main/feeds/feed_anthropic_news.xml', 'topic' => 'ai'],
        ['name' => 'Google AI', 'url' => 'https://blog.google/technology/ai/rss/', 'topic' => 'ai'],
        ['name' => 'DeepMind', 'url' => 'https://deepmind.google/blog/rss.xml', 'topic' => 'ai'],
        ['name' => 'Hugging Face', 'url' => 'https://huggingface.co/blog/feed.xml', 'topic' => 'ai'],
        ['name' => 'Latent Space', 'url' => 'https://www.latent.space/feed', 'topic' => 'ai'],
        ['name' => 'Ollama', 'url' => 'https://ollama.com/blog/rss.xml', 'topic' => 'ai'],
        ['name' => 'web.dev', 'url' => 'https://web.dev/static/blog/feed.xml', 'topic' => 'webdev'],
        ['name' => 'Chrome for Developers', 'url' => 'https://developer.chrome.com/static/blog/feed.xml', 'topic' => 'webdev'],
        ['name' => 'Smashing Magazine', 'url' => 'https://www.smashingmagazine.com/feed/', 'topic' => 'webdev'],
        ['name' => 'CSS-Tricks', 'url' => 'https://css-tricks.com/feed/', 'topic' => 'webdev'],
        ['name' => 'Frontend Masters', 'url' => 'https://frontendmasters.com/blog/feed/', 'topic' => 'webdev'],
        ['name' => 'Josh Comeau', 'url' => 'https://www.joshwcomeau.com/rss.xml', 'topic' => 'webdev'],
        ['name' => 'WebKit', 'url' => 'https://webkit.org/feed/', 'topic' => 'webdev'],
        ['name' => 'Frontend Focus', 'url' => 'https://frontendfoc.us/rss', 'topic' => 'webdev'],
        ['name' => 'JavaScript Weekly', 'url' => 'https://javascriptweekly.com/rss', 'topic' => 'webdev'],
        ['name' => 'PHP', 'url' => 'https://www.php.net/feed.atom', 'topic' => 'php'],
        ['name' => 'stitcher.io', 'url' => 'https://stitcher.io/rss', 'topic' => 'php'],
        ['name' => 'Laravel News', 'url' => 'https://laravel-news.com/feed', 'topic' => 'php'],
        ['name' => 'Make WordPress Core', 'url' => 'https://make.wordpress.org/core/feed/', 'topic' => 'wordpress'],
        ['name' => 'Hacker News', 'url' => 'https://hn.algolia.com/api/v1/search?tags=front_page', 'topic' => 'dev',
            'format' => 'algolia'],
        ['name' => 'Lobsters', 'url' => 'https://lobste.rs/rss', 'topic' => 'dev'],
    ],
];
