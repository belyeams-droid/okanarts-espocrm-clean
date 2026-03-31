<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Jobs\Base;
use Espo\ORM\EntityManager;

class CampaignMonitorUrlProcessor extends Base
{
    protected EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function run(): void
    {
        echo "Starting URL Processor\n";

        $pdo = $this->entityManager->getPDO();

        $stmt = $pdo->query("
            SELECT DISTINCT e.url, e.url_hash
            FROM cm_click_event e
            LEFT JOIN cm_url_metadata m ON e.url_hash = m.url_hash
            WHERE m.url_hash IS NULL
            LIMIT 1000
        ");

        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {

            $url = $row['url'];
            $urlHash = $row['url_hash'];

            // 🔥 IMPORTANT: USE HOST + PATH
            $host = parse_url($url, PHP_URL_HOST) ?? '';
            $path = parse_url($url, PHP_URL_PATH) ?? '';

            $full = strtolower($host . $path);

            $normalizedPath = strtolower(trim($path, '/'));

            $topic = $this->extractTopic($full);
            $subtopic = $this->extractSubtopic($normalizedPath);

            $insert = $pdo->prepare("
                INSERT INTO cm_url_metadata (
                    url_hash,
                    url,
                    path,
                    topic,
                    subtopic
                ) VALUES (
                    :url_hash,
                    :url,
                    :path,
                    :topic,
                    :subtopic
                )
            ");

            $insert->execute([
                'url_hash' => $urlHash,
                'url' => $url,
                'path' => $normalizedPath,
                'topic' => $topic,
                'subtopic' => $subtopic
            ]);
        }

        echo "Processed " . count($rows) . " URLs\n";
    }

    private function extractTopic(string $text): string
    {
        // 🌍 DESTINATIONS
        if (str_contains($text, 'scotland')) return 'scotland';
        if (str_contains($text, 'wales')) return 'wales';
        if (str_contains($text, 'england')) return 'england';
        if (str_contains($text, 'japan')) return 'japan';

        // 🎯 HIGH INTENT (EVENTS / REGISTRATION)
        if (str_contains($text, 'cvent')) return 'event';
        if (str_contains($text, 'event')) return 'event';
        if (str_contains($text, 'register')) return 'event';
        if (str_contains($text, 'regprocess')) return 'event';

        // 🛒 COMMERCE
        if (str_contains($text, '/collections')) return 'commerce';
        if (str_contains($text, '/products')) return 'commerce';
        if (str_contains($text, 'shop')) return 'commerce';

        // 🎓 EDUCATION / WORKSHOPS
        if (str_contains($text, 'workshop')) return 'workshop';
        if (str_contains($text, 'lecture')) return 'education';
        if (str_contains($text, 'guild')) return 'education';

        // 🧳 TRAVEL
        if (str_contains($text, 'tour')) return 'tour';
        if (str_contains($text, 'itinerary')) return 'travel';

        // 📰 CONTENT
        if (str_contains($text, 'blog')) return 'content';
        if (str_contains($text, 'article')) return 'content';
        if (str_contains($text, 'issue')) return 'content';
        if (str_contains($text, 'exhibitions')) return 'content';

        // 🎥 MEDIA
        if (str_contains($text, 'vimeo')) return 'media';
        if (str_contains($text, 'spotify')) return 'media';

        // 📱 SOCIAL
        if (str_contains($text, 'instagram')) return 'social';
        if (str_contains($text, 'facebook')) return 'social';
        if (str_contains($text, 'pinterest')) return 'social';

        return 'other';
    }

    private function extractSubtopic(string $path): ?string
    {
        $parts = explode('/', $path);
        return $parts[count($parts) - 1] ?? null;
    }
}
