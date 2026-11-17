<?php

function getStaticProductSizeChartRow(string $size): ?array
{
    $originalSize = trim($size);
    $size = strtolower(trim($size));
    $presets = [
        'small' => ['height_min' => 155, 'height_max' => 165, 'height_cm' => 160, 'height_range' => '155–165', 'weight_min' => 45, 'weight_max' => 55, 'weight_kg' => 50, 'weight_range' => '45–55', 'shoulder_cm' => 42, 'top_length_cm' => 65, 'waist_cm' => 92],
        'medium' => ['height_min' => 165, 'height_max' => 170, 'height_cm' => 167.5, 'height_range' => '165–170', 'weight_min' => 55, 'weight_max' => 65, 'weight_kg' => 60, 'weight_range' => '55–65', 'shoulder_cm' => 44, 'top_length_cm' => 68, 'waist_cm' => 98],
        'large' => ['height_min' => 170, 'height_max' => 175, 'height_cm' => 172.5, 'height_range' => '170–175', 'weight_min' => 65, 'weight_max' => 75, 'weight_kg' => 70, 'weight_range' => '65–75', 'shoulder_cm' => 46, 'top_length_cm' => 71, 'waist_cm' => 104],
        'xl' => ['height_min' => 175, 'height_max' => 180, 'height_cm' => 177.5, 'height_range' => '175–180', 'weight_min' => 75, 'weight_max' => 85, 'weight_kg' => 80, 'weight_range' => '75–85', 'shoulder_cm' => 48, 'top_length_cm' => 74, 'waist_cm' => 110],
        '2xl' => ['height_min' => 180, 'height_max' => 185, 'height_cm' => 182.5, 'height_range' => '180–185', 'weight_min' => 85, 'weight_max' => 95, 'weight_kg' => 90, 'weight_range' => '85–95', 'shoulder_cm' => 50, 'top_length_cm' => 77, 'waist_cm' => 116],
        '3xl' => ['height_min' => 185, 'height_max' => 190, 'height_cm' => 187.5, 'height_range' => '185–190', 'weight_min' => 95, 'weight_max' => 105, 'weight_kg' => 100, 'weight_range' => '95–105', 'shoulder_cm' => 52, 'top_length_cm' => 80, 'waist_cm' => 122],
        '4xl' => ['height_min' => 190, 'height_max' => 195, 'height_cm' => 192.5, 'height_range' => '190–195', 'weight_min' => 105, 'weight_max' => 115, 'weight_kg' => 110, 'weight_range' => '105–115', 'shoulder_cm' => 54, 'top_length_cm' => 83, 'waist_cm' => 128],
        '5xl' => ['height_min' => 195, 'height_max' => 200, 'height_cm' => 197.5, 'height_range' => '195–200', 'weight_min' => 115, 'weight_max' => 125, 'weight_kg' => 120, 'weight_range' => '115–125', 'shoulder_cm' => 56, 'top_length_cm' => 86, 'waist_cm' => 134],
    ];

    $sizeAliases = [
        'small' => ['small', 's'],
        'medium' => ['medium', 'm'],
        'large' => ['large', 'l'],
        'xl' => ['xl'],
        '2xl' => ['2xl', 'xxl'],
        '3xl' => ['3xl', 'xxxl'],
        '4xl' => ['4xl', 'xxxxl'],
        '5xl' => ['5xl', 'xxxxxl'],
    ];
    foreach ($sizeAliases as $label => $aliases) {
        foreach ($aliases as $alias) {
            if (preg_match('/(?<![a-z0-9])' . preg_quote($alias, '/') . '(?![a-z0-9])/', $size)) {
                return array_merge(['size' => $originalSize], $presets[$label]);
            }
        }
    }

    return null;
}

function recommendStaticProductSize(array $measurements, array $availableSizes): ?string
{
    $fields = ['body_height', 'body_weight', 'shoulder', 'top_length', 'waist'];
    foreach ($fields as $field) {
        if (!isset($measurements[$field]) || !is_numeric($measurements[$field]) || (float)$measurements[$field] <= 0) return null;
    }

    $bestSize = null;
    $bestScore = INF;
    foreach ($availableSizes as $size) {
        $chart = getStaticProductSizeChartRow((string)$size);
        if (!$chart) continue;
        $score = 0;
        foreach ($fields as $field) {
            $chartField = ['body_height' => 'height_cm', 'body_weight' => 'weight_kg', 'shoulder' => 'shoulder_cm', 'top_length' => 'top_length_cm', 'waist' => 'waist_cm'][$field];
            $target = (float)$chart[$chartField];
            $value = (float)$measurements[$field];
            if ($field === 'body_height' || $field === 'body_weight') {
                $prefix = $field === 'body_height' ? 'height' : 'weight';
                $minimum = (float)$chart[$prefix . '_min'];
                $maximum = (float)$chart[$prefix . '_max'];
                $difference = $value < $minimum ? $minimum - $value : ($value > $maximum ? $value - $maximum : 0);
            } else {
                $difference = abs($value - $target);
            }
            $score += $difference / $target;
        }
        $score /= count($fields);
        if ($score < $bestScore) {
            $bestScore = $score;
            $bestSize = (string)$size;
        }
    }
    return $bestSize;
}

function parseOrderedFitDetails(string $storedSize): array
{
    $parts = explode(' | Fit: ', $storedSize, 2);
    if (count($parts) !== 2) return ['size' => $storedSize, 'recommended_size' => '', 'measurements' => ''];
    [$size, $fitDetails] = $parts;
    $fitParts = explode(' | Measurements: ', $fitDetails, 2);
    return [
        'size' => $size,
        'recommended_size' => $fitParts[0] ?? '',
        'measurements' => $fitParts[1] ?? '',
    ];
}
