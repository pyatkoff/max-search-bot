<?php

class WebsiteRolloutService
{
    private int $percent;
    private string $salt;

    public function __construct(int $percent, string $salt = 'anytour-website-rollout-v1')
    {
        $this->percent = max(0, min(100, $percent));
        $this->salt = $salt;
    }

    public function percent(): int
    {
        return $this->percent;
    }

    public function bucket(string $visitorId): int
    {
        $visitorId = trim($visitorId);
        if ($visitorId === '') {
            return 100;
        }

        $hex = substr(hash('sha256', $this->salt . '|' . $visitorId), 0, 8);
        return (int) (hexdec($hex) % 100);
    }

    public function isEnabled(string $visitorId): bool
    {
        if ($this->percent <= 0) {
            return false;
        }
        if ($this->percent >= 100) {
            return trim($visitorId) !== '';
        }

        return $this->bucket($visitorId) < $this->percent;
    }
}
