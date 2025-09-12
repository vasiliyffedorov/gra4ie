<?php
declare(strict_types=1);

namespace App\Processors;

use App\Utilities\Logger;

class CorridorWidthEnsurer
{
    public static function ensureWidth(
        array $upper,
        array $lower,
        float $upperZeroAmp,
        float $lowerZeroAmp,
        array $config,
        Logger $logger,
        ?array $upperTrend = null,
        ?array $lowerTrend = null
    ): array {
        $minWidthFactor = $config['corrdor_params']['min_corridor_width_factor'] ?? 0.1;
        $minWidth = $minWidthFactor * abs($upperZeroAmp - $lowerZeroAmp);

        if ($minWidth <= 0) {
            $minWidth = $minWidthFactor * max(abs($upperZeroAmp), abs($lowerZeroAmp), 1);
        }

        $correctedUpper = $upper;
        $correctedLower = $lower;
        $breakPoints    = [];

        // ищем точки, где ширина >= минимальной
        foreach ($upper as $i => $u) {
            $w = ($u['value'] ?? 0) - ($lower[$i]['value'] ?? 0);
            if ($w >= $minWidth) {
                $breakPoints[] = [
                    'time'        => $u['time'],
                    'upper_value' => $u['value'],
                    'lower_value' => $lower[$i]['value']
                ];
            }
        }

        // если ни одна точка не подошла — строим равномерный коридор
        if (empty($breakPoints)) {
            $mid = 0;
            if ($upperTrend !== null && $lowerTrend !== null && isset($upperTrend['intercept']) && isset($lowerTrend['intercept'])) {
                $mid = ($upperTrend['intercept'] + $lowerTrend['intercept']) / 2;
                $logger->info("Использован средний intercept трендов для mid: $mid");
            } else {
                $mid = ($upperZeroAmp + $lowerZeroAmp) / 2;
                $logger->info("Использованы нулевые гармоники для mid: $mid");
            }
            $halfW = $minWidth / 2;
            foreach ($upper as $i => $p) {
                $correctedUpper[$i]['value'] = $mid + $halfW;
                $correctedLower[$i]['value'] = $mid - $halfW;
            }
            return [$correctedUpper, $correctedLower];
        }

        // добавляем крайние точки, если нужно
        usort($breakPoints, fn($a,$b)=> $a['time']<=>$b['time']);
        $firstTime = $upper[0]['time'];
        $lastTime  = $upper[count($upper)-1]['time'];

        if ($breakPoints[0]['time'] > $firstTime) {
            array_unshift($breakPoints, [
                'time'=>$firstTime,
                'upper_value'=>$breakPoints[0]['upper_value'],
                'lower_value'=>$breakPoints[0]['lower_value']
            ]);
            $logger->info("Добавлена начальная точка разрыва");
        }
        if (end($breakPoints)['time'] < $lastTime) {
            $bp = end($breakPoints);
            $breakPoints[] = [
                'time'=>$lastTime,
                'upper_value'=>$bp['upper_value'],
                'lower_value'=>$bp['lower_value']
            ];
            $logger->info("Добавлена конечная точка разрыва");
        }

        // для каждой точки делаем линейную интерполяцию между соседними breakPoints
        foreach ($upper as $i => $point) {
            $t = $point['time'];
            // если ширина уже норм — пропускаем
            if (($point['value'] - $lower[$i]['value']) >= $minWidth) {
                continue;
            }
            $left = null; $right = null;
            foreach ($breakPoints as $bp) {
                if ($bp['time'] <= $t) {
                    $left = $bp;
                } elseif ($right === null || $bp['time'] < $right['time']) {
                    $right = $bp;
                }
            }
            if (!$left) {
                $correctedUpper[$i]['value'] = $right['upper_value'];
                $correctedLower[$i]['value'] = $right['lower_value'];
                continue;
            }
            if (!$right) {
                $correctedUpper[$i]['value'] = $left['upper_value'];
                $correctedLower[$i]['value'] = $left['lower_value'];
                continue;
            }
            $dt = $right['time'] - $left['time'];
            if ($dt === 0) {
                $correctedUpper[$i]['value'] = $left['upper_value'];
                $correctedLower[$i]['value'] = $left['lower_value'];
            } else {
                $f = ($t - $left['time']) / $dt;
                $correctedUpper[$i]['value'] = $left['upper_value'] + $f * ($right['upper_value'] - $left['upper_value']);
                $correctedLower[$i]['value'] = $left['lower_value'] + $f * ($right['lower_value'] - $left['lower_value']);
            }
        }

        return [$correctedUpper, $correctedLower];
    }
}
