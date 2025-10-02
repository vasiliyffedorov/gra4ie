<?php class Test {
    private function alignByTime(array $orig, array $u, array $l): array
    {
        $origTimes = array_column($orig, 'time');
        $uTimes = array_column($u, 'time');
        $lTimes = array_column($l, 'time');

        $commonTimes = array_intersect($origTimes, $uTimes, $lTimes);
        if (empty($commonTimes)) {
            return $orig; // fallback
        }

        $aligned = [];
        foreach ($orig as $point) {
            if (in_array($point['time'], $commonTimes)) {
                $aligned[] = $point;
            }
        }
        return $aligned;
    /**
     * Корректирует границы коридора для баланса.
     */
    private function adjustCorridorBounds(float &$upper, float &$lower, float $value): void
    {
        $mid = ($upper + $lower) / 2;
        $diffUpper = $upper - $mid;
        $diffLower = $mid - $lower;

        if ($diffUpper > 5 * $diffLower) {
            $upper = $mid + 5 * $diffLower;
        }
        if ($diffLower > 5 * $diffUpper) {
            $lower = $mid - 5 * $diffUpper;
        }
    }
}
